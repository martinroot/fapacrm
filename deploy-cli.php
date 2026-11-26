<?php
/**
 * Headless driver for the CMS deploy wizard's AUTO mode.
 *   php deploy-cli.php <domain> [...]
 *
 * Mirrors AUTO in pages/site_deploy.php, with two corrections the wizard needs
 * on this machine:
 *
 *  1. build_ctx() hardcodes the PHP 7.4 FPM socket from the old server; the
 *     live socket is detected instead, otherwise every PHP request 502s.
 *  2. AUTO renders the HTTPS vhost directly, but a brand-new domain has no
 *     certificate yet, so `nginx -t` fails and the deploy dies at nginx_apply.
 *     A cert phase is inserted: HTTP-only vhost -> certbot -> HTTPS vhost.
 */
declare(strict_types=1);

chdir('/var/www/fapacrm');
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pages/deploy/lib.php';
require_once __DIR__ . '/pages/site_deploy_cli_helpers.php';

$db = db_connect();

$templatePath = (string)ucfg($GLOBALS['USER_CFG'] ?? [], 'TEMPLATE.site_path', '/var/www/hotpornmoms.ru');
$templateDb   = (string)ucfg($GLOBALS['USER_CFG'] ?? [], 'TEMPLATE.db_name', 'hotpornmoms');
$siteMigrationsDir = rtrim($templatePath, '/') . '/cmd/migrations_site';

$sock = null;
foreach (glob('/run/php/php*-fpm.sock') ?: [] as $s) $sock = $s;
if (!$sock) { fwrite(STDERR, "не нашёл php-fpm сокет\n"); exit(1); }

/* steps before the vhost exists */
$pre = [
  ['key'=>'mkdir',     'file'=>'pages/deploy/01_mkdir.php',           'title'=>'Создание папки'],
  ['key'=>'copy',      'file'=>'pages/deploy/02_rsync.php',           'title'=>'Копирование эталона'],
  ['key'=>'branding',  'file'=>'pages/deploy/13_branding.php',        'title'=>'Branding'],
  ['key'=>'chown',     'file'=>'pages/deploy/03_chown.php',           'title'=>'Права'],
  ['key'=>'db_create', 'file'=>'pages/deploy/04_db_create.php',       'title'=>'Создание БД'],
  ['key'=>'db_dump',   'file'=>'pages/deploy/05_db_dump.php',         'title'=>'Дамп эталона'],
  ['key'=>'db_import', 'file'=>'pages/deploy/06_db_import.php',       'title'=>'Импорт БД'],
  ['key'=>'config',    'file'=>'pages/deploy/07_config.php',          'title'=>'config.user.php'],
  ['key'=>'migrate',   'file'=>'pages/deploy/08_site_migrations.php', 'title'=>'Миграции'],
];
/* steps that need the site already answering */
$post = [
  ['key'=>'apply_site_settings','file'=>'pages/deploy/11_apply_site_settings.php','title'=>'Счётчики + реклама'],
  ['key'=>'cron_seed',         'file'=>'pages/deploy/12_cron_seed.php',          'title'=>'Cron пресеты'],
];


/**
 * The site template routes everything through index.php, which eats the ACME
 * challenge. Inject a prefix location that outranks it.
 */
function acme_location(string $conf): string {
    $block = "\n    location ^~ /.well-known/acme-challenge/ {\n"
           . "        root /var/www/letsencrypt;\n"
           . "        default_type \"text/plain\";\n"
           . "        try_files \$uri =404;\n"
           . "    }\n";
    $pos = strpos($conf, "{");
    return $pos === false ? $conf : substr($conf, 0, $pos + 1) . $block . substr($conf, $pos + 1);
}

function sh(string $cmd): array {
    $out = []; $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    return ['rc'=>$rc, 'out'=>implode("\n", $out)];
}

$targets = array_slice($argv, 1);
if (!$targets) exit("использование: deploy-cli.php <домен> [...]\n");

/* Шаги объявляют функции в глобальной области, поэтому второй домен в том же
   процессе падает на redeclare. Каждому домену — свой процесс (одиночный запуск). */
if (count($targets) > 1) {
    $rc = 0;
    foreach ($targets as $t) {
        passthru(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($t), $c);
        if ($c !== 0) $rc = $c;
    }
    exit($rc);
}

foreach ($targets as $domainName) {
    echo "\n================ $domainName ================\n";
    $dom = db_one($db, "SELECT * FROM crm_domains WHERE domain=?", [$domainName]);
    if (!$dom) { echo "  нет такого домена в crm_domains\n"; continue; }
    $domain_id = (int)$dom['id'];

    db_exec($db, "INSERT INTO crm_deploy_runs(domain_id,status) VALUES(?, 'running')", [$domain_id]);
    $run_id = (int)$db->insert_id;

    cli_normalize_domain_path_db($db, $dom, $domain_id);
    $dom = db_one($db, "SELECT * FROM crm_domains WHERE id=?", [$domain_id]);

    $ctx = cli_build_ctx($dom, $domain_id, $run_id, $templatePath, $templateDb, $siteMigrationsDir);
    $ctx['phpSock'] = 'unix:' . $sock;
    printf("  путь %s | БД %s\n", $ctx['path'], $ctx['dbName']);

    $failed = false;
    $runSteps = function(array $steps) use (&$db, &$run_id, &$domain_id, &$ctx, &$failed) {
        foreach ($steps as $p) {
            if ($failed) return;
            $k = $p['key']; $file = __DIR__ . '/' . $p['file'];
            deploy_ensure_step($db, $run_id, $k, $p['title']);
            deploy_step_start($db, $run_id, $k);
            $res = require $file;
            $rc = (int)($res['rc'] ?? 1); $out = (string)($res['out'] ?? '');
            if ($rc !== 0) {
                deploy_step_finish($db, $run_id, $k, 'fail', $rc, $out);
                printf("  [%-20s] УПАЛ rc=%d\n%s\n", $k, $rc, substr($out, 0, 700));
                $failed = true; return;
            }
            deploy_step_finish($db, $run_id, $k, 'ok', 0, $out);
            printf("  [%-20s] ok\n", $k);
        }
    };

    $runSteps($pre);

    /* ---- vhost + certificate ---- */
    if (!$failed) {
        $d    = $ctx['domain'];
        $conf = "/etc/nginx/sites-enabled/{$d}.conf";
        $cert = "/etc/letsencrypt/live/{$d}/fullchain.pem";

        if (!is_file($cert)) {
            file_put_contents($conf, acme_location(deploy_nginx_render_http_only_config($ctx)));
            $r = sh('nginx -t && systemctl reload nginx');
            if ($r['rc'] !== 0) { echo "  [http-конфиг] УПАЛ\n{$r['out']}\n"; @unlink($conf); $failed = true; }
            else {
                printf("  [%-20s] ok\n", 'nginx http');
                $r = sh('certbot certonly --webroot -w /var/www/letsencrypt'
                    . ' -d ' . escapeshellarg($d) . ' -d ' . escapeshellarg('www.' . $d)
                    . ' --non-interactive --agree-tos --register-unsafely-without-email --keep-until-expiring');
                if ($r['rc'] !== 0 || !is_file($cert)) {
                    echo "  [сертификат] УПАЛ\n" . substr($r['out'], -700) . "\n";
                    $failed = true;
                } else printf("  [%-20s] ok\n", 'сертификат');
            }
        } else printf("  [%-20s] уже есть\n", 'сертификат');

        if (!$failed) {
            file_put_contents($conf, deploy_nginx_render_config($ctx));
            $r = sh('nginx -t && systemctl reload nginx');
            if ($r['rc'] !== 0) { echo "  [https-конфиг] УПАЛ\n{$r['out']}\n"; $failed = true; }
            else printf("  [%-20s] ok\n", 'nginx https');
        }
    }

    /* убираю дамп эталона: он уже импортирован, а весит около гигабайта */
    if (!empty($ctx['dumpFile']) && is_file($ctx['dumpFile'])) {
        $sz = filesize($ctx['dumpFile']);
        @unlink($ctx['dumpFile']);
        printf("  [%-20s] освобождено %.0f МБ\n", 'очистка дампа', $sz / 1048576);
    }

    $runSteps($post);

    if (!$failed) {
        db_exec($db, "UPDATE crm_deploy_runs SET status='ok', finished_at=?, summary=? WHERE id=?",
            [deploy_now_sql(), 'ok', $run_id]);
        db_exec($db, "UPDATE crm_domains SET deploy_status='done' WHERE id=?", [$domain_id]);
        $code = trim((string)shell_exec('curl -sk -o /dev/null -w "%{http_code}" -m 20 https://'
            . escapeshellcmd($ctx['domain']) . '/en/'));
        echo "  ГОТОВО, сайт отвечает $code\n";
    } else {
        db_exec($db, "UPDATE crm_deploy_runs SET status='fail', finished_at=?, summary=? WHERE id=?",
            [deploy_now_sql(), 'failed', $run_id]);
    }
}

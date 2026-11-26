<?php
// /var/www/fapa.io/fapacrm/pages/site_ssl.php
declare(strict_types=1);

csrf_check();
$db = db_connect();

require_once __DIR__ . '/deploy/lib.php';

$domain_id = req_int('domain_id', 0);
if ($domain_id <= 0) { echo '<div class="alert alert-warning">Нужен domain_id</div>'; return; }

$d = db_one($db, "SELECT * FROM crm_domains WHERE id=?", [$domain_id]);
if (!$d) { echo '<div class="alert alert-danger">Домен не найден</div>'; return; }

$domain = (string)($d['domain'] ?? '');
$path   = (string)($d['path'] ?? '');
if ($domain === '') { echo '<div class="alert alert-danger">Пустой домен в БД</div>'; return; }
if ($path === '') $path = '/var/www/'.$domain;

function ssl_ucfg(string $k, $def = null) {
  $cfg = $GLOBALS['USER_CFG'] ?? [];
  if (!function_exists('ucfg')) return $def;
  return ucfg($cfg, $k, $def);
}

$ngAvail    = (string)ssl_ucfg('DEPLOY.nginx_available', '/etc/nginx/sites-available');
$ngEn       = (string)ssl_ucfg('DEPLOY.nginx_enabled', '/etc/nginx/sites-enabled');
$phpSock    = (string)ssl_ucfg('DEPLOY.php_sock', 'unix:/run/php/php7.4-fpm.sock');
$videosRoot = (string)ssl_ucfg('DEPLOY.videos_root', '/var/www/xxx-asiantube.ru/videos');
$certEmail  = (string)ssl_ucfg('DEPLOY.certbot_email', 'admin@'.$domain);

$confName = $domain . '.conf';
$confPath = rtrim($ngAvail,'/') . '/' . $confName;
$confLink = rtrim($ngEn,'/') . '/' . $confName;

function ssl_log(mysqli $db, int $domain_id, string $line): void {
  deploy_push_log($db, $domain_id, 0, $line);
}

function ssl_nginx_test(): array { return deploy_run_cmd_sudo('nginx -t'); }
function ssl_nginx_reload(): array { return deploy_run_cmd_sudo('systemctl reload nginx'); }

function ssl_certbot_nginx(string $domain, string $email): array {
  $d = escapeshellarg($domain);
  $w = escapeshellarg('www.'.$domain);
  $m = escapeshellarg($email);
  $cmd = "certbot --nginx -n --agree-tos -m $m -d $d -d $w --redirect";
  return deploy_run_cmd_sudo($cmd);
}

function ssl_check_https(string $domain): array {
  $d = escapeshellarg('https://'.$domain.'/');
  return deploy_run_cmd("curl -k -I -L --max-time 20 $d | head -n 40");
}

$ran = false;
$steps = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('run_ssl', false)) {
  $ran = true;

  $ctx = [
    'domain' => $domain,
    'path' => $path,
    'phpSock' => $phpSock,
    'videos_root' => $videosRoot,
  ];

  try {
    ssl_log($db, $domain_id, "SSL START for $domain");

    // 1) HTTP-only config (без ssl_certificate)
    $httpConf = deploy_nginx_render_http_only_config($ctx);

    $r = deploy_write_root_file($confPath, $httpConf);
    $steps[] = ['title'=>'Write nginx HTTP-only (sudo tee)', 'ok'=>$r['ok'], 'out'=>$r['out'], 'cmd'=>$r['cmd']];
    ssl_log($db, $domain_id, "write http-only rc={$r['rc']}");
    if (!$r['ok']) throw new RuntimeException("Failed to write HTTP-only nginx config:\n".$r['out']);

    $r = deploy_symlink_root_force($confPath, $confLink);
    $steps[] = ['title'=>'Enable site (sudo ln -sf)', 'ok'=>($r['rc']===0), 'out'=>$r['out'], 'cmd'=>$r['cmd']];
    ssl_log($db, $domain_id, "enable site rc={$r['rc']}");
    if ($r['rc'] !== 0) throw new RuntimeException("Failed to enable site:\n".$r['out']);

    $r = ssl_nginx_test();
    $steps[] = ['title'=>'nginx -t (HTTP-only)', 'ok'=>($r['rc']===0), 'out'=>$r['out'], 'cmd'=>$r['cmd']];
    ssl_log($db, $domain_id, "nginx -t http-only rc={$r['rc']}");
    if ($r['rc'] !== 0) throw new RuntimeException("nginx -t failed (HTTP-only):\n".$r['out']);

    $r = ssl_nginx_reload();
    $steps[] = ['title'=>'systemctl reload nginx (HTTP-only)', 'ok'=>($r['rc']===0), 'out'=>$r['out'], 'cmd'=>$r['cmd']];
    ssl_log($db, $domain_id, "nginx reload http-only rc={$r['rc']}");
    if ($r['rc'] !== 0) throw new RuntimeException("nginx reload failed (HTTP-only):\n".$r['out']);

    // 2) certbot
    $r = ssl_certbot_nginx($domain, $certEmail);
    $steps[] = ['title'=>'certbot --nginx (issue/ensure)', 'ok'=>($r['rc']===0), 'out'=>$r['out'], 'cmd'=>$r['cmd']];
    ssl_log($db, $domain_id, "certbot rc={$r['rc']}");
    if ($r['rc'] !== 0) {
      db_exec($db, "UPDATE crm_domains SET ssl_status='fail' WHERE id=?", [$domain_id]);
      throw new RuntimeException("certbot failed:\n".$r['out']);
    }

    // 3) финальный HTTPS конфиг (наш шаблон с /media/videos/)
    $httpsConf = deploy_nginx_render_config($ctx);

    $r2 = deploy_write_root_file($confPath, $httpsConf);
    $steps[] = ['title'=>'Write nginx HTTPS-final (sudo tee)', 'ok'=>$r2['ok'], 'out'=>$r2['out'], 'cmd'=>$r2['cmd']];
    ssl_log($db, $domain_id, "write https-final rc={$r2['rc']}");
    if (!$r2['ok']) throw new RuntimeException("Failed to write HTTPS-final nginx config:\n".$r2['out']);

    $r2 = ssl_nginx_test();
    $steps[] = ['title'=>'nginx -t (HTTPS-final)', 'ok'=>($r2['rc']===0), 'out'=>$r2['out'], 'cmd'=>$r2['cmd']];
    ssl_log($db, $domain_id, "nginx -t https-final rc={$r2['rc']}");
    if ($r2['rc'] !== 0) {
      db_exec($db, "UPDATE crm_domains SET ssl_status='fail' WHERE id=?", [$domain_id]);
      throw new RuntimeException("nginx -t failed (HTTPS-final):\n".$r2['out']);
    }

    $r2 = ssl_nginx_reload();
    $steps[] = ['title'=>'systemctl reload nginx (HTTPS-final)', 'ok'=>($r2['rc']===0), 'out'=>$r2['out'], 'cmd'=>$r2['cmd']];
    ssl_log($db, $domain_id, "nginx reload https-final rc={$r2['rc']}");
    if ($r2['rc'] !== 0) {
      db_exec($db, "UPDATE crm_domains SET ssl_status='fail' WHERE id=?", [$domain_id]);
      throw new RuntimeException("nginx reload failed (HTTPS-final):\n".$r2['out']);
    }

    // 4) curl https (главная проверка)
    $r2 = ssl_check_https($domain);
    $okHttps = ($r2['rc'] === 0) && (stripos((string)$r2['out'], 'HTTP/') !== false);
    $steps[] = ['title'=>'curl https check', 'ok'=>$okHttps, 'out'=>$r2['out'], 'cmd'=>$r2['cmd']];
    ssl_log($db, $domain_id, "curl https rc={$r2['rc']}");

    if (!$okHttps) {
      db_exec($db, "UPDATE crm_domains SET ssl_status='fail' WHERE id=?", [$domain_id]);
      throw new RuntimeException("HTTPS check failed:\n".$r2['out']);
    }

    db_exec($db, "UPDATE crm_domains SET ssl_status='ok' WHERE id=?", [$domain_id]);
    ssl_log($db, $domain_id, "SSL OK for $domain");
    flash_set('success', "SSL готов: $domain");
  } catch (Throwable $e) {
    ssl_log($db, $domain_id, "SSL FAIL: ".$e->getMessage());
    db_exec($db, "UPDATE crm_domains SET ssl_status='fail' WHERE id=?", [$domain_id]);
    flash_set('danger', "SSL ошибка: ".$e->getMessage());
  }

  $d = db_one($db, "SELECT * FROM crm_domains WHERE id=?", [$domain_id]);
}

$ssl_status = strtolower((string)($d['ssl_status'] ?? 'todo'));
$adminUrl = 'https://' . $domain . '/cmd/menu.php';
$log = (string)($d['deploy_last_log'] ?? '');

?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h3 class="m-0">🔐 SSL мастер</h3>
    <div class="text-muted small">
      Домен: <b><?=h($domain)?></b>
      | ssl_status: <span class="badge <?=($ssl_status==='ok'?'bg-success':'bg-warning text-dark')?>"><?=h($ssl_status)?></span>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="menu.php?page=domains">← Домены</a>
    <?php if ($ssl_status === 'ok'): ?>
      <a class="btn btn-outline-primary" href="<?=h($adminUrl)?>" target="_blank" rel="noopener">Открыть ADMIN</a>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <div class="row g-2">
      <div class="col-md-6"><div class="text-muted small">root path</div><div><code><?=h($path)?></code></div></div>
      <div class="col-md-6"><div class="text-muted small">nginx config</div><div><code><?=h($confPath)?></code></div></div>
      <div class="col-md-6"><div class="text-muted small">php sock</div><div><code><?=h($phpSock)?></code></div></div>
      <div class="col-md-6"><div class="text-muted small">videos_root (alias /media/videos/)</div><div><code><?=h($videosRoot)?></code></div></div>
      <div class="col-md-6"><div class="text-muted small">certbot email</div><div><code><?=h($certEmail)?></code></div></div>
    </div>

    <hr class="my-3">

    <form method="post" class="d-flex align-items-center gap-2">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="run_ssl" value="1">
      <button class="btn btn-primary" <?=($ssl_status==='ok'?'disabled':'')?>>
        <?=($ssl_status==='ok' ? 'SSL уже OK' : 'Запустить SSL (HTTP → certbot → HTTPS)')?>
      </button>
      <span class="text-muted small">без sudo test -f, проверка идёт через nginx -t + curl https</span>
    </form>
  </div>
</div>

<?php if ($ran): ?>
  <div class="card mb-3">
    <div class="card-header"><b>Результаты шагов</b></div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th style="width:260px;">Шаг</th><th style="width:80px;">OK</th><th>Output</th></tr></thead>
          <tbody>
            <?php foreach ($steps as $s): ?>
              <tr>
                <td><b><?=h((string)$s['title'])?></b></td>
                <td><span class="badge <?=(!empty($s['ok'])?'bg-success':'bg-danger')?>"><?=(!empty($s['ok'])?'OK':'FAIL')?></span></td>
                <td style="white-space:pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace; font-size: 12px;">
                  <?php if (!empty($s['cmd'])): ?><div class="text-muted">CMD: <?=h((string)$s['cmd'])?></div><?php endif; ?>
                  <?=h((string)($s['out'] ?? ''))?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <b>deploy_last_log</b>
    <span class="text-muted small">всё пишем сюда</span>
  </div>
  <div class="card-body">
    <pre style="white-space:pre-wrap; margin:0; font-size:12px;"><?=h($log ?: '—')?></pre>
  </div>
</div>

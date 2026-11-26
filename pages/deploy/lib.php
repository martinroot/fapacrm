<?php
// /var/www/fapa.io/fapacrm/pages/deploy/lib.php
declare(strict_types=1);

function deploy_now_sql(): string { return date('Y-m-d H:i:s'); }

function deploy_run_cmd(string $cmd): array {
  $descriptors = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ];
  $proc = proc_open($cmd, $descriptors, $pipes);
  if (!is_resource($proc)) return ['rc'=>127,'out'=>"proc_open failed\nCMD: $cmd", 'cmd'=>$cmd];
  $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
  $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
  $rc = proc_close($proc);
  $out = trim((string)$stdout . ((trim((string)$stderr) !== '') ? ("\n".$stderr) : ''));
  return ['rc'=>$rc,'out'=>$out,'cmd'=>$cmd];
}

function deploy_push_log(mysqli $db, int $domain_id, int $run_id, string $line): void {
  $row = db_one($db, "SELECT deploy_last_log FROM crm_domains WHERE id=?", [$domain_id]);
  $cur = (string)($row['deploy_last_log'] ?? '');
  $cur .= ($cur !== '' ? "\n" : '') . '['.date('H:i:s').'] '.$line;
  db_exec($db, "UPDATE crm_domains SET deploy_last_log=? WHERE id=?", [$cur, $domain_id]);
}

function deploy_ensure_step(mysqli $db, int $run_id, string $step_key, string $title): void {
  db_exec($db, "INSERT IGNORE INTO crm_deploy_steps(run_id, step_key, title, status)
                VALUES(?,?,?, 'pending')",
    [$run_id, $step_key, $title]);
}

function deploy_step_start(mysqli $db, int $run_id, string $step_key): void {
  db_exec($db, "UPDATE crm_deploy_steps
                SET status='running', started_at=?
                WHERE run_id=? AND step_key=?",
    [deploy_now_sql(), $run_id, $step_key]);
}

function deploy_step_finish(mysqli $db, int $run_id, string $step_key, string $status, int $rc, string $output): void {
  db_exec($db, "UPDATE crm_deploy_steps
                SET status=?, finished_at=?, rc=?, output=?
                WHERE run_id=? AND step_key=?",
    [$status, deploy_now_sql(), $rc, $output, $run_id, $step_key]);
}

function deploy_get_db_root_cfg(): array {
  $cfg = $GLOBALS['USER_CFG'] ?? [];
  $host = (string)ucfg($cfg, 'DB.root_host', 'localhost');
  $user = (string)ucfg($cfg, 'DB.root_user', 'root');
  $pass = (string)ucfg($cfg, 'DB.root_pass', '');
  return ['host'=>$host, 'user'=>$user, 'pass'=>$pass];
}

function deploy_write_site_config_user(string $siteCmdDir, array $dbCfg, string $dbName): void {
  $path = rtrim($siteCmdDir,'/').'/config.user.php';
  $cfg = [
    'BM_DEBUG' => false,
    'DB' => [
      'host' => (string)($dbCfg['host'] ?? 'localhost'),
      'user' => (string)($dbCfg['user'] ?? 'root'),
      'pass' => (string)($dbCfg['pass'] ?? ''),
      'name' => (string)$dbName,
    ],
  ];
  $export = "<?php\nreturn " . var_export($cfg, true) . ";\n";
  if (file_put_contents($path, $export) === false) {
    throw new RuntimeException("Failed to write $path");
  }
}

function deploy_patch_db_name_in_php_file(string $file, string $dbName): bool {
  $src = @file_get_contents($file);
  if ($src === false) return false;
  $orig = $src;

  $src2 = preg_replace(
    "/define\\(\\s*['\\\"]BM_DB_NAME['\\\"]\\s*,\\s*['\\\"][^'\\\"]*['\\\"]\\s*\\)\\s*;/",
    "define('BM_DB_NAME', ".var_export($dbName, true).");",
    $src
  );
  if (is_string($src2)) $src = $src2;

  $src2 = preg_replace(
    "/const\\s+BM_DB_NAME\\s*=\\s*['\\\"][^'\\\"]*['\\\"]\\s*;/",
    "const BM_DB_NAME = ".var_export($dbName, true).";",
    $src
  );
  if (is_string($src2)) $src = $src2;

  $src2 = preg_replace(
    "/\\$(DB_NAME|dbname)\\s*=\\s*['\\\"][^'\\\"]*['\\\"]\\s*;/",
    "\$\\1 = ".var_export($dbName, true).";",
    $src
  );
  if (is_string($src2)) $src = $src2;

  $src2 = preg_replace(
    "/(['\\\"]name['\\\"]\\s*=>\\s*)['\\\"][^'\\\"]*['\\\"]/m",
    "\$1".var_export($dbName, true),
    $src
  );
  if (is_string($src2)) $src = $src2;

  if ($src === $orig) return false;
  return (@file_put_contents($file, $src) !== false);
}

function deploy_split_sql_statements(string $sql): array {
  $sql = preg_replace('/^\s*--.*$/m', '', $sql);
  $parts = preg_split('/;\s*[\r\n]+/', $sql);
  if (!is_array($parts)) $parts = [];
  $out = [];
  foreach ($parts as $p) {
    $p = trim((string)$p);
    if ($p !== '') $out[] = $p;
  }
  return $out;
}

function deploy_apply_sql_dir(mysqli $dbConn, string $dir): array {
  if (!is_dir($dir)) return ['ok'=>true, 'applied'=>0, 'msg'=>"No dir: $dir (skip)"];
  $files = glob(rtrim($dir,'/').'/*.sql');
  if (!is_array($files)) $files = [];
  sort($files);
  $applied = 0;

  foreach ($files as $file) {
    $sql = (string)file_get_contents((string)$file);
    $stmts = deploy_split_sql_statements($sql);

    $dbConn->begin_transaction();
    try {
      foreach ($stmts as $stmt) {
        $dbConn->query($stmt);
        if ($dbConn->error) {
          throw new RuntimeException("SQL error: ".$dbConn->error."\nFILE: ".basename((string)$file)."\n$stmt");
        }
      }
      $dbConn->commit();
      $applied++;
    } catch (Throwable $e) {
      $dbConn->rollback();
      return ['ok'=>false, 'applied'=>$applied, 'msg'=>$e->getMessage()];
    }
  }
  return ['ok'=>true, 'applied'=>$applied, 'msg'=>'ok'];
}

function deploy_patch_config_php(string $file, string $dbName, string $domain): bool
{
  $src = @file_get_contents($file);
  if ($src === false) return false;

  $orig = $src;
  $changed = false;

  $dbPattern = "~define\s*\(\s*['\"]HP_DB_NAME['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;~i";
  if (preg_match($dbPattern, $src)) {
    $src2 = preg_replace(
      $dbPattern,
      "define('HP_DB_NAME', '".addslashes($dbName)."');",
      $src,
      1
    );
    if (is_string($src2) && $src2 !== $src) { $src = $src2; $changed = true; }
  }

  $mediaPattern = "~define\s*\(\s*['\"]HP_MEDIA_BASE['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;~i";
  if (preg_match($mediaPattern, $src)) {
    $src2 = preg_replace(
      $mediaPattern,
      "define('HP_MEDIA_BASE', '/media/videos/');",
      $src,
      1
    );
    if (is_string($src2) && $src2 !== $src) { $src = $src2; $changed = true; }
  }

  if (!$changed || $src === $orig) return false;
  return (@file_put_contents($file, $src) !== false);
}

/**
 * Финальный HTTPS nginx конфиг (после того как сертификат уже существует)
 */
function deploy_nginx_render_config(array $ctx): string
{
  $domain = (string)$ctx['domain'];
  $root   = (string)$ctx['path'];
  $phpSock = (string)($ctx['phpSock'] ?? 'unix:/run/php/php8.3-fpm.sock');
  $videosRoot = rtrim((string)($ctx['videos_root'] ?? '/var/www/xxx-asiantube.ru/videos'), '/').'/' ;
  $langs = 'en|fr|de|es|it|pt|tr|ar|hi|id|ja|ko|zh|ru';

  return <<<NGINX
# AUTO-GENERATED by fapacrm deploy
# domain: {$domain}
# root: {$root}
# shared videos: {$videosRoot}

server {
    listen 80;
    server_name {$domain} www.{$domain};
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl http2;
    server_name {$domain} www.{$domain};

    ssl_certificate     /etc/letsencrypt/live/{$domain}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{$domain}/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    root  {$root};
    index index.php index.html;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy no-referrer-when-downgrade always;

    set \$best_lang en;
    if (\$http_accept_language ~* "(^|,)\\s*ru\\b") { set \$best_lang ru; }
    if (\$http_accept_language ~* "(^|,)\\s*zh\\b") { set \$best_lang zh; }
    if (\$http_accept_language ~* "(^|,)\\s*ko\\b") { set \$best_lang ko; }
    if (\$http_accept_language ~* "(^|,)\\s*ja\\b") { set \$best_lang ja; }
    if (\$http_accept_language ~* "(^|,)\\s*id\\b") { set \$best_lang id; }
    if (\$http_accept_language ~* "(^|,)\\s*hi\\b") { set \$best_lang hi; }
    if (\$http_accept_language ~* "(^|,)\\s*ar\\b") { set \$best_lang ar; }
    if (\$http_accept_language ~* "(^|,)\\s*tr\\b") { set \$best_lang tr; }
    if (\$http_accept_language ~* "(^|,)\\s*pt\\b") { set \$best_lang pt; }
    if (\$http_accept_language ~* "(^|,)\\s*it\\b") { set \$best_lang it; }
    if (\$http_accept_language ~* "(^|,)\\s*es\\b") { set \$best_lang es; }
    if (\$http_accept_language ~* "(^|,)\\s*de\\b") { set \$best_lang de; }
    if (\$http_accept_language ~* "(^|,)\\s*fr\\b") { set \$best_lang fr; }
    if (\$http_accept_language ~* "(^|,)\\s*en\\b") { set \$best_lang en; }

    location = / {
        return 302 /\$best_lang/\$is_args\$args;
    }

    location ^~ /media/videos/ {
        alias {$videosRoot};

        access_log off;
        expires 30d;
        add_header Cache-Control "public, max-age=2592000" always;

        autoindex off;
        try_files \$uri =404;
    }

    location ~* \\.(?:css|js|jpg|jpeg|png|gif|webp|svg|ico|woff2?|ttf|eot)\$ {
        expires 7d;
        access_log off;
        try_files \$uri =404;
    }

    location ^~ /images/ { expires 7d; access_log off; try_files \$uri =404; }
    location ^~ /css/    { expires 7d; access_log off; try_files \$uri =404; }
    location ^~ /js/     { expires 7d; access_log off; try_files \$uri =404; }
    location ^~ /assets/ { expires 7d; access_log off; try_files \$uri =404; }

    location ~ ^/({$langs})/(index|cat|video|popular)\\.php\$ {
        rewrite ^/({$langs})/(index|cat|video|popular)\\.php\$ /\$2.php?lang=\$1&\$args last;
    }

    location / {
        try_files \$uri \$uri/ @rewrite;
    }

    location @rewrite {
        rewrite ^/({$langs})/([^/]+)/([^/]+)-([0-9]+)/?\$  /video.php?lang=\$1&cat_slug=\$2&slug=\$3&video_id=\$4&\$args last;
        rewrite ^/({$langs})/popular/?\$  /popular.php?lang=\$1&\$args last;
        rewrite ^/({$langs})/([^/]+)/?\$  /cat.php?lang=\$1&slug=\$2&\$args last;
        rewrite ^/({$langs})/?\$  /index.php?lang=\$1&\$args last;
        rewrite ^ /index.php?\$args last;
    }

    location ~ \\.php\$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_pass {$phpSock};
        fastcgi_read_timeout 300;
    }

    error_page 404 /404.html;
    error_page 500 502 503 504 /50x.html;

    location = /404.html { internal; }
    location = /50x.html { internal; }
}
NGINX;
}

/**
 * Временный HTTP-only конфиг (до выпуска сертификата).
 * Никаких ssl_certificate, чтобы nginx -t проходил.
 */
function deploy_nginx_render_http_only_config(array $ctx): string
{
  $domain = (string)$ctx['domain'];
  $root   = (string)$ctx['path'];
  $phpSock = (string)($ctx['phpSock'] ?? 'unix:/run/php/php8.3-fpm.sock');
  $videosRoot = rtrim((string)($ctx['videos_root'] ?? '/var/www/xxx-asiantube.ru/videos'), '/').'/' ;
  $langs = 'en|fr|de|es|it|pt|tr|ar|hi|id|ja|ko|zh|ru';

  return <<<NGINX
# AUTO-GENERATED by fapacrm SSL (HTTP-only stage)
# domain: {$domain}
# root: {$root}
# shared videos: {$videosRoot}

server {
    listen 80;
    server_name {$domain} www.{$domain};

    root  {$root};
    index index.php index.html;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy no-referrer-when-downgrade always;

    set \$best_lang en;
    if (\$http_accept_language ~* "(^|,)\\s*ru\\b") { set \$best_lang ru; }
    if (\$http_accept_language ~* "(^|,)\\s*zh\\b") { set \$best_lang zh; }
    if (\$http_accept_language ~* "(^|,)\\s*ko\\b") { set \$best_lang ko; }
    if (\$http_accept_language ~* "(^|,)\\s*ja\\b") { set \$best_lang ja; }
    if (\$http_accept_language ~* "(^|,)\\s*id\\b") { set \$best_lang id; }
    if (\$http_accept_language ~* "(^|,)\\s*hi\\b") { set \$best_lang hi; }
    if (\$http_accept_language ~* "(^|,)\\s*ar\\b") { set \$best_lang ar; }
    if (\$http_accept_language ~* "(^|,)\\s*tr\\b") { set \$best_lang tr; }
    if (\$http_accept_language ~* "(^|,)\\s*pt\\b") { set \$best_lang pt; }
    if (\$http_accept_language ~* "(^|,)\\s*it\\b") { set \$best_lang it; }
    if (\$http_accept_language ~* "(^|,)\\s*es\\b") { set \$best_lang es; }
    if (\$http_accept_language ~* "(^|,)\\s*de\\b") { set \$best_lang de; }
    if (\$http_accept_language ~* "(^|,)\\s*fr\\b") { set \$best_lang fr; }
    if (\$http_accept_language ~* "(^|,)\\s*en\\b") { set \$best_lang en; }

    location = / {
        return 302 /\$best_lang/\$is_args\$args;
    }

    location ^~ /media/videos/ {
        alias {$videosRoot};

        access_log off;
        expires 30d;
        add_header Cache-Control "public, max-age=2592000" always;

        autoindex off;
        try_files \$uri =404;
    }

    location ~* \\.(?:css|js|jpg|jpeg|png|gif|webp|svg|ico|woff2?|ttf|eot)\$ {
        expires 7d;
        access_log off;
        try_files \$uri =404;
    }

    location ^~ /images/ { expires 7d; access_log off; try_files \$uri =404; }
    location ^~ /css/    { expires 7d; access_log off; try_files \$uri =404; }
    location ^~ /js/     { expires 7d; access_log off; try_files \$uri =404; }
    location ^~ /assets/ { expires 7d; access_log off; try_files \$uri =404; }

    location ~ ^/({$langs})/(index|cat|video|popular)\\.php\$ {
        rewrite ^/({$langs})/(index|cat|video|popular)\\.php\$ /\$2.php?lang=\$1&\$args last;
    }

    location / {
        try_files \$uri \$uri/ @rewrite;
    }

    location @rewrite {
        rewrite ^/({$langs})/([^/]+)/([^/]+)-([0-9]+)/?\$  /video.php?lang=\$1&cat_slug=\$2&slug=\$3&video_id=\$4&\$args last;
        rewrite ^/({$langs})/popular/?\$  /popular.php?lang=\$1&\$args last;
        rewrite ^/({$langs})/([^/]+)/?\$  /cat.php?lang=\$1&slug=\$2&\$args last;
        rewrite ^/({$langs})/?\$  /index.php?lang=\$1&\$args last;
        rewrite ^ /index.php?\$args last;
    }

    location ~ \\.php\$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_pass {$phpSock};
        fastcgi_read_timeout 300;
    }
}
NGINX;
}

/* ========================= SUDO HELPERS ========================= */

function deploy_run_cmd_sudo(string $cmd): array {
  return deploy_run_cmd('sudo -n ' . $cmd);
}

function deploy_write_root_file(string $path, string $content): array {
  $tmp = tempnam(sys_get_temp_dir(), 'fapa_ng_');
  if ($tmp === false) return ['ok'=>false,'rc'=>1,'out'=>'tempnam failed','cmd'=>''];
  file_put_contents($tmp, $content);

  $p = escapeshellarg($path);
  $cmd = "tee $p > /dev/null";
  $res = deploy_run_cmd_sudo($cmd . " < " . escapeshellarg($tmp));
  @unlink($tmp);

  $ok = ((int)($res['rc'] ?? 1) === 0);
  return ['ok'=>$ok,'rc'=>(int)($res['rc'] ?? 1),'out'=>(string)($res['out'] ?? ''),'cmd'=>(string)($res['cmd'] ?? '')];
}

function deploy_symlink_root_force(string $target, string $link): array {
  $t = escapeshellarg($target);
  $l = escapeshellarg($link);
  return deploy_run_cmd_sudo("ln -sf $t $l");
}

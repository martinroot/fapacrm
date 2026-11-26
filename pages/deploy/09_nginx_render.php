<?php
// /var/www/fapa.io/fapacrm/pages/deploy/09_nginx_render.php
declare(strict_types=1);

/**
 * Требования к окружению (у тебя уже так сделано):
 * - этот файл вызывается через require из site_deploy.php
 * - в scope есть: $db (mysqli), $run_id, $domain_id, $ctx (array)
 * - функции из deploy/lib.php доступны
 */

$step_key = 'nginx_render';
$title = 'Nginx: рендер конфига';

deploy_ensure_step($db, (int)$run_id, $step_key, $title);
deploy_step_start($db, (int)$run_id, $step_key);

try {
  $domain = (string)$ctx['domain'];
  $confName = $domain . '.conf';
  $tmpFile = "/tmp/nginx_{$domain}_run{$run_id}.conf";

  $ctx['nginxTmpFile'] = $tmpFile;
  $ctx['nginxConfName'] = $confName;

  $conf = deploy_nginx_render_config($ctx);
  if (file_put_contents($tmpFile, $conf) === false) {
    throw new RuntimeException("Failed to write tmp nginx config: {$tmpFile}");
  }

  // небольшая диагностика (не обяз.)
  $cmd = "ls -lah " . escapeshellarg($tmpFile) . " && head -n 5 " . escapeshellarg($tmpFile);

  $r = deploy_run_cmd($cmd);
  $out = "Rendered: {$tmpFile}\n" . $r['out'];

  deploy_push_log($db, (int)$domain_id, (int)$run_id, "nginx_render OK => {$tmpFile}");
  deploy_step_finish($db, (int)$run_id, $step_key, 'ok', 0, $out);

  return ['rc'=>0,'out'=>$out,'cmd'=>$cmd];
} catch (Throwable $e) {
  $msg = "nginx_render FAIL: ".$e->getMessage();
  deploy_push_log($db, (int)$domain_id, (int)$run_id, $msg);
  deploy_step_finish($db, (int)$run_id, $step_key, 'fail', 1, $msg);
  return ['rc'=>1,'out'=>$msg,'cmd'=>''];
}

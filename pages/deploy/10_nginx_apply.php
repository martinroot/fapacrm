<?php
// /var/www/fapa.io/fapacrm/pages/deploy/10_nginx_apply.php
declare(strict_types=1);

/**
 * Делает apply в /etc/nginx/sites-enabled/{domain}.conf через sudo.
 *
 * РЕКОМЕНДУЕМЫЙ ВАРИАНТ:
 *   /usr/local/bin/fapa-nginx-apply.sh  (root)
 *   sudoers: www-data ALL=(root) NOPASSWD: /usr/local/bin/fapa-nginx-apply.sh
 *
 * Скрипт принимает:
 *   $1 = /tmp/file.conf
 *   $2 = tronosex.ru.conf
 *
 * И делает:
 *   install -m 644 "$1" "/etc/nginx/sites-enabled/$2"
 *   nginx -t
 *   systemctl reload nginx
 */

$step_key = 'nginx_apply';
$title = 'Nginx: применить конфиг + nginx -t + reload';

deploy_ensure_step($db, (int)$run_id, $step_key, $title);
deploy_step_start($db, (int)$run_id, $step_key);

try {
  $domain = (string)$ctx['domain'];
  $tmpFile = (string)($ctx['nginxTmpFile'] ?? ("/tmp/nginx_{$domain}_run{$run_id}.conf"));
  $confName = (string)($ctx['nginxConfName'] ?? ($domain . '.conf'));

  if (!is_file($tmpFile)) {
    throw new RuntimeException("tmp nginx config not found: {$tmpFile} (run nginx_render first)");
  }

  // 1) prefer root wrapper script (best)
  $wrapper = '/usr/local/bin/fapa-nginx-apply.sh';
  if (is_file($wrapper) && is_executable($wrapper)) {
    $cmd = "sudo " . escapeshellarg($wrapper) . " " . escapeshellarg($tmpFile) . " " . escapeshellarg($confName);
    $r = deploy_run_cmd($cmd);

    if ((int)$r['rc'] !== 0) {
      throw new RuntimeException("apply failed rc={$r['rc']}\n".$r['out']);
    }

    $out = "Applied via wrapper: {$confName}\n".$r['out'];
    deploy_push_log($db, (int)$domain_id, (int)$run_id, "nginx_apply OK (wrapper) {$confName}");
    deploy_step_finish($db, (int)$run_id, $step_key, 'ok', 0, $out);
    return ['rc'=>0,'out'=>$out,'cmd'=>$cmd];
  }

  // 2) fallback: direct install + nginx -t + reload (нужно sudoers на каждую команду)
  $dst = "/etc/nginx/sites-enabled/" . $confName;
  $cmd = "sudo /usr/bin/install -m 644 " . escapeshellarg($tmpFile) . " " . escapeshellarg($dst)
      . " && sudo /usr/sbin/nginx -t"
      . " && sudo /bin/systemctl reload nginx";

  $r = deploy_run_cmd($cmd);
  if ((int)$r['rc'] !== 0) {
    throw new RuntimeException("apply failed rc={$r['rc']}\n".$r['out']);
  }

  $out = "Applied: {$dst}\n".$r['out'];
  deploy_push_log($db, (int)$domain_id, (int)$run_id, "nginx_apply OK {$confName}");
  deploy_step_finish($db, (int)$run_id, $step_key, 'ok', 0, $out);

  return ['rc'=>0,'out'=>$out,'cmd'=>$cmd];
} catch (Throwable $e) {
  $msg = "nginx_apply FAIL: ".$e->getMessage();
  deploy_push_log($db, (int)$domain_id, (int)$run_id, $msg);
  deploy_step_finish($db, (int)$run_id, $step_key, 'fail', 1, $msg);
  return ['rc'=>1,'out'=>$msg,'cmd'=>''];
}

<?php
declare(strict_types=1);

/**
 * STEP 07: config
 * 1) write /cmd/config.user.php (admin DB)
 * 2) patch ONLY /config.php (site HP_* constants: DB + domain for media)
 * NOTE: DO NOT patch /cmd/config.php (it is shared template loader)
 */

require_once __DIR__ . '/lib.php';

$domain = (string)$ctx['domain'];
$path   = (string)$ctx['path'];
$dbName = (string)$ctx['dbName'];

$step_key = 'config';
$title = 'Обновление config.php + cmd/config.user.php';

deploy_ensure_step($db, $run_id, $step_key, $title);
deploy_step_start($db, $run_id, $step_key);

deploy_push_log($db, $domain_id, $run_id, "STEP config: write cmd/config.user.php + patch /config.php (domain=$domain db=$dbName)");

$siteRoot   = rtrim($path, '/');
$siteCmdDir = $siteRoot . '/cmd';

if (!is_dir($siteCmdDir)) {
  $msg = "No cmd dir on new site: $siteCmdDir";
  deploy_step_finish($db, $run_id, $step_key, 'fail', 1, $msg);
  return ['rc'=>1,'out'=>$msg,'cmd'=>'(php)'];
}

$dbCfg = [
  'host' => (string)BM_DB_HOST,
  'user' => (string)BM_DB_USER,
  'pass' => (string)BM_DB_PASS,
];

try {
  // 1) ADMIN config.user.php (DB for /cmd)
  deploy_write_site_config_user($siteCmdDir, $dbCfg, $dbName);

  // 2) SITE config.php (HP_* for public site)
  $siteConfig = $siteRoot . '/config.php';
  if (!is_file($siteConfig)) {
    throw new RuntimeException("Нет файла сайта: $siteConfig");
  }

  $ok = deploy_patch_config_php($siteConfig, $dbName, $domain);
  if (!$ok) {
    throw new RuntimeException("Не смог распознать HP_* шаблон в файле: $siteConfig");
  }

  $out = "ok: cmd/config.user.php written; patched: /config.php";
  deploy_step_finish($db, $run_id, $step_key, 'ok', 0, $out);
  return ['rc'=>0,'out'=>$out,'cmd'=>'(php)'];

} catch (Throwable $e) {
  deploy_step_finish($db, $run_id, $step_key, 'fail', 1, $e->getMessage());
  return ['rc'=>1,'out'=>$e->getMessage(),'cmd'=>'(php)'];
}

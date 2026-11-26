<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dbName = (string)$ctx['dbName'];

$step_key = 'db_create';
$title = "Создание БД $dbName";
deploy_ensure_step($db, $run_id, $step_key, $title);

$cmd = "mysql -h ".escapeshellarg((string)BM_DB_HOST)." -u ".escapeshellarg((string)BM_DB_USER)
  ." -p".escapeshellarg((string)BM_DB_PASS)
  ." -e ".escapeshellarg("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

deploy_push_log($db, $domain_id, $run_id, "STEP db_create: $cmd");
deploy_step_start($db, $run_id, $step_key);

$r = deploy_run_cmd($cmd);
deploy_step_finish($db, $run_id, $step_key, ($r['rc']===0?'ok':'fail'), (int)$r['rc'], (string)$r['out']);

return $r;

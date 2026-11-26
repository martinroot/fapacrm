<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$templateDb = (string)$ctx['templateDb'];
$dumpFile = (string)$ctx['dumpFile'];

$step_key = 'db_dump';
$title = "Дамп эталона БД $templateDb";
deploy_ensure_step($db, $run_id, $step_key, $title);

$cmd = "mysqldump -h ".escapeshellarg((string)BM_DB_HOST)." -u ".escapeshellarg((string)BM_DB_USER)
  ." -p".escapeshellarg((string)BM_DB_PASS)
  ." ".escapeshellarg($templateDb)." > ".escapeshellarg($dumpFile);

deploy_push_log($db, $domain_id, $run_id, "STEP db_dump: $cmd");
deploy_step_start($db, $run_id, $step_key);

$r = deploy_run_cmd($cmd);
deploy_step_finish($db, $run_id, $step_key, ($r['rc']===0?'ok':'fail'), (int)$r['rc'], (string)$r['out']);

return $r;

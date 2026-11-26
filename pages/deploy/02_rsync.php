<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$templatePath = (string)$ctx['templatePath'];
$path = (string)$ctx['path'];

$step_key = 'copy';
$title = 'Копирование эталона (rsync)';
deploy_ensure_step($db, $run_id, $step_key, $title);

$cmd = "rsync -a --delete --info=progress2 " .
  "--exclude='videos/' " .
  escapeshellarg(rtrim($templatePath,'/')."/") . " " .
  escapeshellarg(rtrim($path,'/')."/");

deploy_push_log($db, $domain_id, $run_id, "STEP rsync: $cmd");
deploy_step_start($db, $run_id, $step_key);

$r = deploy_run_cmd($cmd);
deploy_step_finish($db, $run_id, $step_key, ($r['rc']===0?'ok':'fail'), (int)$r['rc'], (string)$r['out']);

return $r;

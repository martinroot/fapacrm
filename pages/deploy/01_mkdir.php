<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$path = (string)$ctx['path'];
$step_key = 'mkdir';
$title = 'Создание папки';
deploy_ensure_step($db, $run_id, $step_key, $title);

deploy_push_log($db, $domain_id, $run_id, "STEP mkdir: mkdir -p $path");
deploy_step_start($db, $run_id, $step_key);

$r = deploy_run_cmd("mkdir -p " . escapeshellarg($path));
deploy_step_finish($db, $run_id, $step_key, ($r['rc']===0?'ok':'fail'), (int)$r['rc'], (string)$r['out']);

return $r;

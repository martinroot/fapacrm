<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$path = (string)$ctx['path'];
$step_key = 'chown';
$title = 'Права на файлы (chown)';
deploy_ensure_step($db, $run_id, $step_key, $title);

$cmd = "chown -R www-data:www-data " . escapeshellarg($path);

deploy_push_log($db, $domain_id, $run_id, "STEP chown: $cmd");
deploy_step_start($db, $run_id, $step_key);

$r = deploy_run_cmd($cmd);
deploy_step_finish($db, $run_id, $step_key, ($r['rc']===0?'ok':'fail'), (int)$r['rc'], (string)$r['out']);

return $r;

<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dbName = (string)$ctx['dbName'];
$siteMigrationsDir = (string)$ctx['siteMigrationsDir'];

$step_key = 'migrate';
$title = 'Миграции БД нового сайта';
deploy_ensure_step($db, $run_id, $step_key, $title);

deploy_push_log($db, $domain_id, $run_id, "STEP migrate: apply sql dir $siteMigrationsDir to DB $dbName");
deploy_step_start($db, $run_id, $step_key);

try {
  $siteDbConn = db_connect($dbName);
  $mr = deploy_apply_sql_dir($siteDbConn, $siteMigrationsDir);
  $siteDbConn->close();

  if (!$mr['ok']) {
    deploy_step_finish($db, $run_id, $step_key, 'fail', 1, (string)$mr['msg']);
    return ['rc'=>1,'out'=>$mr['msg'],'cmd'=>'(php/sql)'];
  }

  deploy_step_finish($db, $run_id, $step_key, 'ok', 0, "Applied: ".$mr['applied']);
  return ['rc'=>0,'out'=>"Applied: ".$mr['applied'],'cmd'=>'(php/sql)'];

} catch (Throwable $e) {
  deploy_step_finish($db, $run_id, $step_key, 'fail', 1, $e->getMessage());
  return ['rc'=>1,'out'=>$e->getMessage(),'cmd'=>'(php/sql)'];
}

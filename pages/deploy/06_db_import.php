<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dbName   = (string)$ctx['dbName'];
$dumpFile = (string)$ctx['dumpFile'];

$step_key = 'db_import';
$title = "Импорт в БД $dbName";
deploy_ensure_step($db, $run_id, $step_key, $title);

$cmd = "mysql -h ".escapeshellarg((string)BM_DB_HOST)." -u ".escapeshellarg((string)BM_DB_USER)
  ." -p".escapeshellarg((string)BM_DB_PASS)
  ." ".escapeshellarg($dbName)." < ".escapeshellarg($dumpFile);

deploy_push_log($db, $domain_id, $run_id, "STEP db_import: $cmd");
deploy_step_start($db, $run_id, $step_key);

$r = deploy_run_cmd($cmd);

/**
 * После успешного импорта — чистим статистику/логи от эталона
 * (если таблицы существуют в новой БД)
 */
if ((int)($r['rc'] ?? 1) === 0) {
  $tablesToWipe = [
    'clicks',
    'user_video_stats',
    'user_events',
    'user_page_stats',
  ];

  $wipeOut = deploy_wipe_stats_tables($dbName, $tablesToWipe);
  $r['out'] = (string)($r['out'] ?? '') . "\n\n" . $wipeOut;
}

deploy_step_finish(
  $db,
  $run_id,
  $step_key,
  ((int)($r['rc'] ?? 1) === 0 ? 'ok' : 'fail'),
  (int)($r['rc'] ?? 1),
  (string)($r['out'] ?? '')
);

return $r;

/**
 * TRUNCATE таблиц статистики в целевой БД.
 * - FK временно выключаем, чтобы не упираться в связи
 * - если таблицы нет — пропускаем
 */
function deploy_wipe_stats_tables(string $dbName, array $tables): string
{
  $out = [];
  $out[] = "=== WIPE STATS TABLES ===";
  $out[] = "DB: {$dbName}";

  $mysqli = @new mysqli((string)BM_DB_HOST, (string)BM_DB_USER, (string)BM_DB_PASS, $dbName);
  if ($mysqli->connect_errno) {
    $out[] = "ERR: connect failed: " . $mysqli->connect_error;
    return implode("\n", $out);
  }
  $mysqli->set_charset('utf8mb4');

  // Важно: TRUNCATE часто требует выключить FK
  $mysqli->query("SET FOREIGN_KEY_CHECKS=0");

  foreach ($tables as $t) {
    $t = trim((string)$t);
    if ($t === '') continue;

    // проверка существования таблицы
    $tt = $mysqli->real_escape_string($t);
    $rr = $mysqli->query("SHOW TABLES LIKE '{$tt}'");
    $exists = ($rr && $rr->num_rows > 0);
    if ($rr) $rr->free();

    if (!$exists) {
      $out[] = "SKIP: {$t} (no such table)";
      continue;
    }

    $sql = "TRUNCATE TABLE `".$t."`";
    if ($mysqli->query($sql)) {
      $out[] = "OK: TRUNCATE {$t}";
    } else {
      $out[] = "FAIL: {$t} :: " . $mysqli->error;
    }
  }

  $mysqli->query("SET FOREIGN_KEY_CHECKS=1");
  $mysqli->close();

  $out[] = "=== /WIPE ===";
  return implode("\n", $out);
}

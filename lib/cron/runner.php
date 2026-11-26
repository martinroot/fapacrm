<?php
declare(strict_types=1);
/**
 * lib/cron/runner.php
 * Cron runner:
 *   php /var/www/fapa.io/fapacrm/lib/cron/runner.php
 * Требует helpers CRM:
 *   db_connect(), db_all(), db_exec()
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

$LOG_FILE = getenv('CRON_RUNNER_LOG') ?: (__DIR__ . '/../../var/log/cron_runner.log');

function log_line(string $msg): void {
  global $LOG_FILE;
  $ts = date('Y-m-d H:i:s');
  $line = "[{$ts}] {$msg}\n";
  @fwrite(STDOUT, $line);
  $dir = dirname($LOG_FILE);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
}

register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
    log_line("FATAL: {$e['message']} in {$e['file']}:{$e['line']}");
  }
});

set_error_handler(function ($severity, $message, $file, $line) {
  log_line("PHP_ERROR({$severity}): {$message} in {$file}:{$line}");
  return false;
});

set_exception_handler(function (Throwable $e) {
  log_line("UNCAUGHT_EXCEPTION: " . $e->getMessage());
  log_line($e->getTraceAsString());
  exit(1);
});

log_line("runner start, php=" . PHP_VERSION . ", pid=" . getmypid() . ", cwd=" . getcwd());

if (!function_exists('db_connect')) {
  $try = realpath(__DIR__ . '/../../config.php');
  if ($try && is_file($try)) { require_once $try; log_line("config loaded: {$try}"); }
  else log_line("config NOT found at ../../config.php (resolved: " . (string)$try . ")");
}

if (!function_exists('db_connect') || !function_exists('db_all') || !function_exists('db_exec')) {
  log_line("helpers missing: db_connect/db_all/db_exec not defined. STOP.");
  exit(2);
}

/** connectors (sync with pages/cron_jobs.php) */
require_once __DIR__ . '/../connectors/ConnectorInterface.php';
require_once __DIR__ . '/../connectors/KadamCost.php';
require_once __DIR__ . '/../connectors/ClickaduRevenue.php';
require_once __DIR__ . '/../connectors/MyBidRevenue.php';
require_once __DIR__ . '/../connectors/MyBidRevenueShare.php';
require_once __DIR__ . '/../connectors/TacoLocoRevenue.php';
require_once __DIR__ . '/../connectors/KadamPublisherRevenue.php';
require_once __DIR__ . '/../connectors/YandexMetrikaToday.php';
require_once __DIR__ . '/../connectors/MyBidRevenueTag.php';
require_once __DIR__ . '/../connectors/OwnMetrikaToday.php';
require_once __DIR__ . '/../connectors/VibeMetricsToday.php';

$db = db_connect();
log_line("db connected");

function clip_str(string $s, int $max = 20000): string { return (strlen($s) <= $max) ? $s : substr($s, 0, $max) . "\n... [clipped] ...\n"; }
function pretty_json($x): string { return json_encode($x, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function now_sql(): string { return date('Y-m-d H:i:s'); }

function calc_next_run(string $schedule): string {
  $t = time();
  if ($schedule === '5min')   return date('Y-m-d H:i:s', $t + 300);
  if ($schedule === 'hourly') return date('Y-m-d H:i:s', $t + 3600);
  if ($schedule === 'daily')  return date('Y-m-d H:i:s', $t + 86400);
  if (strpos($schedule, 'custom:') === 0) {
    $n = (int)substr($schedule, 7);
    if ($n > 0) return date('Y-m-d H:i:s', $t + $n);
  }
  return date('Y-m-d H:i:s', $t + 3600);
}

$CONNECTORS = [
  KadamCost::code()             => new KadamCost(),
  ClickaduRevenue::code()       => new ClickaduRevenue(),
  MyBidRevenue::code()          => new MyBidRevenue(),
  MyBidRevenueShare::code()     => new MyBidRevenueShare(),
  MyBidRevenueTag::code()     => new MyBidRevenueTag(),
  TacoLocoRevenue::code()       => new TacoLocoRevenue(),
  KadamPublisherRevenue::code() => new KadamPublisherRevenue(),
  YandexMetrikaToday::code()    => new YandexMetrikaToday(),
  OwnMetrikaToday::code()       => new OwnMetrikaToday(),
  VibeMetricsToday::code()      => new VibeMetricsToday(),
];

/** history table for intraday dynamics (safe create) */
db_exec($db, "CREATE TABLE IF NOT EXISTS crm_finance_ticks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL,
  d DATE NOT NULL,
  domain_id INT NOT NULL,
  kind VARCHAR(32) NOT NULL,
  connector VARCHAR(64) NOT NULL,
  external_id VARCHAR(191) NOT NULL DEFAULT '',
  amount DOUBLE NOT NULL DEFAULT 0,
  meta_json MEDIUMTEXT NULL,
  run_id INT NULL,
  PRIMARY KEY (id),
  KEY idx_d_domain_kind (d,domain_id,kind),
  KEY idx_ts_domain_kind (ts,domain_id,kind),
  KEY idx_domain_connector (domain_id,connector),
  KEY idx_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$now = now_sql();

/** due jobs */
$jobs = db_all($db, "
  SELECT *
  FROM crm_cron_jobs
  WHERE is_enabled=1
    AND (next_run_at IS NULL OR next_run_at <= ?)
  ORDER BY COALESCE(next_run_at,'1970-01-01') ASC
  LIMIT 50
", [$now]);

log_line("due jobs: " . count($jobs));

foreach ($jobs as $job) {
  $jobId    = (int)$job['id'];
  $domainId = (int)$job['domain_id'];
  $kind     = (string)$job['job_type'];
  $code     = (string)$job['connector'];

  /** lock 2 min (only if still due) */
  $lockNext = date('Y-m-d H:i:s', time() + 120);
  db_exec($db, "
    UPDATE crm_cron_jobs
       SET next_run_at=?
     WHERE id=?
       AND is_enabled=1
       AND (next_run_at IS NULL OR next_run_at <= ?)
  ", [$lockNext, $jobId, $now]);

  $chk = db_all($db, "SELECT next_run_at FROM crm_cron_jobs WHERE id=? LIMIT 1", [$jobId]);
  $curNext = (string)($chk[0]['next_run_at'] ?? '');
  if ($curNext !== $lockNext) {
    log_line("skip job#{$jobId} (locked by other runner?) next_run_at={$curNext}");
    continue;
  }

  log_line("run job#{$jobId} domain={$domainId} kind={$kind} connector={$code}");

  db_exec($db, "INSERT INTO crm_cron_runs(job_id,status,started_at) VALUES(?,?,NOW())", [$jobId, 'running']);
  $rowRun = db_all($db, "SELECT LAST_INSERT_ID() AS id");
  $runId  = (int)($rowRun[0]['id'] ?? 0);

  $params = json_decode((string)$job['params_json'], true);
  if (!is_array($params)) $params = [];
  /* домен задания важнее того, что записано в параметрах: параметры копируются
     с соседнего задания при заведении нового домена, и чужой domain_id в копии
     тихо превращает статистику одного сайта в статистику другого */
  $params['domain_id'] = $domainId;

  try {
    if (!isset($CONNECTORS[$code])) throw new RuntimeException("Unknown connector: {$code}");

    /** @var ConnectorInterface $obj */
    $obj = $CONNECTORS[$code];

    $result = $obj->run($params);

    $d = (string)($result['date'] ?? date('Y-m-d'));
    $items = $result['items'] ?? [];
    if (!is_array($items)) $items = [];

    $saved = 0;
    $tsNow = date('Y-m-d H:i:s');

    foreach ($items as $it) {
      if (!is_array($it)) continue;

      $ext = (string)($it['external_id'] ?? '');
      $amt = (float)($it['amount'] ?? 0.0);

      $meta = $it['meta'] ?? null;
      $metaJson = $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;

      /* Визиты Метрики считает московскими сутками и в 21:00 UTC обнуляет счётчик,
         поэтому сырой снимок сюда класть нельзя — он затрёт день тремя часами
         следующего. Дневную строку для metric собирает /root/metric-day.php. */
      if ($kind !== 'metric') db_exec($db, "
        INSERT INTO crm_finance_daily(d,domain_id,kind,connector,external_id,amount,meta_json)
        VALUES(?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE amount=VALUES(amount), meta_json=VALUES(meta_json)
      ", [$d, $domainId, $kind, $code, $ext, $amt, $metaJson]);

      /** intraday history tick */
      db_exec($db, "
        INSERT INTO crm_finance_ticks(ts,d,domain_id,kind,connector,external_id,amount,meta_json,run_id)
        VALUES(?,?,?,?,?,?,?,?,?)
      ", [$tsNow, $d, $domainId, $kind, $code, $ext, $amt, $metaJson, $runId]);

      $saved++;
    }

    db_exec($db, "
      UPDATE crm_cron_runs
         SET status='ok', finished_at=NOW(), message=?, output_json=?
       WHERE id=?
    ", ["saved {$saved}", clip_str(pretty_json($result)), $runId]);

    $next = calc_next_run((string)$job['schedule']);
    db_exec($db, "UPDATE crm_cron_jobs SET last_run_at=NOW(), next_run_at=? WHERE id=?", [$next, $jobId]);

    log_line("ok job#{$jobId} run#{$runId} saved={$saved} next={$next}");

  } catch (Throwable $e) {
    $err = [
      'error' => $e->getMessage(),
      'trace' => explode("\n", $e->getTraceAsString()),
      'connector' => $code,
      'domain_id' => $domainId,
      'kind' => $kind,
      'params' => $params,
      'when' => $now,
    ];

    db_exec($db, "
      UPDATE crm_cron_runs
         SET status='error', finished_at=NOW(), message=?, output_json=?
       WHERE id=?
    ", [$e->getMessage(), clip_str(pretty_json($err)), $runId]);

    /** backoff 10 min */
    $next = date('Y-m-d H:i:s', time() + 600);
    db_exec($db, "UPDATE crm_cron_jobs SET last_run_at=NOW(), next_run_at=? WHERE id=?", [$next, $jobId]);

    log_line("error job#{$jobId} run#{$runId}: " . $e->getMessage());
  }
}

log_line("runner finish");

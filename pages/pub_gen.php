<?php
// /fapacrm/pages/pub_gen.php
declare(strict_types=1);

/**
 * Publishers BL Generator (CRM)
 *
 * Генерирует автономный скрипт на НУЖНЫЙ ДОМЕН:
 *   /var/www/{domain}/cmd/pages/publishers.php
 *
 * ВАЖНО:
 * - KADAM app_id / client_id / campaign_id (РК) / bearer БЕРЁМ ИЗ CRON_JOB (cost: kadam_cost) В CRM.
 * - DB для кликов/рейтов БЕРЁМ ИЗ /var/www/{domain}/cmd/config.user.php (DB секция).
 * - НИКАКИХ CFG['KADAM'] / конфигов — токены/РК вшиваются в файл при генерации.
 *
 * Cron URL после деплоя:
 *   https://{domain}/cmd/menu.php?page=publishers&cron=1&action=send_kadam
 * (+ если на сайте включён CRON_KEY в CFG — добавь &key=...)
 *
 * Требует helpers CRM:
 *   csrf_check(), csrf_token(), db_connect(), db_all(),
 *   req_int(), req_str(), req_bool(), flash_set(), h()
 */

csrf_check();
$db = db_connect();

/** ---------- tiny helpers ---------- */
function safe_domain(string $domain): string {
  $domain = trim($domain);
  if ($domain === '' || !preg_match('~^[a-z0-9.-]+$~i', $domain)) return '';
  return $domain;
}

function decode_params(string $json): array {
  $j = json_decode($json, true);
  return is_array($j) ? $j : [];
}

function pick_job(array $jobs, array $prefer): ?array {
  if (!$jobs) return null;
  foreach ($prefer as $code) {
    foreach ($jobs as $j) {
      if ((string)($j['connector'] ?? '') === $code) return $j;
    }
  }
  return $jobs[0] ?? null;
}

function load_site_db_cfg(string $domain): array {
  $p = "/var/www/{$domain}/cmd/config.user.php";
  if (!is_file($p)) return [];
  $cfg = @require $p;
  if (!is_array($cfg)) return [];
  $db = $cfg['DB'] ?? null;
  if (!is_array($db)) return [];
  $host = (string)($db['host'] ?? '');
  $user = (string)($db['user'] ?? '');
  $pass = (string)($db['pass'] ?? '');
  $name = (string)($db['name'] ?? '');
  if ($host==='' || $user==='' || $name==='') return [];
  return ['host'=>$host,'user'=>$user,'pass'=>$pass,'name'=>$name];
}

/** ---------- builder (NOWDOC + strtr) ---------- */
function build_publishers_php(array $kadamCostJob, array $siteDb): string
{
  $p = decode_params((string)($kadamCostJob['params_json'] ?? '{}'));

  // KADAM — строго из cron_job (cost=kadam_cost)
  $k_app  = (int)($p['app_id'] ?? 0);
  $k_cli  = (int)($p['client_id'] ?? $k_app);
  $k_bear = (string)($p['bearer'] ?? '');
  $k_camp = (int)($p['campaign_id'] ?? ($p['campaignId'] ?? 0));

  $db_host = (string)($siteDb['host'] ?? '');
  $db_user = (string)($siteDb['user'] ?? '');
  $db_pass = (string)($siteDb['pass'] ?? '');
  $db_name = (string)($siteDb['name'] ?? '');

  $tpl = <<<'PHP'
<?php
// /cmd/pages/publishers.php (generated)
declare(strict_types=1);

/**
 * Publishers — lead2 BL → Kadam black_list
 *
 * AUTONOMOUS (per-domain):
 * - DB_* and KADAM_* are embedded by CRM generator.
 * - No CRM config required.
 *
 * Cron (via site menu router):
 *   /cmd/menu.php?page=publishers&cron=1&action=send_kadam
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

/*** KADAM (embedded) ***/
if (!defined('KADAM_APP_ID'))      define('KADAM_APP_ID', __KADAM_APP_ID__);
if (!defined('KADAM_CLIENT_ID'))   define('KADAM_CLIENT_ID', __KADAM_CLIENT_ID__);
if (!defined('KADAM_CAMPAIGN_ID')) define('KADAM_CAMPAIGN_ID', __KADAM_CAMPAIGN_ID__);
if (!defined('KADAM_BEARER'))      define('KADAM_BEARER', '__KADAM_BEARER__');

/*** DB (site DB for clicks/rates) ***/
if (!defined('DB_HOST')) define('DB_HOST','__DB_HOST__');
if (!defined('DB_USER')) define('DB_USER','__DB_USER__');
if (!defined('DB_PASS')) define('DB_PASS','__DB_PASS__');
if (!defined('DB_NAME')) define('DB_NAME','__DB_NAME__');

// optional: site config (for CRON_KEY etc). If missing — ok.
@require_once __DIR__ . '/../config.php';

if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('req_param')) {
  function req_param(string $key, $default = null) { return $_REQUEST[$key] ?? $default; }
}

function dbp(): mysqli {
  static $m = null;
  if ($m instanceof mysqli) return $m;
  $m = new mysqli((string)DB_HOST, (string)DB_USER, (string)DB_PASS, (string)DB_NAME);
  if ($m->connect_error) die('DB error: '.$m->connect_error);
  $m->set_charset('utf8mb4');
  return $m;
}

$conn = dbp();

$isCron = ((string)req_param('cron', '') === '1');

/** --- cron safety: optional key from site CFG --- */
if ($isCron) {
  ini_set('display_errors', '0');

  $cronErrors = [];
  set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$cronErrors) {
    $cronErrors[] = [
      'errno' => $errno,
      'err'   => $errstr,
      'file'  => basename((string)$errfile),
      'line'  => (int)$errline,
    ];
    return true;
  });

  register_shutdown_function(function() use (&$cronErrors) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
      if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
      }
      echo json_encode([
        'ok' => false,
        'fatal' => [
          'type' => $e['type'],
          'message' => $e['message'],
          'file' => basename((string)$e['file']),
          'line' => (int)$e['line'],
        ],
        'php_errors' => $cronErrors,
      ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
  });

  $cronKey = '';
  if (isset($CFG) && is_array($CFG) && isset($CFG['CRON_KEY'])) $cronKey = (string)$CFG['CRON_KEY'];
  if ($cronKey !== '') {
    $reqKey = (string)req_param('key', '');
    if (!hash_equals($cronKey, $reqKey)) {
      http_response_code(403);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>false,'error'=>'CRON_KEY invalid'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
      $conn->close();
      exit;
    }
  }
}

function col_exists(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $res = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  $ok = ($res && $res->num_rows > 0);
  if ($res) $res->free();
  return $ok;
}

function table_exists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '{$t}'");
  $ok = ($res && $res->num_rows > 0);
  if ($res) $res->free();
  return $ok;
}

/* ---------- Kadam REST ---------- */
class RateLimiter {
  private array $ts = [];
  private int $cap;
  public function __construct(int $capPerSec = 5){ $this->cap=$capPerSec; }
  public function wait(): void {
    $now = microtime(true);
    $this->ts = array_filter($this->ts, fn($t)=>($now-$t)<1.0);
    if (count($this->ts) >= $this->cap) {
      $sleep = 1.0 - ($now - min($this->ts));
      if ($sleep > 0) usleep((int)($sleep*1e6));
    }
    $this->ts[] = microtime(true);
  }
}
$limiter = new RateLimiter(5);

function kadam_get(string $url, string $bearer, RateLimiter $rl, int $timeout=25): array {
  $rl->wait();
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$bearer],
    CURLOPT_TIMEOUT        => $timeout,
  ]);
  $raw  = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  return ['http'=>$http,'error'=>$err?:null,'raw'=>$raw,'json'=>json_decode((string)$raw,true)];
}

function kadam_post_form(string $url, array $fields, string $bearer, RateLimiter $rl, int $timeout=30, int $retries=2): array {
  $payload = http_build_query($fields, '', '&');
  for ($i=0; $i<=$retries; $i++) {
    $rl->wait();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST            => true,
      CURLOPT_RETURNTRANSFER  => true,
      CURLOPT_HTTPHEADER      => [
        'Authorization: Bearer '.$bearer,
        'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
      ],
      CURLOPT_POSTFIELDS      => $payload,
      CURLOPT_TIMEOUT         => $timeout,
    ]);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if (in_array($http, [429,503], true) && $i < $retries) {
      usleep((int)(pow(2,$i)*250000));
      continue;
    }
    return ['http'=>$http,'error'=>$err?:null,'raw'=>$raw,'json'=>json_decode((string)$raw,true)];
  }
  return ['http'=>0,'error'=>'retry_exhausted','raw'=>null,'json'=>null];
}

function kadam_sync_blacklist(int $app_id, int $client_id, int $campaign_id, string $bearer, array $black_list, RateLimiter $rl): array {
  $black_list = array_values(array_unique(array_filter(array_map('strval', $black_list), fn($v)=>$v!=='' && $v!=='[empty]')));
  $out = ['ping'=>null,'update'=>null,'sent'=>count($black_list)];

  $out['ping'] = kadam_get('https://api.kadam.net/ads.account.get?app_id='.$app_id, $bearer, $rl);
  if (($out['ping']['http'] ?? 0) !== 200 || (!empty($out['ping']['json']['error']) && $out['ping']['json']['error'] !== '')) {
    $out['error'] = 'Kadam token invalid (ping failed)';
    return $out;
  }

  $upd = [
    'client_id'   => $client_id,
    'campaign_id' => $campaign_id,
    'black_list'  => $black_list,
  ];

  $out['update'] = kadam_post_form(
    'https://api.kadam.net/ads.campaigns.update',
    [
      'app_id' => $app_id,
      'data'   => json_encode($upd, JSON_UNESCAPED_UNICODE),
    ],
    $bearer,
    $rl
  );

  return $out;
}

/* ---------- defaults / period ---------- */
$today   = (new DateTime('today'))->format('Y-m-d');
$weekAgo = (new DateTime('-7 days'))->format('Y-m-d');

$from = (string)req_param('from', $weekAgo);
$to   = (string)req_param('to',   $today);

try {
  $fromDt = new DateTime($from . ' 00:00:00');
  $toDt   = new DateTime($to   . ' 23:59:59');
} catch (Throwable $e) {
  $fromDt = new DateTime($weekAgo . ' 00:00:00');
  $toDt   = new DateTime($today   . ' 23:59:59');
}

$fromSql = $conn->real_escape_string($fromDt->format('Y-m-d H:i:s'));
$toSql   = $conn->real_escape_string($toDt->format('Y-m-d H:i:s'));

/* ---------- publisher column (site_id обычно в c1) ---------- */
$pubCol = 'c1';
if (!col_exists($conn, 'clicks', $pubCol)) {
  $pubCol = col_exists($conn, 'clicks', 'publisher') ? 'publisher' : (col_exists($conn, 'clicks', 'ref') ? 'ref' : 'c2');
}

/* ---------- rates compatibility (geo/rate2 OR cc/rate) ---------- */
$ratesOk = table_exists($conn, 'rates');
$ratesHasGeo   = $ratesOk && col_exists($conn, 'rates', 'geo');
$ratesHasRate2 = $ratesOk && col_exists($conn, 'rates', 'rate2');
$ratesHasCc    = $ratesOk && col_exists($conn, 'rates', 'cc');
$ratesHasRate  = $ratesOk && col_exists($conn, 'rates', 'rate');

$ratesSub = "(SELECT 'ZZ' AS geo, 0 AS rate_val) r";
if ($ratesHasGeo && $ratesHasRate2) {
  $ratesSub = "
    (
      SELECT
        CONVERT(UPPER(TRIM(geo)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS geo,
        MAX(rate2) AS rate_val
      FROM rates
      GROUP BY geo
    ) r
  ";
} elseif ($ratesHasCc && $ratesHasRate) {
  $ratesSub = "
    (
      SELECT
        CONVERT(UPPER(TRIM(cc)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS geo,
        MAX(rate) AS rate_val
      FROM rates
      GROUP BY cc
    ) r
  ";
}

/* ---------- rules: lead2-based BL ---------- */
$minClicksBL = (int)req_param('min_clicks_bl', 20);
$minClicksBL = max(1, $minClicksBL);

$maxCrPctBL  = (float)req_param('max_cr_pct_bl', 15.0);
$maxCrPctBL  = max(0.0, $maxCrPctBL);
$maxCrFrac   = $maxCrPctBL / 100.0;

$onlyBad = ((string)req_param('only_bad', '') === '1');

/* revenue lead2 only: click.revenue first, else rates fallback */
$revExpr   = "SUM(CASE WHEN c.lead2>0 THEN COALESCE(c.revenue, r.rate_val, 0) ELSE 0 END)";
$lead2Expr = "SUM(c.lead2 > 0)";

/* ---------- SUMMARY ---------- */
$summary = ['clicks'=>0,'cost'=>0.0,'rev'=>0.0,'profit'=>0.0,'roi'=>0.0,'lead2'=>0,'cr2'=>0.0];
$sumErr = null;

$sqlSummary = "
  SELECT
    COUNT(*) AS clicks,
    COALESCE(SUM(c.cost),0) AS cost,
    {$revExpr} AS revenue,
    {$lead2Expr} AS lead2_cnt
  FROM clicks c
  LEFT JOIN {$ratesSub}
    ON (CONVERT(UPPER(TRIM(c.country)) USING utf8mb4) COLLATE utf8mb4_unicode_ci) = r.geo
  WHERE c.ts BETWEEN '{$fromSql}' AND '{$toSql}'
";
if ($resSum = $conn->query($sqlSummary)) {
  $row = $resSum->fetch_assoc();
  $summary['clicks'] = (int)($row['clicks'] ?? 0);
  $summary['cost']   = (float)($row['cost'] ?? 0);
  $summary['rev']    = (float)($row['revenue'] ?? 0);
  $summary['lead2']  = (int)($row['lead2_cnt'] ?? 0);
  $summary['profit'] = $summary['rev'] - $summary['cost'];
  $summary['roi']    = ($summary['cost'] > 0) ? ($summary['profit'] / $summary['cost'] * 100.0) : 0.0;
  $summary['cr2']    = ($summary['clicks'] > 0) ? ($summary['lead2'] / $summary['clicks'] * 100.0) : 0.0;
  $resSum->free();
} else {
  $sumErr = $conn->error;
}

/* ---------- LIST by publisher ---------- */
$listErr = null;

$sql = "
  SELECT
    COALESCE(NULLIF(c.{$pubCol},''),'[empty]') AS publisher,
    COUNT(*) AS clicks,
    COALESCE(SUM(c.cost),0) AS cost,
    {$lead2Expr} AS lead2_cnt,
    {$revExpr} AS revenue
  FROM clicks c
  LEFT JOIN {$ratesSub}
    ON (CONVERT(UPPER(TRIM(c.country)) USING utf8mb4) COLLATE utf8mb4_unicode_ci) = r.geo
  WHERE c.ts BETWEEN '{$fromSql}' AND '{$toSql}'
  GROUP BY publisher
  ORDER BY clicks DESC
";

$res = $conn->query($sql);

$rows = [];
if ($res) {
  while ($r = $res->fetch_assoc()) {
    $pub    = (string)($r['publisher'] ?? '[empty]');
    $clicks = (int)($r['clicks'] ?? 0);
    if ($clicks <= 0) continue;

    $cost  = (float)($r['cost'] ?? 0);
    $rev   = (float)($r['revenue'] ?? 0);
    $lead2 = (int)($r['lead2_cnt'] ?? 0);

    $profit = $rev - $cost;
    $roi    = ($cost > 0) ? ($profit / $cost * 100.0) : 0.0;
    $cpa2   = ($lead2 > 0) ? ($cost / $lead2) : 0.0;
    $cr2    = ($clicks > 0) ? ($lead2 / $clicks) : 0.0;

    $isBad = ($clicks >= $minClicksBL && $cr2 < $maxCrFrac && $pub !== '' && $pub !== '[empty]');

    if ($onlyBad && !$isBad) continue;

    $rows[] = [
      'publisher'=>$pub,
      'clicks'=>$clicks,
      'cost'=>$cost,
      'revenue'=>$rev,
      'profit'=>$profit,
      'roi'=>$roi,
      'lead2'=>$lead2,
      'cpa2'=>$cpa2,
      'cr2'=>$cr2,
      'is_bad'=>$isBad,
    ];
  }
  $res->free();
} else {
  $listErr = $conn->error;
}

$total = count($rows);
$bad = 0;
foreach ($rows as $rr) if ($rr['is_bad']) $bad++;

/* ---------- KADAM CONFIG (embedded by generator; allow override by request/form) ---------- */
$kadam_app_id      = (int)req_param('kadam_app_id', (int)KADAM_APP_ID);
$kadam_client_id   = (int)req_param('kadam_client_id', (int)KADAM_CLIENT_ID);
$kadam_campaign_id = (int)req_param('kadam_campaign_id', (int)KADAM_CAMPAIGN_ID);
$kadam_bearer      = (string)req_param('kadam_bearer', (string)KADAM_BEARER);

$action = (string)req_param('action', '');
if ($isCron && $action === '') $action = 'send_kadam';

$kadamResult = null;
$blackIds = [];

/* ---------- action ---------- */
if ($action === 'build_bl' || $action === 'send_kadam') {
  $selected = req_param('pub', null);

  if (is_array($selected) && $selected) {
    $blackIds = array_values(array_unique(array_filter(array_map('strval', $selected), fn($v)=>$v!=='' && $v!=='[empty]')));
  } else {
    foreach ($rows as $rr) {
      if ($rr['is_bad']) $blackIds[] = (string)$rr['publisher'];
    }
    $blackIds = array_values(array_unique(array_filter($blackIds, fn($v)=>$v!=='' && $v!=='[empty]')));
  }

  if ($action === 'send_kadam') {
    if ($kadam_app_id <= 0 || $kadam_campaign_id <= 0 || $kadam_bearer === '') {
      $kadamResult = [
        'error' => 'Kadam not configured: need kadam_app_id, kadam_campaign_id, kadam_bearer',
        'kadam_app_id'=>$kadam_app_id,
        'kadam_client_id'=>$kadam_client_id,
        'kadam_campaign_id'=>$kadam_campaign_id,
        'has_bearer'=>($kadam_bearer !== ''),
        'sent_ids'=>count($blackIds),
      ];
    } else {
      $kadamResult = kadam_sync_blacklist($kadam_app_id, $kadam_client_id, $kadam_campaign_id, $kadam_bearer, $blackIds, $limiter);
    }
  }
}

/* ---------- CRON OUTPUT (JSON) ---------- */
if ($isCron) {
  $okUpdate = false;
  $pingHttp = $kadamResult['ping']['http'] ?? null;
  $updHttp  = $kadamResult['update']['http'] ?? null;

  if ($action === 'send_kadam' && (int)$updHttp === 200 && empty($kadamResult['error'])) $okUpdate = true;

  $out = [
    'ok' => $okUpdate && !$sumErr && !$listErr,
    'ts' => date('c'),
    'mode' => 'cron',
    'period' => [
      'from' => $fromDt->format('Y-m-d'),
      'to'   => $toDt->format('Y-m-d'),
    ],
    'rules' => [
      'min_clicks_bl' => $minClicksBL,
      'max_cr_pct_bl' => $maxCrPctBL,
    ],
    'meta' => [
      'pub_col' => $pubCol,
      'rates' => ($ratesHasGeo && $ratesHasRate2) ? 'rates.geo/rate2' : (($ratesHasCc && $ratesHasRate) ? 'rates.cc/rate' : 'no rates (only clicks.revenue)'),
    ],
    'summary' => $summary,
    'publishers' => [
      'total' => $total,
      'bad'   => $bad,
      'sent'  => count($blackIds),
    ],
    'kadam' => [
      'app_id' => $kadam_app_id,
      'client_id' => $kadam_client_id,
      'campaign_id' => $kadam_campaign_id,
      'has_bearer' => ($kadam_bearer !== ''),
      'ping_http' => $pingHttp,
      'update_http' => $updHttp,
      'error' => $kadamResult['error'] ?? null,
      'update_error' => $kadamResult['update']['error'] ?? null,
      'update_api_error' => $kadamResult['update']['json']['error'] ?? null,
      'sent' => $kadamResult['sent'] ?? count($blackIds),
    ],
    'sql_errors' => [
      'summary' => $sumErr,
      'list' => $listErr,
    ],
  ];

  if ((string)req_param('debug', '') === '1') {
    $out['black_list'] = $blackIds;
  }

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  $conn->close();
  exit;
}
?>

<h2 class="mb-4">Publishers — lead2 BL (clicks ≥ <?=h((string)$minClicksBL)?> &amp; CR2 &lt; <?=h((string)$maxCrPctBL)?>%) → Kadam black_list</h2>

<?php if ($sumErr): ?><div class="alert alert-danger">SUMMARY SQL ERROR: <?=h($sumErr)?></div><?php endif; ?>
<?php if ($listErr): ?><div class="alert alert-danger">LIST SQL ERROR: <?=h($listErr)?></div><?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2 align-items-end" method="get" action="menu.php">
      <input type="hidden" name="page" value="publishers">

      <div class="col-12 col-md-2">
        <label class="form-label">Дата с</label>
        <input type="date" name="from" value="<?=h($from)?>" class="form-control">
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label">Дата по</label>
        <input type="date" name="to" value="<?=h($to)?>" class="form-control">
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Clicks ≥</label>
        <input type="number" name="min_clicks_bl" value="<?=h((string)$minClicksBL)?>" class="form-control">
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">CR2 &lt; %</label>
        <input type="number" step="0.1" name="max_cr_pct_bl" value="<?=h((string)$maxCrPctBL)?>" class="form-control">
      </div>

      <div class="col-12 col-md-2 form-check mt-4 ms-2">
        <input class="form-check-input" type="checkbox" id="only_bad" name="only_bad" value="1" <?=$onlyBad?'checked':''?>>
        <label class="form-check-label" for="only_bad">Только кандидаты BL</label>
      </div>

      <div class="col-12 col-md-2 mt-4">
        <button type="submit" class="btn btn-primary w-100">Применить</button>
      </div>

      <div class="col-12 col-md-6 text-muted small">
        Publisher column: <b><?=h($pubCol)?></b>.
        Rates: <b><?=h(($ratesHasGeo && $ratesHasRate2) ? 'rates.geo/rate2' : (($ratesHasCc && $ratesHasRate) ? 'rates.cc/rate' : 'no rates (only clicks.revenue)'))?></b>.
      </div>
    </form>

    <hr class="mt-3 mb-2">

    <div class="row">
      <div class="col-12 col-md-8">
        <table class="table table-sm table-bordered mb-0">
          <thead class="table-light">
            <tr>
              <th>Период</th>
              <th class="text-end">Clicks</th>
              <th class="text-end">Cost</th>
              <th class="text-end">Revenue</th>
              <th class="text-end">Profit</th>
              <th class="text-end">ROI%</th>
              <th class="text-end">Lead2</th>
              <th class="text-end">CR2%</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><?=h($from.' … '.$to)?></td>
              <td class="text-end"><?=number_format($summary['clicks'])?></td>
              <td class="text-end"><?=number_format($summary['cost'], 2)?></td>
              <td class="text-end"><?=number_format($summary['rev'], 2)?></td>
              <td class="text-end"><?=number_format($summary['profit'], 2)?></td>
              <td class="text-end"><?=number_format($summary['roi'], 2)?>%</td>
              <td class="text-end"><?=number_format($summary['lead2'])?></td>
              <td class="text-end"><?=number_format($summary['cr2'], 2)?>%</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="col-12 col-md-4">
        <div class="alert alert-secondary mb-0">
          Всего пабов: <b><?=$total?></b><br>
          Кандидатов BL: <b><?=$bad?></b>
        </div>
      </div>
    </div>
  </div>
</div>

<form method="post" action="menu.php?page=publishers" class="card mb-3">
  <div class="card-body">
    <input type="hidden" name="from" value="<?=h($from)?>">
    <input type="hidden" name="to" value="<?=h($to)?>">
    <input type="hidden" name="min_clicks_bl" value="<?=h((string)$minClicksBL)?>">
    <input type="hidden" name="max_cr_pct_bl" value="<?=h((string)$maxCrPctBL)?>">
    <input type="hidden" name="only_bad" value="<?=h($onlyBad ? '1' : '')?>">

    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-2">
        <label class="form-label">Kadam app_id</label>
        <input type="number" name="kadam_app_id" value="<?=h((string)$kadam_app_id)?>" class="form-control">
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label">Kadam client_id</label>
        <input type="number" name="kadam_client_id" value="<?=h((string)$kadam_client_id)?>" class="form-control">
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label">Kadam campaign_id</label>
        <input type="number" name="kadam_campaign_id" value="<?=h((string)$kadam_campaign_id)?>" class="form-control">
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Bearer</label>
        <input type="text" name="kadam_bearer" value="<?=h($kadam_bearer)?>" class="form-control" placeholder="Bearer token">
      </div>

      <div class="col-6 col-md-2 mt-2">
        <button name="action" value="build_bl" class="btn btn-outline-secondary w-100">Сформировать BL</button>
      </div>
      <div class="col-6 col-md-3 mt-2">
        <button name="action" value="send_kadam" class="btn btn-danger w-100">Отправить в Kadam</button>
      </div>

      <div class="col-12 col-md-7 mt-2 text-muted small">
        Отправка = <code>ads.account.get</code> (ping) → <code>ads.campaigns.update</code> с <code>black_list</code> (полная замена).
        Если чекбоксы не выбраны — отправим всех кандидатов по правилу.
      </div>
    </div>

    <hr>

    <div class="table-responsive">
      <table class="table table-sm table-striped table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:36px;">
              <input type="checkbox" id="chk_all" onclick="document.querySelectorAll('input.pubchk').forEach(x=>x.checked=this.checked);">
            </th>
            <th>Publisher (<?=h($pubCol)?>)</th>
            <th class="text-end">Clicks</th>
            <th class="text-end">Lead2</th>
            <th class="text-end">CR2%</th>
            <th class="text-end">Cost</th>
            <th class="text-end">Revenue</th>
            <th class="text-end">Profit</th>
            <th class="text-end">ROI%</th>
            <th class="text-end">CPA(L2)</th>
            <th class="text-center">BL</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="11" class="text-center text-muted">Нет данных.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $rowClass = '';
                if ($r['is_bad']) $rowClass = 'table-danger';
                elseif ($r['roi'] > 0) $rowClass = 'table-success';
                $cr2pct = $r['cr2'] * 100.0;
              ?>
              <tr class="<?=$rowClass?>">
                <td>
                  <input class="form-check-input pubchk" type="checkbox" name="pub[]" value="<?=h($r['publisher'])?>">
                </td>
                <td class="fw-semibold"><?=h($r['publisher'])?></td>
                <td class="text-end"><?=number_format($r['clicks'])?></td>
                <td class="text-end fw-semibold"><?=number_format($r['lead2'])?></td>
                <td class="text-end"><?=number_format($cr2pct, 2)?>%</td>
                <td class="text-end"><?=number_format($r['cost'], 2)?></td>
                <td class="text-end"><?=number_format($r['revenue'], 2)?></td>
                <td class="text-end"><?=number_format($r['profit'], 2)?></td>
                <td class="text-end"><?=number_format($r['roi'], 2)?>%</td>
                <td class="text-end"><?=number_format($r['cpa2'], 4)?></td>
                <td class="text-center">
                  <?php if ($r['is_bad']): ?>
                    <span class="badge bg-danger">BL</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">ok</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="text-muted small mt-2">
      Правило BL: <b>clicks ≥ <?=h((string)$minClicksBL)?></b> и <b>CR2 &lt; <?=h((string)$maxCrPctBL)?>%</b>.
    </div>
  </div>
</form>

<?php if ($action === 'build_bl' || $action === 'send_kadam'): ?>
  <div class="card mb-3">
    <div class="card-body">
      <h5 class="mb-2">black_list (к отправке)</h5>

      <?php if (!$blackIds): ?>
        <div class="alert alert-warning mb-0">Список пуст.</div>
      <?php else: ?>
        <div class="alert alert-secondary">IDs: <b><?=count($blackIds)?></b></div>
        <pre class="mb-0" style="max-height:260px; overflow:auto; background:#111; color:#e9ecef; padding:12px; border-radius:8px;"><?=h(json_encode($blackIds, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))?></pre>
      <?php endif; ?>

      <?php if ($kadamResult !== null): ?>
        <hr>
        <h5 class="mb-2">Kadam response</h5>
        <pre class="mb-0" style="max-height:360px; overflow:auto; background:#111; color:#e9ecef; padding:12px; border-radius:8px;"><?=h(json_encode($kadamResult, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))?></pre>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php $conn->close(); ?>
PHP;

  $repl = [
    '__KADAM_APP_ID__'      => (string)$k_app,
    '__KADAM_CLIENT_ID__'   => (string)$k_cli,
    '__KADAM_CAMPAIGN_ID__' => (string)$k_camp,
    '__KADAM_BEARER__'      => addslashes($k_bear),

    '__DB_HOST__'           => addslashes($db_host),
    '__DB_USER__'           => addslashes($db_user),
    '__DB_PASS__'           => addslashes($db_pass),
    '__DB_NAME__'           => addslashes($db_name),
  ];

  return strtr($tpl, $repl);
}

/** ---------- UI ---------- */
$domains = db_all($db, "SELECT id, domain FROM crm_domains ORDER BY domain");

$sel_domain_id = req_int('domain_id', 0);
$domainName = '';
foreach ($domains as $d) if ((int)$d['id'] === $sel_domain_id) { $domainName = (string)$d['domain']; break; }
$domainSafe = safe_domain($domainName);

$costJobs = [];
if ($sel_domain_id > 0) {
  $jobs = db_all($db, "SELECT * FROM crm_cron_jobs WHERE domain_id=? AND is_enabled=1 ORDER BY id DESC", [$sel_domain_id]);
  foreach ($jobs as $j) {
    if ((string)($j['job_type'] ?? '') === 'cost') $costJobs[] = $j;
  }
}
$kadamCostPick = pick_job($costJobs, ['kadam_cost']);

$msg = '';
$preview = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('do_generate', false)) {
  $sel_domain_id = req_int('domain_id', $sel_domain_id);

  $domainName = '';
  foreach ($domains as $d) if ((int)$d['id'] === $sel_domain_id) { $domainName = (string)$d['domain']; break; }
  $domainSafe = safe_domain($domainName);

  if ($sel_domain_id <= 0 || $domainSafe === '') {
    $msg = 'Ошибка: выбери домен';
  } elseif (!$kadamCostPick) {
    $msg = 'Ошибка: нет enabled cost-job (ожидаю connector=kadam_cost)';
  } else {
    $siteDb = load_site_db_cfg($domainSafe);
    if (!$siteDb) {
      $msg = 'Ошибка: не прочитал DB из /var/www/'.$domainSafe.'/cmd/config.user.php';
    } else {
      $p = decode_params((string)($kadamCostPick['params_json'] ?? '{}'));
      $need = [
        'app_id' => (int)($p['app_id'] ?? 0),
        'campaign_id' => (int)($p['campaign_id'] ?? ($p['campaignId'] ?? 0)),
        'bearer' => (string)($p['bearer'] ?? ''),
      ];
      if ($need['app_id']<=0 || $need['campaign_id']<=0 || $need['bearer']==='') {
        $msg = 'Ошибка: в kadam_cost params_json нет app_id/campaign_id/bearer (РК/токен)';
      } else {
        $target = '/var/www/' . $domainSafe . '/cmd/pages/publishers.php';
        $dir = dirname($target);
        if (!is_dir($dir)) {
          $msg = 'Ошибка: нет каталога ' . $dir;
        } else {
          $php = build_publishers_php($kadamCostPick, $siteDb);
          $preview = $php;

          if (is_file($target)) @copy($target, $target.'.bak.'.date('Ymd_His'));
          $ok = @file_put_contents($target, $php, LOCK_EX);

          $msg = ($ok === false) ? ('Ошибка записи: ' . $target) : ('OK: записан ' . $target . ' (' . (int)$ok . ' bytes)');
        }
      }
    }
  }
}
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">🧰 Publishers BL Generator</h3>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-primary" href="menu.php?page=cron_jobs<?=($sel_domain_id?('&domain_id='.(int)$sel_domain_id):'')?>">Cron Jobs</a>
  </div>
</div>

<?php if ($msg): ?>
  <div class="alert <?= (strpos($msg,'OK:')===0 ? 'alert-success' : 'alert-danger') ?>">
    <?=h($msg)?>
  </div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-header"><b>Выбор домена</b></div>
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="page" value="pub_gen">
      <div class="col-md-6">
        <label class="form-label small text-muted mb-0">Домен</label>
        <select class="form-select" name="domain_id" onchange="this.form.submit()">
          <option value="0">— выбрать —</option>
          <?php foreach ($domains as $d): ?>
            <option value="<?=h($d['id'])?>" <?=((int)$d['id']===$sel_domain_id)?'selected':''?>><?=h($d['domain'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 d-flex gap-2">
        <button class="btn btn-outline-primary">Показать</button>
        <a class="btn btn-outline-secondary" href="menu.php?page=pub_gen">Сброс</a>
      </div>
    </form>

    <?php if ($sel_domain_id>0): ?>
      <hr>
      <div class="row g-2">
        <div class="col-md-6">
          <div class="p-2 border rounded">
            <div><b>Cost job (must be kadam_cost)</b></div>
            <?php if ($kadamCostPick): ?>
              <div class="small text-muted">connector: <code><?=h((string)$kadamCostPick['connector'])?></code></div>
              <pre style="max-height:180px;overflow:auto;background:#f7f7f7;border:1px solid #eee;padding:8px;border-radius:6px;"><?=h((string)$kadamCostPick['params_json'])?></pre>
            <?php else: ?>
              <div class="text-danger">нет enabled kadam_cost</div>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-md-6">
          <div class="p-2 border rounded">
            <div><b>Target</b></div>
            <div class="small text-muted">/var/www/<?=h($domainSafe)?>/cmd/pages/publishers.php</div>
            <div class="small text-muted mt-2">Cron URL:</div>
            <div class="small"><code>https://<?=h($domainSafe)?>/cmd/menu.php?page=publishers&amp;cron=1&amp;action=send_kadam</code></div>
          </div>
        </div>
      </div>

      <hr>

      <form method="post" onsubmit="return confirm('Перезаписать /var/www/<?=h($domainSafe)?>/cmd/pages/publishers.php ?');">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="do_generate" value="1">
        <input type="hidden" name="domain_id" value="<?=h((string)$sel_domain_id)?>">
        <button class="btn btn-success" <?=(!$kadamCostPick || $domainSafe==='')?'disabled':''?>>
          СГЕНЕРИРОВАТЬ / ОБНОВИТЬ publishers.php
        </button>
      </form>

      <?php if ($preview): ?>
        <div class="mt-3">
          <div class="small text-muted mb-1"><b>Preview (что записали):</b></div>
          <pre style="max-height:360px;overflow:auto;background:#0b1020;color:#d7e0ff;border-radius:8px;padding:10px;"><?=h($preview)?></pre>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

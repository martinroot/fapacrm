<?php
declare(strict_types=1);

/**
 * /cmd/pages/wall_money.php
 *
 * Fullscreen tablet wallboard: "Доход / Расход (10м, факт)"
 * - Standalone HTML (no menu)
 * - Uses CRM DB via config/helpers auto-bootstrap
 * - Data: crm_finance_ticks SNAPSHOT (last tick in 10-min bucket), summed across domains
 * - Auto refresh via AJAX every 120 seconds
 *
 * Query:
 *   ?d=YYYY-MM-DD
 *   ?domain_id=0|ID
 *   ?ajax=1  -> JSON response {labels, rev, cost, updated_at, day, domain_id}
 */

/* ---------------- bootstrap (auto-include CRM config/helpers) ---------------- */

function wall_try_require(string $path): bool {
  if ($path === '') return false;
  if (!is_file($path)) return false;
  require_once $path;
  return true;
}

function wall_bootstrap(): array {
  // Try to find CRM bootstrap/config in common places relative to /cmd/pages/
  $base = realpath(__DIR__ . '/..'); // /cmd
  $tries = [];

  if ($base) {
    // common: /cmd/config.php
    $tries[] = $base . '/config.php';

    // sometimes: /cmd/config.user.php (rare)
    $tries[] = $base . '/config.user.php';

    // common helpers: /cmd/lib/helpers.php or /cmd/lib/helper.php
    $tries[] = $base . '/lib/helpers.php';
    $tries[] = $base . '/lib/helper.php';

    // some projects keep helpers in /cmd/inc/...
    $tries[] = $base . '/inc/helpers.php';
    $tries[] = $base . '/inc/helper.php';

    // some keep DB in /cmd/db.php
    $tries[] = $base . '/db.php';
  }

  // As a last resort, walk up a few levels looking for cmd/config.php
  $walk = __DIR__;
  for ($i=0; $i<5; $i++) {
    $cand = realpath($walk . '/../cmd/config.php');
    if ($cand && is_file($cand)) $tries[] = $cand;
    $walk = realpath($walk . '/..') ?: $walk;
  }

  $loaded = [];
  foreach ($tries as $p) {
    if (isset($loaded[$p])) continue;
    $loaded[$p] = true;
    @wall_try_require($p);
  }

  $ok = function_exists('db_connect') && function_exists('db_all');
  return [
    'ok' => $ok,
    'tried' => array_keys($loaded),
    'base' => $base ?: '',
  ];
}

$boot = wall_bootstrap();

/* ---------------- minimal fallbacks (only if CRM helpers not loaded) ---------------- */

if (!function_exists('req_str')) {
  function req_str(string $k, string $def=''): string { return isset($_GET[$k]) ? (string)$_GET[$k] : $def; }
}
if (!function_exists('req_int')) {
  function req_int(string $k, int $def=0): int { return isset($_GET[$k]) ? (int)$_GET[$k] : $def; }
}
if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
}

// timezone as in your CRM pages
if (!ini_get('date.timezone')) {
  date_default_timezone_set('Europe/Moscow');
}

if (!$boot['ok']) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "wall_money.php: CRM helpers not found.\n\n";
  echo "Expected functions: db_connect(), db_all()\n\n";
  echo "Put this file into: /cmd/pages/ (inside your CRM)\n";
  echo "Tried require:\n";
  foreach ($boot['tried'] as $p) echo " - {$p}\n";
  exit;
}

/* ---------------- optional csrf_check if present (not required for read-only) ---------------- */
if (function_exists('csrf_check')) {
  @csrf_check();
}

/* ---------------- params ---------------- */
$day       = req_str('d', date('Y-m-d'));
$domain_id = req_int('domain_id', 0);
$ajax      = (req_int('ajax', 0) === 1);

$db = db_connect();

/* ---------------- data function ---------------- */
function wall_money_data(mysqli $db, string $day, int $domain_id): array
{
  $sql = "
    SELECT
      x.bucket_ts,
      t.kind,
      t.amount,
      t.ts
    FROM crm_finance_ticks t
    JOIN (
      SELECT
        FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/600)*600) AS bucket_ts,
        kind, domain_id, connector, external_id,
        MAX(ts) AS ts
      FROM crm_finance_ticks
      WHERE d=?
        AND kind IN ('cost','revenue')
        " . ($domain_id > 0 ? "AND domain_id=".(int)$domain_id : "") . "
      GROUP BY bucket_ts, kind, domain_id, connector, external_id
    ) x
      ON x.bucket_ts   = FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/600)*600)
     AND x.kind        = t.kind
     AND x.domain_id   = t.domain_id
     AND x.connector   = t.connector
     AND x.external_id = t.external_id
     AND x.ts          = t.ts
    ORDER BY x.bucket_ts ASC, t.kind ASC
  ";

  $rows = db_all($db, $sql, [$day]);

  $b = [];     // bucket => ['rev'=>0,'cost'=>0]
  $last_ts = ''; // latest tick ts among selected

  foreach ($rows as $r) {
    $bucket = (string)$r['bucket_ts'];
    if (!isset($b[$bucket])) $b[$bucket] = ['rev'=>0.0,'cost'=>0.0];

    $kind = (string)$r['kind'];
    $amt  = (float)$r['amount'];
    $ts   = (string)$r['ts'];

    if ($kind === 'revenue') $b[$bucket]['rev']  += $amt;
    if ($kind === 'cost')    $b[$bucket]['cost'] += $amt;

    if ($ts !== '' && ($last_ts === '' || $ts > $last_ts)) $last_ts = $ts;
  }

  $labels = [];
  $rev = [];
  $cost = [];

  foreach ($b as $bucket => $v) {
    $labels[] = date('H:i', strtotime($bucket));
    $rev[]    = round((float)$v['rev'],  6);
    $cost[]   = round((float)$v['cost'], 6);
  }

  return [
    'labels' => $labels,
    'rev' => $rev,
    'cost' => $cost,
    'updated_at' => $last_ts ? date('H:i:s', strtotime($last_ts)) : '—',
    'day' => $day,
    'domain_id' => $domain_id,
  ];
}

/* ---------------- ajax json ---------------- */
if ($ajax) {
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(wall_money_data($db, $day, $domain_id), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

$init = wall_money_data($db, $day, $domain_id);

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>Доход / Расход (10м, факт)</title>
  <style>
    :root{
      --bg1:#0b1220;
      --bg2:#081726;
      --card: rgba(255,255,255,.06);
      --bd: rgba(255,255,255,.10);
      --fg:#eaf2ff;
      --mut: rgba(234,242,255,.65);
      --err:#ffb4b4;
    }
    *{box-sizing:border-box}
    html,body{height:100%;margin:0;background:
      radial-gradient(1200px 800px at 15% 10%, #133b5c 0%, transparent 55%),
      radial-gradient(1200px 800px at 90% 40%, #1a5b42 0%, transparent 50%),
      linear-gradient(180deg,var(--bg1),var(--bg2));
      font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:var(--fg)
    }
    .wrap{height:100%;display:flex;flex-direction:column;padding:14px;gap:12px}
    .top{
      display:flex;align-items:center;justify-content:space-between;gap:10px;
      padding:10px 14px;border:1px solid var(--bd);background:var(--card);
      border-radius:16px;backdrop-filter: blur(10px);-webkit-backdrop-filter: blur(10px);
    }
    .ttl{font-weight:900;font-size:18px;letter-spacing:.2px}
    .sub{color:var(--mut);font-size:13px;display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
    .pill{padding:4px 10px;border-radius:999px;border:1px solid var(--bd);background:rgba(0,0,0,.20);color:var(--mut)}
    .card{
      flex:1;min-height:0;
      border:1px solid var(--bd);background:var(--card);
      border-radius:18px;backdrop-filter: blur(10px);-webkit-backdrop-filter: blur(10px);
      padding:12px;
      display:flex;flex-direction:column;gap:10px;
    }
    .hint{color:var(--mut);font-size:12px}
    .canvasBox{flex:1;min-height:0;position:relative}
    canvas{width:100% !important;height:100% !important}
    .err{color:var(--err);font-size:12px;white-space:pre-wrap}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div class="ttl">💸 Доход / Расход (10м, факт)</div>
      <div class="sub">
        <span class="pill">Day: <b><?=h($init['day'])?></b></span>
        <span class="pill">Domain: <b><?=h((string)$init['domain_id'])?></b></span>
        <span class="pill">Updated: <b id="updatedAt"><?=h($init['updated_at'])?></b></span>
        <span class="pill">Auto refresh: <b>120s</b></span>
      </div>
    </div>

    <div class="card">
      <div class="hint">crm_finance_ticks → последний тик в 10-мин бакете (сумма по доменам)</div>
      <div class="canvasBox"><canvas id="chart"></canvas></div>
      <div id="err" class="err"></div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
    const INIT = <?=json_encode($init, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
    const errEl = document.getElementById('err');
    const updatedEl = document.getElementById('updatedAt');

    const chart = new Chart(document.getElementById('chart'), {
      type: 'line',
      data: {
        labels: INIT.labels,
        datasets: [
          { label: 'Revenue', data: INIT.rev,  borderWidth: 3, tension: 0.25, pointRadius: 0 },
          { label: 'Cost',    data: INIT.cost, borderWidth: 3, tension: 0.25, pointRadius: 0 }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode:'index', intersect:false },
        plugins: {
          legend: {
            display: true,
            labels: { color: 'rgba(234,242,255,.85)', boxWidth: 14, boxHeight: 14 }
          },
          tooltip: {
            enabled: true,
            callbacks: {
              label: (ctx) => `${ctx.dataset.label}: ${Number(ctx.raw ?? 0).toFixed(6)}`
            }
          }
        },
        scales: {
          x: {
            ticks: { color: 'rgba(234,242,255,.65)', maxRotation: 0, autoSkip: true },
            grid: { color: 'rgba(255,255,255,.06)' }
          },
          y: {
            beginAtZero: true,
            ticks: {
              color: 'rgba(234,242,255,.65)',
              callback: (v) => Number(v).toFixed(4)
            },
            grid: { color: 'rgba(255,255,255,.06)' }
          }
        }
      }
    });

    function qs(obj){
      const p = new URLSearchParams(obj);
      return p.toString();
    }

    async function refresh(){
      errEl.textContent = '';
      try{
        const url = location.pathname + '?' + qs({
          d: '<?=h($day)?>',
          domain_id: <?= (int)$domain_id ?>,
          ajax: 1,
          _t: Date.now()
        });

        const r = await fetch(url, { cache:'no-store' });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const j = await r.json();

        chart.data.labels = j.labels || [];
        chart.data.datasets[0].data = j.rev || [];
        chart.data.datasets[1].data = j.cost || [];
        chart.update('none');

        updatedEl.textContent = j.updated_at || '—';
      } catch(e){
        errEl.textContent = 'Refresh error: ' + (e && e.message ? e.message : String(e));
      }
    }

    setInterval(refresh, 20000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  </script>
</body>
</html>

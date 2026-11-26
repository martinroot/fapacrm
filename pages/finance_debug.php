<?php
declare(strict_types=1);

/**
 * pages/finance_charts.php
 *
 * ОДНА СТРАНИЦА: графики 10-минуток по ВСЕМ доменам, БЕЗ таблицы.
 * Для каждого домена выводим 2 квадрата (glass):
 *  - 👥 Посетители (10м, факт)  -> kind='metric' (meta_json.visits либо amount)
 *  - 💸 Доход / Расход (10м, факт) -> kind='revenue' / kind='cost' (amount)
 *
 * Источник: crm_finance_ticks (SNAPSHOT значения, НЕ cumulative)
 * В каждом 10-мин бакете берём последний ts для каждой комбинации:
 *   bucket, kind, domain_id, connector, external_id
 * Потом суммируем по домену в бакете.
 *
 * Requires CRM helpers:
 *   csrf_check(), db_connect(), db_all(), req_str(), h()
 */

csrf_check();
$db = db_connect();

$day = req_str('d', date('Y-m-d'));

function money_fmt($x, int $dec=6): string { return number_format((float)$x, $dec, '.', ' '); }

echo '<style>
:root{--fg:#111;--mut:#6b7280;--bd:rgba(0,0,0,.08)}
.wrap{max-width:1800px}
.rowTop{display:flex;justify-content:space-between;align-items:center;margin:10px 0 10px 0;gap:12px;flex-wrap:wrap}
.small-muted{color:#777;font-size:12px}
a.btn{display:inline-block;padding:2px 6px;border-radius:6px;border:1px solid #888;font-size:11px;text-decoration:none;color:#004;background:#f0f3ff;}
a.btn:hover{background:#dde4ff;}
.hr{height:1px;background:rgba(0,0,0,.08);margin:14px 0}

.glass{
  border:1px solid rgba(255,255,255,.35);
  background:linear-gradient(180deg, rgba(255,255,255,.72), rgba(255,255,255,.48));
  box-shadow:0 10px 30px rgba(0,0,0,.08);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-radius:16px;
  padding:14px 14px;
  overflow:hidden;
}
.glass h4{margin:0 0 10px 0;font-size:14px;font-weight:900;color:var(--fg);display:flex;justify-content:space-between;gap:10px;align-items:center}
.kpis{display:flex;gap:10px;flex-wrap:wrap;margin:6px 0 10px 0}
.kpi{border:1px solid var(--bd);border-radius:12px;padding:6px 8px;background:rgba(255,255,255,.36);font-size:12px}
.kpi b{font-size:12px}

.grid2{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:10px;
  align-items:stretch;
}
@media (max-width:1200px){ .grid2{grid-template-columns:1fr;} }

/* КВАДРАТНЫЙ canvas */
.canvasBox{
  position: relative;
  width: 100%;
  aspect-ratio: 1 / 1;
}
.canvasBox canvas{
  position:absolute !important;
  inset:0;
  width:100% !important;
  height:100% !important;
}
</style>';

echo '<div class="wrap">';
echo '<div class="rowTop">';
echo '<h3 style="margin:0">📊 Графики (10м) по всем доменам</h3>';
echo '<div class="small-muted">День: <b>'.h($day).'</b> | <a class="btn" href="menu.php?page=finance&d='.h($day).'">💰 Финансы</a></div>';
echo '</div>';

echo '<form method="get" style="margin:8px 0 12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
echo '<input type="hidden" name="page" value="finance_charts">';
echo '<input type="date" name="d" value="'.h($day).'" class="form-control" style="max-width:180px">';
echo '<button class="btn btn-primary">Показать</button>';
echo '</form>';

$domains = db_all($db, "SELECT id, domain FROM crm_domains WHERE status='live' ORDER BY domain");
if (!$domains) {
  echo '<div class="glass"><div class="small-muted">Нет доменов в crm_domains.</div></div>';
  echo '</div>';
  return;
}

/**
 * Берём последний тик в 10-минутном бакете по каждой комбинации:
 * bucket, kind, domain_id, connector, external_id
 */
$bucket_rows = db_all($db, "
  SELECT
    FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/600)*600) AS bucket_ts,
    t.kind,
    t.domain_id,
    t.connector,
    t.external_id,
    t.amount,
    t.meta_json,
    t.ts
  FROM crm_finance_ticks t
  JOIN (
    SELECT
      FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/600)*600) AS bucket_ts,
      kind, domain_id, connector, external_id,
      MAX(ts) AS ts
    FROM crm_finance_ticks
    WHERE d=?
      AND kind IN ('cost','revenue','metric')
      AND domain_id IN (SELECT id FROM crm_domains WHERE status='live')
    GROUP BY bucket_ts, kind, domain_id, connector, external_id
  ) x
    ON x.bucket_ts = FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/600)*600)
   AND x.kind = t.kind
   AND x.domain_id = t.domain_id
   AND x.connector = t.connector
   AND x.external_id = t.external_id
   AND x.ts = t.ts
  ORDER BY t.domain_id, bucket_ts, t.kind
", [$day]);

if (!$bucket_rows) {
  echo '<div class="glass"><div class="small-muted">Нет данных в crm_finance_ticks за этот день.</div></div>';
  echo '</div>';
  return;
}

/**
 * Суммируем по домену внутри бакета:
 * $byDom[did][bucket] = ['cost'=>..., 'rev'=>..., 'visits'=>...]
 */
$byDom = [];
$buckets = [];

foreach ($bucket_rows as $r) {
  $did    = (int)$r['domain_id'];
  $bucket = (string)$r['bucket_ts'];
  $kind   = (string)$r['kind'];

  $buckets[$bucket] = true;

  if (!isset($byDom[$did])) $byDom[$did] = [];
  if (!isset($byDom[$did][$bucket])) $byDom[$did][$bucket] = ['cost'=>0.0,'rev'=>0.0,'visits'=>0.0];

  if ($kind === 'cost') {
    $byDom[$did][$bucket]['cost'] += (float)$r['amount'];
    continue;
  }
  if ($kind === 'revenue') {
    $byDom[$did][$bucket]['rev'] += (float)$r['amount'];
    continue;
  }

  // metric
  $meta = json_decode((string)($r['meta_json'] ?? ''), true);
  if (!is_array($meta)) $meta = [];
  $vis = (float)($meta['visits'] ?? ($r['amount'] ?? 0));
  $byDom[$did][$bucket]['visits'] += $vis;
}

$bucketList = array_keys($buckets);
sort($bucketList);

$labels = [];
foreach ($bucketList as $bt) $labels[] = date('H:i', strtotime($bt));

/**
 * SERIES[did] = { visits:[], cost:[], rev:[] }
 * Пустые бакеты -> null (чтоб не “пилило” нулями)
 */
$SERIES = [];
foreach ($domains as $d) {
  $did = (int)$d['id'];

  $visits = [];
  $cost = [];
  $rev  = [];

  foreach ($bucketList as $bt) {
    $v = $byDom[$did][$bt] ?? ['cost'=>0.0,'rev'=>0.0,'visits'=>0.0];
    $hasAny = ((float)$v['visits'] != 0.0) || ((float)$v['cost'] != 0.0) || ((float)$v['rev'] != 0.0);

    $visits[] = $hasAny ? round((float)$v['visits'], 2) : null;
    $cost[]   = $hasAny ? round((float)$v['cost'],   6) : null;
    $rev[]    = $hasAny ? round((float)$v['rev'],    6) : null;
  }

  $SERIES[(string)$did] = ['visits'=>$visits,'cost'=>$cost,'rev'=>$rev];
}

echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
echo '<script>const LABELS='.json_encode($labels).'; const SERIES='.json_encode($SERIES).';</script>';

foreach ($domains as $d) {
  $did = (int)$d['id'];
  $dom = (string)$d['domain'];

  $idMetric = 'chartMetric_'.$did;
  $idMoney  = 'chartMoney_'.$did;

  // дневные итоги по домену (сумма бакетов)
  $sumV = 0.0; $sumC = 0.0; $sumR = 0.0;
  foreach ($bucketList as $bt) {
    if (!isset($byDom[$did][$bt])) continue;
    $sumV += (float)$byDom[$did][$bt]['visits'];
    $sumC += (float)$byDom[$did][$bt]['cost'];
    $sumR += (float)$byDom[$did][$bt]['rev'];
  }
  $roi = ($sumC > 0.0) ? (($sumR - $sumC) / $sumC * 100.0) : 0.0;

  echo '<div class="glass">';
  echo '<h4><span>🌐 '.h($dom).'</span><span class="small-muted">Visits: <b>'.h(money_fmt($sumV,0)).'</b> | Cost: <b>'.h(money_fmt($sumC,6)).'</b> | Rev: <b>'.h(money_fmt($sumR,6)).'</b> | ROI: <b>'.h(number_format($roi,2,'.',' ')).'%</b></span></h4>';

  echo '<div class="grid2">';

  echo '<div class="glass" style="padding:12px">';
  echo '<h4>👥 Посетители (10м, факт)</h4>';
  echo '<div class="canvasBox"><canvas id="'.h($idMetric).'"></canvas></div>';
  echo '</div>';

  echo '<div class="glass" style="padding:12px">';
  echo '<h4>💸 Доход / Расход (10м, факт)</h4>';
  echo '<div class="canvasBox"><canvas id="'.h($idMoney).'"></canvas></div>';
  echo '</div>';

  echo '</div>'; // grid2
  echo '</div>'; // domain glass

  echo '<div class="hr"></div>';
}

echo '<script>
function drawForDomain(did){
  const s = SERIES[String(did)];
  if(!s) return;

  const elM = document.getElementById("chartMetric_"+did);
  if(elM){
    new Chart(elM, {
      type:"line",
      data:{ labels: LABELS, datasets:[{ label:"Visits", data:s.visits, borderWidth:2, tension:0.25, pointRadius:0 }] },
      options:{
        responsive:true,
        maintainAspectRatio:false,
        plugins:{legend:{display:false}},
        interaction:{mode:"index",intersect:false},
        scales:{y:{beginAtZero:true}}
      }
    });
  }

  const el$ = document.getElementById("chartMoney_"+did);
  if(el$){
    new Chart(el$, {
      type:"line",
      data:{ labels: LABELS, datasets:[
        { label:"Revenue", data:s.rev,  borderWidth:2, tension:0.25, pointRadius:0 },
        { label:"Cost",    data:s.cost, borderWidth:2, tension:0.25, pointRadius:0 }
      ]},
      options:{
        responsive:true,
        maintainAspectRatio:false,
        plugins:{legend:{display:true}},
        interaction:{mode:"index",intersect:false},
        scales:{y:{beginAtZero:true}}
      }
    });
  }
}

Object.keys(SERIES).forEach(did => drawForDomain(did));
</script>';

echo '</div>'; // wrap

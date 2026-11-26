<?php
declare(strict_types=1);
/**
 * pages/revenue.php — ЖИВОЙ график ревенью по минутам.
 * Ревенью-тики кумулятивны за день по (domain_id, external_id) => чтобы получить
 * суммарный «сколько накопили к моменту T», forward-fill: в каждую минуту берём
 * последнее известное значение каждой серии и суммируем. Линия растёт в динамике.
 * Ниже тем же способом расход и трафик — ось минут у всех трёх общая, чтобы
 * можно было сопоставлять: подняли расход — пошёл ли доход.
 * ?net=mybid|taco|all (default all). Автообновление каждые 20с.
 */
$db = db_connect();

$net = $_GET['net'] ?? 'all';
$connFilter = $net === 'mybid' ? "AND connector LIKE 'mybid%'"
            : ($net === 'taco' ? "AND connector='tacoloco_revenue'" : "");

/* минутные максимумы кумулятива по каждой серии (domain|external_id|connector) */
$rows = $db->query("
  SELECT DATE_FORMAT(ts,'%H:%i') mm,
         CONCAT(domain_id,'|',external_id,'|',connector) k,
         connector conn,
         MAX(amount) a
  FROM crm_finance_ticks
  WHERE kind='revenue' AND d=CURDATE() $connFilter
  GROUP BY mm, k, conn
  ORDER BY mm");

$series = [];           // k => [mm => cumulative]
$connOf = [];           // k => connector
$minsSet = [];
while ($rows && ($x = $rows->fetch_assoc())) {
  $series[$x['k']][$x['mm']] = (float)$x['a'];
  $connOf[$x['k']] = $x['conn'];
  $minsSet[$x['mm']] = true;
}

/* расход: тики кумулятивны так же, но серия — кампания (external_id) */
$spendSeries = [];
$rs = $db->query("
  SELECT DATE_FORMAT(ts,'%H:%i') mm, external_id k, MAX(amount) a
  FROM crm_finance_ticks
  WHERE kind='cost' AND d=CURDATE()
  GROUP BY mm, k
  ORDER BY mm");
while ($rs && ($x = $rs->fetch_assoc())) {
  $spendSeries[$x['k']][$x['mm']] = (float)$x['a'];
  $minsSet[$x['mm']] = true;
}

/* трафик: клики по минутам из журналов сайтов — величина не накопительная */
$clickDbs = [];
$q = $db->query("SELECT db_name FROM crm_domains WHERE status='live' AND db_name<>''");
while ($q && ($x = $q->fetch_row()))
  if (@$db->query("SELECT 1 FROM `{$x[0]}`.clicks LIMIT 1")) $clickDbs[] = $x[0];

$clickPerMin = [];
if ($clickDbs) {
  $parts = [];
  foreach ($clickDbs as $d)
    $parts[] = "SELECT ts FROM `$d`.clicks WHERE DATE(ts)=CURDATE()";
  $qc = $db->query("SELECT DATE_FORMAT(ts,'%H:%i') mm, COUNT(*) n
                      FROM (" . implode(' UNION ALL ', $parts) . ") u
                     GROUP BY mm ORDER BY mm");
  while ($qc && ($x = $qc->fetch_assoc())) {
    $clickPerMin[$x['mm']] = (int)$x['n'];
    $minsSet[$x['mm']] = true;
  }
}

/* полная ось минут от первого тика до текущей минуты (шаг 1 мин) */
$labels = [];
if ($minsSet) {
  ksort($minsSet);
  $allm = array_keys($minsSet);
  $start = $allm[0];
  $nowm = date('H:i');
  $end = $nowm > end($allm) ? $nowm : end($allm);
  [$sh,$sm] = array_map('intval', explode(':', $start));
  [$eh,$em] = array_map('intval', explode(':', $end));
  for ($t = $sh*60+$sm; $t <= $eh*60+$em; $t++)
    $labels[] = sprintf('%02d:%02d', intdiv($t,60), $t%60);
}

/* forward-fill каждой серии по общей оси, суммируем в total + по сетям */
$total = array_fill(0, count($labels), 0.0);
$byMy  = array_fill(0, count($labels), 0.0);
$byTc  = array_fill(0, count($labels), 0.0);
foreach ($series as $k => $pts) {
  $last = 0.0;
  foreach ($labels as $i => $mm) {
    if (isset($pts[$mm])) $last = $pts[$mm];
    $total[$i] += $last;
    if ($connOf[$k] === 'tacoloco_revenue') $byTc[$i] += $last; else $byMy[$i] += $last;
  }
}

/* расход — тем же forward-fill, ось та же */
$spend = array_fill(0, count($labels), 0.0);
foreach ($spendSeries as $pts) {
  $last = 0.0;
  foreach ($labels as $i => $mm) {
    if (isset($pts[$mm])) $last = $pts[$mm];
    $spend[$i] += $last;
  }
}

/* трафик: клики в минуту и накопленным итогом */
$clicks = []; $clicksCum = []; $acc = 0;
foreach ($labels as $mm) {
  $n = $clickPerMin[$mm] ?? 0;
  $acc += $n;
  $clicks[]    = $n;
  $clicksCum[] = $acc;
}

$cur   = $labels ? end($total) : 0.0;
$curMy = $labels ? end($byMy) : 0.0;
$curTc = $labels ? end($byTc) : 0.0;
$curSp = $labels ? end($spend) : 0.0;
$curCl = $labels ? end($clicksCum) : 0;
$profit = $cur - $curSp;
$roi    = $curSp > 0 ? ($cur - $curSp) / $curSp * 100 : 0.0;
/* прирост за последние 10 минут */
$n = count($total);
$d10 = $n > 10 ? $cur - $total[$n-11] : $cur;
$sp10 = $n > 10 ? $curSp - $spend[$n-11] : $curSp;

$J = fn($a) => json_encode(array_map(fn($v)=>round((float)$v,4), $a));
?>
<div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
  <div>
    <h3 class="mb-0">📈 Ревенью — живая динамика</h3>
    <div class="text-muted small">Кумулятив за сегодня по минутам · forward-fill по всем сайтам · время серверное (UTC) · автообновление 20с</div>
  </div>
  <div class="btn-group" role="group">
    <a class="btn btn-sm <?=$net==='all'?'btn-dark':'btn-outline-dark'?>"  href="menu.php?page=revenue&net=all">Все</a>
    <a class="btn btn-sm <?=$net==='mybid'?'btn-dark':'btn-outline-dark'?>" href="menu.php?page=revenue&net=mybid">MyBid</a>
    <a class="btn btn-sm <?=$net==='taco'?'btn-dark':'btn-outline-dark'?>"  href="menu.php?page=revenue&net=taco">TacoLoco</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card shadow-sm border-0"><div class="card-body py-3">
      <div class="text-muted small">Доход сегодня</div>
      <div style="font-size:2.2rem;font-weight:800;line-height:1;color:#16a34a">$<?=number_format($cur,4)?></div>
      <div class="text-muted small mt-1">+ за 10 мин $<?=number_format($d10,4)?></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm border-0"><div class="card-body py-3">
      <div class="text-muted small">Расход сегодня</div>
      <div style="font-size:2.2rem;font-weight:800;line-height:1;color:#dc2626">$<?=number_format($curSp,4)?></div>
      <div class="text-muted small mt-1">+ за 10 мин $<?=number_format($sp10,4)?></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm border-0"><div class="card-body py-3">
      <div class="text-muted small">Профит</div>
      <div style="font-size:2.2rem;font-weight:800;line-height:1;color:<?=$profit>=0?'#16a34a':'#dc2626'?>">
        <?=$profit>=0?'+':'−'?>$<?=number_format(abs($profit),4)?>
      </div>
      <div class="text-muted small mt-1">ROI <?=$curSp>0?sprintf('%+.0f%%',$roi):'—'?></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm border-0"><div class="card-body py-3">
      <div class="text-muted small">Кликов сегодня</div>
      <div style="font-size:2.2rem;font-weight:800;line-height:1;color:#0ea5e9"><?=number_format($curCl)?></div>
      <div class="text-muted small mt-1">MyBid $<?=number_format($curMy,3)?> · Taco $<?=number_format($curTc,3)?></div>
    </div></div>
  </div>
</div>

<div class="card shadow-sm border-0 mb-3"><div class="card-body">
  <div class="text-muted small mb-1">Доход, накопленным итогом</div>
  <canvas id="revChart" height="110"></canvas>
</div></div>

<div class="card shadow-sm border-0 mb-3"><div class="card-body">
  <div class="text-muted small mb-1">Расход, накопленным итогом — та же ось времени</div>
  <canvas id="spendChart" height="110"></canvas>
</div></div>

<div class="card shadow-sm border-0"><div class="card-body">
  <div class="text-muted small mb-1">Трафик: кликов в минуту — та же ось времени</div>
  <canvas id="trafChart" height="110"></canvas>
</div></div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const labels = <?=json_encode($labels)?>;
const total  = <?=$J($total)?>;
const spend  = <?=$J($spend)?>;
const clicks = <?=$J($clicks)?>;
const showSplit = <?=$net==='all'?'true':'false'?>;

/* общие настройки: ось X одинаковая у всех трёх, чтобы моменты совпадали */
const baseOpts = (yfmt) => ({
  responsive:true, maintainAspectRatio:true, animation:false,
  interaction:{intersect:false, mode:'index'},
  plugins:{ legend:{display:false},
            tooltip:{callbacks:{label:c=>c.dataset.label+': '+yfmt(c.parsed.y)}} },
  scales:{
    x:{ ticks:{maxTicksLimit:14, autoSkip:true}, grid:{display:false} },
    y:{ beginAtZero:true, ticks:{callback:yfmt} }
  }
});
const money = v => '$' + Number(v).toFixed(3);
const plain = v => Number(v).toLocaleString('ru-RU');

const gradient = (id, rgb) => {
  const ctx = document.getElementById(id).getContext('2d');
  const g = ctx.createLinearGradient(0,0,0,320);
  g.addColorStop(0, `rgba(${rgb},.35)`); g.addColorStop(1, `rgba(${rgb},0)`);
  return [ctx, g];
};

/* доход */
const [rctx, rg] = gradient('revChart','22,163,74');
const rds = [{ label:'Всего $', data: total, borderColor:'#16a34a', backgroundColor:rg,
               fill:true, tension:.25, pointRadius:0, borderWidth:3 }];
if (showSplit) {
  rds.push({label:'MyBid $', data: <?=$J($byMy)?>, borderColor:'#6366f1', fill:false, tension:.25, pointRadius:0, borderWidth:1.5, borderDash:[4,3]});
  rds.push({label:'Taco $',  data: <?=$J($byTc)?>, borderColor:'#f59e0b', fill:false, tension:.25, pointRadius:0, borderWidth:1.5, borderDash:[4,3]});
}
const revOpts = baseOpts(money);
revOpts.plugins.legend.display = showSplit;
new Chart(rctx, { type:'line', data:{ labels, datasets: rds }, options: revOpts });

/* расход, для сравнения рядом полупрозрачная линия дохода */
const [sctx, sg] = gradient('spendChart','220,38,38');
new Chart(sctx, {
  type:'line',
  data:{ labels, datasets:[
    { label:'Расход $', data: spend, borderColor:'#dc2626', backgroundColor:sg,
      fill:true, tension:.25, pointRadius:0, borderWidth:3 },
    { label:'Доход $', data: total, borderColor:'rgba(22,163,74,.45)', fill:false,
      tension:.25, pointRadius:0, borderWidth:1.5, borderDash:[4,3] }
  ]},
  options: (() => { const o = baseOpts(money); o.plugins.legend.display = true; return o; })()
});

/* трафик */
const [tctx, tg] = gradient('trafChart','14,165,233');
new Chart(tctx, {
  type:'bar',
  data:{ labels, datasets:[
    { label:'Кликов в минуту', data: clicks, backgroundColor:'rgba(14,165,233,.55)',
      borderColor:'#0ea5e9', borderWidth:0, barPercentage:1, categoryPercentage:1 }
  ]},
  options: baseOpts(plain)
});

setTimeout(()=>location.reload(), 20000);
</script>

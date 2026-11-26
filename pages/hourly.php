<?php
declare(strict_types=1);

/**
 * pages/hourly.php — разбивка текущих суток по часам.
 *
 * Сетки отдают не почасовые цифры, а накопительный счётчик за сутки: каждый
 * опрос — это снимок «сколько всего с начала дня». Поэтому час считается как
 * разница между последним снимком внутри часа и последним снимком предыдущего
 * часа, отдельно по каждой кампании и площадке. Складывать тики нельзя —
 * получилось бы умножение на число опросов.
 *
 * Показов по часам нет ни у одной из сеток: их API отдают только дневную
 * разбивку, поэтому колонки показов здесь нет — врать нулями хуже, чем не
 * показывать. Клики, визиты и глубина берутся из наших же баз, где у каждого
 * события есть своя отметка времени, так что там разбивка честная.
 */

csrf_check();
$db = db_connect();

$day = req_str('day', '') === 'prev'
     ? date('Y-m-d', strtotime('-1 day'))
     : date('Y-m-d');
$isToday = $day === date('Y-m-d');

/* Кеш короткий: страница про текущие сутки, её открывают, чтобы увидеть
   последний час, а не десятиминутной давности картинку. */
$hrl_dir = dirname(__DIR__) . '/var/cache';
if (!is_dir($hrl_dir)) @mkdir($hrl_dir, 0775, true);
$hrl_cache = $hrl_dir . "/hourly_{$day}.json";
$hrl_ttl   = 120;
$hrl_force = req_str('refresh', '') === '1';

$hours = null;
if (!$hrl_force && is_file($hrl_cache) && (time() - filemtime($hrl_cache)) < $hrl_ttl) {
    $cached = json_decode((string)file_get_contents($hrl_cache), true);
    if (is_array($cached) && $cached) $hours = $cached;
}
$hrl_age = is_file($hrl_cache) ? time() - filemtime($hrl_cache) : null;

if ($hours === null) {
    $hours = [];
    for ($h = 0; $h < 24; $h++)
        $hours[$h] = ['spend'=>0.0, 'revenue'=>0.0, 'bought'=>0,
                      'landed'=>0, 'live'=>0, 'visits'=>0, 'events'=>0, 'sites'=>[]];

    $dayQ = $db->real_escape_string($day);

    /* расход: последний снимок каждого часа по каждой кампании, час = разница
       с предыдущим её снимком */
    $res = $db->query("
        SELECT HOUR(t.ts) AS h, t.external_id AS eid, t.domain_id AS did, t.amount AS amt,
               CAST(JSON_EXTRACT(t.meta_json,'$.clicks') AS UNSIGNED) AS cl
          FROM crm_finance_ticks t
          JOIN (SELECT HOUR(ts) AS h, external_id, MAX(ts) AS mx
                  FROM crm_finance_ticks
                 WHERE kind='cost' AND d='{$dayQ}'
                 GROUP BY HOUR(ts), external_id) x
            ON x.external_id = t.external_id AND x.mx = t.ts
         WHERE t.kind='cost' AND t.d='{$dayQ}'
         ORDER BY t.external_id, h");
    $prev = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $eid = (string)$r['eid']; $h = (int)$r['h'];
        $amt = (float)$r['amt']; $cl = (int)$r['cl'];
        $da = $amt - ($prev[$eid]['a'] ?? 0.0);
        $dc = $cl  - ($prev[$eid]['c'] ?? 0);
        /* счётчик кампании обнулили или пересоздали — берём значение как есть,
           иначе час ушёл бы в минус */
        if ($da < 0) $da = $amt;
        if ($dc < 0) $dc = $cl;
        $prev[$eid] = ['a'=>$amt, 'c'=>$cl];
        $hours[$h]['spend']  += $da;
        $hours[$h]['bought'] += $dc;
        if ($da > 0) $hours[$h]['sites'][(string)$r['did']] = true;
    }

    /* доход: тот же приём, но ключ — площадка внутри домена */
    $res = $db->query("
        SELECT HOUR(t.ts) AS h, t.external_id AS eid, t.domain_id AS did, t.amount AS amt
          FROM crm_finance_ticks t
          JOIN (SELECT HOUR(ts) AS h, external_id, domain_id, MAX(ts) AS mx
                  FROM crm_finance_ticks
                 WHERE kind='revenue' AND d='{$dayQ}'
                 GROUP BY HOUR(ts), external_id, domain_id) x
            ON x.external_id = t.external_id AND x.domain_id = t.domain_id AND x.mx = t.ts
         WHERE t.kind='revenue' AND t.d='{$dayQ}'
         ORDER BY t.external_id, t.domain_id, h");
    $prev = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $k = $r['eid'] . '|' . $r['did']; $h = (int)$r['h'];
        $amt = (float)$r['amt'];
        $da = $amt - ($prev[$k] ?? 0.0);
        if ($da < 0) $da = $amt;
        $prev[$k] = $amt;
        $hours[$h]['revenue'] += $da;
    }

    /* наши собственные базы: у кликов и событий есть своё время, тут разбивка
       прямая, без всяких разниц */
    $clickDbs = $eventDbs = [];
    $q = $db->query("SELECT db_name FROM crm_domains WHERE status='live' AND db_name<>''");
    while ($q && ($x = $q->fetch_row())) {
        if (@$db->query("SELECT 1 FROM `{$x[0]}`.clicks LIMIT 1"))      $clickDbs[] = $x[0];
        if (@$db->query("SELECT 1 FROM `{$x[0]}`.user_events LIMIT 1")) $eventDbs[] = $x[0];
    }

    if ($clickDbs) {
        $parts = [];
        foreach ($clickDbs as $d)
            $parts[] = "SELECT HOUR(ts) h, COUNT(*) n, SUM(CASE WHEN js>0 THEN 1 ELSE 0 END) l
                          FROM `$d`.clicks WHERE DATE(ts)='{$dayQ}' GROUP BY HOUR(ts)";
        $res = @$db->query("SELECT h, SUM(n) n, SUM(l) l FROM ("
                          . implode(' UNION ALL ', $parts) . ") u GROUP BY h");
        while ($res && ($r = $res->fetch_assoc())) {
            $h = (int)$r['h'];
            $hours[$h]['landed'] = (int)$r['n'];
            $hours[$h]['live']   = (int)$r['l'];
        }
    }

    if ($eventDbs) {
        $parts = [];
        foreach ($eventDbs as $d)
            $parts[] = "SELECT HOUR(ts) h, COUNT(*) ev, COUNT(DISTINCT uiq) vis
                          FROM `$d`.user_events WHERE DATE(ts)='{$dayQ}' GROUP BY HOUR(ts)";
        $res = @$db->query("SELECT h, SUM(ev) ev, SUM(vis) vis FROM ("
                          . implode(' UNION ALL ', $parts) . ") u GROUP BY h");
        while ($res && ($r = $res->fetch_assoc())) {
            $h = (int)$r['h'];
            $hours[$h]['events'] = (int)$r['ev'];
            $hours[$h]['visits'] = (int)$r['vis'];
        }
    }

    foreach ($hours as $h => $v) $hours[$h]['sites'] = count($v['sites']);

    file_put_contents($hrl_cache, json_encode($hours));
    $hrl_age = 0;
}

/* показываем только прожитые часы: пустые строки до конца суток не несут
   смысла и только растягивают таблицу */
$lastHour = $isToday ? (int)date('G') : 23;
$rows = [];
for ($h = 0; $h <= $lastHour; $h++) {
    $v = $hours[$h] ?? null;
    if ($v === null) continue;
    $rows[] = $v + ['h' => $h];
}

$tS = $tR = 0.0; $tB = $tL = $tLv = $tV = $tE = 0;
foreach ($rows as $r) {
    $tS += (float)$r['spend'];  $tR += (float)$r['revenue'];
    $tB += (int)$r['bought'];   $tL += (int)$r['landed'];
    $tLv += (int)$r['live'];    $tV += (int)$r['visits']; $tE += (int)$r['events'];
}

$js = [
    'labels'  => array_map(fn($r) => sprintf('%02d:00', $r['h']), $rows),
    'spend'   => array_map(fn($r) => round((float)$r['spend'], 4), $rows),
    'revenue' => array_map(fn($r) => round((float)$r['revenue'], 4), $rows),
    'profit'  => array_map(fn($r) => round((float)$r['revenue'] - (float)$r['spend'], 4), $rows),
    'bought'  => array_map(fn($r) => (int)$r['bought'], $rows),
    'visits'  => array_map(fn($r) => (int)$r['visits'], $rows),
    'depth'   => array_map(fn($r) => (int)$r['visits'] > 0
                    ? round((int)$r['events'] / (int)$r['visits'], 2) : null, $rows),
];
?>
<style>
  .hrl-cards { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
  .hrl-card { flex:1 1 140px; padding:10px 12px; border-radius:10px;
              background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12); }
  .hrl-card .k { font-size:12px; opacity:.7; }
  .hrl-card .v { font-size:19px; font-weight:600; margin-top:2px; }
  .hrl-pos { color:#3ddc84; } .hrl-neg { color:#ff6b6b; }
  .hrl-chart { background:rgba(255,255,255,.04); border-radius:10px; padding:8px 10px 4px; margin-bottom:16px; }
  table.hrl { width:100%; border-collapse:collapse; font-size:14px; }
  table.hrl th, table.hrl td { padding:7px 10px; border-bottom:1px solid rgba(255,255,255,.08); text-align:right; }
  table.hrl th:first-child, table.hrl td:first-child { text-align:left; }
  table.hrl th { font-weight:600; opacity:.75; white-space:nowrap; }
  table.hrl tbody tr:hover { background:rgba(255,255,255,.05); }
  table.hrl tr.hrl-now td { background:rgba(255,159,67,.10); }
  .hrl-dim { opacity:.55; }
</style>

<h3 class="mb-3">🕐 По часам</h3>
<p style="font-size:12px;opacity:.6;margin-top:-6px">
  <?= $isToday ? 'Сегодня' : 'Вчера' ?>, <?= h($day) ?> ·
  обновлено <?= $hrl_age === null || $hrl_age < 60 ? 'только что' : intdiv((int)$hrl_age, 60).' мин назад' ?>,
  кеш на 2 минуты ·
  <a href="menu.php?page=hourly&day=<?= $isToday ? 'today' : 'prev' ?>&refresh=1">обновить сейчас</a>
</p>

<form method="get" class="mb-3">
  <input type="hidden" name="page" value="hourly">
  <label>Сутки:
    <select name="day" onchange="this.form.submit()">
      <option value="today" <?= $isToday ? 'selected' : '' ?>>сегодня</option>
      <option value="prev"  <?= $isToday ? '' : 'selected' ?>>вчера</option>
    </select>
  </label>
</form>

<div class="hrl-cards">
  <div class="hrl-card"><div class="k">Расход</div><div class="v"><?=number_format($tS,4)?></div></div>
  <div class="hrl-card"><div class="k">Доход</div><div class="v"><?=number_format($tR,4)?></div></div>
  <div class="hrl-card"><div class="k">Профит</div>
    <div class="v <?=($tR-$tS)>=0?'hrl-pos':'hrl-neg'?>"><?=number_format($tR-$tS,4)?></div></div>
  <div class="hrl-card"><div class="k">ROI</div>
    <div class="v <?=($tR-$tS)>=0?'hrl-pos':'hrl-neg'?>"><?=$tS>0?sprintf('%+.1f%%',($tR-$tS)/$tS*100):'—'?></div></div>
  <div class="hrl-card"><div class="k">Куплено кликов</div><div class="v"><?=number_format($tB,0,'.',' ')?></div></div>
  <div class="hrl-card"><div class="k">Живых</div>
    <div class="v"><?=$tL>0?sprintf('%d%%',round($tLv/$tL*100)):'—'?></div></div>
  <div class="hrl-card"><div class="k">Визиты</div><div class="v"><?=number_format($tV,0,'.',' ')?></div></div>
</div>

<div class="hrl-chart"><div id="hrlChart"></div></div>
<p style="font-size:12px;opacity:.6;margin-top:-8px;margin-bottom:16px">
  Клик по метрике в легенде включает и выключает её. Деньги, штуки и глубина — на разных осях.
</p>

<table class="hrl">
  <thead><tr>
    <th>Час</th><th>Сайты</th><th>Куплено</th><th>Доехало</th><th>Живых</th>
    <th>Визиты</th><th>Глубина</th><th>Расход</th><th>Доход</th><th>Профит</th><th>ROI</th>
  </tr></thead>
  <tbody>
  <?php foreach (array_reverse($rows) as $r):
      $sp = (float)$r['spend']; $rev = (float)$r['revenue']; $pf = $rev - $sp;
      $ld = (int)$r['landed'];  $lv = (int)$r['live'];
      $vs = (int)$r['visits'];  $ev = (int)$r['events'];
      $now = $isToday && (int)$r['h'] === $lastHour; ?>
    <tr class="<?= $now ? 'hrl-now' : '' ?>">
      <td><?=sprintf('%02d:00', (int)$r['h'])?><?= $now ? ' <span class="hrl-dim">идёт</span>' : '' ?></td>
      <td><?=(int)$r['sites']?></td>
      <td><?=number_format((int)$r['bought'],0,'.',' ')?></td>
      <td><?=number_format($ld,0,'.',' ')?></td>
      <td><?=$ld>0?round($lv/$ld*100).'%':'—'?></td>
      <td><?=number_format($vs,0,'.',' ')?></td>
      <td><?=$vs>0?number_format($ev/$vs,2):'—'?></td>
      <td><?=number_format($sp,4)?></td>
      <td><?=number_format($rev,4)?></td>
      <td class="<?=$pf>=0?'hrl-pos':'hrl-neg'?>"><?=number_format($pf,4)?></td>
      <td class="<?=$pf>=0?'hrl-pos':'hrl-neg'?>"><?=$sp>0?sprintf('%+.1f%%',$pf/$sp*100):'—'?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<p style="font-size:12px;opacity:.65;margin-top:10px">
  Сайты — сколько доменов тратило бюджет в этот час. Куплено — клики, за которые
  заплатили в Kadam; доехало — сколько из них реально открыло страницу; живых —
  доля тех, у кого работает JS, только они могут увидеть рекламу. Профит за час
  всегда выглядит хуже правды: расход списывается сразу, а доход по пушу
  доезжает позже, иногда на следующий день. Показов по часам нет — сетки отдают
  их только за сутки целиком.
</p>

<script src="assets/apexcharts.min.js"></script>
<script>
(function () {
  var D = <?=json_encode($js, JSON_UNESCAPED_UNICODE)?>;
  var el = document.getElementById('hrlChart');
  if (!el || !window.ApexCharts) return;

  var money = { seriesName: 'Расход', title: { text: '$', style: { color: '#9aa' } },
                labels: { style: { colors: '#9aa' }, formatter: function (v) { return v == null ? '' : v.toFixed(3); } } };

  var chart = new ApexCharts(el, {
    chart: {
      type: 'line', height: 340, background: 'transparent',
      toolbar: { show: true, tools: { download: true, zoom: true, reset: true,
                                      pan: false, selection: false, zoomin: false, zoomout: false } },
      animations: { enabled: false }
    },
    theme: { mode: 'dark' },
    colors: ['#ff9f43', '#3ddc84', '#4dabf7', '#b197fc', '#ffd43b', '#ff8787'],
    stroke: { width: [2,2,3,2,2,2], curve: 'smooth', dashArray: [0,0,0,0,0,4] },
    markers: { size: 3, hover: { size: 5 } },
    series: [
      { name: 'Расход',  data: D.spend },
      { name: 'Доход',   data: D.revenue },
      { name: 'Профит',  data: D.profit },
      { name: 'Куплено', data: D.bought },
      { name: 'Визиты',  data: D.visits },
      { name: 'Глубина', data: D.depth }
    ],
    xaxis: { categories: D.labels, labels: { style: { colors: '#9aa' } } },
    yaxis: [
      money,
      Object.assign({}, money, { seriesName: 'Расход', show: false }),
      Object.assign({}, money, { seriesName: 'Расход', show: false }),
      { seriesName: 'Куплено', opposite: true, title: { text: 'шт', style: { color: '#9aa' } },
        labels: { style: { colors: '#9aa' },
                  formatter: function (v) { return v == null ? '' : Math.round(v).toLocaleString('ru-RU'); } } },
      { seriesName: 'Куплено', opposite: true, show: false },
      { seriesName: 'Глубина', opposite: true, show: false, min: 0 }
    ],
    legend: { position: 'top', horizontalAlign: 'left', labels: { colors: '#ccc' },
              markers: { radius: 3 }, itemMargin: { horizontal: 10, vertical: 4 } },
    grid: { borderColor: 'rgba(255,255,255,.08)' },
    tooltip: { shared: true, intersect: false, theme: 'dark' },
    noData: { text: 'Нет данных', style: { color: '#999' } }
  });
  chart.render();
})();
</script>

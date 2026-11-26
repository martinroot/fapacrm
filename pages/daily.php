<?php
declare(strict_types=1);

/**
 * pages/daily.php — сводка по дням с переключаемыми метриками.
 *
 * Метрики живут в разных масштабах (деньги — сотые доли, клики — десятки тысяч,
 * глубина — единицы), поэтому у каждой группы своя ось. На одной оси всё, кроме
 * кликов, вырождается в прямую у нуля.
 *
 * Клики берём как ПОСЛЕДНИЙ снимок дня по кампании: Kadam отдаёт накопительный
 * счётчик, поэтому сумма тиков умножила бы цифру на число опросов, а максимум
 * завысил бы её там, где сетка пересчитала клики вниз и вычистила фрод.
 *
 * Библиотека лежит локально в assets/ — админка не должна зависеть от CDN.
 */

csrf_check();
$db = db_connect();

$days = max(3, min(90, (int)req_str('days', '14')));
$from = date('Y-m-d', strtotime("-{$days} day"));
$to   = date('Y-m-d');

/* Сбор тяжёлый: три сетки опрашиваются по всему диапазону дат, а глубина
   считается объединением по двум десяткам баз сайтов — вместе почти 30 секунд.
   Данные меняются не поминутно, поэтому держим их в файловом кеше. */
$dly_dir = dirname(__DIR__) . '/var/cache';
if (!is_dir($dly_dir)) @mkdir($dly_dir, 0775, true);
$dly_cache = $dly_dir . "/daily_{$days}.json";
$dly_ttl   = 600;
$dly_force = req_str('refresh', '') === '1';

$rows = null;
if (!$dly_force && is_file($dly_cache) && (time() - filemtime($dly_cache)) < $dly_ttl) {
    $cached = json_decode((string)file_get_contents($dly_cache), true);
    if (is_array($cached) && $cached) $rows = $cached;
}
$dly_age = is_file($dly_cache) ? time() - filemtime($dly_cache) : null;

if ($rows === null) {
    $rows = [];
    $res = $db->query("
        SELECT f.d,
               ROUND(SUM(CASE WHEN f.kind='cost'    THEN f.amount END), 4) AS spend,
               ROUND(SUM(CASE WHEN f.kind='revenue' THEN f.amount END), 4) AS revenue,
               ROUND(SUM(CASE WHEN f.kind='metric'  THEN f.amount END), 0) AS visits,
               COUNT(DISTINCT CASE WHEN f.kind='cost' AND f.amount > 0 THEN f.domain_id END) AS sites_paid,
               COUNT(DISTINCT f.domain_id) AS sites_any
          FROM crm_finance_daily f
         WHERE f.d >= '{$from}'
         GROUP BY f.d ORDER BY f.d");
    while ($res && ($r = $res->fetch_assoc())) {
        $r['clicks'] = 0; $r['imp'] = 0; $r['depth'] = null;
        $rows[$r['d']] = $r;
    }

    $res = $db->query("
        SELECT t.d, SUM(t.cl) AS clicks FROM (
            SELECT d, external_id,
                   CAST(JSON_EXTRACT(meta_json,'$.clicks') AS UNSIGNED) AS cl,
                   ROW_NUMBER() OVER (PARTITION BY d, external_id ORDER BY ts DESC) AS rn
              FROM crm_finance_ticks
             WHERE kind='cost' AND d >= '{$from}'
        ) t WHERE t.rn = 1 GROUP BY t.d");
    while ($res && ($r = $res->fetch_assoc()))
        if (isset($rows[$r['d']])) $rows[$r['d']]['clicks'] = (int)$r['clicks'];

    /* глубина: событий на визит по всем живым сайтам */
    $dbs = [];
    $q = $db->query("SELECT db_name FROM crm_domains WHERE status='live' AND db_name<>''");
    while ($q && ($x = $q->fetch_row()))
        if (@$db->query("SELECT 1 FROM `{$x[0]}`.user_events LIMIT 1")) $dbs[] = $x[0];
    if ($dbs) {
        $parts = [];
        foreach ($dbs as $d)
            $parts[] = "SELECT DATE(ts) dd, uiq FROM `$d`.user_events WHERE ts >= '{$from} 00:00:00'";
        $res = @$db->query("SELECT dd, COUNT(*) ev, COUNT(DISTINCT uiq) vis FROM ("
                          .implode(' UNION ALL ', $parts).") u GROUP BY dd");
        while ($res && ($r = $res->fetch_assoc()))
            if (isset($rows[$r['dd']]) && (int)$r['vis'] > 0)
                $rows[$r['dd']]['depth'] = round((int)$r['ev'] / (int)$r['vis'], 2);
    }

    /* показы рекламы по дням, суммарно по всем сеткам */
    $api = function (string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25,
            CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: FapaCRM/1.0']]);
        $j = json_decode((string)curl_exec($ch), true);
        if (!is_array($j)) return [];
        return array_is_list($j) ? $j : ($j['data'] ?? $j['stats'] ?? []);
    };
    $addImp = function ($day, int $imp) use (&$rows): void {
        $d = substr((string)$day, 0, 10);
        if ($d !== '' && isset($rows[$d])) $rows[$d]['imp'] += $imp;
    };

    if ($t = $db->query("SELECT params_json FROM crm_cron_jobs
                          WHERE connector='tacoloco_revenue' AND is_enabled=1 LIMIT 1")->fetch_assoc()) {
        $p = json_decode($t['params_json'], true);
        $base = rtrim($p['base_url'] ?? 'https://api.tacolo.co', '/');
        foreach (['push','inpagepush'] as $type)
            foreach ($api("$base/publisher/v1/statistics/$type/date?".http_build_query(
                     ['startDate'=>$from,'endDate'=>$to,'token'=>$p['token']],'','&',PHP_QUERY_RFC3986)) as $x)
                if (is_array($x)) $addImp($x['date'] ?? $x['Date'] ?? '',
                                          (int)($x['impressions'] ?? $x['Impressions'] ?? 0));
    }
    if ($c = $db->query("SELECT params_json FROM crm_cron_jobs
                          WHERE connector='clickadu_revenue' AND is_enabled=1 LIMIT 1")->fetch_assoc()) {
        $p = json_decode($c['params_json'], true);
        foreach ($api('https://v2.api.clickadu.com/partner/stats?'.http_build_query(
                 ['dateFrom'=>$from,'dateTo'=>$to,'groupBy'=>'date','format'=>'json',
                  'token'=>$p['token'],'timezone'=>$p['timezone'] ?? 'Europe/Berlin'])) as $x)
            if (is_array($x)) $addImp($x['date'] ?? '', (int)($x['impressions'] ?? 0));
    }
    if ($mb = $db->query("SELECT params_json FROM crm_cron_jobs
                           WHERE connector IN ('mybid_revenue','mybid_revenue_share') AND is_enabled=1 LIMIT 1")->fetch_assoc()) {
        $p = json_decode($mb['params_json'], true);
        foreach ($api(($p['stats_url'] ?? 'https://publishers.mybid.io/backend/api/public/stats').'?'
                 .http_build_query(['token'=>$p['token'],'fields'=>'date,impressions,money',
                                    'date1'=>$from,'date2'=>$to,'orderBy'=>'-date',
                                    'limit'=>1000,'offset'=>0],'','&',PHP_QUERY_RFC3986)) as $x)
            if (is_array($x)) $addImp($x['date'] ?? '', (int)($x['impressions'] ?? 0));
    }

    file_put_contents($dly_cache, json_encode($rows));
    $dly_age = 0;
}

$data = array_values($rows);
$tS = $tR = 0.0; $tC = $tV = $tI = 0;
foreach ($data as $r) {
    $tS += (float)$r['spend']; $tR += (float)$r['revenue'];
    $tC += (int)$r['clicks'];  $tV += (int)$r['visits']; $tI += (int)$r['imp'];
}

$js = [
    'labels'  => array_map(fn($r) => (string)$r['d'], $data),
    'spend'   => array_map(fn($r) => round((float)$r['spend'], 4), $data),
    'revenue' => array_map(fn($r) => round((float)$r['revenue'], 4), $data),
    'profit'  => array_map(fn($r) => round((float)$r['revenue'] - (float)$r['spend'], 4), $data),
    'clicks'  => array_map(fn($r) => (int)$r['clicks'], $data),
    'visits'  => array_map(fn($r) => (int)$r['visits'], $data),
    'imp'     => array_map(fn($r) => (int)$r['imp'], $data),
    'depth'   => array_map(fn($r) => $r['depth'] === null ? null : (float)$r['depth'], $data),
];
?>
<style>
  .dly-cards { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
  .dly-card { flex:1 1 140px; padding:10px 12px; border-radius:10px;
              background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12); }
  .dly-card .k { font-size:12px; opacity:.7; }
  .dly-card .v { font-size:19px; font-weight:600; margin-top:2px; }
  .dly-pos { color:#3ddc84; } .dly-neg { color:#ff6b6b; }
  .dly-chart { background:rgba(255,255,255,.04); border-radius:10px; padding:8px 10px 4px; margin-bottom:16px; }
  table.dly { width:100%; border-collapse:collapse; font-size:14px; }
  table.dly th, table.dly td { padding:7px 10px; border-bottom:1px solid rgba(255,255,255,.08); text-align:right; }
  table.dly th:first-child, table.dly td:first-child { text-align:left; }
  table.dly th { font-weight:600; opacity:.75; white-space:nowrap; }
  table.dly tbody tr:hover { background:rgba(255,255,255,.05); }
</style>

<h3 class="mb-3">📈 По дням</h3>
<p style="font-size:12px;opacity:.6;margin-top:-6px">
  Обновлено <?= $dly_age === null || $dly_age < 60 ? 'только что' : intdiv((int)$dly_age, 60).' мин назад' ?>,
  кеш на 10 минут ·
  <a href="menu.php?page=daily&days=<?=$days?>&refresh=1">обновить сейчас</a>
</p>

<form method="get" class="mb-3">
  <input type="hidden" name="page" value="daily">
  <label>Период:
    <select name="days" onchange="this.form.submit()">
      <?php foreach ([7,14,30,60,90] as $d): ?>
        <option value="<?=$d?>" <?=$d===$days?'selected':''?>><?=$d?> дней</option>
      <?php endforeach; ?>
    </select>
  </label>
</form>

<div class="dly-cards">
  <div class="dly-card"><div class="k">Расход</div><div class="v"><?=number_format($tS,4)?></div></div>
  <div class="dly-card"><div class="k">Доход</div><div class="v"><?=number_format($tR,4)?></div></div>
  <div class="dly-card"><div class="k">Профит</div>
    <div class="v <?=($tR-$tS)>=0?'dly-pos':'dly-neg'?>"><?=number_format($tR-$tS,4)?></div></div>
  <div class="dly-card"><div class="k">ROI</div>
    <div class="v <?=($tR-$tS)>=0?'dly-pos':'dly-neg'?>"><?=$tS>0?sprintf('%+.1f%%',($tR-$tS)/$tS*100):'—'?></div></div>
  <div class="dly-card"><div class="k">Клики</div><div class="v"><?=number_format($tC,0,'.',' ')?></div></div>
  <div class="dly-card"><div class="k">Визиты</div><div class="v"><?=number_format($tV,0,'.',' ')?></div></div>
  <div class="dly-card"><div class="k">Показы</div><div class="v"><?=number_format($tI,0,'.',' ')?></div></div>
</div>

<div class="dly-chart"><div id="dlyChart"></div></div>
<p style="font-size:12px;opacity:.6;margin-top:-8px;margin-bottom:16px">
  Клик по метрике в легенде включает и выключает её. Деньги, штуки и глубина — на разных осях.
</p>

<table class="dly">
  <thead><tr>
    <th>Дата</th><th>Сайты</th><th>Клики</th><th>Визиты</th><th>Показы</th>
    <th>Глубина</th><th>Расход</th><th>Доход</th><th>Профит</th><th>ROI</th>
  </tr></thead>
  <tbody>
  <?php foreach (array_reverse($data) as $r):
      $sp=(float)$r['spend']; $rev=(float)$r['revenue']; $pf=$rev-$sp; ?>
    <tr>
      <td><?=h((string)$r['d'])?></td>
      <td><?=(int)$r['sites_paid']?> / <?=(int)$r['sites_any']?></td>
      <td><?=number_format((int)$r['clicks'],0,'.',' ')?></td>
      <td><?=number_format((int)$r['visits'],0,'.',' ')?></td>
      <td><?=number_format((int)$r['imp'],0,'.',' ')?></td>
      <td><?=$r['depth']===null?'—':number_format((float)$r['depth'],2)?></td>
      <td><?=number_format($sp,4)?></td>
      <td><?=number_format($rev,4)?></td>
      <td class="<?=$pf>=0?'dly-pos':'dly-neg'?>"><?=number_format($pf,4)?></td>
      <td class="<?=$pf>=0?'dly-pos':'dly-neg'?>"><?=$sp>0?sprintf('%+.1f%%',$pf/$sp*100):'—'?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<p style="font-size:12px;opacity:.65;margin-top:10px">
  Сайты: сколько доменов тратило бюджет / сколько было с любой активностью.
  Клики — купленные в Kadam. Показы — суммарно TacoLoco, Clickadu и MyBid.
  Глубина — событий на визит.
</p>

<script src="assets/apexcharts.min.js"></script>
<script>
(function () {
  var D = <?=json_encode($js, JSON_UNESCAPED_UNICODE)?>;
  var el = document.getElementById('dlyChart');
  if (!el || !window.ApexCharts) return;

  var money = { seriesName: 'Расход', title: { text: '$', style: { color: '#9aa' } },
                labels: { style: { colors: '#9aa' }, formatter: function (v) { return v == null ? '' : v.toFixed(2); } } };

  var chart = new ApexCharts(el, {
    chart: {
      type: 'line', height: 380, background: 'transparent',
      toolbar: { show: true, tools: { download: true, zoom: true, reset: true,
                                      pan: false, selection: false, zoomin: false, zoomout: false } },
      animations: { enabled: false }
    },
    theme: { mode: 'dark' },
    colors: ['#ff9f43', '#3ddc84', '#4dabf7', '#b197fc', '#ffd43b', '#63e6be', '#ff8787'],
    stroke: { width: [2,2,3,2,2,2,2], curve: 'smooth', dashArray: [0,0,0,0,0,0,4] },
    markers: { size: 3, hover: { size: 5 } },
    series: [
      { name: 'Расход',  data: D.spend },
      { name: 'Доход',   data: D.revenue },
      { name: 'Профит',  data: D.profit },
      { name: 'Клики',   data: D.clicks },
      { name: 'Визиты',  data: D.visits },
      { name: 'Показы',  data: D.imp },
      { name: 'Глубина', data: D.depth }
    ],
    xaxis: { categories: D.labels, labels: { style: { colors: '#9aa' }, rotate: -35, rotateAlways: false } },
    yaxis: [
      money,
      Object.assign({}, money, { seriesName: 'Расход', show: false }),
      Object.assign({}, money, { seriesName: 'Расход', show: false }),
      { seriesName: 'Клики', opposite: true, title: { text: 'шт', style: { color: '#9aa' } },
        labels: { style: { colors: '#9aa' },
                  formatter: function (v) { return v == null ? '' : Math.round(v).toLocaleString('ru-RU'); } } },
      { seriesName: 'Клики', opposite: true, show: false },
      { seriesName: 'Клики', opposite: true, show: false },
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

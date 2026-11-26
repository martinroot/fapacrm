<?php
/**
 * Траф/мин — поминутный график трафика (клики + спенд) по всем live-доменам.
 * Данные — из per-domain <db_name>.clicks (ts). Ось непрерывная (нули заполнены).
 */
csrf_check();
$db = db_connect();

$mins = req_int('mins', 180);
if (!in_array($mins, [60,180,360,720,1440], true)) $mins = 180;

/* live domain DBs */
$dbs = []; $domId = [];
$q = $db->query("SELECT id, domain, db_name FROM crm_domains WHERE status='live' AND db_name<>''");
while ($q && ($x=$q->fetch_assoc())) { $dbs[$x['domain']] = $x['db_name']; $domId[$x['domain']] = (int)$x['id']; }

/* per-minute aggregate across all domain DBs */
$byMin = [];   // 'Y-m-d H:i' => ['c'=>clicks,'sp'=>spend,'l2'=>lead2]
$topDom = [];  // domain => clicks in window
if ($dbs) {
  $parts = [];
  foreach ($dbs as $dom=>$dbn) {
    $d = str_replace('`','',$dbn);
    $parts[] = "SELECT DATE_FORMAT(ts,'%Y-%m-%d %H:%i') m, cost, lead2 FROM `$d`.clicks WHERE ts >= NOW() - INTERVAL $mins MINUTE";
  }
  $union = implode(' UNION ALL ', $parts);
  $res = @$db->query("SELECT m, COUNT(*) c, COALESCE(SUM(cost),0) sp, COALESCE(SUM(lead2),0) l2
                        FROM ($union) u GROUP BY m ORDER BY m");
  while ($res && ($r=$res->fetch_assoc()))
    $byMin[$r['m']] = ['c'=>(int)$r['c'],'sp'=>(float)$r['sp'],'l2'=>(int)$r['l2']];

  /* per-domain WINDOW: clicks, spend, lead2 */
  $parts2 = [];
  foreach ($dbs as $dom=>$dbn) { $d=str_replace('`','',$dbn); $ql="'".$db->real_escape_string($dom)."'";
    $parts2[] = "SELECT $ql dom, COUNT(*) c, COALESCE(SUM(cost),0) sp, COALESCE(SUM(lead2),0) l2 FROM `$d`.clicks WHERE ts >= NOW() - INTERVAL $mins MINUTE"; }
  $win=[];
  $res = @$db->query("SELECT dom, SUM(c) c, ROUND(SUM(sp),6) sp, SUM(l2) l2 FROM (".implode(' UNION ALL ',$parts2).") u GROUP BY dom HAVING c>0");
  while ($res && ($r=$res->fetch_assoc())) $win[$r['dom']]=$r;

  /* per-domain 7d lead2 (rate denominator) */
  $parts3 = [];
  foreach ($dbs as $dom=>$dbn) { $d=str_replace('`','',$dbn); $ql="'".$db->real_escape_string($dom)."'";
    $parts3[] = "SELECT $ql dom, COALESCE(SUM(lead2),0) l2 FROM `$d`.clicks WHERE ts >= NOW() - INTERVAL 7 DAY"; }
  $l2_7d=[];
  $res = @$db->query("SELECT dom, SUM(l2) l2 FROM (".implode(' UNION ALL ',$parts3).") u GROUP BY dom");
  while ($res && ($r=$res->fetch_assoc())) $l2_7d[$r['dom']]=(int)$r['l2'];

  /* per-domain 7d revenue (network, crm_finance_daily) → rate = rev7d / lead2_7d */
  $revById=[];
  $res = @$db->query("SELECT domain_id, ROUND(SUM(amount),4) rev FROM crm_finance_daily
                       WHERE kind='revenue' AND d>=CURDATE()-INTERVAL 7 DAY GROUP BY domain_id");
  while ($res && ($r=$res->fetch_assoc())) $revById[(int)$r['domain_id']]=(float)$r['rev'];

  foreach ($win as $dom=>$w) {
    $c=(int)$w['c']; $sp=(float)$w['sp']; $l2=(int)$w['l2'];
    $rev7 = $revById[$domId[$dom] ?? -1] ?? 0.0;
    $rate = ($l2_7d[$dom] ?? 0) > 0 ? $rev7/$l2_7d[$dom] : 0.0;  // $/lead2
    $erev = $l2*$rate;
    $topDom[] = ['dom'=>$dom,'c'=>$c,'sp'=>$sp,'l2'=>$l2,'cpc'=>($c>0?$sp/$c:0),
                 'erev'=>$erev,'profit'=>$erev-$sp,'roi'=>($sp>0?$erev/$sp:0)];
  }
  usort($topDom, fn($a,$b)=>$b['c']<=>$a['c']);
  $topDom = array_slice($topDom,0,20);
}

/* continuous minute axis (fill gaps with 0) */
$labels=[]; $clicks=[]; $spend=[]; $leads=[];
$now = time();
$start = $now - $mins*60;
$sumC=0; $sumSp=0.0; $sumL=0;
for ($t = $start - ($start % 60); $t <= $now; $t += 60) {
  $key = date('Y-m-d H:i', $t);
  $labels[] = date('H:i', $t);
  $c = $byMin[$key]['c'] ?? 0;  $s = $byMin[$key]['sp'] ?? 0.0; $l = $byMin[$key]['l2'] ?? 0;
  $clicks[]=$c; $spend[]=round($s,5); $leads[]=$l;
  $sumC+=$c; $sumSp+=$s; $sumL+=$l;
}
$nMin = max(1, count($labels));
$avgClk = $sumC/$nMin;

/* currents from sequencer */
$curN = 0; $halted = null;
$seq = json_decode((string)@file_get_contents('/var/lib/kadam-sequence.json'), true);
if (is_array($seq)) { $curN = count($seq['currents'] ?? []); $halted = $seq['halted'] ?? null; }

echo '<meta http-equiv="refresh" content="60">';
echo '<div class="card"><div class="card-body">';
echo '<h3 class="mb-1">⚡ Траф/мин — поминутный график</h3>';

/* window selector */
echo '<div class="mb-2">';
foreach ([60=>'1ч',180=>'3ч',360=>'6ч',720=>'12ч',1440=>'24ч'] as $mv=>$ml) {
  $cls = ($mv===$mins)?'btn btn-sm btn-primary':'btn btn-sm btn-outline-secondary';
  echo '<a class="'.$cls.'" style="margin-right:4px" href="menu.php?page=traffic_live&mins='.$mv.'">'.$ml.'</a>';
}
echo ' <span class="small-muted">автообновление 60с</span></div>';

/* tiles */
$tiles = [
  ['Клики за окно', number_format($sumC)],
  ['Клики/мин (сред.)', number_format($avgClk,1)],
  ['Спенд за окно', '$'.number_format($sumSp,4)],
  ['lead2 за окно', number_format($sumL)],
  ['Льётся сейчас', $curN.' дом.'.($halted?' <span class="badge badge-bad">HALTED</span>':'')],
];
echo '<div class="row g-2 my-2">';
foreach ($tiles as $t)
  echo '<div class="col"><div class="stat"><div class="small-muted">'.$t[0].'</div><div class="h5">'.$t[1].'</div></div></div>';
echo '</div>';

echo '<div style="position:relative;height:340px"><canvas id="chartTraf"></canvas></div>';

/* top domains */
if ($topDom) {
  echo '<h5 class="mt-3">Домены за окно ('.$mins.' мин)</h5>';
  echo '<div class="small-muted mb-1">CPC/спенд/клики — факт. Ревенью — сетевой (crm_finance_daily), оценён через lead2 '
     . '(курс = доход домена за 7д ÷ lead2 за 7д). Профит/ROI по этой оценке. За окно &lt;суток доход всегда приблизительный (пуш отдаёт с задержкой).</div>';
  echo '<div style="overflow-x:auto"><table class="table table-sm"><thead><tr>'
     . '<th>Домен</th><th class="text-end">Клики</th><th class="text-end">Кл/мин</th>'
     . '<th class="text-end">CPC</th><th class="text-end">Спенд</th>'
     . '<th class="text-end">est.Рев</th><th class="text-end">Профит</th><th class="text-end">ROI*</th></tr></thead><tbody>';
  foreach ($topDom as $r) {
    $roi=$r['roi'];
    $roiCls=$r['sp']>0?($roi>=1.5?'badge-ok':($roi>=1?'':'badge-bad')):'';
    $prStyle=$r['profit']>=0?'':' style="color:#dc2626"';
    echo '<tr><td>'.h($r['dom']).'</td>'
       . '<td class="text-end">'.number_format($r['c']).'</td>'
       . '<td class="text-end">'.number_format($r['c']/$nMin,1).'</td>'
       . '<td class="text-end">$'.number_format($r['cpc'],5).'</td>'
       . '<td class="text-end">$'.number_format($r['sp'],4).'</td>'
       . '<td class="text-end">$'.number_format($r['erev'],4).'</td>'
       . '<td class="text-end"'.$prStyle.'>$'.number_format($r['profit'],4).'</td>'
       . '<td class="text-end">'.($r['sp']>0?'<span class="badge '.$roiCls.'">'.number_format($roi,2).'×</span>':'—').'</td></tr>';
  }
  echo '</tbody></table></div><div class="small-muted">*ROI по оценённому доходу.</div>';
}

echo '</div></div>';

echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
$jl = json_encode($labels); $jc = json_encode($clicks); $js = json_encode($spend);
echo '<script>
new Chart(document.getElementById("chartTraf"), {
  type: "line",
  data: { labels: '.$jl.', datasets: [
    { label: "Клики/мин", data: '.$jc.', yAxisID:"y", borderColor:"#2563eb", backgroundColor:"rgba(37,99,235,.12)", fill:true, tension:.25, pointRadius:0, borderWidth:2 },
    { label: "Спенд/мин ($)", data: '.$js.', yAxisID:"y1", borderColor:"#dc2626", backgroundColor:"transparent", fill:false, tension:.25, pointRadius:0, borderWidth:1.5 }
  ]},
  options: {
    responsive:true, maintainAspectRatio:false, interaction:{mode:"index",intersect:false},
    scales:{
      y:{ position:"left", title:{display:true,text:"клики/мин"}, beginAtZero:true },
      y1:{ position:"right", title:{display:true,text:"$ /мин"}, beginAtZero:true, grid:{drawOnChartArea:false} },
      x:{ ticks:{ maxTicksLimit:24, autoSkip:true } }
    },
    plugins:{ legend:{position:"top"} }
  }
});
</script>';

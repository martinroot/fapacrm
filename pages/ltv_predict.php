<?php
/**
 * LTV разгон — предикт буста по домену.
 *
 * Анализирует 4 измерения из фактических данных домена:
 *   Пабы (c1) / Страны (country) / Сетки (connector) / Форматы (device+kadam)
 * и выдаёт числовой прогноз + пошаговый алгоритм с учётом специфики Кадама.
 *
 * ВАЖНО по модели дохода: в per-domain clicks столбец revenue не заполняется —
 * доход приходит на уровне СЕТКИ (crm_finance_daily). Поэтому доход раскидываем
 * по сегментам пропорционально lead2 (это и есть конверсия, как в pub_gen.php):
 *   est_rev(сегмент) = доход_сетки × lead2(сегмент) / lead2(всего).
 * lead1 почти всегда 0 — рабочий сигнал именно lead2.
 *
 * AI-слой (опционально): env ANTHROPIC_API_KEY или crm_networks code='claude'
 *   → краткий прогноз через api.anthropic.com (claude-opus-5). Без ключа —
 *   чистая аналитика. Подписка Claude Code для веб-кнопки не подходит.
 */

csrf_check();
$db = db_connect();

$days      = max(1, min(90, req_int('days', 7)));
$domain_id = req_int('domain_id', 0);
$domain_nm = req_str('domain', '');

/* -------- resolve domain -------- */
if ($domain_id <= 0 && $domain_nm !== '') {
  $r = db_one($db, "SELECT id FROM crm_domains WHERE domain=? LIMIT 1", [$domain_nm]);
  if ($r) $domain_id = (int)$r['id'];
}
$dom = $domain_id > 0
  ? db_one($db, "SELECT id,domain,db_name,finance_mode,status FROM crm_domains WHERE id=? LIMIT 1", [$domain_id])
  : null;

echo '<div class="card"><div class="card-body">';
echo '<h3 class="mb-1">🚀 LTV разгон — предикт буста</h3>';
echo '<div class="small-muted mb-3">Окно: <b>'.$days.' дн.</b> · '
   . '<a class="btn" href="menu.php?page=finance">← Finance</a></div>';

if (!$dom) {
  echo '<div class="alert alert-warning">Домен не найден. Открой из Finance кнопкой 🚀, '
     . 'или добавь <code>?domain=maximhot.ru</code>.</div></div></div>';
  return;
}

$domName = (string)$dom['domain'];
$dbName  = (string)$dom['db_name'];
$mode    = (string)$dom['finance_mode'];
echo '<h4>'.h($domName).' <span class="badge '.($mode==='WORK'?'badge-ok':'badge-bad').'">'.h($mode).'</span></h4>';

/* ============ helpers ============ */
function pct($n){ return number_format((float)$n*100, 1).'%'; }
function usd($n){ return '$'.number_format((float)$n, 4); }
function usd2($n){ return '$'.number_format((float)$n, 2); }
function roi_badge($cost,$rev){
  if ($cost <= 0) return $rev>0 ? '<span class="badge badge-ok">∞</span>' : '<span class="badge">—</span>';
  $roi = $rev/$cost;
  $cls = $roi>=1.5?'badge-ok':($roi>=1.0?'':'badge-bad');
  return '<span class="badge '.$cls.'">'.number_format($roi,2).'×</span>';
}

/* ============ 1) СЕТКИ (crm_finance_daily) — реальный доход ============ */
$net = db_all($db,
  "SELECT kind, connector, ROUND(SUM(amount),4) amt
     FROM crm_finance_daily
    WHERE domain_id=? AND d >= CURDATE()-INTERVAL ? DAY
    GROUP BY kind, connector", [$domain_id, $days]);
$spend_net = 0.0; $rev_by_net = [];
foreach ($net as $n) {
  if ($n['kind']==='cost') $spend_net += (float)$n['amt'];
  elseif ($n['kind']==='revenue') $rev_by_net[$n['connector']] = (float)$n['amt'];
}
arsort($rev_by_net);
$rev_net = array_sum($rev_by_net);   // ← доход домена (правда о деньгах)

/* ============ open per-domain DB ============ */
$cdb = @new mysqli(BM_DB_HOST, BM_DB_USER, BM_DB_PASS, $dbName);
$clicksOK = $cdb && !$cdb->connect_errno;

$W = "ts >= NOW() - INTERVAL {$days} DAY";
$tot = ['clk'=>0,'cost'=>0.0,'l1'=>0,'l2'=>0];
$byGeo = $byPub = $byDev = [];
if ($clicksOK) {
  $q = $cdb->query("SELECT COUNT(*) clk, COALESCE(SUM(cost),0) cost,
                           COALESCE(SUM(lead1),0) l1, COALESCE(SUM(lead2),0) l2
                      FROM clicks WHERE $W");
  if ($q && ($row=$q->fetch_assoc())) $tot = ['clk'=>(int)$row['clk'],'cost'=>(float)$row['cost'],
        'l1'=>(int)$row['l1'],'l2'=>(int)$row['l2']];

  $q = $cdb->query("SELECT country k, COUNT(*) clk, COALESCE(SUM(cost),0) cost, COALESCE(SUM(lead2),0) l2
                      FROM clicks WHERE $W AND country<>'' GROUP BY country ORDER BY l2 DESC, clk DESC LIMIT 40");
  while ($q && ($row=$q->fetch_assoc())) $byGeo[] = $row;

  $q = $cdb->query("SELECT c1 k, COUNT(*) clk, COALESCE(SUM(cost),0) cost, COALESCE(SUM(lead2),0) l2
                      FROM clicks WHERE $W AND c1<>'' GROUP BY c1 HAVING clk >= 20 ORDER BY clk DESC LIMIT 80");
  while ($q && ($row=$q->fetch_assoc())) $byPub[] = $row;

  $q = $cdb->query("SELECT COALESCE(NULLIF(device,''),'?') k, COUNT(*) clk, COALESCE(SUM(cost),0) cost, COALESCE(SUM(lead2),0) l2
                      FROM clicks WHERE $W GROUP BY device ORDER BY l2 DESC LIMIT 20");
  while ($q && ($row=$q->fetch_assoc())) $byDev[] = $row;
}

/* revenue-per-lead2 — курс, по которому конверсия превращается в деньги */
$rpl = ($tot['l2']>0) ? $rev_net/$tot['l2'] : 0.0;
$est = function(int $l2) use ($rpl){ return $l2*$rpl; };  // est_rev сегмента

$profit = $rev_net - $tot['cost'];
$roi    = $tot['cost']>0 ? $rev_net/$tot['cost'] : 0;
$epc    = $tot['clk']>0 ? $rev_net/$tot['clk'] : 0;
$cr2    = $tot['clk']>0 ? $tot['l2']/$tot['clk'] : 0;

echo '<div class="row g-2 my-2">';
foreach ([
  ['Клики', number_format($tot['clk'])],
  ['Спенд', usd2($tot['cost'])],
  ['Доход (сети)', usd2($rev_net)],
  ['Профит', usd2($profit)],
  ['ROI', $tot['cost']>0?number_format($roi,2).'×':'—'],
  ['CR2', pct($cr2)],
  ['EPC', usd($epc)],
] as $t) {
  echo '<div class="col"><div class="stat"><div class="small-muted">'.$t[0].'</div>'
     . '<div class="h5">'.$t[1].'</div></div></div>';
}
echo '</div>';
echo '<div class="small-muted mb-2">Доход — с сеток (crm_finance_daily). По сегментам доход оценён через lead2 '
   . '(курс '.usd($rpl).'/lead2). Клики/спенд/лиды — факт из БД домена.</div>';
if (!$clicksOK) echo '<div class="alert alert-warning">Не открылась БД домена ('.h($dbName).') — сегменты недоступны.</div>';

/* ============ tables ============ */
$estFn = $est;
function seg_table($title,$rows,$keyName,$estFn){
  if (!$rows) return;
  echo '<h5 class="mt-3">'.$title.'</h5><div style="overflow-x:auto"><table class="table table-sm">';
  echo '<thead><tr><th>'.$keyName.'</th><th class="text-end">Клики</th><th class="text-end">Спенд</th>'
     . '<th class="text-end">lead2</th><th class="text-end">CR2</th><th class="text-end">est.доход</th>'
     . '<th class="text-end">ROI*</th></tr></thead><tbody>';
  foreach ($rows as $r) {
    $clk=(int)$r['clk']; $cost=(float)$r['cost']; $l2=(int)$r['l2'];
    $erev=$estFn($l2); $cr=$clk>0?$l2/$clk:0;
    echo '<tr><td><code>'.h((string)$r['k']).'</code></td>'
       . '<td class="text-end">'.number_format($clk).'</td>'
       . '<td class="text-end">'.usd($cost).'</td>'
       . '<td class="text-end">'.$l2.'</td>'
       . '<td class="text-end">'.pct($cr).'</td>'
       . '<td class="text-end">'.usd($erev).'</td>'
       . '<td class="text-end">'.roi_badge($cost,$erev).'</td></tr>';
  }
  echo '</tbody></table></div><div class="small-muted">*ROI по оценённому доходу (lead2×курс).</div>';
}

echo '<h5 class="mt-3">💰 Сетки (доход по источнику, '.$days.' дн.)</h5>';
echo '<table class="table table-sm"><thead><tr><th>Сетка</th><th class="text-end">Доход</th><th class="text-end">Доля</th></tr></thead><tbody>';
foreach ($rev_by_net as $c=>$v) {
  echo '<tr><td>'.h($c).'</td><td class="text-end">'.usd($v).'</td>'
     . '<td class="text-end">'.($rev_net>0?pct($v/$rev_net):'—').'</td></tr>';
}
echo '<tr class="fw-bold"><td>Спенд (Кадам)</td><td class="text-end" colspan="2">'.usd($spend_net).'</td></tr>';
echo '</tbody></table>';

seg_table('🌍 Страны', $byGeo, 'GEO', $estFn);
seg_table('📱 Устройства / форматы', $byDev, 'device', $estFn);
seg_table('🏷 Пабы (c1, клики ≥ 20)', array_slice($byPub,0,25), 'pub', $estFn);

/* ============ ALGORITHM (rule-based, lead2 economics) ============ */
$MIN_CLK_GEO = max(30, (int)round($tot['clk']*0.01));
$MIN_CLK_PUB = 30;

$scaleGeo=[]; $cutGeo=[];
foreach ($byGeo as $g){
  $clk=(int)$g['clk']; $cost=(float)$g['cost']; $erev=$est((int)$g['l2']);
  if ($clk < $MIN_CLK_GEO) continue;
  $r = $cost>0 ? $erev/$cost : ($erev>0?9:0);
  if ($r>=1.5 && $erev>0) $scaleGeo[]=[$g['k'],$r,$erev];
  elseif ($cost>0 && $r<0.8) $cutGeo[]=[$g['k'],$r,$cost];
}
usort($scaleGeo,fn($a,$b)=>$b[2]<=>$a[2]);
usort($cutGeo,fn($a,$b)=>$b[2]<=>$a[2]);

$blPub=[]; $keepPub=[];
foreach ($byPub as $p){
  $clk=(int)$p['clk']; $cost=(float)$p['cost']; $l2=(int)$p['l2']; $erev=$est($l2);
  if ($clk < $MIN_CLK_PUB) continue;
  $r = $cost>0 ? $erev/$cost : ($erev>0?9:0);
  if (($l2==0 && $clk>=$MIN_CLK_PUB) || ($cost>0 && $r<0.5)) $blPub[]=[$p['k'],$clk,$cost];
  elseif ($r>=2.0 && $erev>0) $keepPub[]=[$p['k'],$r,$erev];
}
usort($blPub,fn($a,$b)=>$b[2]<=>$a[2]);
usort($keepPub,fn($a,$b)=>$b[2]<=>$a[2]);

$bestDev=null;
foreach ($byDev as $d){ $c=(float)$d['cost']; $erev=$est((int)$d['l2']);
  $r=$c>0?$erev/$c:0; if (($bestDev===null || $r>$bestDev[1]) && (int)$d['l2']>0) $bestDev=[$d['k'],$r,$erev]; }

$bestNet = $rev_by_net ? array_key_first($rev_by_net) : null;
$deadNet = [];
foreach (['tacoloco_revenue','mybid_revenue_share','clickadu_revenue'] as $cn)
  if (!isset($rev_by_net[$cn]) || $rev_by_net[$cn]<=0.0001) $deadNet[]=$cn;

$wasted = array_sum(array_map(fn($x)=>$x[2], $cutGeo));
$topRoi = $scaleGeo ? $scaleGeo[0][1] : ($roi>1?$roi:1.2);
$proj_low  = $profit + $wasted*0.5;
$proj_high = $profit + $wasted*($topRoi-1)*0.7 + $wasted;

echo '<h4 class="mt-4">🧭 Алгоритм буста (что / куда / зачем)</h4><ol class="lh-lg">';

echo '<li><b>Гео-ставки.</b> ';
if ($scaleGeo){ echo 'Поднять ставку на плюс-гео: ';
  echo implode(', ', array_map(fn($x)=>'<code>'.$x[0].'</code> (ROI '.number_format($x[1],2).'×)', array_slice($scaleGeo,0,5))); echo '. '; }
if ($cutGeo){ echo 'Срезать/обнулить: ';
  echo implode(', ', array_map(fn($x)=>'<code>'.$x[0].'</code> (ROI '.number_format($x[1],2).'×, слив '.usd($x[2]).')', array_slice($cutGeo,0,6))); echo '.'; }
if (!$scaleGeo && !$cutGeo) echo 'Мало данных (нужно ≥'.$MIN_CLK_GEO.' кликов на гео).';
echo ' <span class="small-muted">Кадам: гео-ставки в РК, минималка 0.0001.</span></li>';

echo '<li><b>Блек пабов.</b> ';
if ($blPub){ echo 'В блек (клики есть, lead2=0 / ROI&lt;0.5): ';
  echo implode(', ', array_map(fn($x)=>'<code>'.$x[0].'</code> ('.number_format($x[1]).' кл, '.usd($x[2]).')', array_slice($blPub,0,8)));
  echo '. <span class="badge badge-bad">⚠ поле Кадама <code>bw_list</code> сейчас через API не приклеивается — проверить боем / чинить pub_gen.php</span>'; }
else echo 'Явных дохлых пабов нет.';
echo '</li>';

echo '<li><b>Устройства/форматы.</b> ';
if ($bestDev) echo 'Лучший по конверсии — <code>'.h($bestDev[0]).'</code> (ROI '.number_format($bestDev[1],2).'×). Таргет туда; проверить пуш/инпейдж формат под девайс.';
else echo 'Нет данных по устройствам.';
echo '</li>';

echo '<li><b>Сетки.</b> ';
if ($bestNet) echo 'Основной монетизатор — <code>'.h($bestNet).'</code>. Лить больше на связки, где он платит. ';
if ($deadNet) echo '<span class="badge badge-bad">Не платят: '.implode(', ',array_map('h',$deadNet)).'</span> — код монетизации/интеграция скорее всего битые, проверить.';
echo '</li>';

echo '<li><b>Масштаб.</b> Наращивать спенд только там, где ROI&gt;1.5. Слепой пролив уходит в минус.</li>';
echo '</ol>';

echo '<div class="alert alert-info"><b>📈 Предикт ('.$days.' дн.):</b><br>';
echo 'Сейчас: профит <b>'.usd2($profit).'</b>, ROI '.($tot['cost']>0?number_format($roi,2).'×':'—').', CR2 '.pct($cr2).'.<br>';
echo 'Слив на минус-гео: <b>'.usd2($wasted).'</b>.<br>';
echo 'После оптимизации (срез минуса + перелив в топ-гео ROI '.number_format($topRoi,2).'×): '
   . '<b>'.usd2($proj_low).' … '.usd2($proj_high).'</b> профита за окно.<br>';
echo '<span class="small-muted">Оценка при неизменной push-базе; доход по сегментам — через lead2. Реальный рост выше за счёт накопления tacoloco-подписчиков.</span></div>';

/* ============ AI layer (optional) ============ */
$aiKey = getenv('ANTHROPIC_API_KEY') ?: '';
if ($aiKey==='') {
  $r = db_one($db, "SELECT api_key FROM crm_networks WHERE code IN ('claude','anthropic') AND api_key<>'' LIMIT 1");
  if ($r) $aiKey = trim((string)$r['api_key']);
}
echo '<h4 class="mt-4">🤖 AI-прогноз</h4>';
if ($aiKey==='') {
  echo '<div class="alert alert-secondary">AI-слой выключен. Включить — Anthropic API key: '
     . 'Networks → code <code>claude</code>, поле API key (или env <code>ANTHROPIC_API_KEY</code>). '
     . 'Подписка Claude Code для веб-кнопки не подходит — сервер ходит в <code>api.anthropic.com</code> по ключу (оплата по токенам).</div>';
} else {
  $brief = [
    'domain'=>$domName, 'window_days'=>$days,
    'totals'=>['clicks'=>$tot['clk'],'spend'=>round($tot['cost'],4),'revenue_networks'=>round($rev_net,4),
               'profit'=>round($profit,4),'roi'=>round($roi,3),'cr2'=>round($cr2,4),
               'lead2'=>$tot['l2'],'rev_per_lead2'=>round($rpl,5)],
    'networks_revenue'=>$rev_by_net,
    'top_geo'=>array_map(fn($g)=>['geo'=>$g['k'],'clk'=>(int)$g['clk'],'cost'=>round((float)$g['cost'],4),
        'lead2'=>(int)$g['l2'],'est_rev'=>round($est((int)$g['l2']),4)], array_slice($byGeo,0,15)),
    'top_pubs'=>array_map(fn($p)=>['pub'=>$p['k'],'clk'=>(int)$p['clk'],'cost'=>round((float)$p['cost'],4),
        'lead2'=>(int)$p['l2']], array_slice($byPub,0,25)),
    'devices'=>array_map(fn($d)=>['device'=>$d['k'],'clk'=>(int)$d['clk'],'lead2'=>(int)$d['l2']], $byDev),
  ];
  $sys = "Ты медиабайер по адалт-арбитражу на сетке Kadam (push/inpage), монетизация TacoLoco/MyBid. "
       . "Доход считается на уровне сетей; по сегментам доход оценивается через lead2 (конверсия). "
       . "Отвечай КОРОТКО по-русски, буллеты. Дай прогноз буста LTV и чёткий алгоритм: что/куда/зачем. "
       . "Учитывай специфику Kadam: блек пабов (bw_list), гео-ставки, форматы, eCPM. Без лонгридов.";
  $usr = "Данные домена (JSON):\n".json_encode($brief, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
       . "\n\nДай предикт и алгоритм.";
  $payload = json_encode([
    'model'=>'claude-opus-5','max_tokens'=>1200,
    'system'=>$sys,'messages'=>[['role'=>'user','content'=>$usr]],
  ], JSON_UNESCAPED_UNICODE);
  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>90,
    CURLOPT_HTTPHEADER=>['content-type: application/json','anthropic-version: 2023-06-01','x-api-key: '.$aiKey],
    CURLOPT_POSTFIELDS=>$payload,
  ]);
  $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
  curl_close($ch);
  if ($resp===false) {
    echo '<div class="alert alert-danger">AI-запрос не прошёл: '.h($err).'</div>';
  } else {
    $j = json_decode($resp, true);
    if ($code>=200 && $code<300 && !empty($j['content'])) {
      $txt=''; foreach ($j['content'] as $b) if (($b['type']??'')==='text') $txt.=$b['text'];
      echo '<div class="alert alert-success" style="white-space:pre-wrap">'.h($txt).'</div>';
    } else {
      $msg = $j['error']['message'] ?? substr((string)$resp,0,300);
      echo '<div class="alert alert-danger">Anthropic API '.h((string)$code).': '.h($msg).'</div>';
    }
  }
}

echo '</div></div>';

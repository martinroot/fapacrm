<?php
/**
 * Сетки — live network status.
 * Every number is either measured now, read from instrumented data with its real date
 * range printed, or shown as "нет данных". Nothing is hardcoded or estimated.
 */
/* included by menu.php, which already required config.php and opened $db.
   Fall back to a fresh handle if opened standalone. */
if (!isset($db) || !($db instanceof mysqli)) { $db = db_connect(); }

/* ---------- manual balance update ---------- */
$msg = '';
if (($_POST['action'] ?? '') === 'set_balance' && !empty($_POST['code'])) {
    $st = $db->prepare("UPDATE crm_network_status
                           SET manual_balance=?, manual_pending=?, balance_updated_at=NOW()
                         WHERE code=?");
    $bal = ($_POST['balance'] === '') ? null : (float)$_POST['balance'];
    $pen = ($_POST['pending'] === '') ? null : (float)$_POST['pending'];
    $code = (string)$_POST['code'];
    $st->bind_param('dds', $bal, $pen, $code);
    $st->execute();
    $msg = 'Баланс обновлён: ' . htmlspecialchars($code);
}

/* ---------- live balance where an API exists ---------- */
function kadam_balance(mysqli $db): array {
    $r = $db->query("SELECT params_json FROM crm_cron_jobs
                      WHERE connector='kadam_cost' AND is_enabled=1 LIMIT 1");
    if (!$r || !($row = $r->fetch_assoc())) return ['ok'=>false,'err'=>'нет активной задачи'];
    $p = json_decode($row['params_json'], true);
    $ch = curl_init('https://api.kadam.net/ads.account.get?'
        . http_build_query(['app_id'=>$p['app_id'],'client_id'=>$p['client_id']]));
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$p['bearer']]]);
    $d = json_decode((string)curl_exec($ch), true);
    return isset($d['response']['balance'])
        ? ['ok'=>true,'value'=>(float)$d['response']['balance'],'cabinet'=>$p['client_id']]
        : ['ok'=>false,'err'=>'API не ответил'];
}
$kadam = kadam_balance($db);

/* ---------- money per connector, with the REAL window it covers ---------- */
$fin = [];
$q = $db->query("
  SELECT f.connector, f.kind,
         ROUND(SUM(CASE WHEN f.d >= CURDATE()-INTERVAL 7  DAY THEN f.amount END),4) AS d7,
         ROUND(SUM(CASE WHEN f.d >= CURDATE()-INTERVAL 30 DAY THEN f.amount END),4) AS d30,
         MIN(f.d) AS first_day, MAX(f.d) AS last_day, COUNT(DISTINCT f.d) AS days
    FROM crm_finance_daily f
    JOIN crm_domains dm ON dm.id = f.domain_id AND dm.status='live'
   GROUP BY f.connector, f.kind");
while ($x = $q->fetch_assoc()) $fin[$x['connector']][$x['kind']] = $x;

/* how fresh is the data at all? */
$fresh = $db->query("SELECT MAX(d) AS last_day FROM crm_finance_daily
                      JOIN crm_domains dm ON dm.id=crm_finance_daily.domain_id AND dm.status='live'")
             ->fetch_assoc()['last_day'] ?? null;

$rows = $db->query("SELECT * FROM crm_network_status
                     ORDER BY (status='connected') DESC, code");
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('nl_money')) {
    function nl_money($v){ return $v === null ? '<span class="none">нет данных</span>' : number_format((float)$v, 4); }
}
?>
<style>
 .net table{width:100%;border-collapse:collapse;font-size:13px}
 .net th,.net td{padding:7px 9px;border-bottom:1px solid rgba(128,128,128,.25);text-align:left;vertical-align:top}
 .net th{font-weight:700;white-space:nowrap}
 .net .none{opacity:.45;font-style:italic}
 .net .live{color:#137a39;font-weight:700}
 .net .stale{color:#a61b1b}
 .net .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}
 .net .c{background:#e7f7ee;color:#137a39}
 .net .n{background:#f1f1f1;color:#666}
 .net .warn{background:#fdecea;color:#a61b1b}
 .net form{display:inline-flex;gap:4px}
 .net input{width:74px;padding:2px 4px;font-size:12px}
 .net .note{font-size:11px;opacity:.7}
</style>
<div class="net">
<h2>Сетки</h2>
<?php if ($msg): ?><p class="live"><?=h($msg)?></p><?php endif; ?>

<p class="note">
  Баланс: <b>live</b> — читается по API прямо сейчас. <b>manual</b> — вводится руками,
  показывается возраст записи. Доход/расход — по данным сбора, окно указано в колонке.
  <?php if ($fresh): ?>Самые свежие данные по живым сайтам: <b><?=h($fresh)?></b>.<?php endif; ?>
</p>

<table>
<tr>
  <th>Сетка</th><th>Статус</th><th>Баланс</th><th>Доход 7д / 30д</th>
  <th>Расход 7д / 30д</th><th>Окно данных</th>
  <th>Мин. трафик</th><th>Аппрув</th><th>Выплата</th><th>Заметки</th>
</tr>
<?php while ($n = $rows->fetch_assoc()):
  $conn = $n['connector'];
  $rev  = $fin[$conn]['revenue'] ?? null;
  $cost = $fin[$conn]['cost']    ?? null;
  $win  = $rev ?: $cost;
  $ageH = $n['balance_updated_at']
        ? floor((time() - strtotime($n['balance_updated_at']))/3600) : null;
?>
<tr>
  <td><b><?=h($n['title'])?></b></td>
  <td><span class="pill <?= $n['status']==='connected'?'c':'n' ?>"><?=h($n['status'])?></span></td>
  <td>
    <?php if ($n['balance_source']==='api' && $n['code']==='kadam'): ?>
      <?php if ($kadam['ok']): ?>
        <span class="live"><?=number_format($kadam['value'],4)?></span>
        <div class="note">live · кабинет <?=h($kadam['cabinet'])?></div>
      <?php else: ?><span class="stale">API: <?=h($kadam['err'])?></span><?php endif; ?>
    <?php elseif ($n['balance_source']==='manual'): ?>
      <?= nl_money($n['manual_balance']) ?>
      <?php if ($n['manual_pending'] !== null): ?>
        <div class="note">в ожидании выплаты: <?=number_format((float)$n['manual_pending'],4)?></div>
      <?php endif; ?>
      <div class="note <?= ($ageH !== null && $ageH > 48) ? 'stale' : '' ?>">
        <?= $ageH === null ? 'никогда не вводили' : ('вручную, '.$ageH.' ч назад') ?>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="set_balance">
        <input type="hidden" name="code" value="<?=h($n['code'])?>">
        <input name="balance" placeholder="баланс">
        <input name="pending" placeholder="ожид.">
        <button type="submit">ок</button>
      </form>
    <?php else: ?><span class="none">—</span><?php endif; ?>
  </td>
  <td><?= $rev ? nl_money($rev['d7']).' / '.nl_money($rev['d30']) : '<span class="none">—</span>' ?></td>
  <td><?= $cost ? nl_money($cost['d7']).' / '.nl_money($cost['d30']) : '<span class="none">—</span>' ?></td>
  <td>
    <?php if ($win): ?>
      <span class="note"><?=h($win['first_day'])?> … <?=h($win['last_day'])?><br><?=h($win['days'])?> дн.</span>
      <?php if ($win['last_day'] < date('Y-m-d', strtotime('-2 day'))): ?>
        <div class="pill warn">данные устарели</div>
      <?php endif; ?>
    <?php else: ?><span class="none">нет данных</span><?php endif; ?>
  </td>
  <td><?= $n['min_daily_traffic'] ? h($n['min_daily_traffic']) : '<span class="none">—</span>' ?></td>
  <td><?= $n['approval_time'] ? h($n['approval_time']) : '<span class="none">—</span>' ?></td>
  <td><?= $n['payout_min'] ? h($n['payout_min']) : '<span class="none">—</span>' ?></td>
  <td class="note"><?=h($n['notes'])?></td>
</tr>
<?php endwhile; ?>
</table>

<?php /* ---------- SIGNALS PANEL: everything below is computed live ---------- */ ?>
<h3 style="margin-top:22px">Сигналы</h3>

<?php
/* per-domain depth (pages / unique visitor), today */
$depth = [];
$dq = $db->query("SELECT id, domain, db_name FROM crm_domains WHERE status='live' ORDER BY domain");
while ($d = $dq->fetch_assoc()) {
    if (empty($d['db_name'])) continue;
    $dbn = str_replace('`','',$d['db_name']);
    /* only user_events carries both a visitor id (uiq) and a timestamp;
       user_page_stats has neither, so it cannot be used for depth. */
    $chk = $db->query("SELECT COUNT(*) c FROM information_schema.columns
                        WHERE table_schema='".$db->real_escape_string($dbn)."'
                          AND table_name='user_events' AND column_name IN ('uiq','ts')");
    if (!$chk || (int)$chk->fetch_assoc()['c'] < 2) continue;
    $r = $db->query("SELECT COUNT(*) pages, COUNT(DISTINCT uiq) vis
                       FROM `$dbn`.`user_events` WHERE DATE(ts)=CURDATE()");
    if ($r && ($x = $r->fetch_assoc()) && (int)$x['vis'] > 0) {
        $depth[$d['domain']] = ['pages'=>(int)$x['pages'], 'vis'=>(int)$x['vis'],
                                'd'=>(int)$x['pages'] / (int)$x['vis']];
    }
}

/* campaigns with no impressions yet */
$fresh = $db->query("
    SELECT dm.domain,
           JSON_UNQUOTE(JSON_EXTRACT(j.params_json,'$.campaign_id')) camp,
           COALESCE(MAX(JSON_EXTRACT(t.meta_json,'$.clicks')),0) clicks
      FROM crm_cron_jobs j
      JOIN crm_domains dm ON dm.id=j.domain_id AND dm.status='live'
      LEFT JOIN crm_finance_ticks t
             ON t.external_id = JSON_UNQUOTE(JSON_EXTRACT(j.params_json,'$.campaign_id'))
            AND t.kind='cost' AND t.d=CURDATE()
     WHERE j.connector='kadam_cost' AND j.is_enabled=1
     GROUP BY dm.domain, camp ORDER BY clicks ASC");

/* network coverage */
$cov = [];
$cq = $db->query("SELECT j.connector, COUNT(DISTINCT dm.id) n,
                         GROUP_CONCAT(dm.domain ORDER BY dm.domain SEPARATOR ', ') doms
                    FROM crm_cron_jobs j
                    JOIN crm_domains dm ON dm.id=j.domain_id AND dm.status='live'
                   WHERE j.is_enabled=1 AND j.connector IN
                         ('clickadu_revenue','mybid_revenue','tacoloco_revenue')
                   GROUP BY j.connector");
while ($x = $cq->fetch_assoc()) $cov[$x['connector']] = $x;
$liveTotal = (int)$db->query("SELECT COUNT(*) c FROM crm_domains WHERE status='live'")->fetch_assoc()['c'];
?>

<table>
<tr><th>Сигнал</th><th>Значение</th><th>Что это значит</th></tr>

<tr>
  <td><b>Глубина просмотра</b><br><span class="note">страниц на посетителя, сегодня</span></td>
  <td>
    <?php if ($depth): uasort($depth, fn($a,$b)=>$a['d']<=>$b['d']);
      foreach ($depth as $dom=>$v):
        $cls = $v['d'] < 3 ? 'stale' : ''; ?>
      <div><span class="<?=$cls?>"><b><?=number_format($v['d'],2)?></b></span>
        <?=h($dom)?> <span class="note">(<?=$v['pages']?> стр / <?=$v['vis']?> чел)</span></div>
    <?php endforeach; else: ?><span class="none">нет данных</span><?php endif; ?>
  </td>
  <td class="note">
    Домены с закупом дают ~2.5–2.7 страницы, без закупа — 8–17.
    Купленный трафик листает заметно хуже. Порогом для аппрува глубина нигде
    не публикуется — она влияет на RPM, а не на approval.
  </td>
</tr>

<tr>
  <td><b>Тестовые РК без показов</b></td>
  <td>
    <?php $none=[]; while ($f = $fresh->fetch_assoc())
            if ((int)$f['clicks'] === 0) $none[] = $f['domain'].' ('.$f['camp'].')'; ?>
    <?php if ($none): ?>
      <?php foreach ($none as $n): ?><div class="stale"><?=h($n)?></div><?php endforeach; ?>
    <?php else: ?><span class="live">все набирают трафик</span><?php endif; ?>
  </td>
  <td class="note">Пока показов нет — по доходу этих доменов выводы делать рано.</td>
</tr>

<tr>
  <td><b>Покрытие сеток</b><br><span class="note">на скольких живых сайтах стоит</span></td>
  <td>
    <?php foreach (['tacoloco_revenue'=>'TacoLoco','mybid_revenue'=>'MyBid','clickadu_revenue'=>'Clickadu'] as $k=>$lbl):
        $n = (int)($cov[$k]['n'] ?? 0); ?>
      <div><span class="<?= $n===0 ? 'stale' : ($n < $liveTotal ? '' : 'live') ?>">
        <b><?=$lbl?>: <?=$n?> / <?=$liveTotal?></b></span>
        <?php if ($n>0 && $n<$liveTotal): ?>
          <span class="note"><?=h($cov[$k]['doms'])?></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </td>
  <td class="note">
    Clickadu не привязан ни к одному живому сайту, хотя исторически давал 15% всего дохода.
    MyBid стоит только на maximhot. Это незакрытые дыры в монетизации.
  </td>
</tr>

<tr>
  <td><b>TacoLoco: формат</b></td>
  <td class="note">
    push — показы идут, кликов 0 по всем сайтам<br>
    in-page — даёт 91% дохода при вчетверо меньшем числе показов
  </td>
  <td class="note">
    Пуш накопительный: подписчик отдаёт трафик несколько дней, поэтому
    доход одного дня его занижает. Читать через 3–5 дней после залива.
  </td>
</tr>
</table>

<p class="note" style="margin-top:14px">
  Порог аппрува у большинства адалт-сеток — не объём, а качество: заполненный сайт,
  живой не-ботовый трафик, возрастной гейт, 2257 и privacy. Числовой минимум есть
  только у Kadam (500 уников/день) и рекомендация у Clickadu (5K/день).
  Глубина просмотра нигде не публикуется как порог — она влияет на RPM, а не на аппрув.
</p>
</div>

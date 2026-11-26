<?php
declare(strict_types=1);

/**
 * pages/geo.php — картина по странам: ставка, объём, качество, доход, ROI.
 *
 * Расход считаем как клики × текущую ставку этой страны. Ставки разные по гео,
 * поэтому одной цифрой умножать нельзя.
 *
 * «Живых» — доля кликов, где отработал JS. Вся реклама у нас на JS, поэтому
 * клик без него не может принести денег в принципе, и это половина ответа
 * на вопрос «почему гео не окупается».
 *
 * Ставка ниже 0.0001 означает не «дешевле», а «не крутится»: у Kadam там порог
 * аукциона. Такие строки помечаются отдельно.
 */

csrf_check();
$db = db_connect();

const FLOOR = 0.0001;

$days = max(1, min(30, (int)req_str('days', '3')));
$from = date('Y-m-d', strtotime("-".($days-1)." day"));

$dir = dirname(__DIR__) . '/var/cache';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$cache = $dir . "/geo_{$days}.json";
$force = req_str('refresh', '') === '1';
$rows = null;
if (!$force && is_file($cache) && (time() - filemtime($cache)) < 600) {
    $c = json_decode((string)file_get_contents($cache), true);
    if (is_array($c) && $c) $rows = $c;
}
$age = is_file($cache) ? time() - filemtime($cache) : null;

if ($rows === null) {
    $rows = [];

    /* клики и качество из собственных логов */
    $dbs = [];
    $q = $db->query("SELECT db_name FROM crm_domains WHERE status='live' AND db_name<>''");
    while ($q && ($x = $q->fetch_row()))
        if (@$db->query("SELECT 1 FROM `{$x[0]}`.clicks LIMIT 1")) $dbs[] = $x[0];
    if ($dbs) {
        $parts = [];
        foreach ($dbs as $d)
            $parts[] = "SELECT country, js FROM `$d`.clicks WHERE DATE(ts) >= '{$from}'";
        $res = $db->query("SELECT country, COUNT(*) n, SUM(CASE WHEN js>0 THEN 1 ELSE 0 END) lv
                             FROM (".implode(' UNION ALL ', $parts).") u GROUP BY country");
        while ($res && ($x = $res->fetch_assoc())) {
            $cc = strtoupper(trim((string)$x['country']));
            if ($cc === '') continue;
            $rows[$cc] = ['cc'=>$cc,'clicks'=>(int)$x['n'],'live'=>(int)$x['lv'],
                          'rev'=>0.0,'imp'=>0,'bid'=>null];
        }
    }

    /* доход и показы по странам из сетки */
    $t = $db->query("SELECT params_json FROM crm_cron_jobs
                      WHERE connector='tacoloco_revenue' AND is_enabled=1 LIMIT 1")->fetch_assoc();
    if ($t) {
        $p = json_decode($t['params_json'], true);
        $base = rtrim($p['base_url'] ?? 'https://api.tacolo.co','/');
        foreach (['push','inpagepush'] as $type) {
            $u = "$base/publisher/v1/statistics/$type/country?".http_build_query(
                ['startDate'=>$from,'endDate'=>date('Y-m-d'),'token'=>$p['token']],
                '','&',PHP_QUERY_RFC3986);
            $ch = curl_init($u);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,
                CURLOPT_HTTPHEADER=>['Accept: application/json']]);
            $j = json_decode((string)curl_exec($ch), true);
            foreach ((array)(is_array($j) ? (array_is_list($j)?$j:($j['data']??[])) : []) as $x) {
                if (!is_array($x)) continue;
                $cc = strtoupper((string)($x['Code2'] ?? ''));
                if ($cc === '') continue;
                if (!isset($rows[$cc])) $rows[$cc] = ['cc'=>$cc,'clicks'=>0,'live'=>0,
                                                      'rev'=>0.0,'imp'=>0,'bid'=>null];
                $rows[$cc]['rev'] += (float)($x['Revenue'] ?? 0);
                $rows[$cc]['imp'] += (int)($x['Impressions'] ?? 0);
            }
        }
    }

    /* текущие ставки: кампаний больше трёхсот, читаем постранично */
    $kp = $db->query("SELECT params_json FROM crm_cron_jobs
                       WHERE connector='kadam_cost' AND is_enabled=1 LIMIT 1")->fetch_assoc();
    if ($kp) {
        $p = json_decode($kp['params_json'], true);
        $byId = [];
        $r2 = $db->query("SELECT kadam_id, cc FROM db_maximhot_ru.kadam_geolist WHERE cc<>''");
        while ($r2 && ($x = $r2->fetch_assoc())) $byId[(string)$x['kadam_id']] = strtoupper($x['cc']);

        $cid = (string)($db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(j.params_json,'$.campaign_id')) c
                                      FROM crm_cron_jobs j JOIN crm_domains d ON d.id=j.domain_id
                                     WHERE j.connector='kadam_cost' AND d.status='live' LIMIT 1")
                            ->fetch_assoc()['c'] ?? '');
        $found = null; $off = 0;
        for ($i = 0; $i < 12 && $found === null; $i++) {
            $ch = curl_init("https://api.kadam.net/ads.campaigns.get?".http_build_query(
                ['app_id'=>$p['app_id'],'client_id'=>$p['app_id'],'limit'=>100,'offset'=>$off]));
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>40,
                CURLOPT_HTTPHEADER=>["Authorization: Bearer {$p['bearer']}"]]);
            $d2 = json_decode((string)curl_exec($ch), true);
            $it = $d2['response']['items'] ?? [];
            if (!$it) break;
            foreach ($it as $c2) if ((string)$c2['id'] === $cid) $found = $c2;
            if (count($it) < 100) break;
            $off += 100;
        }
        if ($found) foreach ((array)($found['countries'] ?? []) as $g => $v) {
            $cc = $byId[(string)$g] ?? null;
            if (!$cc) continue;
            if (!isset($rows[$cc])) $rows[$cc] = ['cc'=>$cc,'clicks'=>0,'live'=>0,
                                                  'rev'=>0.0,'imp'=>0,'bid'=>null];
            $rows[$cc]['bid'] = (float)($v['bid'] ?? 0);
        }
    }

    file_put_contents($cache, json_encode($rows));
    $age = 0;
}

$data = array_values($rows);
foreach ($data as &$r) {
    $r['spend'] = $r['bid'] !== null ? $r['clicks'] * $r['bid'] : 0.0;
    $r['roi']   = $r['spend'] > 0 ? ($r['rev'] - $r['spend']) / $r['spend'] * 100 : null;
    $r['ecpm']  = $r['imp'] > 0 ? $r['rev'] / $r['imp'] * 1000 : 0.0;
    $r['lp']    = $r['clicks'] > 0 ? $r['live'] / $r['clicks'] * 100 : 0.0;
}
unset($r);

$sort = req_str('sort', 'spend');
usort($data, function ($a, $b) use ($sort) {
    return match ($sort) {
        'roi'    => ($b['roi'] ?? -9999) <=> ($a['roi'] ?? -9999),
        'rev'    => $b['rev'] <=> $a['rev'],
        'clicks' => $b['clicks'] <=> $a['clicks'],
        'ecpm'   => $b['ecpm'] <=> $a['ecpm'],
        'cc'     => strcmp($a['cc'], $b['cc']),
        default  => $b['spend'] <=> $a['spend'],
    };
});

$tS=$tR=0.0; $tC=$tL=$tI=0;
foreach ($data as $r) { $tS+=$r['spend']; $tR+=$r['rev']; $tC+=$r['clicks']; $tL+=$r['live']; $tI+=$r['imp']; }
function lnk(string $k, string $label, string $cur, int $days): string {
    $b = $k === $cur ? 'font-weight:600;text-decoration:underline' : '';
    return '<a style="'.$b.'" href="menu.php?page=geo&days='.$days.'&sort='.$k.'">'.$label.'</a>';
}
?>
<style>
  .g-cards { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
  .g-card { flex:1 1 130px; padding:9px 11px; border-radius:10px;
            background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12); }
  .g-card .k { font-size:12px; opacity:.7; } .g-card .v { font-size:18px; font-weight:600; }
  .g-pos { color:#3ddc84; } .g-neg { color:#ff6b6b; } .g-off { color:#ffa94d; }
  table.g { width:100%; border-collapse:collapse; font-size:14px; }
  table.g th, table.g td { padding:6px 9px; border-bottom:1px solid rgba(255,255,255,.08); text-align:right; }
  table.g th:first-child, table.g td:first-child { text-align:left; }
  table.g th { font-weight:600; opacity:.75; white-space:nowrap; }
  table.g tbody tr:hover { background:rgba(255,255,255,.05); }
</style>

<h3 class="mb-2">🌍 По гео</h3>
<p style="font-size:12px;opacity:.6">
  Обновлено <?= $age === null || $age < 60 ? 'только что' : intdiv((int)$age,60).' мин назад' ?>, кеш 10 минут ·
  <a href="menu.php?page=geo&days=<?=$days?>&sort=<?=h($sort)?>&refresh=1">обновить</a>
</p>

<form method="get" class="mb-3">
  <input type="hidden" name="page" value="geo">
  <input type="hidden" name="sort" value="<?=h($sort)?>">
  <label>Период:
    <select name="days" onchange="this.form.submit()">
      <?php foreach ([1,3,7,14,30] as $d): ?>
        <option value="<?=$d?>" <?=$d===$days?'selected':''?>><?=$d===1?'сегодня':$d.' дней'?></option>
      <?php endforeach; ?>
    </select>
  </label>
</form>

<div class="g-cards">
  <div class="g-card"><div class="k">Расход</div><div class="v"><?=number_format($tS,4)?></div></div>
  <div class="g-card"><div class="k">Доход</div><div class="v"><?=number_format($tR,4)?></div></div>
  <div class="g-card"><div class="k">Профит</div>
    <div class="v <?=($tR-$tS)>=0?'g-pos':'g-neg'?>"><?=number_format($tR-$tS,4)?></div></div>
  <div class="g-card"><div class="k">ROI</div>
    <div class="v <?=($tR-$tS)>=0?'g-pos':'g-neg'?>"><?=$tS>0?sprintf('%+.0f%%',($tR-$tS)/$tS*100):'—'?></div></div>
  <div class="g-card"><div class="k">Клики</div><div class="v"><?=number_format($tC,0,'.',' ')?></div></div>
  <div class="g-card"><div class="k">Живых</div><div class="v"><?=$tC?number_format($tL/$tC*100,0):0?>%</div></div>
</div>

<p style="font-size:13px;opacity:.8">Сортировка:
  <?=lnk('spend','по расходу',$sort,$days)?> ·
  <?=lnk('roi','по ROI',$sort,$days)?> ·
  <?=lnk('rev','по доходу',$sort,$days)?> ·
  <?=lnk('clicks','по кликам',$sort,$days)?> ·
  <?=lnk('ecpm','по eCPM',$sort,$days)?> ·
  <?=lnk('cc','по алфавиту',$sort,$days)?>
</p>

<table class="g">
  <thead><tr>
    <th>Гео</th><th>Ставка</th><th>Клики</th><th>Живых</th><th>Расход</th>
    <th>Показы</th><th>Доход</th><th>eCPM</th><th>ROI</th>
  </tr></thead>
  <tbody>
  <?php foreach ($data as $r):
    $off = $r['bid'] !== null && $r['bid'] > 0 && $r['bid'] < FLOOR - 1e-9; ?>
    <tr>
      <td><strong><?=h($r['cc'])?></strong><?= $r['bid'] === null ? ' <span style="opacity:.5">не в таргете</span>' : '' ?></td>
      <td class="<?=$off?'g-off':''?>">
        <?= $r['bid'] === null ? '—' : number_format($r['bid'],5) ?><?= $off ? ' ниже порога' : '' ?>
      </td>
      <td><?=number_format($r['clicks'],0,'.',' ')?></td>
      <td class="<?= $r['clicks']>=200 && $r['lp']<55 ? 'g-neg' : '' ?>"><?=number_format($r['lp'],0)?>%</td>
      <td><?=number_format($r['spend'],4)?></td>
      <td><?=number_format($r['imp'],0,'.',' ')?></td>
      <td><?=number_format($r['rev'],4)?></td>
      <td><?=number_format($r['ecpm'],4)?></td>
      <td class="<?= $r['roi']===null ? '' : ($r['roi']>=0?'g-pos':'g-neg') ?>">
        <?= $r['roi']===null ? '—' : sprintf('%+.0f%%',$r['roi']) ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<p style="font-size:12px;opacity:.65;margin-top:10px">
  Расход = клики × ставка этой страны. Живых — доля кликов, где отработал JS;
  без него реклама не грузится и заработать нельзя, поэтому меньше 55% на объёме
  подсвечено. Ставка ниже 0.0001 — порог аукциона Kadam, такое гео фактически не
  крутится. Доход и показы приходят из статистики сетки по странам.
</p>

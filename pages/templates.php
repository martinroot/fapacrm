<?php
/**
 * Шаблоны — реестр 34 HTML-шаблонов (v1, доноры) + назначение Домен > шаблон.
 * Назначение — ВСЕ домены сети (crm_domains, любой сервер/статус),
 * хранится в crm_domains.template_code (механизм прежний).
 * Превью шаблона — статические HTML в /var/www/pornfluxx.ru/money/templates/v1/<id>/.
 */
if (!isset($db) || !($db instanceof mysqli)) { $db = db_connect(); }
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
csrf_check();

$msg = '';
$act = $_POST['action'] ?? '';

/** каталог шаблонов: donors.json + факт наличия HTML в v1 */
$cat = json_decode((string)@file_get_contents('/var/www/pornfluxx.ru/money/donors.json'), true) ?: [];
$meta = [];
foreach (($cat['templates'] ?? []) as $t) {
    $id = (string)($t['id'] ?? '');
    if ($id === '') continue;
    $d = '/var/www/pornfluxx.ru/money/templates/v1/' . $id;
    $files = [];
    foreach (['index.html','categories.html','category.html','video.html'] as $f)
        if (is_file($d . '/' . $f)) $files[] = $f;
    if ($files) $meta['d_' . $id] = ['id' => $id, 'name' => (string)($t['name'] ?? $id),
        'theme' => (string)($t['theme'] ?? ''), 'source_domain' => (string)($t['source_domain'] ?? ''),
        'notes' => (string)($t['notes'] ?? ''), 'files' => $files];
}

/** реестр шаблонов из БД: только d_* (34 новых HTML-шаблона) */
$tpl = [];
$r = $db->query("SELECT code, title, verdict, source_ref, notes FROM crm_site_templates
                 WHERE code LIKE 'd\\_%' ORDER BY code");
while ($x = $r->fetch_assoc()) {
    $code = (string)$x['code'];
    $m = $meta[$code] ?? [];
    $tid = $m['id'] ?? substr($code, 2);
    $preview = '';
    foreach (['index.html','categories.html','category.html'] as $f)
        if (is_file('/var/www/pornfluxx.ru/money/templates/v1/' . $tid . '/' . $f)) {
            $preview = 'https://pornfluxx.ru/money/templates/v1/' . $tid . '/' . $f; break;
        }
    $tpl[$code] = [
        'code' => $code,
        'title' => (string)$x['title'],
        'verdict' => (string)$x['verdict'],
        'donor' => $tid,
        'theme' => $m['theme'] ?? '',
        'source_domain' => $m['source_domain'] ?? '',
        'notes' => ($m['notes'] ?? '') ?: (string)$x['notes'],
        'files' => $m['files'] ?? [],
        'preview' => $preview,
    ];
}

/** ВСЕ домены сети + сервер-код */
$live = [];
$r = $db->query("SELECT d.domain, d.template_code, d.template_applied_at, d.status,
                        COALESCE(d.server_name,'') AS srv
                 FROM crm_domains d
                 ORDER BY d.server_ip, d.domain");
while ($x = $r->fetch_assoc()) $live[] = $x;

/** --- actions --- */
if ($act === 'assign' && !empty($_POST['domain']) && !empty($_POST['code'])) {
    $d = (string)$_POST['domain']; $c = (string)$_POST['code'];
    if (isset($tpl[$c])) {
        $st = $db->prepare("UPDATE crm_domains SET template_code=?, template_applied_at=NULL WHERE domain=?");
        $st->bind_param('ss', $c, $d); $st->execute();
        $msg = "Назначено: {$d} → {$c}";
    } else $msg = "Неизвестный шаблон {$c}";
}

if ($act === 'random') {
    $codes = array_keys($tpl);
    shuffle($codes);
    $i = 0; $done = 0;
    $st = $db->prepare("UPDATE crm_domains SET template_code=?, template_applied_at=NULL WHERE domain=?");
    foreach ($live as $x) {
        $c = $codes[$i % count($codes)];
        $st->bind_param('ss', $c, $x['domain']); $st->execute();
        $i++; $done++;
    }
    $msg = "Рандомно назначено {$done} доменам (шаблонов в ротации: " . count($codes) . ")";
    $r = $db->query("SELECT d.domain, d.template_code, d.template_applied_at, d.status,
                            COALESCE(d.server_name,'') AS srv
                     FROM crm_domains d
                     ORDER BY d.server_ip, d.domain");
    $live = [];
    while ($x = $r->fetch_assoc()) $live[] = $x;
}

if ($act === 'verdict' && !empty($_POST['code'])) {
    $st = $db->prepare("UPDATE crm_site_templates SET verdict=? WHERE code=?");
    $v = in_array($_POST['verdict'] ?? '', ['new','good','bad'], true) ? $_POST['verdict'] : 'new';
    $c = (string)$_POST['code'];
    $st->bind_param('ss', $v, $c); $st->execute();
    $msg = "Шаблон {$c}: вердикт {$v}";
    foreach ($tpl as $code => $t) if ($code === $c) $tpl[$code]['verdict'] = $v;
}

$cnt = ['new'=>0,'good'=>0,'bad'=>0];
foreach ($tpl as $t) $cnt[$t['verdict']] = ($cnt[$t['verdict']] ?? 0) + 1;
$used = [];
foreach ($live as $x) if (!empty($x['template_code'])) $used[$x['template_code']][] = $x['domain'];
?>
<style>
 .tpl table{width:100%;border-collapse:collapse;font-size:13px}
 .tpl th,.tpl td{padding:8px 9px;border-bottom:1px solid rgba(128,128,128,.25);text-align:left;vertical-align:top}
 .tpl th{white-space:nowrap;font-weight:700}
 .tpl .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}
 .tpl .good{background:#e7f7ee;color:#137a39}
 .tpl .bad{background:#fdecea;color:#a61b1b}
 .tpl .new{background:#f1f1f1;color:#666}
 .tpl .prev{color:#4f46e5;text-decoration:none}
 .tpl .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:10px}
 .tpl .card{border:1px solid rgba(128,128,128,.25);border-radius:10px;padding:10px}
 .tpl .card .nm{font-weight:700}
 .tpl .card .mut{color:#777;font-size:12px}
 .tpl .row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
 .tpl select,.tpl button{font-size:12.5px;padding:4px 8px;border-radius:8px;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit}
 .tpl button{cursor:pointer}
 .tpl .randbtn{font-weight:800;background:#4f46e5;color:#fff;border:none;padding:8px 14px;border-radius:10px;cursor:pointer}
 .tpl .st-live{color:#137a39;font-weight:700}
 .tpl .st-off{color:#a61b1b}
 .tpl .srv{display:inline-block;background:#eef2ff;color:#4f46e5;border-radius:5px;padding:1px 6px;font-size:11px;font-weight:700}
</style>
<div class="tpl">
<?php if ($msg): ?><div style="background:#eef;border:1px solid #88f;border-radius:8px;padding:8px 12px;margin-bottom:10px"><?= h($msg) ?></div><?php endif; ?>

<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
  <b>Шаблоны (<?= count($tpl) ?>, HTML-доноры v1)</b>
  <span class="pill new">new <?= $cnt['new'] ?? 0 ?></span>
  <span class="pill good">good <?= $cnt['good'] ?? 0 ?></span>
  <span class="pill bad">bad <?= $cnt['bad'] ?? 0 ?></span>
  <form method="post" style="margin-left:auto" onsubmit="return confirm('Рандомно распределить <?= count($tpl) ?> шаблонов по <?= count($live) ?> доменам?')">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="random">
    <button class="randbtn">🎲 Рандомно назначить Домен → шаблон</button>
  </form>
</div>

<h3 style="margin:14px 0 6px">Назначение: Домен → шаблон (все домены сети: <?= count($live) ?>)</h3>
<div class="grid">
<?php foreach ($live as $x):
    $c = (string)$x['template_code'];
    $t = $tpl[$c] ?? null;
    $stCls = ($x['status'] === 'live') ? 'st-live' : 'st-off';
?>
  <div class="card">
    <div class="row">
      <div class="nm" style="min-width:210px">
        <a href="https://<?= h($x['domain']) ?>/" target="_blank"><?= h($x['domain']) ?></a>
        <span class="srv"><?= h($x['srv'] ?: '?') ?></span>
        <span class="<?= $stCls ?>"><?= h($x['status']) ?></span>
      </div>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="domain" value="<?= h($x['domain']) ?>">
        <select name="code">
          <option value="">— не выбран —</option>
          <?php foreach ($tpl as $code => $tt): ?>
            <option value="<?= h($code) ?>" <?= $code === $c ? 'selected' : '' ?>><?= h($code) ?> — <?= h($tt['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <button>Назначить</button>
      </form>
    </div>
    <?php if ($t): ?>
      <div class="mut" style="margin-top:6px">
        <?= h($t['title']) ?> · донор: <a class="prev" href="<?= h($t['preview']) ?>" target="_blank">HTML-превью ↗</a>
        <?php if ($t['source_domain']): ?> · источник: <?= h($t['source_domain']) ?><?php endif; ?>
        · applied: <?= h($x['template_applied_at'] ?: '—') ?>
      </div>
    <?php elseif ($c): ?><div class="mut" style="margin-top:6px;color:#a61b1b">шаблон «<?= h($c) ?>» не в реестре 34</div><?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<h3 style="margin:18px 0 6px">Реестр шаблонов (<?= count($tpl) ?>)</h3>
<table>
  <tr><th>code</th><th>название</th><th>донор</th><th>превью</th><th>verdict</th><th>назначен доменам</th><th></th></tr>
<?php foreach ($tpl as $t): ?>
  <tr>
    <td><b><?= h($t['code']) ?></b></td>
    <td><?= h($t['title']) ?><div class="mut"><?= h(mb_substr($t['notes'], 0, 120)) ?></div></td>
    <td><?= h($t['source_domain']) ?></td>
    <td><?php if ($t['preview']): ?><a class="prev" href="<?= h($t['preview']) ?>" target="_blank">HTML ↗</a><?php endif; ?></td>
    <td><span class="pill <?= h($t['verdict']) ?>"><?= h($t['verdict']) ?></span></td>
    <td><?= h(implode(', ', $used[$t['code']] ?? [])) ?></td>
    <td>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="verdict">
        <input type="hidden" name="code" value="<?= h($t['code']) ?>">
        <select name="verdict">
          <?php foreach (['new','good','bad'] as $v): ?>
            <option value="<?= $v ?>" <?= $t['verdict'] === $v ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
        <button>ok</button>
      </form>
    </td>
  </tr>
<?php endforeach; ?>
</table>
</div>

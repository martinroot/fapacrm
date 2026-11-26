<?php
/**
 * Прогресс модернизации доменов (внедрение шаблонов d_*).
 * Источники: fapacrm.crm_domains (шаблон, язык, дата done), каталог слайдов превью.
 * Read-only страница (без POST).
 */
if (!isset($db) || !($db instanceof mysqli)) { $db = db_connect(); }
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/** каталог шаблонов (имена из donors.json) */
$meta = [];
$cat = json_decode((string)@file_get_contents('/var/www/pornfluxx.ru/money/donors.json'), true) ?: [];
foreach (($cat['templates'] ?? []) as $t) {
    $id = (string)($t['id'] ?? '');
    if ($id !== '') $meta['d_' . $id] = (string)($t['name'] ?? $id);
}

/** прогресс превью: каталогов в preview / mp4 (кэш 5 мин) */
$cacheFile = '/tmp/fapacrm-preview-progress.json';
$prev = ['dirs' => 0, 'mp4' => 0, 'ts' => 0];
if (is_file($cacheFile)) {
    $prev = json_decode((string)@file_get_contents($cacheFile), true) ?: $prev;
}
if (time() - (int)($prev['ts'] ?? 0) > 300) {
    $dirs = 0;
    $pd = @opendir('/var/www/wildfucktube-media/preview');
    if ($pd) {
        while (($f = readdir($pd)) !== false) {
            if ($f !== '.' && $f !== '..' && is_dir('/var/www/wildfucktube-media/preview/' . $f)) $dirs++;
        }
        closedir($pd);
    }
    $mp4 = 0;
    $md = @opendir('/var/www/wildfucktube-media/videos');
    if ($md) {
        while (($f = readdir($md)) !== false) {
            if (substr($f, -4) === '.mp4') $mp4++;
        }
        closedir($md);
    }
    $prev = ['dirs' => $dirs, 'mp4' => $mp4, 'ts' => time()];
    @file_put_contents($cacheFile, json_encode($prev));
}
$prevPct = $prev['mp4'] > 0 ? round(100 * $prev['dirs'] / $prev['mp4']) : 0;

$res = $db->query("SELECT domain, server_name, lang, template_code, template_applied_at, status
                   FROM crm_domains ORDER BY (server_name='edge-147') DESC, domain");
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;

$edge = array_values(array_filter($rows, fn($r) => ($r['server_name'] ?? '') === 'edge-147'));
$other = array_values(array_filter($rows, fn($r) => ($r['server_name'] ?? '') !== 'edge-147'));
$done = count(array_filter($edge, fn($r) => !empty($r['template_applied_at'])));

echo '<h2 class="mb-3">🚀 Прогресс модернизации доменов</h2>';
echo '<div class="row g-3 mb-4">';
echo '<div class="col-md-4"><div class="card text-bg-dark"><div class="card-body">';
echo '<div class="h3 mb-0">' . $done . ' / ' . count($edge) . '</div><div class="text-muted">внедрено на edge-147</div>';
echo '</div></div></div>';
echo '<div class="col-md-4"><div class="card text-bg-dark"><div class="card-body">';
echo '<div class="h3 mb-0">' . number_format($prev['dirs']) . ' / ' . number_format($prev['mp4']) . ' (' . $prevPct . '%)</div>';
echo '<div class="text-muted">слайды превью сгенерированы</div>';
echo '</div></div></div>';
echo '<div class="col-md-4"><div class="card text-bg-dark"><div class="card-body">';
echo '<div class="h3 mb-0">EN</div><div class="text-muted">основной язык (один язык, без /en/)</div>';
echo '</div></div></div></div>';

function rollout_table(array $rows, array $meta): void {
    echo '<div class="table-responsive"><table class="table table-dark table-striped table-hover align-middle">';
    echo '<thead><tr><th>Домен</th><th>Сервер</th><th>Язык</th><th>Шаблон</th><th>Превью</th><th>Статус</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $dom = (string)$r['domain'];
        $code = trim((string)($r['template_code'] ?? ''));
        $applied = !empty($r['template_applied_at']);
        $isEdge = ($r['server_name'] ?? '') === 'edge-147';
        $status = $applied
            ? '<span class="badge text-bg-success">✅ done ' . h(date('d.m', strtotime((string)$r['template_applied_at']))) . '</span>'
            : ($isEdge ? '<span class="badge text-bg-secondary">⏳ ожидает</span>'
                       : '<span class="badge text-bg-warning">origin-169 (DNS за оператором)</span>');
        $tname = $code !== '' && isset($meta[$code]) ? ' <span class="text-muted small">(' . h($meta[$code]) . ')</span>' : '';
        $lang = (string)($r['lang'] ?? 'en') !== '' ? strtoupper((string)$r['lang']) : 'EN';
        $tplCell = $code !== '' ? '<code>' . h($code) . '</code>' . $tname : '<span class="text-muted">—</span>';
        echo '<tr><td><strong>' . h($dom) . '</strong></td><td>' . h($r['server_name'] ?? '') . '</td>'
           . '<td>' . h($lang) . '</td><td>' . $tplCell . '</td>'
           . '<td>' . ($isEdge ? '<span class="text-muted small">общая база: ' . number_format($prev['dirs'] ?? 0) . '</span>' : '—') . '</td>'
           . '<td>' . $status . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

echo '<h5 class="mt-2">edge-147 — клоны</h5>';
rollout_table($edge, $meta);
if ($other) {
    echo '<h5 class="mt-4">Другие серверы (штабы)</h5>';
    rollout_table($other, $meta);
}
echo '<p class="text-muted small mt-3">Статус «done» = заполненный template_applied_at (см. меню «🎨 Шаблоны»). '
   . 'Превью — общая база слайдов /var/www/wildfucktube-media/preview (кэш 5 мин).</p>';

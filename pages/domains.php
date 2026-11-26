<?php
// /var/www/fapa.io/fapacrm/pages/domains.php
declare(strict_types=1);

csrf_check();
$db = db_connect();

/**
 * Домены — прогресс по реальным данным:
 * - SSL: ssl_status=ok
 * - Сетка: ad_network not empty
 * - API: закрываем если есть ВКЛЮЧЕННАЯ cron-задача job_type='revenue'
 * - Деплой: deploy_status=done ИЛИ (status=live && path+db_name)
 * - Счётчики: crm_domain_counters (если включён и заполнен хотя бы 1)
 * - Ads: если есть хотя бы 1 включенный блок И он привязан к странице
 * - Cron: если есть хотя бы 1 включенная задача (любого типа)
 * - Трафик: по crm_finance_daily kind='metric' connector='yandex_metrika_today' (visits>0)
 * - ROI: по crm_finance_daily (есть cost>0 и revenue>0 за день, считаем ROI)
 * - SEO: по наличию файла {path}/domains/robots.txt
 * - Авто-закуп: по наличию spend>0 (cost) за день
 *
 * Чек-лист в модалке оставлен (crm_check_fields/values) — но прогресс-колонки выше считаются из реальных таблиц.
 *
 * PHP 7.x compatible
 */

// -------------------- helpers --------------------

function badge_ok($ok): string { return $ok ? 'bg-success' : 'bg-danger'; }
function is_nonempty($s): bool { return trim((string)$s) !== ''; }

function get_val(array $map, int $domainId, int $fieldId): array {
  $k = $domainId . ':' . $fieldId;
  return (isset($map[$k]) && is_array($map[$k])) ? $map[$k] : [];
}

function field_filled(string $type, array $v): bool {
  if (!$v) return false;
  if ($type === 'bool') return (int)($v['val_bool'] ?? 0) === 1;
  if ($type === 'number') return isset($v['val_number']) && $v['val_number'] !== null && $v['val_number'] !== '';
  if ($type === 'date') return isset($v['val_date']) && (string)$v['val_date'] !== '';
  return isset($v['val_text']) && trim((string)$v['val_text']) !== '';
}

function field_value_as_string(string $type, array $v): string {
  if (!$v) return '';
  if ($type === 'bool') return ((int)($v['val_bool'] ?? 0) === 1) ? '1' : '0';
  if ($type === 'number') return (string)($v['val_number'] ?? '');
  if ($type === 'date') return (string)($v['val_date'] ?? '');
  return (string)($v['val_text'] ?? '');
}

function status_badge_class(string $s): string {
  $s = strtolower(trim($s));
  if ($s === 'live') return 'bg-success';
  if ($s === 'queue') return 'bg-warning text-dark';
  if ($s === 'broken') return 'bg-danger';
  if ($s === 'parked') return 'bg-dark';
  return 'bg-secondary';
}

function normalize_domain(string $raw): string {
  $s = trim($raw);
  if ($s === '') return '';
  $s = preg_replace('~^\s*https?://~i', '', $s);
  $s = preg_replace('~^\s*www\.~i', '', $s);
  $s = preg_replace('~/.*$~', '', $s);
  $s = preg_replace('~\s+~', '', $s);
  $s = trim($s, ". \t\n\r\0\x0B");
  return strtolower($s);
}

// сетки в select
function render_network_options(array $networks, string $current): string {
  $cur = trim((string)$current);
  $out = '<option value="">— не выбрано —</option>';
  foreach ($networks as $n) {
    $code  = (string)($n['code'] ?? '');
    if ($code === '') continue;
    $title = (string)($n['title'] ?? $code);
    $sel = ($code === $cur) ? ' selected' : '';
    $out .= '<option value="'.h($code).'"'.$sel.'>'.h($title).' ('.h($code).')</option>';
  }
  return $out;
}

function safe_file_exists(?string $path): bool {
  if (!$path) return false;
  $p = trim((string)$path);
  if ($p === '') return false;
  // не трогаем URL, только локальные пути
  if (preg_match('~^\w+://~', $p)) return false;
  return is_file($p);
}

// -------------------- actions --------------------

// save domain (from modal) + save checklist fields in one POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('save_domain', false)) {
  $domain_id   = req_int('domain_id', 0);

  $domain      = trim(req_str('domain', ''));
  $registrar   = trim(req_str('registrar', ''));
  $status      = trim(req_str('status', 'new'));
  $ssl_status  = trim(req_str('ssl_status', 'todo'));
  $ad_network  = trim(req_str('ad_network', ''));
  $server_name = trim(req_str('server_name', ''));
  $server_ip   = trim(req_str('server_ip', ''));
  $path        = trim(req_str('path', ''));
  $db_name     = trim(req_str('db_name', ''));
  $notes       = trim(req_str('notes', ''));

  if ($domain === '') {
    flash_set('danger', 'Домен обязателен');
    header('Location: menu.php?page=domains'); exit;
  }

  if ($domain_id > 0) {
    db_exec($db, "UPDATE crm_domains
      SET domain=?, registrar=?, status=?, ssl_status=?, ad_network=?, server_name=?, server_ip=?, path=?, db_name=?, notes=?
      WHERE id=?",
      [$domain,$registrar,$status,$ssl_status,$ad_network,$server_name,$server_ip,$path,$db_name,$notes,$domain_id]
    );
  } else {
    if ($path === '') $path = '/var/www/'.$domain;
    if ($db_name === '') {
      $safe = preg_replace('/[^a-zA-Z0-9_]+/', '_', $domain);
      $db_name = 'db_'.$safe;
    }
    db_exec($db, "INSERT IGNORE INTO crm_domains(domain,registrar,status,ssl_status,ad_network,server_name,server_ip,path,db_name,notes)
      VALUES(?,?,?,?,?,?,?,?,?,?)",
      [$domain,$registrar,$status,$ssl_status,$ad_network,$server_name,$server_ip,$path,$db_name,$notes]
    );
    $row = db_one($db, "SELECT id FROM crm_domains WHERE domain=?", [$domain]);
    $domain_id = (int)($row['id'] ?? 0);
  }

  // Save checklist values from modal arrays
  $field_ids = $_POST['field_ids'] ?? [];
  if (is_array($field_ids) && $domain_id > 0) {
    foreach ($field_ids as $fidRaw) {
      $fid = (int)$fidRaw;
      if ($fid <= 0) continue;

      $ftype = isset($_POST['field_type'][$fid]) ? (string)$_POST['field_type'][$fid] : 'text';

      $val_bool = null; $val_text = null; $val_number = null; $val_date = null;

      if ($ftype === 'bool') {
        $val_bool = isset($_POST['field_bool'][$fid]) ? 1 : 0;
      } elseif ($ftype === 'number') {
        $s = isset($_POST['field_number'][$fid]) ? str_replace(',', '.', (string)$_POST['field_number'][$fid]) : '';
        $val_number = ($s === '' ? null : (is_numeric($s) ? (float)$s : null));
      } elseif ($ftype === 'date') {
        $s = isset($_POST['field_date'][$fid]) ? trim((string)$_POST['field_date'][$fid]) : '';
        $val_date = ($s === '' ? null : $s);
      } else {
        $s = isset($_POST['field_text'][$fid]) ? trim((string)$_POST['field_text'][$fid]) : '';
        $val_text = ($s === '' ? null : $s);
      }

      db_exec($db, "INSERT INTO crm_check_values(domain_id,field_id,val_bool,val_text,val_number,val_date)
        VALUES(?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          val_bool=VALUES(val_bool),
          val_text=VALUES(val_text),
          val_number=VALUES(val_number),
          val_date=VALUES(val_date)",
        [$domain_id,$fid,$val_bool,$val_text,$val_number,$val_date]
      );
    }
  }

  flash_set('success', 'Сохранено');
  header('Location: menu.php?page=domains'); exit;
}

// add checklist field (bottom small form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('add_field', false)) {
  $code = trim(req_str('code',''));
  $title = trim(req_str('title',''));
  $type = trim(req_str('field_type','bool'));
  $sort = req_int('sort_order', 100);

  if ($code==='' || $title==='') {
    flash_set('danger','code/title обязательны');
  } else {
    db_exec($db, "INSERT IGNORE INTO crm_check_fields(code,title,field_type,sort_order) VALUES(?,?,?,?)",
      [$code,$title,$type,$sort]);
    flash_set('success','Пункт добавлен (или уже был)');
  }
  header('Location: menu.php?page=domains'); exit;
}

// bulk add domains
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('bulk_add', false)) {
  $raw = (string)req_str('bulk_domains', '');
  $lines = preg_split('/\R+/', $raw) ?: [];
  $added = 0; $skipped = 0;

  $def_status     = trim(req_str('bulk_status','new'));
  $def_ssl_status = trim(req_str('bulk_ssl_status','todo'));
  $def_registrar  = trim(req_str('bulk_registrar',''));
  $def_server     = trim(req_str('bulk_server_name',''));
  $def_server_ip  = trim(req_str('bulk_server_ip',''));
  $def_network    = trim(req_str('bulk_ad_network',''));

  foreach ($lines as $line) {
    $dom = normalize_domain((string)$line);
    if ($dom === '') { $skipped++; continue; }

    $path = '/var/www/'.$dom;
    $safe = preg_replace('/[^a-zA-Z0-9_]+/', '_', $dom);
    $db_name = 'db_'.$safe;

    $aff = db_exec($db, "INSERT IGNORE INTO crm_domains(domain,registrar,status,ssl_status,ad_network,server_name,server_ip,path,db_name,notes)
      VALUES(?,?,?,?,?,?,?,?,?,?)",
      [$dom,$def_registrar,$def_status,$def_ssl_status,$def_network,$def_server,$def_server_ip,$path,$db_name,'']
    );
    if ($aff > 0) $added++; else $skipped++;
  }

  flash_set('success', "Массовое добавление: добавлено $added, пропущено $skipped");
  header('Location: menu.php?page=domains'); exit;
}

// -------------------- data --------------------

$fields  = db_all($db, "SELECT * FROM crm_check_fields ORDER BY sort_order, id");
$domains = db_all($db, "SELECT * FROM crm_domains ORDER BY updated_at DESC");

$vals = db_all($db, "SELECT domain_id, field_id, val_bool, val_text, val_number, val_date FROM crm_check_values");
$valMap = [];
foreach ($vals as $v) $valMap[$v['domain_id'].':'.$v['field_id']] = $v;

// networks list for dropdown (если таблицы нет — просто будет fallback)
$networks = [];
try {
  $networks = db_all($db, "SELECT id, code, title FROM crm_networks ORDER BY title, code");
} catch (Throwable $e) {
  $networks = [];
}

// --- Day used for checks (same as finance.php default: today)
$DAY = date('Y-m-d');

// ✅ Counters presence per domain via crm_domain_counters:
// OK если включён и заполнен хотя бы 1 счётчик
$countersMap = [];
try {
  $rows = db_all($db, "SELECT domain_id, metrika_enabled, metrika_id, ga_enabled, ga_measurement_id FROM crm_domain_counters");
  foreach ($rows as $r) {
    $did = (int)$r['domain_id'];
    $metrikaOk = ((int)($r['metrika_enabled'] ?? 0) === 1) && ((int)($r['metrika_id'] ?? 0) > 0);
    $gaOk      = ((int)($r['ga_enabled'] ?? 0) === 1) && (trim((string)($r['ga_measurement_id'] ?? '')) !== '');
    $countersMap[$did] = ($metrikaOk || $gaOk);
  }
} catch (Throwable $e) {}

// ✅ Ads presence per domain via crm_domain_ad_blocks + pages:
// OK если есть хотя бы 1 enabled блок с привязкой к странице
$adsMap = [];
try {
  $rows = db_all($db, "
    SELECT
      b.domain_id,
      SUM(CASE WHEN b.enabled=1 AND p.block_id IS NOT NULL THEN 1 ELSE 0 END) AS enabled_with_pages
    FROM crm_domain_ad_blocks b
    LEFT JOIN crm_domain_ad_block_pages p ON p.block_id=b.id
    GROUP BY b.domain_id
  ");
  foreach ($rows as $r) $adsMap[(int)$r['domain_id']] = ((int)($r['enabled_with_pages'] ?? 0) > 0);
} catch (Throwable $e) {}

// ✅ Cron flags per domain from crm_cron_jobs (is_enabled=1)
$cronAnyMap = [];
$cronRevenueMap = []; // для "API"
try {
  $rows = db_all($db, "
    SELECT domain_id,
      SUM(CASE WHEN is_enabled=1 THEN 1 ELSE 0 END) AS enabled_any,
      SUM(CASE WHEN is_enabled=1 AND job_type='revenue' THEN 1 ELSE 0 END) AS enabled_revenue
    FROM crm_cron_jobs
    GROUP BY domain_id
  ");
  foreach ($rows as $r) {
    $did = (int)$r['domain_id'];
    $cronAnyMap[$did] = ((int)($r['enabled_any'] ?? 0) > 0);
    $cronRevenueMap[$did] = ((int)($r['enabled_revenue'] ?? 0) > 0);
  }
} catch (Throwable $e) {}

// ✅ Финансы за день: cost/revenue sums per domain
$costByDomain = [];
$revByDomain  = [];
try {
  $rows = db_all($db, "
    SELECT domain_id, kind, SUM(amount) AS amt
    FROM crm_finance_daily
    WHERE d=?
      AND kind IN ('cost','revenue')
    GROUP BY domain_id, kind
  ", [$DAY]);
  foreach ($rows as $r) {
    $did = (int)$r['domain_id'];
    $kind = (string)$r['kind'];
    $amt = (float)($r['amt'] ?? 0.0);
    if ($kind === 'cost') $costByDomain[$did] = $amt;
    if ($kind === 'revenue') $revByDomain[$did] = $amt;
  }
} catch (Throwable $e) {}

// ✅ Трафик (метрика) за день: visits per domain из meta_json/amount
$visitsByDomain = [];
try {
  $rows = db_all($db, "
    SELECT domain_id, amount, meta_json
    FROM crm_finance_daily
    WHERE d=? AND kind='metric' AND connector='yandex_metrika_today'
  ", [$DAY]);
  foreach ($rows as $r) {
    $did = (int)$r['domain_id'];
    $meta = json_decode((string)($r['meta_json'] ?? ''), true);
    if (!is_array($meta)) $meta = [];
    $visits = (float)($meta['visits'] ?? ($r['amount'] ?? 0));
    // если несколько строк — суммируем
    $visitsByDomain[$did] = ($visitsByDomain[$did] ?? 0.0) + $visits;
  }
} catch (Throwable $e) {}

// Render modals AFTER table
$modalsHtml = [];

?>
<style>
  .domains-wrap .table thead th { position: sticky; top: 0; z-index: 3; background: #fff; }
  .mini-badge { font-size: 12px; padding: .25rem .55rem; border-radius: 999px; }
  .ok  { background: rgba(25,135,84,.10) !important; }
  .bad { background: rgba(220,53,69,.10) !important; }
  .unk { background: rgba(108,117,125,.10) !important; }
  .cell { text-align:center; white-space:nowrap; }
  .domaincell { white-space:nowrap; }
  .progress.slim { height: 8px; border-radius: 999px; }
  .row-actions { white-space:nowrap; }
  .tight { line-height: 1.05; }
  .w-1 { width: 1%; }
  .small-muted { color:#6c757d; font-size: 12px; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h3 class="m-0">🌐 Домены — прогресс (data-driven)</h3>
    <div class="small-muted">День для проверок: <b><?=h($DAY)?></b> (cost/rev/metric)</div>
  </div>
  <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#modalNewDomain">➕ Новый домен</button>
</div>

<div class="card domains-wrap mb-3">
  <div class="card-header d-flex align-items-center justify-content-between">
    <b>Список</b>
    <span class="text-muted small">подробности только в попапе “Редактировать”</span>
  </div>

  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead>
        <tr>
          <th class="w-1">Прогресс</th>
          <th>Домен</th>
          <th class="cell">SSL</th>
          <th class="cell">Сетка</th>
          <th class="cell">API</th>
          <th class="cell">Деплой</th>
          <th class="cell">Счётчики</th>
          <th class="cell">Ads</th>
          <th class="cell">Cron</th>
          <th class="cell">Трафик</th>
          <th class="cell">ROI</th>
          <th class="cell">SEO</th>
          <th class="cell">Авто-закуп</th>
          <th class="w-1">Действия</th>
        </tr>
      </thead>

      <tbody>
        <?php foreach ($domains as $d):
          $did = (int)$d['id'];

          $ok_ssl     = (strtolower((string)$d['ssl_status']) === 'ok');
          $ok_network = is_nonempty($d['ad_network']);

          // ✅ API закрываем наличием revenue cron
          $ok_api = isset($cronRevenueMap[$did]) ? (bool)$cronRevenueMap[$did] : false;

          $deploy_status = isset($d['deploy_status']) ? strtolower((string)$d['deploy_status']) : '';
          $ok_deploy = ($deploy_status === 'done') || ((strtolower((string)$d['status']) === 'live') && is_nonempty($d['path']) && is_nonempty($d['db_name']));

          $ok_counters = isset($countersMap[$did]) ? (bool)$countersMap[$did] : false;
          $ok_ads      = isset($adsMap[$did]) ? (bool)$adsMap[$did] : false;
          $ok_cron     = isset($cronAnyMap[$did]) ? (bool)$cronAnyMap[$did] : false;

          // ✅ traffic from metrika visits
          $visits = (float)($visitsByDomain[$did] ?? 0.0);
          $ok_traffic = ($visits > 0.0);

          // ✅ finance
          $spend = (float)($costByDomain[$did] ?? 0.0);
          $rev   = (float)($revByDomain[$did] ?? 0.0);

          // ✅ autobuy if spend > 0
          $ok_autobuy = ($spend > 0.0);

          // ✅ roi: meaningful if cost>0 and revenue>0
          $ok_roi = ($spend > 0.0 && $rev > 0.0);
          $roiVal = 0.0;
          if ($spend > 0.0) $roiVal = (($rev - $spend) / $spend) * 100.0;

          // ✅ SEO: local file path/domains/robots.txt
          $robotsPath = '';
          if (is_nonempty($d['path'])) $robotsPath = rtrim((string)$d['path'], '/').'/domains/robots.txt';
          $ok_seo = ($robotsPath !== '' && safe_file_exists($robotsPath));

          // progress steps
          $steps = [$ok_ssl,$ok_network,$ok_api,$ok_deploy,$ok_counters,$ok_ads,$ok_cron,$ok_traffic,$ok_roi,$ok_seo,$ok_autobuy];
          $total = count($steps);
          $done = 0; foreach ($steps as $s) { if ($s) $done++; }
          $pct = $total ? (int)round(($done / $total) * 100) : 0;

          $progressClass = 'bg-danger';
          if ($pct >= 80) $progressClass = 'bg-success';
          elseif ($pct >= 40) $progressClass = 'bg-warning';

          $adminUrl = 'https://' . (string)$d['domain'] . '/cmd/menu.php';
        ?>
          <tr>
            <td class="w-1">
              <div class="tight">
                <div class="progress slim" title="<?=h((string)$done)?>/<?=h((string)$total)?> (<?=h((string)$pct)?>%)">
                  <div class="progress-bar <?=$progressClass?>" style="width: <?=h((string)$pct)?>%"></div>
                </div>
                <div class="text-muted small mt-1"><?=h((string)$pct)?>%</div>
              </div>
            </td>

            <td class="domaincell">
              <div class="d-flex align-items-center gap-2">
                <b><?=h((string)$d['domain'])?></b>
                <span class="badge <?=status_badge_class((string)$d['status'])?>"><?=h((string)$d['status'])?></span>
                <?php if ($ok_deploy): ?>
  <a class="badge bg-primary text-decoration-none" href="<?=h($adminUrl)?>" target="_blank" rel="noopener">ADMIN</a>
<?php endif; ?>

<?php if (!$ok_ssl): ?>
  <a class="badge bg-warning text-dark text-decoration-none"
     href="menu.php?page=site_ssl&domain_id=<?=h((string)$did)?>"
     title="Выпустить SSL через certbot + перезаписать nginx">
     SSL
  </a>
<?php endif; ?>

              </div>
              <div class="small-muted">
                spend: <b><?=h(number_format($spend, 2, '.', ' '))?></b> |
                rev: <b><?=h(number_format($rev, 2, '.', ' '))?></b> |
                visits: <b><?=h(number_format($visits, 0, '.', ' '))?></b>
                <?php if ($spend > 0.0): ?>
                  | roi: <b><?=h(number_format($roiVal, 2, '.', ' '))?>%</b>
                <?php endif; ?>
              </div>
            </td>

            <td class="cell <?= $ok_ssl ? 'ok' : 'bad' ?>"><span class="badge <?=badge_ok($ok_ssl)?> mini-badge"><?= $ok_ssl ? 'OK' : 'NO' ?></span></td>
            <td class="cell <?= $ok_network ? 'ok' : 'bad' ?>"><span class="badge <?=badge_ok($ok_network)?> mini-badge"><?= $ok_network ? 'OK' : 'NO' ?></span></td>

            <td class="cell <?= $ok_api ? 'ok' : 'bad' ?>" title="API=OK если есть enabled revenue cron">
              <span class="badge <?=badge_ok($ok_api)?> mini-badge"><?= $ok_api ? 'OK' : 'NO' ?></span>
            </td>

            <td class="cell <?= $ok_deploy ? 'ok' : 'bad' ?>"><span class="badge <?=badge_ok($ok_deploy)?> mini-badge"><?= $ok_deploy ? 'OK' : 'NO' ?></span></td>

            <td class="cell <?= $ok_counters ? 'ok' : 'bad' ?>"><span class="badge <?=badge_ok($ok_counters)?> mini-badge"><?= $ok_counters ? 'OK' : 'NO' ?></span></td>
            <td class="cell <?= $ok_ads ? 'ok' : 'bad' ?>"><span class="badge <?=badge_ok($ok_ads)?> mini-badge"><?= $ok_ads ? 'OK' : 'NO' ?></span></td>
            <td class="cell <?= $ok_cron ? 'ok' : 'bad' ?>"><span class="badge <?=badge_ok($ok_cron)?> mini-badge"><?= $ok_cron ? 'OK' : 'NO' ?></span></td>

            <td class="cell <?= $ok_traffic ? 'ok' : 'bad' ?>" title="visits: <?=h(number_format($visits,0,'.',' '))?>">
              <span class="badge <?=badge_ok($ok_traffic)?> mini-badge"><?= $ok_traffic ? 'OK' : 'NO' ?></span>
            </td>

            <td class="cell <?= $ok_roi ? 'ok' : 'bad' ?>" title="roi: <?=h(number_format($roiVal,2,'.',' '))?>%">
              <span class="badge <?=badge_ok($ok_roi)?> mini-badge"><?= $ok_roi ? 'OK' : 'NO' ?></span>
            </td>

            <td class="cell <?= $ok_seo ? 'ok' : 'bad' ?>" title="<?=h($robotsPath)?>">
              <span class="badge <?=badge_ok($ok_seo)?> mini-badge"><?= $ok_seo ? 'OK' : 'NO' ?></span>
            </td>

            <td class="cell <?= $ok_autobuy ? 'ok' : 'bad' ?>" title="spend: <?=h(number_format($spend,2,'.',' '))?>">
              <span class="badge <?=badge_ok($ok_autobuy)?> mini-badge"><?= $ok_autobuy ? 'OK' : 'NO' ?></span>
            </td>

            <td class="row-actions w-1 text-end">
              <div class="d-flex gap-2 justify-content-end">
                <a class="btn btn-sm btn-outline-secondary"
                   href="menu.php?page=site_counters&domain_id=<?=h((string)$did)?>"
                   title="Счётчики домена">📊</a>

                <a class="btn btn-sm btn-outline-secondary"
                   href="menu.php?page=global_ad_blocks&domain_id=<?=h((string)$did)?>"
                   title="Реклама домена">🧱</a>

                <a class="btn btn-sm btn-outline-secondary"
                   href="menu.php?page=cron_jobs&domain_id=<?=h((string)$did)?>"
                   title="Cron jobs">⏱</a>

                <a class="btn btn-sm btn-outline-secondary"
                   href="menu.php?page=finance&domain_id=<?=h((string)$did)?>&d=<?=h($DAY)?>"
                   title="Финансы (<?=h($DAY)?>)">💰</a>

                <button class="btn btn-sm btn-outline-primary" type="button"
                        data-bs-toggle="modal" data-bs-target="#modalEditDomain<?=$did?>">
                  Редактировать
                </button>
              </div>
            </td>
          </tr>

<?php
// ----- modal HTML (collect, DO NOT render inside table) -----
ob_start();
?>
<div class="modal fade" id="modalEditDomain<?=$did?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="save_domain" value="1">
        <input type="hidden" name="domain_id" value="<?=h((string)$did)?>">

        <div class="modal-header">
          <h5 class="modal-title">Редактировать: <?=h((string)$d['domain'])?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label small text-muted">Домен</label>
              <input class="form-control" name="domain" value="<?=h((string)$d['domain'])?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label small text-muted">Регистратор</label>
              <input class="form-control" name="registrar" value="<?=h((string)$d['registrar'])?>">
            </div>
            <div class="col-md-2">
              <label class="form-label small text-muted">status</label>
              <select class="form-select" name="status">
                <?php foreach (['new','queue','live','broken','parked'] as $s): ?>
                  <option value="<?=h($s)?>" <?=((string)$d['status']===$s?'selected':'')?>><?=h($s)?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small text-muted">ssl_status</label>
              <select class="form-select" name="ssl_status">
                <?php foreach (['todo','ok','fail'] as $s): ?>
                  <option value="<?=h($s)?>" <?=((string)$d['ssl_status']===$s?'selected':'')?>><?=h($s)?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-3">
              <label class="form-label small text-muted">Сетка (основная)</label>
              <?php if (!empty($networks)): ?>
                <select class="form-select" name="ad_network">
                  <?=render_network_options($networks, (string)$d['ad_network'])?>
                </select>
              <?php else: ?>
                <input class="form-control" name="ad_network" value="<?=h((string)$d['ad_network'])?>">
              <?php endif; ?>
            </div>

            <div class="col-md-3">
              <label class="form-label small text-muted">server_name</label>
              <input class="form-control" name="server_name" value="<?=h((string)$d['server_name'])?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small text-muted">server_ip</label>
              <input class="form-control" name="server_ip" value="<?=h((string)$d['server_ip'])?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small text-muted">Путь</label>
              <input class="form-control" name="path" value="<?=h((string)$d['path'])?>">
              <div class="text-muted small mt-1">SEO: ищем <code><?=h($robotsPath ?: '{path}/domains/robots.txt')?></code></div>
            </div>
            <div class="col-md-3">
              <label class="form-label small text-muted">DB name</label>
              <input class="form-control" name="db_name" value="<?=h((string)$d['db_name'])?>">
            </div>

            <div class="col-md-9">
              <label class="form-label small text-muted">Заметки</label>
              <input class="form-control" name="notes" value="<?=h((string)$d['notes'])?>">
            </div>
          </div>

          <hr class="my-3">

          <div class="d-flex align-items-center justify-content-between mb-2">
            <b>Поля / чек-лист (ручные)</b>
            <span class="text-muted small">не влияет на колонки трафик/roi/seo/autobuy/api</span>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th style="width:240px;">Поле</th>
                  <th style="width:100px;">Тип</th>
                  <th>Значение</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($fields as $f):
                  $fid = (int)$f['id'];
                  $ft  = (string)$f['field_type'];
                  $vv  = get_val($valMap, $did, $fid);
                  $filled = field_filled($ft, $vv);
                ?>
                  <tr class="<?= $filled ? 'ok' : 'bad' ?>">
                    <td>
                      <b><?=h((string)$f['title'])?></b>
                      <div class="text-muted small"><code><?=h((string)$f['code'])?></code></div>
                    </td>
                    <td class="text-muted small">
                      <?=h($ft)?>
                      <input type="hidden" name="field_ids[]" value="<?=h((string)$fid)?>">
                      <input type="hidden" name="field_type[<?=h((string)$fid)?>]" value="<?=h($ft)?>">
                    </td>
                    <td>
                      <?php if ($ft === 'bool'): ?>
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox"
                            name="field_bool[<?=h((string)$fid)?>]" value="1"
                            <?= ((int)($vv['val_bool'] ?? 0)===1 ? 'checked' : '') ?>>
                        </div>
                      <?php elseif ($ft === 'number'): ?>
                        <input class="form-control form-control-sm"
                          name="field_number[<?=h((string)$fid)?>]"
                          value="<?=h((string)($vv['val_number'] ?? ''))?>" placeholder="0">
                      <?php elseif ($ft === 'date'): ?>
                        <input class="form-control form-control-sm" type="date"
                          name="field_date[<?=h((string)$fid)?>]"
                          value="<?=h((string)($vv['val_date'] ?? ''))?>">
                      <?php else: ?>
                        <input class="form-control form-control-sm"
                          name="field_text[<?=h((string)$fid)?>]"
                          value="<?=h((string)($vv['val_text'] ?? ''))?>" placeholder="...">
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$fields): ?>
                  <tr><td colspan="3" class="text-muted">Нет чек-полей. Добавь их внизу страницы.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="modal-footer">
          <?php if ($ok_deploy): ?>
            <a class="btn btn-outline-primary" href="<?=h($adminUrl)?>" target="_blank" rel="noopener">Открыть ADMIN</a>
          <?php endif; ?>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
          <button class="btn btn-primary">Сохранить</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php
$modalsHtml[] = ob_get_clean();
?>

        <?php endforeach; ?>

        <?php if (!$domains): ?>
          <tr><td colspan="14" class="text-muted">Пока нет доменов</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
echo implode("\n", $modalsHtml);
?>

<!-- NEW DOMAIN MODAL -->
<div class="modal fade" id="modalNewDomain" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="save_domain" value="1">
        <input type="hidden" name="domain_id" value="0">

        <div class="modal-header">
          <h5 class="modal-title">➕ Новый домен</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label small text-muted">Домен</label>
              <input class="form-control" name="domain" placeholder="domain.tld" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Регистратор</label>
              <input class="form-control" name="registrar" placeholder="reg.ru / namecheap / ...">
            </div>

            <div class="col-md-4">
              <label class="form-label small text-muted">status</label>
              <select class="form-select" name="status">
                <?php foreach (['new','queue','live','broken','parked'] as $s): ?>
                  <option value="<?=h($s)?>"><?=h($s)?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label small text-muted">ssl_status</label>
              <select class="form-select" name="ssl_status">
                <?php foreach (['todo','ok','fail'] as $s): ?>
                  <option value="<?=h($s)?>"><?=h($s)?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label small text-muted">Сетка</label>
              <?php if (!empty($networks)): ?>
                <select class="form-select" name="ad_network">
                  <?=render_network_options($networks, '')?>
                </select>
              <?php else: ?>
                <input class="form-control" name="ad_network" placeholder="kadam/clickadu/...">
              <?php endif; ?>
            </div>

            <div class="col-md-6">
              <label class="form-label small text-muted">Путь</label>
              <input class="form-control" name="path" placeholder="/var/www/domain.tld (можно пусто)">
              <div class="text-muted small mt-1">SEO проверка: <code>{path}/domains/robots.txt</code></div>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">DB name</label>
              <input class="form-control" name="db_name" placeholder="db_domain (можно пусто)">
            </div>

            <div class="col-md-6">
              <label class="form-label small text-muted">server_name</label>
              <input class="form-control" name="server_name" placeholder="DS-test">
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">server_ip</label>
              <input class="form-control" name="server_ip" placeholder="1.2.3.4">
            </div>

            <div class="col-12">
              <label class="form-label small text-muted">Заметки</label>
              <input class="form-control" name="notes" placeholder="...">
            </div>
          </div>
          <div class="text-muted small mt-2">Ручной чек-лист заполнишь через “Редактировать”.</div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
          <button class="btn btn-primary">Создать</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- BULK ADD -->
<div class="card mb-3">
  <div class="card-header"><b>🧾 Массовое добавление доменов (пустые)</b></div>
  <div class="card-body">
    <form method="post" class="vstack gap-2">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="bulk_add" value="1">

      <textarea class="form-control" name="bulk_domains" rows="6" placeholder="Вставь список доменов:
analwifetube.ru
threesometube.ru
https://www.example.com/path
"></textarea>

      <div class="row g-2">
        <div class="col-md-2">
          <label class="form-label small text-muted">status</label>
          <select class="form-select" name="bulk_status">
            <?php foreach (['new','queue','live','broken','parked'] as $s): ?>
              <option value="<?=h($s)?>" <?=($s==='new'?'selected':'')?>><?=h($s)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted">ssl_status</label>
          <select class="form-select" name="bulk_ssl_status">
            <?php foreach (['todo','ok','fail'] as $s): ?>
              <option value="<?=h($s)?>" <?=($s==='todo'?'selected':'')?>><?=h($s)?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label small text-muted">Сетка</label>
          <?php if (!empty($networks)): ?>
            <select class="form-select" name="bulk_ad_network">
              <?=render_network_options($networks, (string)req_str('bulk_ad_network',''))?>
            </select>
          <?php else: ?>
            <input class="form-control" name="bulk_ad_network" placeholder="kadam">
          <?php endif; ?>
        </div>

        <div class="col-md-3">
          <label class="form-label small text-muted">Регистратор</label>
          <input class="form-control" name="bulk_registrar" placeholder="reg.ru">
        </div>

        <div class="col-md-3">
          <label class="form-label small text-muted">server_name / ip</label>
          <div class="d-flex gap-2">
            <input class="form-control" name="bulk_server_name" placeholder="DS-test">
            <input class="form-control" name="bulk_server_ip" placeholder="1.2.3.4">
          </div>
        </div>
      </div>

      <button class="btn btn-outline-primary">Добавить списком</button>
      <div class="text-muted small">
        Создаст записи только с доменом + дефолтами. <code>path</code> и <code>db_name</code будут авто:
        <code>/var/www/domain</code> и <code>db_domain</code>.
      </div>
    </form>
  </div>
</div>

<!-- OPTIONAL: add checklist param -->
<div class="card mb-4">
  <div class="card-header"><b>➕ Добавить пункт (чек-лист ручной)</b></div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="add_field" value="1">

      <div class="col-md-3">
        <label class="form-label small text-muted">code</label>
        <input class="form-control" name="code" placeholder="any_code" required>
      </div>
      <div class="col-md-5">
        <label class="form-label small text-muted">title</label>
        <input class="form-control" name="title" placeholder="Название" required>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">type</label>
        <select class="form-select" name="field_type">
          <?php foreach (['bool','text','number','date'] as $t): ?>
            <option value="<?=h($t)?>"><?=h($t)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">sort</label>
        <input class="form-control" name="sort_order" type="number" value="100">
      </div>

      <div class="col-12">
        <button class="btn btn-outline-secondary">Добавить пункт</button>
        <div class="text-muted small mt-1">
          Прогресс-колонки (API/Traffic/ROI/SEO/Autobuy/Counters/Ads/Cron) считаются автоматически по таблицам.
          Этот чек-лист — только для ручных меток.
        </div>
      </div>
    </form>
  </div>
</div>

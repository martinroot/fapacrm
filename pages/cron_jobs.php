<?php
declare(strict_types=1);

/**
 * pages/cron_jobs.php
 *
 * - CRUD jobs (создание/редактирование/удаление)
 * - RUN via cron (next_run_at=NOW)
 * - RUN INLINE (manual): выполняет коннектор + ПИШЕТ В crm_finance_daily + проверяет, что записалось
 * - Run JSON view
 *
 * Требует helpers CRM:
 *   csrf_check(), csrf_token(), db_connect(), db_exec(), db_all(),
 *   req_str(), req_int(), req_bool(), flash_set(), h()
 */

csrf_check();
$db = db_connect();

require_once __DIR__ . '/../lib/connectors/ConnectorInterface.php';
require_once __DIR__ . '/../lib/connectors/KadamCost.php';
require_once __DIR__ . '/../lib/connectors/ClickaduRevenue.php';
require_once __DIR__ . '/../lib/connectors/MyBidRevenue.php';
require_once __DIR__ . '/../lib/connectors/TacoLocoRevenue.php';
require_once __DIR__ . '/../lib/connectors/KadamPublisherRevenue.php';
require_once __DIR__ . '/../lib/connectors/YandexMetrikaToday.php';
require_once __DIR__ . '/../lib/connectors/MyBidRevenueTag.php';

$CONNECTORS = [
  KadamCost::code()               => ['title' => 'Kadam cost (spend)',                 'obj' => new KadamCost(), 'default_type' => 'cost'],
  ClickaduRevenue::code()         => ['title' => 'Clickadu revenue',                   'obj' => new ClickaduRevenue(), 'default_type' => 'revenue'],
  MyBidRevenue::code()            => ['title' => 'MyBid revenue',                      'obj' => new MyBidRevenue(), 'default_type' => 'revenue'],
  MyBidRevenueTag::code()          => ['title' => 'MyBid revenue (real, by tag_name)', 'obj' => new MyBidRevenueTag(), 'default_type' => 'revenue'],
  TacoLocoRevenue::code()         => ['title' => 'TacoLoco revenue (publisher)',       'obj' => new TacoLocoRevenue(), 'default_type' => 'revenue'],
  KadamPublisherRevenue::code()   => ['title' => 'Kadam Pub revenue (webmaster)',      'obj' => new KadamPublisherRevenue(), 'default_type' => 'revenue'],
  YandexMetrikaToday::code()      => ['title' => 'Yandex.Metrika today (visits/pageviews/time/depth)', 'obj' => new YandexMetrikaToday(), 'default_type' => 'metric'],
];

$SCHEDULES = [
  '5min'   => 'каждые 5 минут',
  'hourly' => 'каждый час',
  'daily'  => 'раз в день',
  'custom:900'  => 'custom: 15 минут',
  'custom:1800' => 'custom: 30 минут',
];

function redirect_self(string $qs=''): void {
  $url = 'menu.php?page=cron_jobs' . ($qs ? ('&'.$qs) : '');
  header('Location: '.$url);
  exit;
}
function now_sql(): string { return date('Y-m-d H:i:s'); }

function pretty_json($x): string {
  return json_encode($x, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function clip_str(string $s, int $max = 30000): string {
  if (strlen($s) <= $max) return $s;
  return substr($s, 0, $max) . "\n... [clipped] ...\n";
}

/**
 * Надежная запись в crm_finance_daily: если SQL сломался — кидаем exception.
 */
function finance_upsert(mysqli $db, string $d, int $domain_id, string $kind, string $connector, string $external_id, float $amount, ?string $meta_json): void
{
  $sql = "
    INSERT INTO crm_finance_daily(d,domain_id,kind,connector,external_id,amount,meta_json)
    VALUES(?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE amount=VALUES(amount), meta_json=VALUES(meta_json)
  ";
  $stmt = $db->prepare($sql);
  if (!$stmt) throw new RuntimeException("prepare failed: ".$db->error);

  $stmt->bind_param('sisssds', $d, $domain_id, $kind, $connector, $external_id, $amount, $meta_json);
  if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    throw new RuntimeException("finance_upsert execute failed: ".$err);
  }
  $stmt->close();
}
function finance_get_amount(mysqli $db, string $d, int $domain_id, string $kind, string $connector, string $external_id): float
{
  $sql = "SELECT amount FROM crm_finance_daily WHERE d=? AND domain_id=? AND kind=? AND connector=? AND external_id=? LIMIT 1";
  $stmt = $db->prepare($sql);
  if (!$stmt) throw new RuntimeException("prepare failed: ".$db->error);
  $stmt->bind_param('sisss', $d, $domain_id, $kind, $connector, $external_id);
  if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    throw new RuntimeException("finance_get_amount execute failed: ".$err);
  }
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return (float)($row['amount'] ?? 0.0);
}

// filter
$filter_domain_id = req_int('domain_id', 0);

// run view
$view_run_id = req_int('run_id', 0);
$view_run = null;
if ($view_run_id > 0) {
  $vr = db_all($db, "
    SELECT r.*, j.domain_id, j.connector, j.job_type
    FROM crm_cron_runs r
    JOIN crm_cron_jobs j ON j.id=r.job_id
    WHERE r.id=?
    LIMIT 1
  ", [$view_run_id]);
  $view_run = $vr[0] ?? null;
}

/** ---------------- ACTIONS ---------------- **/

// ADD JOB
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('add_job', false)) {
  $domain_id   = req_int('domain_id', 0);
  $job_type    = req_str('job_type', 'cost');   // cost|revenue|metric
  $connector   = req_str('connector', '');
  $schedule    = req_str('schedule', 'hourly');
  $is_enabled  = req_bool('is_enabled', true) ? 1 : 0;
  $params_json = trim(req_str('params_json', '{}'));

  if ($domain_id <= 0 || ($job_type !== 'cost' && $job_type !== 'revenue' && $job_type !== 'metric') || $connector === '') {
    flash_set('danger', 'domain/job_type/connector обязательны');
    redirect_self($filter_domain_id ? 'domain_id='.$filter_domain_id : '');
  }
  if (!isset($CONNECTORS[$connector])) {
    flash_set('danger', 'unknown connector');
    redirect_self($filter_domain_id ? 'domain_id='.$filter_domain_id : '');
  }

  $decoded = json_decode($params_json, true);
  if (!is_array($decoded)) {
    flash_set('danger', 'params_json должен быть валидным JSON');
    redirect_self($filter_domain_id ? 'domain_id='.$filter_domain_id : '');
  }

  db_exec($db, "
    INSERT INTO crm_cron_jobs(domain_id, job_type, connector, params_json, schedule, is_enabled, next_run_at)
    VALUES(?,?,?,?,?,?,?)
  ", [
    $domain_id,
    $job_type,
    $connector,
    json_encode($decoded, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    $schedule,
    $is_enabled,
    now_sql()
  ]);

  flash_set('success', 'Задача добавлена');
  redirect_self($filter_domain_id ? 'domain_id='.$filter_domain_id : '');
}

// DELETE JOB
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('delete_job', false)) {
  $id = req_int('id', 0);
  if ($id > 0) {
    db_exec($db, "DELETE FROM crm_cron_jobs WHERE id=?", [$id]);
    flash_set('success', 'Задача удалена');
  }
  redirect_self($filter_domain_id ? 'domain_id='.$filter_domain_id : '');
}

// SAVE JOB
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('save_job', false)) {
  $id          = req_int('id', 0);
  $domain_id2  = req_int('domain_id', 0);
  $job_type    = req_str('job_type', 'cost');
  $connector   = req_str('connector', '');
  $schedule    = req_str('schedule', 'hourly');
  $is_enabled  = req_bool('is_enabled', true) ? 1 : 0;
  $params_json = trim(req_str('params_json', '{}'));

  if ($id <= 0 || $domain_id2 <= 0 || ($job_type !== 'cost' && $job_type !== 'revenue' && $job_type !== 'metric') || $connector === '') {
    flash_set('danger', 'bad job fields');
    redirect_self($filter_domain_id ? 'domain_id='.$filter_domain_id : '');
  }
  if (!isset($CONNECTORS[$connector])) {
    flash_set('danger', 'unknown connector');
    redirect_self('edit='.(int)$id.($filter_domain_id ? '&domain_id='.$filter_domain_id : ''));
  }

  $decoded = json_decode($params_json, true);
  if (!is_array($decoded)) {
    flash_set('danger', 'params_json должен быть валидным JSON');
    redirect_self('edit='.(int)$id.($filter_domain_id ? '&domain_id='.$filter_domain_id : ''));
  }

  db_exec($db, "
    UPDATE crm_cron_jobs
       SET domain_id=?, job_type=?, connector=?, params_json=?, schedule=?, is_enabled=?
     WHERE id=?
  ", [
    $domain_id2,
    $job_type,
    $connector,
    json_encode($decoded, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    $schedule,
    $is_enabled,
    $id
  ]);

  flash_set('success', 'Задача обновлена');
  redirect_self('edit='.(int)$id.($filter_domain_id ? '&domain_id='.$filter_domain_id : ''));
}

// RUN via cron
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('run_now', false)) {
  $id = req_int('id', 0);
  if ($id > 0) {
    db_exec($db, "UPDATE crm_cron_jobs SET next_run_at=? WHERE id=?", [now_sql(), $id]);
    flash_set('success', 'Поставил на немедленный запуск (cron runner подхватит).');
  }
  redirect_self('edit='.(int)$id . ($filter_domain_id ? '&domain_id='.$filter_domain_id : ''));
}

// RUN INLINE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('run_inline', false)) {
  $id = req_int('id', 0);
  if ($id <= 0) {
    flash_set('danger', 'bad id');
    redirect_self($filter_domain_id ? 'domain_id='.$filter_domain_id : '');
  }

  $jobRow = db_all($db, "SELECT * FROM crm_cron_jobs WHERE id=? LIMIT 1", [$id]);
  $job = $jobRow[0] ?? null;
  if (!$job) {
    flash_set('danger', 'job not found');
    redirect_self($filter_domain_id ? 'domain_id='.$filter_domain_id : '');
  }

  $connector = (string)$job['connector'];
  $domain_id2 = (int)$job['domain_id'];
  $job_type  = (string)$job['job_type'];

  db_exec($db, "INSERT INTO crm_cron_runs(job_id,status,started_at) VALUES(?,?,NOW())", [$id, 'running']);
  $rowRun = db_all($db, "SELECT LAST_INSERT_ID() AS id");
  $runId  = (int)($rowRun[0]['id'] ?? 0);

  $params = json_decode((string)$job['params_json'], true);
  if (!is_array($params)) $params = [];

  try {
    if (!isset($CONNECTORS[$connector])) throw new RuntimeException("Unknown connector: {$connector}");

    /** @var ConnectorInterface $obj */
    $obj = $CONNECTORS[$connector]['obj'];
    $result = $obj->run($params);

    $d = (string)($result['date'] ?? date('Y-m-d'));
    $items = $result['items'] ?? [];
    if (!is_array($items)) $items = [];

    $saved = 0;
    $checks = [];

    foreach ($items as $it) {
      if (!is_array($it)) continue;
      $ext = (string)($it['external_id'] ?? '');
      $amt = (float)($it['amount'] ?? 0.0);
      $meta = $it['meta'] ?? null;
      $meta_json = $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;

      finance_upsert($db, $d, $domain_id2, $job_type, $connector, $ext, $amt, $meta_json);
      $saved++;

      $in_db = finance_get_amount($db, $d, $domain_id2, $job_type, $connector, $ext);
      $checks[] = ['external_id'=>$ext, 'expected'=>$amt, 'in_db'=>$in_db];
    }

    $message = "saved {$saved} item(s)";
    if ($saved > 0) $message .= ", first in_db=".(string)($checks[0]['in_db'] ?? 0);

    $payload = [
      'ok' => true,
      'message' => $message,
      'connector' => $connector,
      'domain_id' => $domain_id2,
      'job_type' => $job_type,
      'params' => $params,
      'checks' => $checks,
      'result' => $result,
    ];

    db_exec($db, "
      UPDATE crm_cron_runs
         SET status='ok', finished_at=NOW(), message=?, output_json=?
       WHERE id=?
    ", [$message, clip_str(pretty_json($payload)), $runId]);

    db_exec($db, "UPDATE crm_cron_jobs SET last_run_at=NOW() WHERE id=?", [$id]);

    flash_set('success', "Выполнено. Run #{$runId}. {$message}");
    redirect_self('edit='.(int)$id . '&run_id='.(int)$runId . ($filter_domain_id ? '&domain_id='.$filter_domain_id : ''));

  } catch (Throwable $e) {
    $payload = [
      'ok' => false,
      'connector' => $connector,
      'domain_id' => $domain_id2,
      'job_type' => $job_type,
      'params' => $params,
      'error' => $e->getMessage(),
      'trace' => explode("\n", $e->getTraceAsString()),
    ];

    db_exec($db, "
      UPDATE crm_cron_runs
         SET status='error', finished_at=NOW(), message=?, output_json=?
       WHERE id=?
    ", [$e->getMessage(), clip_str(pretty_json($payload)), $runId]);

    db_exec($db, "UPDATE crm_cron_jobs SET last_run_at=NOW() WHERE id=?", [$id]);

    flash_set('danger', "Ошибка. Run #{$runId}: ".$e->getMessage());
    redirect_self('edit='.(int)$id . '&run_id='.(int)$runId . ($filter_domain_id ? '&domain_id='.$filter_domain_id : ''));
  }
}

// ---------------- DATA ----------------

$domains = db_all($db, "SELECT id, domain FROM crm_domains ORDER BY domain");

$jobs = db_all($db, "
  SELECT j.*, d.domain AS domain_name
  FROM crm_cron_jobs j
  JOIN crm_domains d ON d.id=j.domain_id
  ".($filter_domain_id>0 ? "WHERE j.domain_id=".(int)$filter_domain_id : "")."
  ORDER BY j.id DESC
");

$edit_id = req_int('edit', 0);
$edit = null;
if ($edit_id > 0) {
  $r = db_all($db, "SELECT * FROM crm_cron_jobs WHERE id=? LIMIT 1", [$edit_id]);
  $edit = $r[0] ?? null;
}

$runs = [];
if ($edit_id > 0) {
  $runs = db_all($db, "
    SELECT *
    FROM crm_cron_runs
    WHERE job_id=?
    ORDER BY id DESC
    LIMIT 50
  ", [$edit_id]);
}

?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">⏱ Cron Jobs</h3>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-primary" href="menu.php?page=finance">Финансы</a>
    <a class="btn btn-sm btn-outline-secondary" href="menu.php?page=finance&d=<?=h(date('Y-m-d'))?>">Сегодня</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">

    <div class="card">
      <div class="card-header"><b>Фильтр</b></div>
      <div class="card-body">
        <form method="get" class="d-flex gap-2">
          <input type="hidden" name="page" value="cron_jobs">
          <select class="form-select" name="domain_id">
            <option value="0">Все домены</option>
            <?php foreach ($domains as $d): ?>
              <option value="<?=h($d['id'])?>" <?=((int)$d['id']===$filter_domain_id)?'selected':''?>><?=h($d['domain'])?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline-primary">Показать</button>
        </form>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header"><b>➕ Добавить задачу</b></div>
      <div class="card-body">
        <form method="post" class="vstack gap-2">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="add_job" value="1">

          <label class="form-label small text-muted mb-0">Домен</label>
          <select class="form-select" name="domain_id" required>
            <?php foreach ($domains as $d): ?>
              <?php if ($filter_domain_id>0 && (int)$d['id'] !== $filter_domain_id) continue; ?>
              <option value="<?=h($d['id'])?>"><?=h($d['domain'])?></option>
            <?php endforeach; ?>
          </select>

          <label class="form-label small text-muted mb-0">Тип</label>
          <select class="form-select" name="job_type">
            <option value="cost">cost</option>
            <option value="revenue">revenue</option>
            <option value="metric">metric</option>
          </select>

          <label class="form-label small text-muted mb-0">Коннектор</label>
          <select class="form-select" name="connector" required>
            <?php foreach ($CONNECTORS as $k=>$c): ?>
              <option value="<?=h($k)?>"><?=h($k)?> — <?=h($c['title'])?></option>
            <?php endforeach; ?>
          </select>

          <label class="form-label small text-muted mb-0">Schedule</label>
          <select class="form-select" name="schedule">
            <?php foreach ($SCHEDULES as $k=>$t): ?>
              <option value="<?=h($k)?>"><?=h($k)?> — <?=h($t)?></option>
            <?php endforeach; ?>
          </select>

          <label class="form-label small text-muted mb-0">params_json</label>
          <textarea class="form-control" name="params_json" rows="10"><?=h("{\n  \"example\": \"set params here\"\n}")?></textarea>

          <label class="form-check mt-1">
            <input class="form-check-input" type="checkbox" name="is_enabled" value="1" checked>
            <span class="form-check-label">Включена</span>
          </label>

          <button class="btn btn-primary">Создать</button>

        <div class="text-muted small mt-2">
  <div><b>Примеры params_json:</b></div>

  <div class="mt-2"><code>clickadu_revenue</code> (revenue)</div>
  <pre class="mb-2"><?=h('{
  "token": "CLICKADU_API_TOKEN",
  "timezone": "Europe/Berlin",
  "groupBy": "zone"
}')?></pre>

  <div class="mt-2"><code>mybid_revenue</code> (revenue)</div>
  <pre class="mb-2"><?=h('{
  "token": "MYBID_API_TOKEN",
  "stats_url": "https://publishers.mybid.io/backend/api/public/stats"
}')?></pre>

  <div class="mt-2"><code>kadam_cost</code> (cost)</div>
  <pre class="mb-2"><?=h('{
  "bearer": "KADAM_BEARER_TOKEN",
  "app_id": 141381,
  "client_id": 141381,
  "campaign_id": 858663,
  "timezone": 3
}')?></pre>

  <div class="mt-2"><code>kadam_pub_revenue</code> (revenue)</div>
  <pre class="mb-2"><?=h('{
  "secret": "KADAM_WEBMASTER_SECRET",
  "timezone": "Europe/Moscow"
}')?></pre>

  <div class="mt-2"><code>tacoloco_revenue</code> (revenue)</div>
  <pre class="mb-2"><?=h('{
  "token": "TACOLOCO_TOKEN",
  "timezone": "Europe/Berlin",
  "base_url": "https://api.tacolo.co",
  "placements": [
    { "id": 12345, "type": "push" },
    { "id": 67890, "type": "inpagepush" }
  ]
}')?></pre>

  <div class="mt-2"><code>yandex_metrika_today</code> (metric)</div>
  <div class="mt-2"><code>own_metrika_today</code> (metric) — своя метрика, параметр site</div>
  <pre class="mb-2"><?=h('{
  "oauth_token": "YANDEX_OAUTH_TOKEN",
  "counter_id": 97866307,
  "timezone": "Europe/Moscow",
  "host": "example.com",
  "use_host_filter": false
}')?></pre>
</div>

        </form>
      </div>
    </div>

    <?php if ($view_run): ?>
      <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between">
          <b>Run JSON #<?=h($view_run['id'])?></b>
          <a class="btn btn-sm btn-outline-secondary" href="menu.php?page=cron_jobs<?=($filter_domain_id?('&domain_id='.(int)$filter_domain_id):'')?><?=($edit_id?('&edit='.(int)$edit_id):'')?>">Закрыть</a>
        </div>
        <div class="card-body">
          <div class="small text-muted mb-2">
            status: <code><?=h($view_run['status'])?></code> |
            connector: <code><?=h($view_run['connector'])?></code> |
            type: <code><?=h($view_run['job_type'])?></code>
          </div>
          <pre style="max-height:520px;overflow:auto;background:#f7f7f7;border:1px solid #eee;padding:10px;border-radius:6px;"><?=h((string)($view_run['output_json'] ?? ''))?></pre>
        </div>
      </div>
    <?php endif; ?>

  </div>

  <div class="col-lg-8">

    <div class="card">
      <div class="card-header"><b>Список задач</b></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>ID</th>
              <th>Домен</th>
              <th>Тип</th>
              <th>Коннектор</th>
              <th>Schedule</th>
              <th class="text-muted">ON</th>
              <th>Next</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($jobs as $j): ?>
              <tr>
                <td><?=h($j['id'])?></td>
                <td><b><?=h($j['domain_name'])?></b></td>
                <td><code><?=h($j['job_type'])?></code></td>
                <td><code><?=h($j['connector'])?></code></td>
                <td class="text-muted"><?=h($j['schedule'])?></td>
                <td class="text-muted"><?=((int)$j['is_enabled']===1)?'✓':'—'?></td>
                <td class="text-muted"><?=h((string)$j['next_run_at'])?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary" href="menu.php?page=cron_jobs&edit=<?=h($j['id'])?><?=($filter_domain_id?('&domain_id='.(int)$filter_domain_id):'')?>">Открыть</a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$jobs): ?>
              <tr><td colspan="8" class="text-muted">Пусто</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($edit): ?>
      <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between">
          <b>Задача #<?=h($edit['id'])?> — <?=h($edit['connector'])?></b>
          <a class="btn btn-sm btn-outline-secondary" href="menu.php?page=cron_jobs<?=($filter_domain_id?('&domain_id='.(int)$filter_domain_id):'')?>">Закрыть</a>
        </div>
        <div class="card-body">

          <form method="post" class="mb-2">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="save_job" value="1">
            <input type="hidden" name="id" value="<?=h($edit['id'])?>">

            <div class="row g-2">
              <div class="col-md-4">
                <label class="form-label small text-muted mb-0">Домен</label>
                <select class="form-select" name="domain_id" required>
                  <?php foreach ($domains as $d): ?>
                    <option value="<?=h($d['id'])?>" <?=((int)$d['id']===(int)$edit['domain_id'])?'selected':''?>><?=h($d['domain'])?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label small text-muted mb-0">Тип</label>
                <select class="form-select" name="job_type">
                  <option value="cost" <?=((string)$edit['job_type']==='cost')?'selected':''?>>cost</option>
                  <option value="revenue" <?=((string)$edit['job_type']==='revenue')?'selected':''?>>revenue</option>
                  <option value="metric" <?=((string)$edit['job_type']==='metric')?'selected':''?>>metric</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small text-muted mb-0">Коннектор</label>
                <select class="form-select" name="connector" required>
                  <?php foreach ($CONNECTORS as $k=>$c): ?>
                    <option value="<?=h($k)?>" <?=((string)$edit['connector']===$k)?'selected':''?>><?=h($k)?> — <?=h($c['title'])?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label small text-muted mb-0">Schedule</label>
                <select class="form-select" name="schedule">
                  <?php foreach ($SCHEDULES as $k=>$t): ?>
                    <option value="<?=h($k)?>" <?=((string)$edit['schedule']===$k)?'selected':''?>><?=h($k)?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label small text-muted mb-0">params_json</label>
                <textarea class="form-control" name="params_json" rows="8"><?=h((string)$edit['params_json'])?></textarea>
              </div>
              <div class="col-12">
                <label class="form-check mt-1">
                  <input class="form-check-input" type="checkbox" name="is_enabled" value="1" <?=((int)$edit['is_enabled']===1)?'checked':''?>>
                  <span class="form-check-label">Включена</span>
                </label>
              </div>
              <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary">Сохранить</button>
              </div>
            </div>
          </form>

          <div class="d-flex gap-2">
            <form method="post" class="flex-fill">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="run_now" value="1">
              <input type="hidden" name="id" value="<?=h($edit['id'])?>">
              <button class="btn btn-outline-success w-100">Поставить на CRON (next_run_at=NOW)</button>
            </form>

            <form method="post" class="flex-fill">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="run_inline" value="1">
              <input type="hidden" name="id" value="<?=h($edit['id'])?>">
              <button class="btn btn-success w-100">ВЫПОЛНИТЬ СЕЙЧАС (INLINE)</button>
            </form>
          </div>

          <form method="post" class="mt-2" onsubmit="return confirm('Удалить задачу?');">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="delete_job" value="1">
            <input type="hidden" name="id" value="<?=h($edit['id'])?>">
            <button class="btn btn-outline-danger w-100">Удалить задачу</button>
          </form>

        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header"><b>Runs (последние 50)</b></div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>ID</th><th>Status</th><th>Started</th><th>Finished</th><th>Message</th><th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($runs as $r): ?>
                <tr>
                  <td><?=h($r['id'])?></td>
                  <td><code><?=h($r['status'])?></code></td>
                  <td class="text-muted"><?=h((string)$r['started_at'])?></td>
                  <td class="text-muted"><?=h((string)$r['finished_at'])?></td>
                  <td style="max-width:520px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=h((string)$r['message'])?></td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-secondary" href="menu.php?page=cron_jobs&edit=<?=h($edit['id'])?><?=($filter_domain_id?('&domain_id='.(int)$filter_domain_id):'')?>&run_id=<?=h($r['id'])?>">JSON</a>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$runs): ?>
                <tr><td colspan="6" class="text-muted">Запусков нет</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

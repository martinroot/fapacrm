<?php
// /var/www/fapa.io/fapacrm/pages/site_deploy.php
declare(strict_types=1);

csrf_check();
$db = db_connect();

require_once __DIR__ . '/deploy/lib.php';

$templatePath = (string)ucfg($GLOBALS['USER_CFG'], 'TEMPLATE.site_path', '/var/www/hotpornmoms.ru');
$templateDb   = (string)ucfg($GLOBALS['USER_CFG'], 'TEMPLATE.db_name', 'hotpornmoms');
$siteMigrationsDir = rtrim($templatePath, '/') . '/cmd/migrations_site';

$action    = req_str('action', '');
$domain_id = req_int('domain_id', 0);
$run_id    = req_int('run_id', 0);
$step      = req_str('step', '');
$auto      = req_int('auto', 0); // 1 => auto-run step via JS

// ✅ ПЛАН ШАГОВ (ВАЖНО: порядок)
$stepsPlan = [
  ['key' => 'mkdir',        'title' => 'Создание папки',            'file' => __DIR__ . '/deploy/01_mkdir.php'],
  ['key' => 'copy',         'title' => 'Копирование эталона',       'file' => __DIR__ . '/deploy/02_rsync.php'],
  ['key' => 'chown',        'title' => 'Права (chown/chmod)',       'file' => __DIR__ . '/deploy/03_chown.php'],
  ['key' => 'db_create',    'title' => 'Создание БД',               'file' => __DIR__ . '/deploy/04_db_create.php'],
  ['key' => 'db_dump',      'title' => 'Дамп эталонной БД',         'file' => __DIR__ . '/deploy/05_db_dump.php'],
  ['key' => 'db_import',    'title' => 'Импорт дампа в новую БД',   'file' => __DIR__ . '/deploy/06_db_import.php'],
  ['key' => 'config',       'title' => 'Патч config.php / config.user.php', 'file' => __DIR__ . '/deploy/07_config.php'],
  ['key' => 'migrate',      'title' => 'Миграции сайта',            'file' => __DIR__ . '/deploy/08_site_migrations.php'],

  // ✅ NGINX (добавили)
  ['key' => 'nginx_render', 'title' => 'Nginx: render config',      'file' => __DIR__ . '/deploy/09_nginx_render.php'],
  ['key' => 'nginx_apply',  'title' => 'Nginx: apply + nginx -t + reload', 'file' => __DIR__ . '/deploy/10_nginx_apply.php'],
];

function deploy_get_next_step_key(array $stepsPlan, string $current): ?string {
  $keys = [];
  foreach ($stepsPlan as $s) $keys[] = (string)$s['key'];

  if ($current === '') return $keys[0] ?? null;

  $n = count($keys);
  for ($i = 0; $i < $n; $i++) {
    if ($keys[$i] === $current) return $keys[$i + 1] ?? null;
  }
  return null;
}

function deploy_file_for_step(array $stepsPlan, string $key): ?string {
  foreach ($stepsPlan as $s) {
    if ((string)$s['key'] === $key) return (string)$s['file'];
  }
  return null;
}

function deploy_title_for_step(array $stepsPlan, string $key): string {
  foreach ($stepsPlan as $s) {
    if ((string)$s['key'] === $key) return (string)($s['title'] ?? $key);
  }
  return $key;
}

function deploy_start_run(mysqli $db, int $domain_id): int {
  db_exec($db, "INSERT INTO crm_deploy_runs(domain_id,status,started_at) VALUES(?, 'running', ?)", [$domain_id, deploy_now_sql()]);
  return (int)$db->insert_id;
}

function deploy_mark_run_done(mysqli $db, int $run_id, int $domain_id, array $ctx): void {
  db_exec($db, "UPDATE crm_deploy_runs SET status='ok', finished_at=?, summary=? WHERE id=?",
    [deploy_now_sql(), 'ok', $run_id]);

  db_exec($db, "UPDATE crm_domains
      SET deploy_status='done', deployed_at=?, status='live', path=?, db_name=?
      WHERE id=?",
    [deploy_now_sql(), (string)$ctx['path'], (string)$ctx['dbName'], $domain_id]);

  // минимальный чек-лист
  $fid_path = (int)(db_scalar($db, "SELECT id FROM crm_check_fields WHERE code='path_ok'") ?: 0);
  $fid_db   = (int)(db_scalar($db, "SELECT id FROM crm_check_fields WHERE code='db_ok'") ?: 0);
  if ($fid_path) db_exec($db, "INSERT INTO crm_check_values(domain_id,field_id,val_bool) VALUES(?,?,1)
                               ON DUPLICATE KEY UPDATE val_bool=1", [$domain_id, $fid_path]);
  if ($fid_db)   db_exec($db, "INSERT INTO crm_check_values(domain_id,field_id,val_bool) VALUES(?,?,1)
                               ON DUPLICATE KEY UPDATE val_bool=1", [$domain_id, $fid_db]);
}

function deploy_mark_run_fail(mysqli $db, int $run_id, int $domain_id, string $msg): void {
  db_exec($db, "UPDATE crm_deploy_runs SET status='fail', finished_at=?, summary=? WHERE id=?",
    [deploy_now_sql(), $msg, $run_id]);
  db_exec($db, "UPDATE crm_domains SET deploy_status='fail' WHERE id=?", [$domain_id]);
}

/**
 * ✅ При старте ранa — заранее создаём записи шагов (pending) с title из плана.
 * Тогда таблица "Статусы шагов" всегда одинаковая и понятная.
 */
function deploy_init_steps(mysqli $db, int $run_id, array $stepsPlan): void {
  foreach ($stepsPlan as $s) {
    $k = (string)$s['key'];
    $t = (string)($s['title'] ?? $k);
    deploy_ensure_step($db, $run_id, $k, $t);
  }
}

/**
 * POST: start run (creates run and redirects to first step)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'start' && $domain_id > 0) {
  if (!BM_ALLOW_SHELL) {
    flash_set('danger', 'Shell OFF. Включи ALLOW_SHELL=true в config.user.php');
    header('Location: menu.php?page=site_deploy');
    exit;
  }

  $dom = db_one($db, "SELECT * FROM crm_domains WHERE id=?", [$domain_id]);
  if (!$dom) {
    flash_set('danger', 'Домен не найден');
    header('Location: menu.php?page=site_deploy');
    exit;
  }

  $run_id = deploy_start_run($db, $domain_id);
  db_exec($db, "UPDATE crm_domains SET deploy_status='running', deploy_last_log=NULL WHERE id=?", [$domain_id]);

  // default path/db
  $domain = (string)$dom['domain'];
  $path   = trim((string)($dom['path'] ?? ''));
  $dbName = trim((string)($dom['db_name'] ?? ''));

  if ($path === '') $path = "/var/www/" . $domain;
  if ($dbName === '') {
    $safe = preg_replace('/[^a-zA-Z0-9_]+/', '_', $domain);
    $dbName = "db_" . $safe;
  }

  db_exec($db, "UPDATE crm_domains SET path=?, db_name=? WHERE id=?", [$path, $dbName, $domain_id]);

  // ✅ создаём pending шаги
  deploy_init_steps($db, $run_id, $stepsPlan);

  $first = deploy_get_next_step_key($stepsPlan, '');
  header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id.'&step='.urlencode((string)$first).'&auto=1');
  exit;
}

/**
 * GET: execute ONE step (run_step)
 * This is a GET intentionally, because we auto-run via JS. CSRF still ok because it's internal UI.
 */
if ($action === 'run_step' && $run_id > 0 && $domain_id > 0 && $step !== '') {
  if (!BM_ALLOW_SHELL) {
    flash_set('danger', 'Shell OFF. Включи ALLOW_SHELL=true в config.user.php');
    header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id);
    exit;
  }

  $dom = db_one($db, "SELECT * FROM crm_domains WHERE id=?", [$domain_id]);
  if (!$dom) {
    flash_set('danger', 'Домен не найден');
    header('Location: menu.php?page=site_deploy');
    exit;
  }

  $domain = (string)$dom['domain'];
  $path   = (string)$dom['path'];
  $dbName = (string)$dom['db_name'];

  // ✅ Контекст шага (передаётся в step-файлы)
  $ctx = [
    'domain' => $domain,
    'path'   => $path,
    'dbName' => $dbName,
    'templatePath' => $templatePath,
    'templateDb'   => $templateDb,
    // чтобы дамп не перетирался между шагами/ранами:
    'dumpFile'     => "/tmp/" . $templateDb . "_dump_run" . $run_id . ".sql",
    'siteMigrationsDir' => $siteMigrationsDir,

    // nginx render/apply будет писать сюда (10-й шаг читает из этого же пути)
    'nginxTmpFile'  => "/tmp/nginx_{$domain}_run{$run_id}.conf",
    'nginxConfName' => "{$domain}.conf",
    'phpSock'       => 'unix:/run/php/php7.4-fpm.sock',
  ];

  $file = deploy_file_for_step($stepsPlan, $step);
  if (!$file || !is_file($file)) {
    deploy_mark_run_fail($db, $run_id, $domain_id, "Step file not found: ".$step);
    flash_set('danger', "Нет файла шага: ".$step);
    header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id);
    exit;
  }

  // ✅ выполняем шаг (step-file сам логирует в crm_deploy_steps через deploy_step_start/finish)
  $result = require $file;
  $rc = (int)($result['rc'] ?? 1);

  if ($rc !== 0) {
    deploy_mark_run_fail($db, $run_id, $domain_id, "Step failed: ".$step);
    flash_set('danger', "Упал шаг: ".$step);
    header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id);
    exit;
  }

  $next = deploy_get_next_step_key($stepsPlan, $step);
  if ($next === null) {
    deploy_mark_run_done($db, $run_id, $domain_id, $ctx);
    flash_set('success', "Деплой OK: ".$domain);
    header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id);
    exit;
  }

  header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id.'&step='.urlencode((string)$next).'&auto='.$auto);
  exit;
}

// ---------- UI data ----------
$pending = db_all($db, "SELECT * FROM crm_domains
  WHERE (deploy_status IS NULL OR deploy_status IN ('todo','fail'))
  ORDER BY updated_at DESC");

$run = null;
$steps = [];
if ($run_id > 0) {
  $run = db_one($db, "SELECT r.*, d.domain FROM crm_deploy_runs r JOIN crm_domains d ON d.id=r.domain_id WHERE r.id=?", [$run_id]);
  $steps = db_all($db, "SELECT * FROM crm_deploy_steps WHERE run_id=? ORDER BY id", [$run_id]);
}

?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">🚀 Деплой (по шагам, 1 шаг = 1 запрос)</h3>
</div>

<?php if ($auto === 1 && $run_id > 0 && $domain_id > 0 && $step !== '' && $action === ''): ?>
  <script>
    // auto-run current step via GET
    window.location.href = "menu.php?page=site_deploy&action=run_step&run_id=<?=h((string)$run_id)?>&domain_id=<?=h((string)$domain_id)?>&step=<?=h($step)?>&auto=1";
  </script>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><b>Ожидают деплоя</b></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th>Домен</th><th>deploy</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($pending as $d): ?>
              <tr>
                <td>
                  <b><?=h((string)$d['domain'])?></b>
                  <div class="text-muted small"><?=h((string)($d['path'] ?? ''))?></div>
                </td>
                <td><span class="badge text-bg-secondary"><?=h((string)($d['deploy_status'] ?? 'todo'))?></span></td>
                <td class="text-end">
                  <form method="post" class="m-0">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="start">
                    <input type="hidden" name="domain_id" value="<?=h((string)$d['id'])?>">
                    <button class="btn btn-sm btn-primary" <?=BM_ALLOW_SHELL ? '' : 'disabled'?>>Старт</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$pending): ?><tr><td colspan="3" class="text-muted">Пусто</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-body">
        <div class="text-muted small">
          Эталон: <code><?=h($templatePath)?></code> / DB: <code><?=h($templateDb)?></code><br>
          Shell: <b><?=BM_ALLOW_SHELL ? 'ON' : 'OFF'?></b>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><b>Статусы шагов</b></div>
      <div class="card-body">
        <?php if (!$run): ?>
          <div class="text-muted">Нажми “Старт” — шаги будут выполняться по одному и логироваться.</div>
        <?php else: ?>
          <div class="mb-2">
            <b>Run #<?=h((string)$run['id'])?></b> — домен: <b><?=h((string)$run['domain'])?></b>,
            статус: <b><?=h((string)$run['status'])?></b>
          </div>

          <?php if ((string)$run['status'] === 'running' && $step !== ''): ?>
            <div class="alert alert-info py-2">
              Текущий шаг: <b><?=h($step)?></b>
              <span class="text-muted small">(<?=h(deploy_title_for_step($stepsPlan, $step))?>)</span>
              <a class="btn btn-sm btn-outline-primary ms-2"
                 href="menu.php?page=site_deploy&action=run_step&run_id=<?=h((string)$run_id)?>&domain_id=<?=h((string)$domain_id)?>&step=<?=h($step)?>&auto=0">
                Выполнить шаг
              </a>
              <a class="btn btn-sm btn-primary ms-2"
                 href="menu.php?page=site_deploy&run_id=<?=h((string)$run_id)?>&domain_id=<?=h((string)$domain_id)?>&step=<?=h($step)?>&auto=1">
                Авто-догон
              </a>
            </div>
          <?php endif; ?>

          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>Шаг</th><th>Статус</th><th>RC</th><th>Вывод</th></tr></thead>
              <tbody>
                <?php foreach ($steps as $s): ?>
                  <tr>
                    <td>
                      <b><?=h((string)$s['title'])?></b>
                      <div class="text-muted small"><?=h((string)$s['step_key'])?></div>
                    </td>
                    <td>
                      <?php
                        $st = (string)$s['status'];
                        $badge = 'secondary';
                        if ($st === 'ok') $badge = 'success';
                        elseif ($st === 'fail') $badge = 'danger';
                        elseif ($st === 'running') $badge = 'warning';
                      ?>
                      <span class="badge text-bg-<?=$badge?>"><?=h($st)?></span>
                    </td>
                    <td class="small"><?=h((string)($s['rc'] ?? ''))?></td>
                    <td class="small"><pre style="white-space:pre-wrap;max-height:160px;overflow:auto;margin:0;"><?=h((string)($s['output'] ?? ''))?></pre></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$steps): ?><tr><td colspan="4" class="text-muted">Шагов пока нет</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ((string)$run['status'] === 'ok'): ?>
            <div class="alert alert-success mt-3 mb-0">
              Готово. ADMIN:
              <a href="<?=h('https://'.$run['domain'].'/cmd/menu.php')?>" target="_blank" rel="noopener">
                <?=h('https://'.$run['domain'].'/cmd/menu.php')?>
              </a>
            </div>
          <?php endif; ?>

          <?php if ((string)$run['status'] === 'fail'): ?>
            <div class="alert alert-danger mt-3 mb-0">
              Деплой упал. Смотри “Вывод” у последнего шага и <code>deploy_last_log</code> в домене.
            </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

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
$step      = req_str('step', ''); // текущий шаг мастера

$stepsPlan = [
  ['key'=>'mkdir',       'title'=>'Создание папки',                 'file'=>__DIR__.'/deploy/01_mkdir.php'],
  ['key'=>'copy',        'title'=>'Копирование эталона (rsync)',     'file'=>__DIR__.'/deploy/02_rsync.php'],

  // ✅ ШАГ 13: branding (замена домена/бренда в index/cat/popular/video + генерация logo)
  ['key'=>'branding',    'title'=>'Branding: домен/заголовки/лого',  'file'=>__DIR__.'/deploy/13_branding.php'],

  ['key'=>'chown',       'title'=>'Права на файлы (chown)',         'file'=>__DIR__.'/deploy/03_chown.php'],
  ['key'=>'db_create',   'title'=>'Создание БД',                    'file'=>__DIR__.'/deploy/04_db_create.php'],
  ['key'=>'db_dump',     'title'=>'Дамп эталона БД',                'file'=>__DIR__.'/deploy/05_db_dump.php'],
  ['key'=>'db_import',   'title'=>'Импорт в новую БД',              'file'=>__DIR__.'/deploy/06_db_import.php'],
  ['key'=>'config',      'title'=>'Обновление config.user.php',     'file'=>__DIR__.'/deploy/07_config.php'],
  ['key'=>'migrate',     'title'=>'Миграции БД сайта',              'file'=>__DIR__.'/deploy/08_site_migrations.php'],

  // nginx шаги
  ['key'=>'nginx_render','title'=>'Nginx: render config',           'file'=>__DIR__.'/deploy/09_nginx_render.php'],
  ['key'=>'nginx_apply', 'title'=>'Nginx: apply + nginx -t + reload','file'=>__DIR__.'/deploy/10_nginx_apply.php'],

  // counters + ads
  ['key'=>'apply_site_settings','title'=>'Счётчики + реклама',      'file'=>__DIR__.'/deploy/11_apply_site_settings.php'],

  // cron presets
  ['key'=>'cron_seed','title'=>'Cron: очистка + 4 пресета',         'file'=>__DIR__.'/deploy/12_cron_seed.php'],
];

function sp_next(array $plan, string $cur): ?string {
  $keys = [];
  foreach ($plan as $p) $keys[] = (string)$p['key'];
  if ($cur === '') return $keys[0] ?? null;
  $n = count($keys);
  for ($i=0; $i<$n; $i++) if ($keys[$i] === $cur) return $keys[$i+1] ?? null;
  return null;
}
function sp_prev(array $plan, string $cur): ?string {
  $keys = [];
  foreach ($plan as $p) $keys[] = (string)$p['key'];
  if ($cur === '') return null;
  $n = count($keys);
  for ($i=0; $i<$n; $i++) if ($keys[$i] === $cur) return $keys[$i-1] ?? null;
  return null;
}
function sp_title(array $plan, string $key): string {
  foreach ($plan as $p) if ((string)$p['key'] === $key) return (string)$p['title'];
  return $key;
}
function sp_file(array $plan, string $key): ?string {
  foreach ($plan as $p) if ((string)$p['key'] === $key) return (string)$p['file'];
  return null;
}

function start_run(mysqli $db, int $domain_id): int {
  db_exec($db, "INSERT INTO crm_deploy_runs(domain_id,status) VALUES(?, 'running')", [$domain_id]);
  return (int)$db->insert_id;
}
function mark_done(mysqli $db, int $run_id, int $domain_id, array $ctx): void {
  db_exec($db, "UPDATE crm_deploy_runs SET status='ok', finished_at=?, summary=? WHERE id=?",
    [deploy_now_sql(), 'ok', $run_id]);
  db_exec($db, "UPDATE crm_domains SET deploy_status='done', deployed_at=?, status='live', path=?, db_name=? WHERE id=?",
    [deploy_now_sql(), (string)$ctx['path'], (string)$ctx['dbName'], $domain_id]);
}
function mark_fail(mysqli $db, int $run_id, int $domain_id, string $msg): void {
  db_exec($db, "UPDATE crm_deploy_runs SET status='fail', finished_at=?, summary=? WHERE id=?",
    [deploy_now_sql(), $msg, $run_id]);
  db_exec($db, "UPDATE crm_domains SET deploy_status='fail' WHERE id=?", [$domain_id]);
}

/**
 * Собираем ctx по текущему домену + run_id (одинаково для manual/auto)
 */
function build_ctx(array $dom, int $domain_id, int $run_id, string $templatePath, string $templateDb, string $siteMigrationsDir): array {
  $newDomain = (string)$dom['domain'];

  // донорский домен пробуем вытащить из пути эталона: /var/www/hotpornmoms.ru -> hotpornmoms.ru
  $tplDomain = basename(rtrim($templatePath, '/'));
  if ($tplDomain === '' || strpos($tplDomain, '.') === false) {
    $tplDomain = (string)$templateDb; // запасной вариант
  }

  // бренд из домена: my-cool-site.com -> "My Cool Site"
  $domain_to_brand = function(string $d): string {
    $d = strtolower(trim($d));
    $d = preg_replace('~^https?://~', '', $d);
    $d = preg_replace('~^www\.~', '', $d);
    $d = preg_replace('~[:/].*$~', '', $d);
    $label = explode('.', $d)[0] ?? $d;
    $label = preg_replace('~[^a-z0-9]+~i', ' ', $label);
    $label = trim((string)preg_replace('~\s+~', ' ', $label));
    if ($label === '') return $d;
    return mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');
  };

  $tplBrand = $domain_to_brand($tplDomain);
  $newBrand = $domain_to_brand($newDomain);

  return [
    'domain_id' => (int)$domain_id,
    'domain' => (string)$dom['domain'],
    'path'   => (string)$dom['path'],
    'dbName' => (string)$dom['db_name'],
    'templatePath' => $templatePath,
    'templateDb'   => $templateDb,
    'dumpFile'     => "/tmp/" . $templateDb . "_dump_run" . $run_id . ".sql",
    'siteMigrationsDir' => $siteMigrationsDir,

    'nginxTmpFile'  => "/tmp/nginx_" . (string)$dom['domain'] . "_run" . $run_id . ".conf",
    'nginxConfName' => (string)$dom['domain'] . ".conf",
    'phpSock'       => 'unix:/run/php/php7.4-fpm.sock',

    // ✅ для branding-шага
    'templateDomain' => (string)$tplDomain,
    'templateBrand'  => (string)$tplBrand,
    'newBrand'       => (string)$newBrand,
  ];
}

/**
 * Нормализация path/dbName (как в start wizard)
 */
function normalize_domain_path_db(mysqli $db, array $dom, int $domain_id): void {
  $domain = (string)$dom['domain'];
  $path   = trim((string)($dom['path'] ?? ''));
  $dbName = trim((string)($dom['db_name'] ?? ''));
  if ($path==='') $path = "/var/www/".$domain;
  if ($dbName==='') {
    $safe = preg_replace('/[^a-zA-Z0-9_]+/', '_', $domain);
    $dbName = "db_" . $safe;
  }
  db_exec($db, "UPDATE crm_domains SET path=?, db_name=? WHERE id=?", [$path, $dbName, $domain_id]);
}

// --------- ACTION: start wizard ----------
if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='start' && $domain_id>0) {
  if (!BM_ALLOW_SHELL) {
    flash_set('danger', 'Shell OFF. Включи ALLOW_SHELL=true в config.user.php');
    header('Location: menu.php?page=site_deploy'); exit;
  }
  $dom = db_one($db, "SELECT * FROM crm_domains WHERE id=?", [$domain_id]);
  if (!$dom) { flash_set('danger','Домен не найден'); header('Location: menu.php?page=site_deploy'); exit; }

  $run_id = start_run($db, $domain_id);
  db_exec($db, "UPDATE crm_domains SET deploy_status='running', deploy_last_log=NULL WHERE id=?", [$domain_id]);
  deploy_push_log($db, $domain_id, $run_id, "WIZARD start");

  normalize_domain_path_db($db, $dom, $domain_id);

  $first = sp_next($stepsPlan, '');
  header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id.'&step='.$first);
  exit;
}

// --------- ACTION: AUTO RUN ALL STEPS ----------
if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='auto' && $domain_id>0) {
  if (!BM_ALLOW_SHELL) {
    flash_set('danger', 'Shell OFF. Включи ALLOW_SHELL=true в config.user.php');
    header('Location: menu.php?page=site_deploy'); exit;
  }

  $dom = db_one($db, "SELECT * FROM crm_domains WHERE id=?", [$domain_id]);
  if (!$dom) {
    flash_set('danger','Домен не найден');
    header('Location: menu.php?page=site_deploy'); exit;
  }

  $run_id = start_run($db, $domain_id);
  db_exec($db, "UPDATE crm_domains SET deploy_status='running', deploy_last_log=NULL WHERE id=?", [$domain_id]);
  deploy_push_log($db, $domain_id, $run_id, "AUTO start");

  // нормализуем path/db_name
  normalize_domain_path_db($db, $dom, $domain_id);

  // перечитываем домен после normalize (path/db_name)
  $dom = db_one($db, "SELECT * FROM crm_domains WHERE id=?", [$domain_id]);
  if (!$dom) {
    mark_fail($db, $run_id, $domain_id, "Domain disappeared after normalize");
    flash_set('danger','Домен не найден (после normalize)');
    header('Location: menu.php?page=site_deploy'); exit;
  }

  $ctx = build_ctx($dom, $domain_id, $run_id, $templatePath, $templateDb, $siteMigrationsDir);

  // прогоняем все шаги по порядку
  foreach ($stepsPlan as $p) {
    $k = (string)$p['key'];
    $title = (string)$p['title'];
    $file = (string)$p['file'];

    if (!is_file($file)) {
      deploy_ensure_step($db, $run_id, $k, $title);
      deploy_step_finish($db, $run_id, $k, 'fail', 127, "Step file missing: {$file}");
      mark_fail($db, $run_id, $domain_id, "Step file missing: ".$k);
      flash_set('danger', "AUTO: нет файла шага {$k}");
      header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id.'&step='.urlencode($k));
      exit;
    }

    deploy_ensure_step($db, $run_id, $k, $title);
    deploy_step_start($db, $run_id, $k);

    // шаги получают $ctx через require
    $res = require $file;

    $rc  = (int)($res['rc'] ?? 1);
    $out = (string)($res['out'] ?? '');

    if ($rc !== 0) {
      deploy_step_finish($db, $run_id, $k, 'fail', $rc, $out);
      mark_fail($db, $run_id, $domain_id, "Step failed: ".$k." rc=".$rc);
      deploy_push_log($db, $domain_id, $run_id, "AUTO FAIL {$k} rc={$rc}");

      flash_set('danger', "AUTO: шаг упал {$k} (см. вывод)");
      header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id.'&step='.urlencode($k));
      exit;
    }

    deploy_step_finish($db, $run_id, $k, 'ok', 0, $out);
    deploy_push_log($db, $domain_id, $run_id, "AUTO OK {$k}");
  }

  // все шаги ok
  $ctxDone = ['path'=>(string)$ctx['path'], 'dbName'=>(string)$ctx['dbName']];
  mark_done($db, $run_id, $domain_id, $ctxDone);
  deploy_push_log($db, $domain_id, $run_id, "AUTO DONE");

  flash_set('success', 'AUTO: деплой завершён');
  header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id);
  exit;
}

// --------- ACTION: save settings on current step (without running) ----------
if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='save' && $run_id>0 && $domain_id>0) {
  $path   = trim(req_str('path',''));
  $dbName = trim(req_str('db_name',''));
  if ($path !== '' || $dbName !== '') {
    $dom = db_one($db, "SELECT * FROM crm_domains WHERE id=?", [$domain_id]);
    $domain = (string)($dom['domain'] ?? '');
    if ($path==='') $path = "/var/www/".$domain;
    if ($dbName==='') {
      $safe = preg_replace('/[^a-zA-Z0-9_]+/', '_', $domain);
      $dbName = "db_" . $safe;
    }
    db_exec($db, "UPDATE crm_domains SET path=?, db_name=? WHERE id=?", [$path, $dbName, $domain_id]);
    flash_set('success','Параметры сохранены');
  }
  header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id.'&step='.urlencode($step));
  exit;
}

// --------- ACTION: run current step (manual) ----------
if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='run' && $run_id>0 && $domain_id>0 && $step!=='') {
  if (!BM_ALLOW_SHELL) {
    flash_set('danger', 'Shell OFF. Включи ALLOW_SHELL=true');
    header('Location: menu.php?page=site_deploy'); exit;
  }

  $dom = db_one($db, "SELECT * FROM crm_domains WHERE id=?", [$domain_id]);
  if (!$dom) { flash_set('danger','Домен не найден'); header('Location: menu.php?page=site_deploy'); exit; }

  $ctx = build_ctx($dom, $domain_id, $run_id, $templatePath, $templateDb, $siteMigrationsDir);

  $file = sp_file($stepsPlan, $step);
  if (!$file || !is_file($file)) {
    mark_fail($db, $run_id, $domain_id, "Step file missing: ".$step);
    flash_set('danger', 'Нет файла шага: '.$step);
    header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id.'&step='.urlencode($step));
    exit;
  }

  deploy_ensure_step($db, $run_id, $step, sp_title($stepsPlan, $step));
  deploy_step_start($db, $run_id, $step);

  $res = require $file;

  $rc  = (int)($res['rc'] ?? 1);
  $out = (string)($res['out'] ?? '');

  if ($rc !== 0) {
    deploy_step_finish($db, $run_id, $step, 'fail', $rc, $out);
    mark_fail($db, $run_id, $domain_id, "Step failed: ".$step." rc=".$rc);
    flash_set('danger', 'Шаг упал: '.$step.' (см. вывод)');
    header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id.'&step='.urlencode($step));
    exit;
  }

  deploy_step_finish($db, $run_id, $step, 'ok', 0, $out);

  flash_set('success', 'Шаг выполнен: '.$step);
  header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id.'&step='.urlencode($step));
  exit;
}

// --------- ACTION: next/prev ----------
if ($action==='next' && $run_id>0 && $domain_id>0 && $step!=='') {
  $next = sp_next($stepsPlan, $step);
  if ($next === null) {
    $dom = db_one($db, "SELECT * FROM crm_domains WHERE id=?", [$domain_id]);
    $ctx = ['path'=>(string)($dom['path'] ?? ''), 'dbName'=>(string)($dom['db_name'] ?? '')];
    mark_done($db, $run_id, $domain_id, $ctx);
    flash_set('success', 'Деплой завершён');
    header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id);
    exit;
  }
  header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id.'&step='.$next);
  exit;
}
if ($action==='prev' && $run_id>0 && $domain_id>0 && $step!=='') {
  $prev = sp_prev($stepsPlan, $step);
  if ($prev === null) $prev = $step;
  header('Location: menu.php?page=site_deploy&run_id='.$run_id.'&domain_id='.$domain_id.'&step='.$prev);
  exit;
}

// --------- UI data ----------
$pending = db_all($db, "SELECT * FROM crm_domains ORDER BY updated_at DESC LIMIT 200");

$run = null; $stepsRows = []; $dom = null;
if ($run_id>0) {
  $run = db_one($db, "SELECT r.*, d.domain FROM crm_deploy_runs r JOIN crm_domains d ON d.id=r.domain_id WHERE r.id=?", [$run_id]);
  $stepsRows = db_all($db, "SELECT * FROM crm_deploy_steps WHERE run_id=? ORDER BY id", [$run_id]);
}
if ($domain_id>0) $dom = db_one($db, "SELECT * FROM crm_domains WHERE id=?", [$domain_id]);

?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">🧭 Деплой мастер (ручной)</h3>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><b>Домены</b></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th>Домен</th><th>deploy</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($pending as $d): ?>
              <tr>
                <td><b><?=h($d['domain'])?></b></td>
                <td><span class="badge text-bg-secondary"><?=h((string)($d['deploy_status'] ?? 'todo'))?></span></td>
                <td class="text-end">
                  <div class="d-flex gap-2 justify-content-end">
                    <!-- Открыть мастер -->
                    <form method="post" class="m-0">
                      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="action" value="start">
                      <input type="hidden" name="domain_id" value="<?=h((string)$d['id'])?>">
                      <button class="btn btn-sm btn-primary" <?=BM_ALLOW_SHELL ? '' : 'disabled'?>>Открыть мастер</button>
                    </form>

                    <!-- AUTO -->
                    <form method="post" class="m-0">
                      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="action" value="auto">
                      <input type="hidden" name="domain_id" value="<?=h((string)$d['id'])?>">
                      <button class="btn btn-sm btn-success"
                        <?=BM_ALLOW_SHELL ? '' : 'disabled'?>
                        onclick="return confirm('AUTO прогонит ВСЕ шаги деплоя подряд для домена: <?=h((string)$d['domain'])?>. Продолжить?');">
                        Auto
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$pending): ?><tr><td colspan="3" class="text-muted">Пусто</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-body">
        <div class="text-muted small">
          Эталон: <code><?=h($templatePath)?></code><br>
          Shell: <b><?=BM_ALLOW_SHELL ? 'ON' : 'OFF'?></b>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><b>Мастер</b></div>
      <div class="card-body">
        <?php if (!$run || !$dom): ?>
          <div class="text-muted">Выбери домен слева → “Открыть мастер”.</div>
        <?php else: ?>
          <div class="mb-2">
            <b>Run #<?=h((string)$run['id'])?></b> домен: <b><?=h((string)$dom['domain'])?></b>,
            статус: <b><?=h((string)$run['status'])?></b>
          </div>

          <div class="alert alert-light border">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
              <div>
                <div class="text-muted small">Текущий шаг</div>
                <div class="fw-bold"><?=h($step)?> — <?=h(sp_title($stepsPlan, $step))?></div>
              </div>
              <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="menu.php?page=site_deploy&action=prev&run_id=<?=h((string)$run_id)?>&domain_id=<?=h((string)$domain_id)?>&step=<?=h($step)?>">Назад</a>
                <a class="btn btn-sm btn-primary" href="menu.php?page=site_deploy&action=next&run_id=<?=h((string)$run_id)?>&domain_id=<?=h((string)$domain_id)?>&step=<?=h($step)?>">Далее</a>
              </div>
            </div>

            <hr class="my-2">

            <form method="post" class="row g-2 align-items-end">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="run_id" value="<?=h((string)$run_id)?>">
              <input type="hidden" name="domain_id" value="<?=h((string)$domain_id)?>">
              <input type="hidden" name="step" value="<?=h($step)?>">

              <div class="col-md-6">
                <label class="form-label small text-muted">path</label>
                <input class="form-control" name="path" value="<?=h((string)$dom['path'])?>">
              </div>
              <div class="col-md-6">
                <label class="form-label small text-muted">db_name</label>
                <input class="form-control" name="db_name" value="<?=h((string)$dom['db_name'])?>">
              </div>

              <div class="col-12">
                <button class="btn btn-sm btn-outline-secondary">Сохранить параметры</button>
              </div>
            </form>

            <hr class="my-2">

            <form method="post" class="d-flex gap-2 flex-wrap">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="run">
              <input type="hidden" name="run_id" value="<?=h((string)$run_id)?>">
              <input type="hidden" name="domain_id" value="<?=h((string)$domain_id)?>">
              <input type="hidden" name="step" value="<?=h($step)?>">
              <button class="btn btn-sm btn-success">Выполнить шаг</button>
              <button class="btn btn-sm btn-outline-danger" name="action" value="run">Повторить шаг</button>
            </form>

            <div class="text-muted small mt-2">
              Выполняется <b>только один шаг</b>. Если упадёт — смотри вывод ниже и чини.
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>Шаг</th><th>Статус</th><th>RC</th><th>Вывод</th></tr></thead>
              <tbody>
                <?php foreach ($stepsRows as $s): ?>
                  <tr>
                    <td><b><?=h((string)$s['title'])?></b><div class="text-muted small"><?=h((string)$s['step_key'])?></div></td>
                    <td>
                      <?php
                        $st = (string)$s['status'];
                        $badge = 'secondary';
                        if ($st==='ok') $badge='success';
                        elseif ($st==='fail') $badge='danger';
                        elseif ($st==='running') $badge='warning';
                      ?>
                      <span class="badge text-bg-<?=$badge?>"><?=h($st)?></span>
                    </td>
                    <td class="small"><?=h((string)($s['rc'] ?? ''))?></td>
                    <td class="small"><pre style="white-space:pre-wrap;max-height:180px;overflow:auto;margin:0;"><?=h((string)($s['output'] ?? ''))?></pre></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$stepsRows): ?><tr><td colspan="4" class="text-muted">Шагов пока нет — нажми “Выполнить шаг”.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ((string)$run['status'] === 'ok'): ?>
            <div class="alert alert-success mt-3 mb-0">
              Готово. ADMIN:
              <a href="<?=h('https://'.$dom['domain'].'/cmd/menu.php')?>" target="_blank" rel="noopener">
                <?=h('https://'.$dom['domain'].'/cmd/menu.php')?>
              </a>
            </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
declare(strict_types=1);

/**
 * pages/networks.php (одним файлом)
 * ✅ Добавить сетку
 * ✅ Редактировать сетку + ключи (postfactum)
 * ✅ Удалить сетку (и ее привязки)
 * ✅ Привязать сетку к домену
 * ✅ Список привязок
 *
 * Требует твоих хелперов: csrf_check(), csrf_token(), db_connect(), db_exec(), db_all(), db_row(),
 * req_str(), req_int(), req_bool(), flash_set(), h()
 */

csrf_check();
$db = db_connect();

/** =========================
 *  ACTIONS
 *  ========================= */

// --- ADD NETWORK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('add_network', false)) {
  $code  = trim(req_str('code', ''));
  $title = trim(req_str('title', ''));

  if ($code === '' || $title === '') {
    flash_set('danger', 'code/title обязательны');
  } else {
    db_exec(
      $db,
      "INSERT IGNORE INTO crm_networks(code,title,api_base_url,api_key,api_extra)
       VALUES(?,?,?,?,?)",
      [
        $code,
        $title,
        trim(req_str('api_base_url', '')),
        req_str('api_key', ''),
        json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ]
    );
    flash_set('success', 'Сетка добавлена (или уже была)');
  }

  header('Location: menu.php?page=networks');
  exit;
}

// --- EDIT NETWORK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('edit_network', false)) {
  $id = req_int('id', 0);
  if ($id <= 0) {
    flash_set('danger', 'bad id');
    header('Location: menu.php?page=networks');
    exit;
  }

  $title        = trim(req_str('title', ''));
  $api_base_url = trim(req_str('api_base_url', ''));
  $api_key      = req_str('api_key', '');

  $api_extra_raw = trim(req_str('api_extra', ''));
  if ($api_extra_raw === '') $api_extra_raw = '[]';

  $decoded = json_decode($api_extra_raw, true);
  if ($decoded === null && $api_extra_raw !== 'null') {
    flash_set('danger', 'api_extra должен быть валидным JSON');
    header('Location: menu.php?page=networks&edit=' . (int)$id);
    exit;
  }

  db_exec(
    $db,
    "UPDATE crm_networks
        SET title=?, api_base_url=?, api_key=?, api_extra=?
      WHERE id=?",
    [
      $title,
      $api_base_url,
      $api_key,
      json_encode($decoded ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      $id,
    ]
  );

  flash_set('success', 'Сетка обновлена');
  header('Location: menu.php?page=networks&edit=' . (int)$id);
  exit;
}

// --- DELETE NETWORK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('delete_network', false)) {
  $id = req_int('id', 0);
  if ($id > 0) {
    db_exec($db, "DELETE FROM crm_domain_networks WHERE network_id=?", [$id]);
    db_exec($db, "DELETE FROM crm_networks WHERE id=?", [$id]);
    flash_set('success', 'Сетка удалена (и привязки тоже)');
  }
  header('Location: menu.php?page=networks');
  exit;
}

// --- LINK DOMAIN <-> NETWORK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('link_domain', false)) {
  $domain_id  = req_int('domain_id', 0);
  $network_id = req_int('network_id', 0);

  db_exec(
    $db,
    "INSERT INTO crm_domain_networks(domain_id,network_id,external_site_id,token,notes)
     VALUES(?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       external_site_id=VALUES(external_site_id),
       token=VALUES(token),
       notes=VALUES(notes)",
    [
      $domain_id,
      $network_id,
      trim(req_str('external_site_id', '')),
      req_str('token', ''),
      trim(req_str('notes', '')),
    ]
  );

  flash_set('success', 'Привязка домен↔сетка сохранена');
  header('Location: menu.php?page=networks');
  exit;
}

/** =========================
 *  DATA
 *  ========================= */

$nets    = db_all($db, "SELECT * FROM crm_networks ORDER BY id DESC");
$domains = db_all($db, "SELECT id, domain FROM crm_domains ORDER BY domain");
$links   = db_all(
  $db,
  "SELECT dn.*, d.domain, n.code AS network_code
   FROM crm_domain_networks dn
   JOIN crm_domains d ON d.id=dn.domain_id
   JOIN crm_networks n ON n.id=dn.network_id
   ORDER BY d.domain, n.code"
);

$edit_id  = req_int('edit', 0);
$edit_net = null;
if ($edit_id > 0) {
  $rows = db_all($db, "SELECT * FROM crm_networks WHERE id=? LIMIT 1", [$edit_id]);
  $edit_net = $rows[0] ?? null;
}


/** helper: hide key preview */
function key_preview(string $s, int $keep = 4): string {
  $s = trim($s);
  if ($s === '') return '';
  $len = mb_strlen($s, 'UTF-8');
  if ($len <= $keep) return str_repeat('•', $len);
  return str_repeat('•', max(0, $len - $keep)) . mb_substr($s, -$keep, null, 'UTF-8');
}

?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">🧩 Сетки + ключи</h3>
</div>

<div class="row g-3">
  <!-- LEFT -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><b>Добавить сетку</b></div>
      <div class="card-body">
        <form method="post" class="vstack gap-2">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="add_network" value="1">

          <input class="form-control" name="code" placeholder="kadam" required>
          <input class="form-control" name="title" placeholder="Kadam" required>
          <input class="form-control" name="api_base_url" placeholder="https://... (опционально)">
          <textarea class="form-control" name="api_key" placeholder="API key / token (хранится в БД)" rows="3"></textarea>

          <button class="btn btn-primary">Добавить</button>
          <div class="text-muted small">
            Если хочешь безопаснее — можно хранить только “alias”, а ключи держать в env.
            Но для MVP оставил в БД.
          </div>
        </form>
      </div>
    </div>

    <?php if ($edit_net): ?>
      <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between">
          <b>Редактировать: <code><?=h($edit_net['code'])?></code></b>
          <a class="btn btn-sm btn-outline-secondary" href="menu.php?page=networks">Закрыть</a>
        </div>
        <div class="card-body">

          <form method="post" class="vstack gap-2">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="edit_network" value="1">
            <input type="hidden" name="id" value="<?=h($edit_net['id'])?>">

            <label class="form-label small text-muted mb-0">Title</label>
            <input class="form-control" name="title" value="<?=h($edit_net['title'])?>" required>

            <label class="form-label small text-muted mb-0">Base URL</label>
            <input class="form-control" name="api_base_url" value="<?=h($edit_net['api_base_url'])?>">

            <label class="form-label small text-muted mb-0">API key / token</label>
            <textarea class="form-control" name="api_key" rows="4"><?=h((string)$edit_net['api_key'])?></textarea>

            <label class="form-label small text-muted mb-0">api_extra (JSON)</label>
            <textarea class="form-control" name="api_extra" rows="4"><?=h(($edit_net['api_extra'] ?? '') !== '' ? (string)$edit_net['api_extra'] : '[]')?></textarea>

            <button class="btn btn-primary">Сохранить</button>
          </form>

          <form method="post" class="mt-2" onsubmit="return confirm('Точно удалить сетку? Удалятся и привязки.');">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="delete_network" value="1">
            <input type="hidden" name="id" value="<?=h($edit_net['id'])?>">
            <button class="btn btn-outline-danger w-100" type="submit">Удалить сетку</button>
          </form>

          <div class="text-muted small mt-2">
            Совет: позже можно сделать хранение ключей в env/файле и в БД держать только alias.
          </div>

        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- RIGHT -->
  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-header"><b>Список сеток</b></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>ID</th>
              <th>Code</th>
              <th>Title</th>
              <th>Base URL</th>
              <th class="text-muted">Key</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($nets as $n): ?>
              <tr>
                <td><?=h($n['id'])?></td>
                <td><code><?=h($n['code'])?></code></td>
                <td><?=h($n['title'])?></td>
                <td class="text-muted small"><?=h($n['api_base_url'])?></td>
                <td class="text-muted small"><code><?=h(key_preview((string)($n['api_key'] ?? ''), 4))?></code></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary"
                     href="menu.php?page=networks&edit=<?=h($n['id'])?>">
                    Edit
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$nets): ?>
              <tr><td colspan="6" class="text-muted">Пока пусто</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header"><b>Привязать сетку к домену</b></div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="link_domain" value="1">

          <div class="col-md-4">
            <label class="form-label small text-muted">Домен</label>
            <select class="form-select" name="domain_id" required>
              <?php foreach ($domains as $d): ?>
                <option value="<?=h($d['id'])?>"><?=h($d['domain'])?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label small text-muted">Сетка</label>
            <select class="form-select" name="network_id" required>
              <?php foreach ($nets as $n): ?>
                <option value="<?=h($n['id'])?>"><?=h($n['code'])?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label small text-muted">ID сайта</label>
            <input class="form-control" name="external_site_id" placeholder="12345">
          </div>

          <div class="col-md-3">
            <label class="form-label small text-muted">Токен</label>
            <input class="form-control" name="token" placeholder="...">
          </div>

          <div class="col-12">
            <label class="form-label small text-muted">Заметки</label>
            <input class="form-control" name="notes" placeholder="push / inpage / campaignId ...">
          </div>

          <div class="col-12">
            <button class="btn btn-outline-secondary">Сохранить привязку</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><b>Текущие привязки</b></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>Домен</th>
              <th>Сетка</th>
              <th>ID</th>
              <th>Токен</th>
              <th>Заметки</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($links as $l): ?>
              <tr>
                <td><?=h($l['domain'])?></td>
                <td><code><?=h($l['network_code'])?></code></td>
                <td><?=h($l['external_site_id'])?></td>
                <td class="text-muted small"><?=h(mb_strimwidth((string)$l['token'], 0, 50, '…', 'UTF-8'))?></td>
                <td class="text-muted small"><?=h($l['notes'])?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$links): ?>
              <tr><td colspan="5" class="text-muted">Пока пусто</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

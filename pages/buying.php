<?php
declare(strict_types=1);

/**
 * pages/buying.php
 *
 * Закупка трафика:
 * - network (crm_networks) -> много кампаний
 * - домен (crm_domains) -> много кампаний
 * - каждая кампания (campaign_id) привязана к ОДНОМУ домену (domain_id)
 *
 * Хелперы: csrf_check(), csrf_token(), db_connect(), db_exec(), db_all(),
 * req_str(), req_int(), req_bool(), flash_set(), h()
 */

csrf_check();
$db = db_connect();

/** =========================
 *  ACTIONS
 *  ========================= */

// ADD CAMPAIGN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('add_campaign', false)) {
  $network_id  = req_int('network_id', 0);
  $domain_id   = req_int('domain_id', 0);
  $campaign_id = trim(req_str('campaign_id', ''));
  $title       = trim(req_str('title', ''));
  $notes       = trim(req_str('notes', ''));
  $is_enabled  = req_bool('is_enabled', true) ? 1 : 0;

  if ($network_id <= 0 || $domain_id <= 0 || $campaign_id === '') {
    flash_set('danger', 'network_id, domain_id и campaign_id обязательны');
    header('Location: menu.php?page=buying');
    exit;
  }

  db_exec(
    $db,
    "INSERT INTO crm_buy_campaigns(network_id,domain_id,campaign_id,title,notes,is_enabled)
     VALUES(?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       domain_id=VALUES(domain_id),
       title=VALUES(title),
       notes=VALUES(notes),
       is_enabled=VALUES(is_enabled)",
    [$network_id, $domain_id, $campaign_id, $title, $notes, $is_enabled]
  );

  flash_set('success', 'РК сохранена');
  header('Location: menu.php?page=buying');
  exit;
}

// EDIT CAMPAIGN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('edit_campaign', false)) {
  $id          = req_int('id', 0);
  $network_id  = req_int('network_id', 0);
  $domain_id   = req_int('domain_id', 0);
  $campaign_id = trim(req_str('campaign_id', ''));
  $title       = trim(req_str('title', ''));
  $notes       = trim(req_str('notes', ''));
  $is_enabled  = req_bool('is_enabled', true) ? 1 : 0;

  if ($id <= 0 || $network_id <= 0 || $domain_id <= 0 || $campaign_id === '') {
    flash_set('danger', 'bad id/network/domain/campaign');
    header('Location: menu.php?page=buying');
    exit;
  }

  db_exec(
    $db,
    "UPDATE crm_buy_campaigns
        SET network_id=?, domain_id=?, campaign_id=?, title=?, notes=?, is_enabled=?
      WHERE id=?",
    [$network_id, $domain_id, $campaign_id, $title, $notes, $is_enabled, $id]
  );

  flash_set('success', 'РК обновлена');
  header('Location: menu.php?page=buying&edit=' . (int)$id);
  exit;
}

// DELETE CAMPAIGN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('delete_campaign', false)) {
  $id = req_int('id', 0);
  if ($id > 0) {
    db_exec($db, "DELETE FROM crm_buy_campaigns WHERE id=?", [$id]);
    flash_set('success', 'РК удалена');
  }
  header('Location: menu.php?page=buying');
  exit;
}

/** =========================
 *  DATA
 *  ========================= */

$networks = db_all($db, "SELECT id, code, title FROM crm_networks ORDER BY code");
$domains  = db_all($db, "SELECT id, domain FROM crm_domains ORDER BY domain");

$campaigns = db_all(
  $db,
  "SELECT c.*,
          n.code AS network_code,
          d.domain AS domain_name
   FROM crm_buy_campaigns c
   JOIN crm_networks n ON n.id=c.network_id
   JOIN crm_domains d ON d.id=c.domain_id
   ORDER BY c.id DESC"
);

$edit_id = req_int('edit', 0);
$edit_campaign = null;
if ($edit_id > 0) {
  $rows = db_all($db, "SELECT * FROM crm_buy_campaigns WHERE id=? LIMIT 1", [$edit_id]);
  $edit_campaign = $rows[0] ?? null;
}

?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">🛒 Закупка: РК → домен</h3>
</div>

<div class="row g-3">
  <div class="col-lg-4">

    <div class="card">
      <div class="card-header"><b>Добавить РК</b></div>
      <div class="card-body">
        <form method="post" class="vstack gap-2">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="add_campaign" value="1">

          <label class="form-label small text-muted mb-0">Сетка закупки</label>
          <select class="form-select" name="network_id" required>
            <?php foreach ($networks as $n): ?>
              <option value="<?=h($n['id'])?>"><?=h($n['code'])?> — <?=h($n['title'])?></option>
            <?php endforeach; ?>
          </select>

          <label class="form-label small text-muted mb-0">Домен</label>
          <select class="form-select" name="domain_id" required>
            <?php foreach ($domains as $d): ?>
              <option value="<?=h($d['id'])?>"><?=h($d['domain'])?></option>
            <?php endforeach; ?>
          </select>

          <label class="form-label small text-muted mb-0">Campaign ID (внешний)</label>
          <input class="form-control" name="campaign_id" placeholder="42342" required>

          <label class="form-label small text-muted mb-0">Название (опц.)</label>
          <input class="form-control" name="title" placeholder="Kadam / Push / RU">

          <label class="form-label small text-muted mb-0">Заметки</label>
          <textarea class="form-control" name="notes" rows="3" placeholder="geo, формат, комментарии..."></textarea>

          <label class="form-check mt-1">
            <input class="form-check-input" type="checkbox" name="is_enabled" value="1" checked>
            <span class="form-check-label">Включена</span>
          </label>

          <button class="btn btn-primary">Сохранить</button>
          <div class="text-muted small">
            Спенд дальше читаешь по campaign_id и кладёшь в домен через domain_id.
          </div>
        </form>
      </div>
    </div>

    <?php if ($edit_campaign): ?>
      <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between">
          <b>Редактировать РК #<?=h($edit_campaign['id'])?></b>
          <a class="btn btn-sm btn-outline-secondary" href="menu.php?page=buying">Закрыть</a>
        </div>
        <div class="card-body">

          <form method="post" class="vstack gap-2">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="edit_campaign" value="1">
            <input type="hidden" name="id" value="<?=h($edit_campaign['id'])?>">

            <label class="form-label small text-muted mb-0">Сетка закупки</label>
            <select class="form-select" name="network_id" required>
              <?php foreach ($networks as $n): ?>
                <option value="<?=h($n['id'])?>" <?=((int)$n['id']===(int)$edit_campaign['network_id'])?'selected':''?>>
                  <?=h($n['code'])?> — <?=h($n['title'])?>
                </option>
              <?php endforeach; ?>
            </select>

            <label class="form-label small text-muted mb-0">Домен</label>
            <select class="form-select" name="domain_id" required>
              <?php foreach ($domains as $d): ?>
                <option value="<?=h($d['id'])?>" <?=((int)$d['id']===(int)$edit_campaign['domain_id'])?'selected':''?>>
                  <?=h($d['domain'])?>
                </option>
              <?php endforeach; ?>
            </select>

            <label class="form-label small text-muted mb-0">Campaign ID</label>
            <input class="form-control" name="campaign_id" value="<?=h($edit_campaign['campaign_id'])?>" required>

            <label class="form-label small text-muted mb-0">Название</label>
            <input class="form-control" name="title" value="<?=h($edit_campaign['title'])?>">

            <label class="form-label small text-muted mb-0">Заметки</label>
            <textarea class="form-control" name="notes" rows="4"><?=h((string)$edit_campaign['notes'])?></textarea>

            <label class="form-check mt-1">
              <input class="form-check-input" type="checkbox" name="is_enabled" value="1" <?=((int)$edit_campaign['is_enabled']===1)?'checked':''?>>
              <span class="form-check-label">Включена</span>
            </label>

            <button class="btn btn-primary">Сохранить</button>
          </form>

          <form method="post" class="mt-2" onsubmit="return confirm('Удалить РК?');">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="delete_campaign" value="1">
            <input type="hidden" name="id" value="<?=h($edit_campaign['id'])?>">
            <button class="btn btn-outline-danger w-100">Удалить РК</button>
          </form>

        </div>
      </div>
    <?php endif; ?>

  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><b>Список РК</b></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>ID</th>
              <th>Сетка</th>
              <th>Домен</th>
              <th>Campaign ID</th>
              <th>Title</th>
              <th class="text-muted">ON</th>
              <th></th>
              <th>MVP Buy</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($campaigns as $c): ?>
              <tr>
                <td><?=h($c['id'])?></td>
                <td><code><?=h($c['network_code'])?></code></td>
                <td><?=h($c['domain_name'])?></td>
                <td><code><?=h($c['campaign_id'])?></code></td>
                <td><?=h($c['title'])?></td>
                <td class="text-muted"><?=((int)$c['is_enabled']===1)?'✓':'—'?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary" href="menu.php?page=buying&edit=<?=h($c['id'])?>">Edit</a>
                </td>
                <td class="text-end">
                  <a class="btn" href="menu.php?page=mvp_buy_gen&domain_id='.(int)$r['did'].'&campaign_id='.urlencode((string)$r['campaign_id']).'">ALGO</a>
                </td>
               

              </tr>
            <?php endforeach; ?>
            <?php if (!$campaigns): ?>
              <tr><td colspan="7" class="text-muted">Пока пусто</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

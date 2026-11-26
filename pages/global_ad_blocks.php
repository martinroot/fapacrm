<?php
declare(strict_types=1);

/**
 * pages/global_ad_blocks.php
 * Рекламные блоки домена (CRM)
 *
 * CSRF: используем ТОЛЬКО штатный csrf_check()/csrf_token()
 */

csrf_check();
$db = db_connect();

$domain_id = req_int('domain_id', 0);
if ($domain_id <= 0) {
  echo '<div class="alert alert-danger">domain_id обязателен</div>';
  return;
}

$dom = db_one($db, "SELECT * FROM crm_domains WHERE id=?", [$domain_id]);
if (!$dom) {
  echo '<div class="alert alert-danger">Домен не найден</div>';
  return;
}

function redirect_self_adblocks(int $domain_id, string $qs=''): void {
  $url = 'menu.php?page=global_ad_blocks&domain_id='.(int)$domain_id.($qs ? ('&'.$qs) : '');
  header('Location: '.$url);
  exit;
}

$PAGES = ['index','cat','video','popular'];
$SLOTS = ['head','body_top','body_bottom'];
$DEFAULT_TYPE = 'html';

$action = req_str('action', '');
$id     = req_int('id', 0);

$msg_ok = '';
$msg_err = '';

// helper for LIKE escape
function db_esc_like(mysqli $db, string $s): string {
  return $db->real_escape_string($s);
}

// toggle (GET) — оставил как было (быстро). Если хочешь 100% CSRF-safe — сделаем POST.
if ($action === 'toggle' && $id > 0) {
  db_exec($db, "UPDATE crm_domain_ad_blocks SET enabled = IF(enabled=1,0,1) WHERE id=? AND domain_id=?", [$id, $domain_id]);
  redirect_self_adblocks($domain_id, 'msg=toggle_ok');
}

// delete (POST) — csrf_check() уже отработал в начале файла
if ($action === 'delete' && $id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
  db_exec($db, "DELETE FROM crm_domain_ad_blocks WHERE id=? AND domain_id=?", [$id, $domain_id]);
  redirect_self_adblocks($domain_id, 'msg=delete_ok');
}

// save (POST) — csrf_check() уже отработал в начале файла
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $sid  = req_int('id', 0);
  $name = trim(req_str('name', ''));
  $slot = trim(req_str('slot_key', 'body_bottom'));
  $code = (string)($_POST['code'] ?? '');
  $en   = req_int('enabled', 0) ? 1 : 0;

  $pagesSel = $_POST['pages'] ?? [];
  if (!is_array($pagesSel)) $pagesSel = [];
  $pagesSel = array_values(array_unique(array_filter(array_map('strval', $pagesSel))));

  $pagesOk = [];
  foreach ($pagesSel as $p) if (in_array($p, $PAGES, true)) $pagesOk[] = $p;

  if ($name === '') $msg_err = 'Название пустое';
  elseif (trim($code) === '') $msg_err = 'Код пустой';
  elseif (!in_array($slot, $SLOTS, true)) $msg_err = 'Некорректное место (slot)';
  elseif (!$pagesOk) $msg_err = 'Выбери хотя бы одну страницу (галочки)';
  else {
    if ($sid > 0) {
      db_exec($db, "UPDATE crm_domain_ad_blocks SET name=?, slot=?, type=?, code=?, enabled=? WHERE id=? AND domain_id=?",
        [$name, $slot, $DEFAULT_TYPE, $code, $en, $sid, $domain_id]
      );
      db_exec($db, "DELETE FROM crm_domain_ad_block_pages WHERE block_id=?", [$sid]);
      foreach ($pagesOk as $p) {
        db_exec($db, "INSERT IGNORE INTO crm_domain_ad_block_pages(block_id,page_key) VALUES(?,?)", [$sid, $p]);
      }
      redirect_self_adblocks($domain_id, 'msg=save_ok');
    } else {
      db_exec($db, "INSERT INTO crm_domain_ad_blocks(domain_id, name, slot, type, code, enabled) VALUES(?,?,?,?,?,?)",
        [$domain_id, $name, $slot, $DEFAULT_TYPE, $code, $en]
      );
      $newId = (int)$db->insert_id;
      foreach ($pagesOk as $p) {
        db_exec($db, "INSERT IGNORE INTO crm_domain_ad_block_pages(block_id,page_key) VALUES(?,?)", [$newId, $p]);
      }
      redirect_self_adblocks($domain_id, 'msg=save_ok');
    }
  }
}

// flash
$flash = req_str('msg', '');
if ($flash === 'save_ok')   $msg_ok = 'Сохранено ✅';
if ($flash === 'delete_ok') $msg_ok = 'Удалено ✅';
if ($flash === 'toggle_ok') $msg_ok = 'Переключено ✅';

// edit
$edit = null;
$editPages = [];
if ($action === 'edit' && $id > 0) {
  $edit = db_one($db, "SELECT * FROM crm_domain_ad_blocks WHERE id=? AND domain_id=?", [$id, $domain_id]);
  if ($edit) {
    $rows = db_all($db, "SELECT page_key FROM crm_domain_ad_block_pages WHERE block_id=?", [$id]);
    foreach ($rows as $r) $editPages[] = (string)($r['page_key'] ?? '');
    $editPages = array_values(array_unique(array_filter($editPages)));
    sort($editPages);
  }
}
if (!$edit) {
  $edit = ['id'=>0,'name'=>'','slot'=>'body_bottom','code'=>'','enabled'=>1];
  $editPages = ['index'];
}

// list
$q = trim(req_str('q', ''));
$f_slot = trim(req_str('f_slot', ''));

$where = ["b.domain_id=".(int)$domain_id];
if ($q !== '') {
  $qe = db_esc_like($db, $q);
  $where[] = "b.name LIKE '%{$qe}%'";
}
if ($f_slot !== '' && in_array($f_slot, $SLOTS, true)) {
  $se = db_esc_like($db, $f_slot);
  $where[] = "b.slot='{$se}'";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$list = db_all($db, "
  SELECT
    b.id, b.name, b.slot, b.enabled, b.updated_at,
    GROUP_CONCAT(p.page_key ORDER BY p.page_key SEPARATOR ',') AS pages
  FROM crm_domain_ad_blocks b
  LEFT JOIN crm_domain_ad_block_pages p ON p.block_id=b.id
  {$whereSql}
  GROUP BY b.id
  ORDER BY b.enabled DESC, b.slot ASC, b.id DESC
  LIMIT 500
");

$CSRF_MAIN = csrf_token();
?>
<div class="container-fluid">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0">🧱 Рекламные блоки домена</h3>
      <div class="text-muted small">Домен: <b><?=h((string)$dom['domain'])?></b></div>
      <div class="text-muted small">slot: <code>head</code>, <code>body_top</code>, <code>body_bottom</code></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="menu.php?page=domains">← Домены</a>
      <a class="btn btn-outline-secondary btn-sm" href="menu.php?page=domain_counters&domain_id=<?=h((string)$domain_id)?>">📊 Счётчики</a>
      <a class="btn btn-outline-secondary btn-sm" href="menu.php?page=global_ad_blocks&domain_id=<?=h((string)$domain_id)?>">➕ Новый</a>
    </div>
  </div>

  <?php if ($msg_ok): ?>
    <div class="alert alert-success py-2"><?=h($msg_ok)?></div>
  <?php endif; ?>
  <?php if ($msg_err): ?>
    <div class="alert alert-danger py-2" style="white-space:pre-wrap"><?=h($msg_err)?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-12 col-lg-5">
      <div class="card shadow-sm">
        <div class="card-header d-flex align-items-center justify-content-between">
          <b><?= ((int)$edit['id'] > 0) ? 'Редактирование' : 'Новый блок' ?></b>
          <?php if ((int)$edit['id'] > 0): ?>
            <a class="btn btn-outline-secondary btn-sm" href="menu.php?page=global_ad_blocks&domain_id=<?=h((string)$domain_id)?>">➕ Новый</a>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <form method="post" action="menu.php?page=global_ad_blocks&domain_id=<?=h((string)$domain_id)?>&action=save">
            <input type="hidden" name="_csrf" value="<?=h($CSRF_MAIN)?>">
            <input type="hidden" name="id" value="<?=h((string)$edit['id'])?>">

            <div class="mb-2">
              <label class="form-label small mb-1">Название *</label>
              <input class="form-control form-control-sm" name="name" value="<?=h((string)($edit['name'] ?? ''))?>" placeholder="Напр.: Kadam Popunder">
            </div>

            <div class="mb-2">
              <label class="form-label small mb-1">Страницы (галочки) *</label>
              <div class="d-flex flex-wrap gap-3">
                <?php foreach ($PAGES as $p): ?>
                  <?php $checked = in_array($p, $editPages, true) ? 'checked' : ''; ?>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="pg_<?=h($p)?>" name="pages[]" value="<?=h($p)?>" <?=$checked?>>
                    <label class="form-check-label" for="pg_<?=h($p)?>"><?=h($p)?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label small mb-1">Место (slot)</label>
              <select class="form-select form-select-sm" name="slot_key">
                <?php $curSlot = (string)($edit['slot'] ?? 'body_bottom'); ?>
                <?php foreach ($SLOTS as $s): ?>
                  <option value="<?=h($s)?>" <?= ($curSlot === $s) ? 'selected' : '' ?>><?=h($s)?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="enabled" name="enabled" value="1" <?= ((int)($edit['enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="enabled">Включён</label>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label small mb-1">Код (RAW JS/HTML) *</label>
              <textarea class="form-control form-control-sm" name="code" rows="14" placeholder="<script>...</script>"><?=h((string)($edit['code'] ?? ''))?></textarea>
            </div>

            <div class="d-flex gap-2 mt-2">
              <button class="btn btn-primary btn-sm" type="submit">💾 Сохранить</button>
              <?php if ((int)$edit['id'] > 0): ?>
                <a class="btn btn-outline-secondary btn-sm" href="menu.php?page=global_ad_blocks&domain_id=<?=h((string)$domain_id)?>">Отмена</a>
              <?php endif; ?>
            </div>

          </form>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-7">
      <div class="card shadow-sm">
        <div class="card-header d-flex align-items-center justify-content-between">
          <b>Список</b>
          <span class="text-muted small">max 500</span>
        </div>
        <div class="card-body">

          <form class="row g-2 mb-3" method="get" action="menu.php">
            <input type="hidden" name="page" value="global_ad_blocks">
            <input type="hidden" name="domain_id" value="<?=h((string)$domain_id)?>">
            <div class="col-12 col-md-7">
              <input class="form-control form-control-sm" name="q" value="<?=h($q)?>" placeholder="поиск по названию">
            </div>
            <div class="col-6 col-md-3">
              <select class="form-select form-select-sm" name="f_slot">
                <option value="">slot: all</option>
                <?php foreach ($SLOTS as $s): ?>
                  <option value="<?=h($s)?>" <?= ($f_slot === $s) ? 'selected' : '' ?>><?=h($s)?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
              <button class="btn btn-outline-primary btn-sm w-100" type="submit">Поиск</button>
              <a class="btn btn-outline-secondary btn-sm w-100" href="menu.php?page=global_ad_blocks&domain_id=<?=h((string)$domain_id)?>">Сброс</a>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead>
                <tr class="text-muted small">
                  <th style="width:70px">ID</th>
                  <th style="width:70px">ON</th>
                  <th>Название</th>
                  <th style="width:140px">slot</th>
                  <th>pages</th>
                  <th class="text-end" style="width:180px">Обновлено</th>
                  <th style="width:160px"></th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$list): ?>
                  <tr><td colspan="7" class="text-muted">Пусто</td></tr>
                <?php else: ?>
                  <?php foreach ($list as $r): ?>
                    <?php $rid=(int)$r['id']; $on=((int)$r['enabled']===1); ?>
                    <tr>
                      <td class="text-muted"><?=h((string)$rid)?></td>
                      <td>
                        <a class="badge text-decoration-none <?= $on ? 'bg-success' : 'bg-secondary' ?>"
                           href="menu.php?page=global_ad_blocks&domain_id=<?=h((string)$domain_id)?>&action=toggle&id=<?=h((string)$rid)?>"><?= $on ? 'ON' : 'OFF' ?></a>
                      </td>
                      <td><b><?=h((string)$r['name'])?></b></td>
                      <td class="text-muted small"><?=h((string)$r['slot'])?></td>
                      <td class="text-muted small"><?=h((string)($r['pages'] ?? ''))?></td>
                      <td class="text-end text-muted small"><?=h((string)$r['updated_at'])?></td>
                      <td class="text-end">
                        <div class="d-flex gap-2 justify-content-end">
                          <a class="btn btn-outline-primary btn-sm" href="menu.php?page=global_ad_blocks&domain_id=<?=h((string)$domain_id)?>&action=edit&id=<?=h((string)$rid)?>">✏️</a>
                          <form method="post"
                                action="menu.php?page=global_ad_blocks&domain_id=<?=h((string)$domain_id)?>&action=delete&id=<?=h((string)$rid)?>"
                                onsubmit="return confirm('Удалить блок #<?=h((string)$rid)?>?');">
                            <input type="hidden" name="_csrf" value="<?=h($CSRF_MAIN)?>">
                            <button class="btn btn-outline-danger btn-sm" type="submit">🗑️</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </div>

  </div>
</div>

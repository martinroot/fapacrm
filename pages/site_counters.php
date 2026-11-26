<?php
declare(strict_types=1);

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

// текущие значения (если нет строки — покажем дефолты)
$row = db_one($db, "SELECT * FROM crm_domain_counters WHERE domain_id=?", [$domain_id]);
if (!$row) {
  $row = [
    'domain_id' => $domain_id,
    'metrika_enabled' => 0,
    'metrika_id' => 0,
    'ga_enabled' => 0,
    'ga_measurement_id' => '',
  ];
}

// save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $metrika_enabled = req_int('metrika_enabled', 0) ? 1 : 0;
  $metrika_id      = req_int('metrika_id', 0);
  $ga_enabled      = req_int('ga_enabled', 0) ? 1 : 0;
  $ga_mid          = trim(req_str('ga_measurement_id', ''));

  db_exec($db, "
    INSERT INTO crm_domain_counters(domain_id, metrika_enabled, metrika_id, ga_enabled, ga_measurement_id)
    VALUES(?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      metrika_enabled=VALUES(metrika_enabled),
      metrika_id=VALUES(metrika_id),
      ga_enabled=VALUES(ga_enabled),
      ga_measurement_id=VALUES(ga_measurement_id)
  ", [$domain_id, $metrika_enabled, $metrika_id, $ga_enabled, $ga_mid]);

  flash_set('success', 'Сохранено');
  header('Location: menu.php?page=site_counters&domain_id='.(int)$domain_id);
  exit;
}

?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h3 class="m-0">📊 Счётчики домена</h3>
    <div class="text-muted small">Домен: <b><?=h((string)$dom['domain'])?></b></div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="menu.php?page=domains">← Домены</a>
    <a class="btn btn-outline-primary btn-sm" href="menu.php?page=domain_ad_blocks&domain_id=<?=h((string)$domain_id)?>">🧱 Реклама домена</a>
  </div>
</div>

<form method="post" class="card p-3" style="max-width:720px">
  <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">

  <div class="row g-3">
    <div class="col-12">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="metrika_enabled" name="metrika_enabled" value="1"
          <?=((int)$row['metrika_enabled']===1?'checked':'')?>>
        <label class="form-check-label" for="metrika_enabled"><b>Yandex Metrika</b></label>
      </div>
      <label class="form-label small text-muted mt-1">Metrika ID</label>
      <input class="form-control" name="metrika_id" placeholder="12345678" value="<?=h((string)$row['metrika_id'])?>">
      <div class="text-muted small mt-1">Включай только если на домене должен быть этот счётчик.</div>
    </div>

    <div class="col-12"><hr class="my-1"></div>

    <div class="col-12">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="ga_enabled" name="ga_enabled" value="1"
          <?=((int)$row['ga_enabled']===1?'checked':'')?>>
        <label class="form-check-label" for="ga_enabled"><b>Google Analytics (GA4)</b></label>
      </div>
      <label class="form-label small text-muted mt-1">GA Measurement ID</label>
      <input class="form-control" name="ga_measurement_id" placeholder="G-XXXXXXXXXX" value="<?=h((string)$row['ga_measurement_id'])?>">
      <div class="text-muted small mt-1">ID должен быть формата <code>G-...</code></div>
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">💾 Сохранить</button>
      <a class="btn btn-outline-secondary" href="menu.php?page=domains">Отмена</a>
    </div>
  </div>
</form>

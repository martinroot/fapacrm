<?php
declare(strict_types=1);
$db = db_connect();
$cnt_domains = (int)db_scalar($db, "SELECT COUNT(*) FROM crm_domains") ?: 0;
$cnt_nets = (int)db_scalar($db, "SELECT COUNT(*) FROM crm_networks") ?: 0;
$last = db_all($db, "SELECT domain,status,ssl_status,updated_at FROM crm_domains ORDER BY updated_at DESC LIMIT 10");
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">🏠 Дашборд</h3>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="card"><div class="card-body">
      <div class="text-muted small">Домены</div>
      <div class="fs-3 fw-bold"><?=$cnt_domains?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card"><div class="card-body">
      <div class="text-muted small">Сетки</div>
      <div class="fs-3 fw-bold"><?=$cnt_nets?></div>
    </div></div>
  </div>
  <div class="col-md-6">
    <div class="card"><div class="card-body">
      <div class="text-muted small">Эталон</div>
      <div><code><?=h((string)ucfg($GLOBALS['USER_CFG'], 'TEMPLATE.site_path', '/var/www/hotpornmoms.ru'))?></code></div>
      <div class="text-muted small">Выполнение shell: <b><?=BM_ALLOW_SHELL ? 'ON' : 'OFF'?></b></div>
    </div></div>
  </div>
</div>

<div class="card">
  <div class="card-header"><b>Последние изменения</b></div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>Домен</th><th>Статус</th><th>SSL</th><th>upd</th></tr></thead>
      <tbody>
        <?php foreach ($last as $r): ?>
          <tr>
            <td><b><?=h($r['domain'])?></b></td>
            <td><?=h($r['status'])?></td>
            <td><?=h($r['ssl_status'])?></td>
            <td class="text-muted small"><?=h($r['updated_at'])?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$last): ?><tr><td colspan="4" class="text-muted">Пока пусто</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$page = req_str('page', 'dashboard');

$pages = [
  'dashboard'       => __DIR__ . '/pages/dashboard.php',
  'site_deploy'     => __DIR__ . '/pages/site_deploy.php',

  'domains'         => __DIR__ . '/pages/domains.php',
  'networks'        => __DIR__ . '/pages/networks.php',
  'networks_live'   => __DIR__ . '/pages/networks_live.php',
  'templates'       => __DIR__ . '/pages/templates.php',
  'rollout'         => __DIR__ . '/pages/rollout.php',
  'buying'          => __DIR__ . '/pages/buying.php',

  // ✅ new: SSL мастер
  'site_ssl'        => __DIR__ . '/pages/site_ssl.php',

  // ✅ Глобальные настройки шаблона сайта (source of truth)
  'site_counters'   => __DIR__ . '/pages/site_counters.php',
  'global_ad_blocks'=> __DIR__ . '/pages/global_ad_blocks.php',

  'db_manager'      => __DIR__ . '/pages/db_manager.php',
  'cron_jobs'       => __DIR__ . '/pages/cron_jobs.php',
  'finance'         => __DIR__ . '/pages/finance.php',
  'ltv_predict'     => __DIR__ . '/pages/ltv_predict.php',
  'daily'           => __DIR__ . '/pages/daily.php',
  'hourly'          => __DIR__ . '/pages/hourly.php',
  'traffic_live'    => __DIR__ . '/pages/traffic_live.php',
  'revenue'         => __DIR__ . '/pages/revenue.php',
  'geo'             => __DIR__ . '/pages/geo.php',
  'finance_debug'   => __DIR__ . '/pages/finance_debug.php',
  'mvp_buy_gen'   => __DIR__ . '/pages/mvp_buy_gen.php',
  'buy_gen2'   => __DIR__ . '/pages/buy_gen2.php',
  'pub_gen'   => __DIR__ . '/pages/pub_gen.php',

  
];

if (!isset($pages[$page])) $page = 'dashboard';

?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FAPA-CRM</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f8f9fa; }
    .sidebar { min-height:100vh; background:#212529; }
    .sidebar .nav-link { color:#ced4da; border-radius:.5rem; }
    .sidebar .nav-link.active, .sidebar .nav-link:hover { color:#fff; background:#343a40; }
    .brandbox{ margin:.5rem .5rem .75rem .5rem; background:#fff; border-radius:14px; padding:.25rem .25rem;
      box-shadow:0 8px 24px rgba(0,0,0,.18); border:1px solid rgba(255,255,255,.08); }
    .brandrow{ display:flex; align-items:center; justify-content:space-between; gap:.75rem; }
    .brandleft{ display:flex; align-items:center; gap:.6rem; min-width:0; }
    .brand-ico{ font-size:1rem; line-height:1; flex:0 0 auto; }
    .brandtitle{ font-weight:800; font-size:1rem; color:#111; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .nav-group-title { color:#adb5bd; font-size:.75rem; letter-spacing:.02em; padding:.65rem .25rem .25rem .25rem;
      text-transform:uppercase; }
  </style>
</head>
<body>
<div class="container-fluid">
  <div class="row">
    <aside class="col-12 col-md-3 col-lg-2 sidebar p-2">
      <div class="brandbox">
        <div class="brandrow">
          <div class="brandleft">
            <span class="brand-ico" aria-hidden="true">🧠</span>
            <div class="brandtitle">FAPA-CRM</div>
          </div>
        </div>
      </div>

      <nav class="nav flex-column gap-1 px-2 pb-3">
        <?php
          $navGroups = [
            'Главное' => [
              'dashboard'     => '🏠 Дашборд',
              'site_deploy'   => '🚀 Авто-деплой',
              'site_ssl'      => '🔐 SSL мастер',
              'finance'       => '💰 Финансы',
              'daily'         => '📈 По дням',
              'hourly'        => '🕐 По часам',
              'traffic_live'  => '⚡ Траф/мин',
              'revenue'       => '📈 Ревенью лайв',
              'geo'           => '🌍 По гео',
              'finance_debug' => '💰 Финансы (DEB)',
            ],
            'Управление' => [
              'domains'       => '🌐 Домены чеклист',
              'networks'      => '🧩 Сетки монетизации',
              'networks_live' => '📡 Сетки (live)',
              'templates'     => '🎨 Шаблоны',
              'rollout'       => '🚀 Прогресс внедрения',
              'buying'        => '🛒 Закупка трафика',
              'cron_jobs'     => '📊 cron_jobs',
            ],
            'Шаблоны сайта' => [
              'site_counters'    => '📊 Счётчики (GA/Метка)',
              'global_ad_blocks' => '🧱 Рекламные блоки',
              'mvp_buy_gen' => '🧱 Автозакуп AI',
              'buy_gen2' => '🧱 Автозакуп buy_gen2',
              
              'pub_gen'   => '🧱 Генерация БлекПабов',
              
            ],
            'Система' => [
              'db_manager'  => '🗄️ Миграции JSON',
            ],
          ];

          foreach ($navGroups as $gTitle => $items) {
            echo '<div class="nav-group-title px-2">'.h($gTitle).'</div>';
            foreach ($items as $k => $label) {
              $cls = ($k === $page) ? 'nav-link active' : 'nav-link';
              echo '<a class="'.$cls.'" href="menu.php?page='.h($k).'">'.h($label).'</a>';
            }
          }
        ?>
      </nav>
    </aside>

    <main class="col-12 col-md-9 col-lg-10 p-3">
      <?php foreach (flash_get_all() as $f): ?>
        <div class="alert alert-<?=h($f['type'])?>"><?=h($f['msg'])?></div>
      <?php endforeach; ?>

      <?php
        try {
          $file = $pages[$page];
          if (!is_file($file)) {
            echo '<div class="alert alert-warning"><b>Страница в разработке</b><br><code>'.h($file).'</code></div>';
          } else {
            require $file;
          }
        } catch (Throwable $e) {
          echo '<div class="alert alert-danger"><b>ОШИБКА</b><pre style="white-space:pre-wrap;">'
            .h($e->getMessage())."\n".h($e->getTraceAsString()).'</pre></div>';
        }
      ?>
    </main>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

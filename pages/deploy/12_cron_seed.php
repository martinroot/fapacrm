<?php
/** @var array $ctx */
declare(strict_types=1);

/**
 * STEP 12
 * - очистка cron_jobs + cron_log в БД сайта
 * - вставка 4 пресетов cron
 * - установка системного crontab (* * * * *)
 * - reload/restart cron service
 */

$domainId = (int)($ctx['domain_id'] ?? 0);
$domain   = trim((string)($ctx['domain'] ?? ''));
$path     = rtrim((string)($ctx['path'] ?? ''), '/');
$dbName   = trim((string)($ctx['dbName'] ?? ''));

if ($domainId <= 0 || $domain === '' || $path === '' || $dbName === '') {
  return ['rc'=>1,'out'=>'cron_seed: missing domain_id / domain / path / dbName'];
}

// ------------------------------------------------------------
// DB CONNECT (CRM + SITE)
// ------------------------------------------------------------
$crm = db_connect();

/**
 * берём MySQL доступы из CRM-конфига
 * USER_CFG['DB'] => host/user/pass
 */
$cfg  = $GLOBALS['USER_CFG'] ?? [];
$host = (string)ucfg($cfg, 'DB.host', 'localhost');
$user = (string)ucfg($cfg, 'DB.user', 'root');
$pass = (string)ucfg($cfg, 'DB.pass', '');

$site = new mysqli($host, $user, $pass, $dbName);
if ($site->connect_error) {
  return [
    'rc'=>2,
    'out'=>'Site DB connect error: '.$site->connect_error.
           " (host={$host} user={$user} db={$dbName})"
  ];
}
$site->set_charset('utf8mb4');

$log = [];
$log[] = "cron_seed: domain_id={$domainId}";
$log[] = "db={$dbName} user={$user}@{$host}";
$log[] = "path={$path}";

// ------------------------------------------------------------
// HELPERS
// ------------------------------------------------------------
$site_table_exists = function(mysqli $db, string $table): bool {
  $t = $db->real_escape_string($table);
  $r = $db->query("SHOW TABLES LIKE '{$t}'");
  $ok = ($r && $r->num_rows > 0);
  if ($r) $r->free();
  return $ok;
};

$read_cfg_array = function(string $file): array {
  if (!is_file($file)) return [];
  try {
    $v = require $file; // config.user.php -> return [...]
    return is_array($v) ? $v : [];
  } catch (Throwable $e) {
    return [];
  }
};

$add_if = function(string $url, string $k, string $v): string {
  $v = trim((string)$v);
  if ($v === '') return $url;
  return $url . (strpos($url,'?')!==false?'&':'?')
       . rawurlencode($k).'='.rawurlencode($v);
};

// ------------------------------------------------------------
// CHECK TABLES
// ------------------------------------------------------------
if (
  !$site_table_exists($site, 'cron_jobs') ||
  !$site_table_exists($site, 'cron_log')
) {
  $site->close();
  return [
    'rc'=>3,
    'out'=>'cron_seed: cron tables not found. Apply 090_cron.sql first.'
  ];
}

// ------------------------------------------------------------
// READ SITE CONFIG (SITE_URL / CRON_KEY / CRON_TOKEN)
// ------------------------------------------------------------
$siteCfg = $read_cfg_array($path.'/cmd/config.user.php');

$siteUrl = '';
if (isset($siteCfg['SITE_URL'])) $siteUrl = (string)$siteCfg['SITE_URL'];
if ($siteUrl === '' && isset($siteCfg['CFG']['SITE_URL']))
  $siteUrl = (string)$siteCfg['CFG']['SITE_URL'];
if ($siteUrl === '') $siteUrl = 'https://'.$domain;
$siteUrl = rtrim($siteUrl,'/');

$cronKey = '';
if (isset($siteCfg['CRON_KEY'])) $cronKey = (string)$siteCfg['CRON_KEY'];
if ($cronKey === '' && isset($siteCfg['CFG']['CRON_KEY']))
  $cronKey = (string)$siteCfg['CFG']['CRON_KEY'];

$cronToken = '';
if (isset($siteCfg['CRON_TOKEN'])) $cronToken = (string)$siteCfg['CRON_TOKEN'];
if ($cronToken === '' && isset($siteCfg['CFG']['CRON_TOKEN']))
  $cronToken = (string)$siteCfg['CFG']['CRON_TOKEN'];

$log[] = "Base URL: {$siteUrl}";
$log[] = "CRON_KEY: ".($cronKey!==''?'••••'.substr($cronKey,-4):'not set');
$log[] = "CRON_TOKEN: ".($cronToken!==''?'••••'.substr($cronToken,-4):'not set');

// ------------------------------------------------------------
// CLEAR SITE CRON TABLES
// ------------------------------------------------------------
if (!$site->query("DELETE FROM cron_log")) {
  $e = $site->error;
  $site->close();
  return ['rc'=>4,'out'=>'DELETE cron_log failed: '.$e];
}
if (!$site->query("DELETE FROM cron_jobs")) {
  $e = $site->error;
  $site->close();
  return ['rc'=>5,'out'=>'DELETE cron_jobs failed: '.$e];
}
$log[] = "cron tables cleared";

// ------------------------------------------------------------
// INSERT PRESETS (4)
// ------------------------------------------------------------
$jobs = [];

// 1) Publishers BL -> Kadam
$u = $siteUrl."/cmd/menu.php?page=publishers&cron=1&action=send_kadam";
$u = $add_if($u,'key',$cronKey);
$jobs[] = ['title'=>'Publishers BL -> Kadam','url'=>$u,'min'=>60,'tmo'=>60];

// 2) Kadam GEO engine
$u = $siteUrl."/cmd/menu.php?page=campaign_851694&cron=1&apply=1&sync=1";
$u = $add_if($u,'token',$cronToken);
$jobs[] = ['title'=>'Kadam GEO engine (851694)','url'=>$u,'min'=>5,'tmo'=>60];

// 3) Finance daily
$u = $siteUrl."/cmd/menu.php?page=finance_daily&cron=1&action=update_range&days=14";
$u = $add_if($u,'key',$cronKey);
$jobs[] = ['title'=>'Finance daily: last 14 days','url'=>$u,'min'=>60,'tmo'=>120];

// 4) Yandex Metrika
$jobs[] = [
  'title'=>'Yandex Metrika cache refresh (7d)',
  'url'=>$siteUrl."/cmd/menu.php?page=yandex_metrika&days=7&no_robots=1&refresh=1",
  'min'=>180,
  'tmo'=>60
];

$stmt = $site->prepare("
  INSERT INTO cron_jobs
  (title,url,method,timeout_sec,interval_sec,is_enabled,next_run_at)
  VALUES (?,?, 'GET', ?, ?, 1, NOW())
");
if (!$stmt) {
  $site->close();
  return ['rc'=>6,'out'=>'Prepare insert failed: '.$site->error];
}

$created = 0;
foreach ($jobs as $j) {
  $iv = max(60, (int)$j['min']*60);
  $tm = max(5,  (int)$j['tmo']);
  $stmt->bind_param("ssii", $j['title'], $j['url'], $tm, $iv);
  if (!$stmt->execute()) {
    $e = $stmt->error;
    $stmt->close();
    $site->close();
    return ['rc'=>7,'out'=>'Insert failed: '.$e];
  }
  $created++;
}
$stmt->close();

$log[] = "Inserted presets: {$created}/4";

// ------------------------------------------------------------
// SYSTEM CRONTAB SETUP
// ------------------------------------------------------------
$log[] = "Setting system crontab";

$cronLine = "* * * * * php {$path}/cmd/cron.php >/dev/null 2>&1";
$cronTag  = "# FAPA-CRON: {$domain}";
$cronCmd  = $cronLine." ".$cronTag;

$cur = shell_exec('crontab -l 2>/dev/null');
$cur = is_string($cur) ? $cur : '';

if (strpos($cur, $cronTag) === false) {
  $new = rtrim($cur)."\n".$cronCmd."\n";
  $tmp = '/tmp/cron_'.preg_replace('~[^a-z0-9]+~i','_',$domain).'.txt';

  if (@file_put_contents($tmp, $new) === false) {
    $site->close();
    return ['rc'=>20,'out'=>'Failed to write temp crontab'];
  }

  shell_exec('crontab '.escapeshellarg($tmp));
  @unlink($tmp);

  $log[] = "Crontab entry added";
} else {
  $log[] = "Crontab already exists";
}

// ------------------------------------------------------------
// CRON SERVICE RELOAD / RESTART
// ------------------------------------------------------------
$log[] = "Reloading cron service";

shell_exec('systemctl reload cron 2>/dev/null');
shell_exec('systemctl restart cron 2>/dev/null');
shell_exec('service cron restart 2>/dev/null');

$log[] = "Cron service reloaded";

$site->close();
return ['rc'=>0,'out'=>implode("\n", $log)];

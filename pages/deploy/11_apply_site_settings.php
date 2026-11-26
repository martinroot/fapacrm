<?php
/** @var array $ctx */
declare(strict_types=1);

$domainId = (int)($ctx['domain_id'] ?? 0);
$dbName   = trim((string)($ctx['dbName'] ?? ''));

if ($domainId <= 0 || $dbName === '') {
  return ['rc'=>1,'out'=>'apply_site_settings: missing domain_id or dbName'];
}

$crm = db_connect();

/**
 * ✅ берём MySQL доступы из обычного CRM-конфига (pma)
 * USER_CFG['DB'] => host/user/pass
 */
$cfg  = $GLOBALS['USER_CFG'] ?? [];
$host = (string)ucfg($cfg, 'DB.host', 'localhost');
$user = (string)ucfg($cfg, 'DB.user', 'root');
$pass = (string)ucfg($cfg, 'DB.pass', '');

$site = new mysqli($host, $user, $pass, $dbName);
if ($site->connect_error) {
  return ['rc'=>2,'out'=>'Site DB connect error: '.$site->connect_error.' (host='.$host.' user='.$user.' db='.$dbName.')'];
}
$site->set_charset('utf8mb4');

$log = [];
$log[] = "apply_site_settings: domain_id={$domainId} db={$dbName} using {$user}@{$host}";

// =====================
// COUNTERS (per-domain)
// =====================
if (!$site->query("DELETE FROM site_counters")) {
  $e = (string)$site->error;
  $site->close();
  return ['rc'=>3,'out'=>'Site SQL failed: DELETE FROM site_counters: '.$e];
}

$c = db_one($crm, "SELECT * FROM crm_domain_counters WHERE domain_id=?", [$domainId]);
if ($c) {
  $metrika_enabled = (int)($c['metrika_enabled'] ?? 0);
  $metrika_id      = (int)($c['metrika_id'] ?? 0);
  $ga_enabled      = (int)($c['ga_enabled'] ?? 0);
  $ga_mid          = (string)($c['ga_measurement_id'] ?? '');

  $stmt = $site->prepare("
    INSERT INTO site_counters
    (id, metrika_enabled, metrika_id, ga_enabled, ga_measurement_id)
    VALUES (1,?,?,?,?)
  ");
  if (!$stmt) {
    $e = (string)$site->error;
    $site->close();
    return ['rc'=>4,'out'=>'Prepare failed (site_counters): '.$e];
  }

  $stmt->bind_param("iiis", $metrika_enabled, $metrika_id, $ga_enabled, $ga_mid);
  if (!$stmt->execute()) {
    $e = (string)$stmt->error;
    $stmt->close();
    $site->close();
    return ['rc'=>5,'out'=>'Execute failed (site_counters): '.$e];
  }
  $stmt->close();
  $log[] = "Counters applied";
} else {
  $log[] = "No crm_domain_counters -> skipped";
}

// =====================
// ADS (DOMAIN ONLY)
// берем только то, что у тебя в pages/global_ad_blocks.php
// =====================

// чистим сайт
if (!$site->query("DELETE FROM ad_block_pages")) {
  $e = (string)$site->error;
  $site->close();
  return ['rc'=>6,'out'=>'Site SQL failed: DELETE FROM ad_block_pages: '.$e];
}
if (!$site->query("DELETE FROM ad_blocks")) {
  $e = (string)$site->error;
  $site->close();
  return ['rc'=>7,'out'=>'Site SQL failed: DELETE FROM ad_blocks: '.$e];
}

// если таблиц в CRM нет (вдруг), не падаем
// но ты говорил “всё есть”, так что это чисто защита
$blocks = [];
try {
  $blocks = db_all(
    $crm,
    "SELECT id, name, slot, type, code, enabled
     FROM crm_domain_ad_blocks
     WHERE domain_id=? AND enabled=1
     ORDER BY id",
    [$domainId]
  );
} catch (Throwable $e) {
  $site->close();
  return ['rc'=>8,'out'=>'CRM query failed (crm_domain_ad_blocks): '.$e->getMessage()];
}

if (!$blocks) {
  $log[] = "No domain ad blocks -> ads skipped";
  $site->close();
  return ['rc'=>0,'out'=>implode("\n", $log)];
}

// вставляем блоки в сайт, строим map старый->новый
$idMap = [];
$insBlocks = 0;

foreach ($blocks as $b) {
  $oldId = (int)($b['id'] ?? 0);
  $name  = (string)($b['name'] ?? '');
  $slot  = (string)($b['slot'] ?? '');
  $type  = (string)($b['type'] ?? 'html');
  $code  = (string)($b['code'] ?? '');

  if ($oldId <= 0 || $name === '' || $slot === '') continue;

  $stmt = $site->prepare("INSERT INTO ad_blocks (name, slot, type, code, enabled) VALUES (?,?,?,?,1)");
  if (!$stmt) {
    $e = (string)$site->error;
    $site->close();
    return ['rc'=>9,'out'=>'Prepare failed (site ad_blocks): '.$e];
  }

  $stmt->bind_param("ssss", $name, $slot, $type, $code);
  if (!$stmt->execute()) {
    $e = (string)$stmt->error;
    $stmt->close();
    $site->close();
    return ['rc'=>10,'out'=>'Execute failed (site ad_blocks): '.$e];
  }

  $newId = (int)$site->insert_id;
  $stmt->close();

  $idMap[$oldId] = $newId;
  $insBlocks++;
}

$log[] = "Inserted blocks: ".$insBlocks;

// страницы блоков (ТОЛЬКО crm_domain_ad_block_pages)
$pages = [];
try {
  $pages = db_all(
    $crm,
    "SELECT block_id, page_key
     FROM crm_domain_ad_block_pages
     WHERE block_id IN (
       SELECT id FROM crm_domain_ad_blocks WHERE domain_id=? AND enabled=1
     )",
    [$domainId]
  );
} catch (Throwable $e) {
  // если таблицы нет/ошибка — просто пропустим привязки страниц
  $pages = [];
  $log[] = "CRM pages query failed -> pages linking skipped: ".$e->getMessage();
}

$insPages = 0;
foreach ($pages as $p) {
  $oldBlockId = (int)($p['block_id'] ?? 0);
  if ($oldBlockId <= 0 || !isset($idMap[$oldBlockId])) continue;

  $pageKey = (string)($p['page_key'] ?? '');
  if ($pageKey === '') continue;

  $bid = (int)$idMap[$oldBlockId];
  $pk  = $site->real_escape_string($pageKey);

  if (!$site->query("INSERT IGNORE INTO ad_block_pages(block_id,page_key) VALUES ({$bid},'{$pk}')")) {
    $e = (string)$site->error;
    $site->close();
    return ['rc'=>11,'out'=>'Insert failed (site ad_block_pages): '.$e];
  }
  $insPages++;
}

$log[] = "Inserted page links: ".$insPages;

$site->close();
return ['rc'=>0,'out'=>implode("\n", $log)];

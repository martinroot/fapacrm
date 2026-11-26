<?php
declare(strict_types=1);
/* Извлечено из site_deploy.php, чтобы CLI-деплой вёл себя ровно так же,
   как кнопка AUTO в интерфейсе. Не редактировать вручную. */

function cli_build_ctx(array $dom, int $domain_id, int $run_id, string $templatePath, string $templateDb, string $siteMigrationsDir): array {
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

function cli_normalize_domain_path_db(mysqli $db, array $dom, int $domain_id): void {
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


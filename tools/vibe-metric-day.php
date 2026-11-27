<?php
declare(strict_types=1);
/**
 * VibeMetrics daily rollup -> fapacrm.crm_finance_daily (kind='metric').
 * Один запрос API за день по всем доменам; idempotent upsert
 * (UNIQUE d+domain_id+kind+connector+external_id) — перезапуск догоняет лаг витрины.
 * Дни: вчера и позавчера (UTC). Секреты не печатает. Лог: append в /root/vibe-metric-day.log.
 */
$key = trim((string)@file_get_contents('/root/.vm_agent_key'));
if ($key === '') { fwrite(STDERR, "no api key\n"); exit(1); }

$sites = [];
foreach (file('/root/vm_sites.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
  $j = json_decode($line, true);
  if (is_array($j) && !empty($j['domain'])) $sites[] = strtolower($j['domain']);
}
$sites = array_values(array_unique($sites));
if (!$sites) { fwrite(STDERR, "no sites\n"); exit(1); }

// domain_id map из crm_domains (через mysql CLI, creds из root-конфига)
$map = [];
$out = shell_exec("mysql --protocol=socket -N -e 'SELECT domain, id FROM fapacrm.crm_domains' 2>/dev/null");
foreach (explode("\n", (string)$out) as $l) {
  $p = explode("\t", trim($l));
  if (count($p) === 2) $map[strtolower(trim($p[0]))] = (int)$p[1];
}
if (!$map) { fwrite(STDERR, "no domain map\n"); exit(1); }

// Дни: по умолчанию вчера и позавчера (UTC); явные дни можно передать
// аргументами для backfill: php /root/vibe-metric-day.php 2026-11-20 2026-11-27
$days = [];
foreach (array_slice($argv, 1) as $arg) {
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) $days[] = $arg;
}
if (!$days) $days = [gmdate('Y-m-d', time() - 86400), gmdate('Y-m-d', time() - 2 * 86400)];
$days = array_values(array_unique($days));
$sqlAll = '';
$total = 0;
foreach ($days as $day) {
  // ВАЖНО: `to` в API VibeMetrics исключителен: from=day&to=day даёт нули.
  $to = gmdate('Y-m-d', strtotime($day . ' +1 day'));
  $url = 'https://vibemetrics.click/api/agent/stats?' . http_build_query([
    'domains' => implode(',', $sites), 'from' => $day, 'to' => $to,
  ]);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json', 'User-Agent: FapaCRM/1.0'],
  ]);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code !== 200 || !is_string($body)) { echo date('c'), " HTTP $code for $day\n"; continue; }
  $j = json_decode($body, true);
  if (!isset($j['stats']) || !is_array($j['stats'])) { echo date('c'), " bad payload for $day\n"; continue; }
  $n = 0;
  foreach ($j['stats'] as $x) {
    $dom = strtolower((string)($x['domain'] ?? ''));
    if (!isset($map[$dom])) continue; // pornfluxx.ru не в crm_domains — его учёт отдельно
    $meta = json_encode([
      'visits' => (int)($x['sessions'] ?? 0), 'pageviews' => (int)($x['pageviews'] ?? 0),
      'avg_time' => (int)($x['avg_time_seconds'] ?? 0), 'depth' => (int)($x['avg_scroll_depth'] ?? 0),
      'visitors' => (int)($x['visitors'] ?? 0),
      'human' => (int)($x['human_visitors'] ?? 0), 'bot' => (int)($x['bot_visitors'] ?? 0),
      'unknown' => (int)($x['unknown_visitors'] ?? 0), 'conversions' => (int)($x['conversions'] ?? 0),
      'source' => 'vibemetrics',
    ], JSON_UNESCAPED_UNICODE);
    $row = [
      'd' => $day, 'domain_id' => $map[$dom], 'ext' => 'vm:' . $dom,
      'amount' => (int)($x['sessions'] ?? 0), 'meta' => $meta,
    ];
    $sqlAll .= sprintf(
      "INSERT INTO fapacrm.crm_finance_daily(d,domain_id,kind,connector,external_id,amount,meta_json) VALUES('%s',%d,'metric','vibemetrics_today','%s',%d,'%s') ON DUPLICATE KEY UPDATE amount=VALUES(amount), meta_json=VALUES(meta_json);\n",
      $row['d'], $row['domain_id'], $row['ext'], $row['amount'], addslashes($row['meta'])
    );
    $n++;
  }
  echo date('c'), " $day: prepared $n rows\n";
  $total += $n;
}
if ($sqlAll !== '') {
  file_put_contents('/tmp/vibe-day.sql', $sqlAll);
  $r = shell_exec('mysql --protocol=socket < /tmp/vibe-day.sql 2>&1');
  if ($r !== null && trim($r) !== '') { echo "SQL ERR: $r\n"; exit(1); }
  unlink('/tmp/vibe-day.sql');
}
echo date('c'), " DONE total=$total\n";

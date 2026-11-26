<?php
declare(strict_types=1);
// Read-only: prints MyBid per-tag stats for one date. Never prints the token.
$db = new mysqli('localhost', 'root', '', 'fapacrm');
if ($db->connect_errno) { fwrite(STDERR, "CRM connect failed\n"); exit(1); }
$res = $db->query("SELECT params_json FROM crm_cron_jobs WHERE connector='mybid_revenue_tag' AND is_enabled=1 ORDER BY updated_at DESC LIMIT 1");
$row = $res ? $res->fetch_assoc() : null;
$cfg = json_decode($row['params_json'] ?? '{}', true);
$token = (string)($cfg['token'] ?? '');
$base  = (string)($cfg['stats_url'] ?? 'https://publishers.mybid.io/backend/api/public/stats');
$date  = $argv[1] ?? gmdate('Y-m-d');
if ($token === '') { fwrite(STDERR, "no token\n"); exit(1); }
$q = http_build_query(['token'=>$token,'date1'=>$date,'date2'=>$date,
  'fields'=>'tag_name,impressions,clicks,money','orderBy'=>'-money','limit'=>1000,'offset'=>0]);
$ch = curl_init($base . '?' . $q);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_HTTPHEADER=>['Accept: application/json']]);
$body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($code !== 200) { fwrite(STDERR, "HTTP $code\n"); exit(1); }
$j = json_decode((string)$body, true);
$rows = isset($j['data']) && is_array($j['data']) ? $j['data'] : (array)$j;
foreach ($rows as $r) {
  if (stripos((string)($r['tag_name'] ?? ''), 'babe') === false) continue;
  printf("%s\timpr=%s\tclicks=%s\tmoney=%s\n", $r['tag_name'], $r['impressions'] ?? 0, $r['clicks'] ?? 0, $r['money'] ?? 0);
}

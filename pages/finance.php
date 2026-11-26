<?php
declare(strict_types=1);

/**
 * pages/finance.php
 *
 * Dashboard (3 glass blocks) + full table.
 *
 * KPI = SNAPSHOT "NOW" from crm_finance_ticks (last accepted values, NOT cumulative).
 * Charts = 10-minute buckets (600s), SNAPSHOT values (NOT cumulative): last tick in bucket, summed across domains.
 *
 * AUTO STOP/WORK (by domain daily PROFIT):
 *  profit(domain) = revenue_daily(domain) - cost_daily(domain)
 *  - if WORK and profit <= -0.10 => STOP (pause Kadam campaigns)
 *  - if STOP and profit >  0.00 => WORK (reset/apply on site + activate Kadam campaigns)
 *
 * DEBUG:
 *  - ?dbg=1 shows debug block
 *  - ?force_pause=1 re-apply Kadam pause for STOP domains (even without transition)
 *  - ?force_act=1   re-apply Kadam activate for WORK domains (even without transition)
 *  - ?cid=878062 (manual override single campaign id for force_pause/force_act)  (for fast debug like your curl)
 *
 * Kadam API (YOUR CURL):
 *  POST https://partners.kadam.net/api/v1/campaigns/pause
 *  POST https://partners.kadam.net/api/v1/campaigns/activate
 *
 * Requires CRM helpers:
 *   csrf_check(), db_connect(), db_all(), req_str(), req_int(), h()
 */

csrf_check();
$db = db_connect();

$day       = req_str('d', date('Y-m-d'));
$domain_id = req_int('domain_id', 0);

$DBG_FIN     = (req_int('dbg', 0) === 1);
$FORCE_PAUSE = (req_int('force_pause', 0) === 1);
$FORCE_ACT   = (req_int('force_act', 0) === 1);
$MANUAL_CID  = req_int('cid', 0); // manual campaign id override (like your curl)

// --- Telegram ---
$tgToken = '356049721:AAFV49yK6ev-mPePw8pQRNKCBU8U3Ym_mgg';
$tgChat  = '-1003677160688';

// Optional hard override if you want (kept empty; bearer is usually in cron job params_json)
$KADAM_BEARER_OVERRIDE = ''; // e.g. 'b706871c0869c9b42cb159ac90cec69f'

function money_fmt($x, int $dec=6): string { return number_format((float)$x, $dec, '.', ' '); }
function roi_fmt($x): string { return number_format((float)$x, 2, '.', ' '); }

function roi_bg_style(float $roi): string {
  if ($roi < -100.0) $roi = -100.0;
  if ($roi >  100.0) $roi =  100.0;
  $norm = ($roi + 100.0) / 200.0;

  if ($norm < 0.5) {
    $t = $norm / 0.5;
    $r = 255 * (1 - $t) + 245 * $t;
    $g = 200 * (1 - $t) + 245 * $t;
    $b = 200 * (1 - $t) + 220 * $t;
  } else {
    $t = ($norm - 0.5) / 0.5;
    $r = 245 * (1 - $t) + 200 * $t;
    $g = 245 * (1 - $t) + 255 * $t;
    $b = 220 * (1 - $t) + 200 * $t;
  }

  $r = max(0, min(255, (int)round($r)));
  $g = max(0, min(255, (int)round($g)));
  $b = max(0, min(255, (int)round($b)));
  $hex = sprintf('#%02X%02X%02X', $r, $g, $b);
  return "background: {$hex};";
}
function fail_bg_style(): string { return "background:#fff0f0;border-left:4px solid #d33;"; }
function warn_bg_style(): string { return "background:#fff8e1;border-left:4px solid #c90;"; }

function connector_label(string $connector): string {
  $c = strtolower(trim($connector));
  if ($c === '') return '—';
  if (strpos($c, 'kadam_publisher') !== false) return 'kadam_pub';
  if (strpos($c, 'kadam') !== false) return 'kadam';
  if (strpos($c, 'clickadu') !== false) return 'clickadu';
  if (strpos($c, 'mybid') !== false) return 'mybid';
  if (strpos($c, 'tacoloco') !== false) return 'tacoloco';
  if (strpos($c, 'metrika') !== false) return 'metrika';
  return $connector;
}

/* ---------------- tiny DB exec helper (if CRM doesn't provide) ---------------- */
if (!function_exists('db_exec')) {
  function db_exec(mysqli $db, string $sql, array $params=[]): bool {
    $st = $db->prepare($sql);
    if (!$st) return false;

    if ($params) {
      $types = '';
      $bind = [];
      foreach ($params as $p) {
        if (is_int($p)) $types .= 'i';
        elseif (is_float($p)) $types .= 'd';
        else $types .= 's';
        $bind[] = $p;
      }
      $st->bind_param($types, ...$bind);
    }
    $ok = $st->execute();
    $st->close();
    return (bool)$ok;
  }
}

/* ---------------- log helper ---------------- */
function log_action(array &$log, array &$raw, string $msg, array $ctx=[]): void {
  $log[] = $msg;
  if ($ctx) $raw[] = ['msg'=>$msg] + $ctx;
}

/* ---------------- HTTP helpers ---------------- */
function http_get_url(string $url, int $timeout=12): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT => 'FapaCRM/1.0',
  ]);
  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ['code'=>$code,'err'=>$err ?: null,'body'=>$body ?: '','url'=>$url];
}

/* ---------------- Telegram ---------------- */
function tg_send(string $token, string $chatId, string $text): array {
  $token = trim($token);
  $chatId = trim($chatId);
  if ($token === '' || $chatId === '') return ['ok'=>false,'skip'=>true,'why'=>'no token/chat'];

  $url = 'https://api.telegram.org/bot'.$token.'/sendMessage';
  $payload = [
    'chat_id' => $chatId,
    'text' => $text,
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => true,
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
  ]);

  $raw  = curl_exec($ch);
  $err  = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $j = json_decode((string)$raw, true);
  $ok = ($http === 200) && (bool)($j['ok'] ?? false);

  return ['ok'=>$ok,'http'=>$http,'err'=>$err ?: null,'raw'=>$raw ?: '', 'json'=>$j, 'url'=>$url];
}
function tg_m(float $x, int $dec=6): string { return number_format($x, $dec, '.', ' '); }

/* ---------------- Kadam API (partners v1) ---------------- */
function kadam_post(string $endpoint, array $payload, string $bearer, int $timeout=15): array {
  $base = 'https://partners.kadam.net/api/v1';
  $url  = $base . $endpoint;

  $headers = [
    'accept: application/json',
    'Content-Type: application/json',
  ];

  $bearer = trim($bearer);
  if ($bearer !== '') $headers[] = 'Authorization: Bearer ' . $bearer;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_CONNECTTIMEOUT => 7,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
  ]);

  $raw  = curl_exec($ch);
  $err  = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return [
    'http'=>$http,
    'err'=>$err ?: null,
    'raw'=>$raw ?: '',
    'json'=>json_decode((string)$raw, true),
    'url'=>$url,
    'payload'=>$payload,
    'headers'=>$headers,
  ];
}

function kadam_pause_campaigns(string $bearer, array $campaignIds): array {
  $campaignIds = array_values(array_unique(array_filter(array_map('intval', $campaignIds), fn($x)=>$x>0)));
  if (!$campaignIds) return ['ok'=>false,'skip'=>true,'why'=>'no campaignIds'];

  $res = kadam_post('/campaigns/pause', ['campaignIds'=>$campaignIds], $bearer);
  $success = (bool)($res['json']['success'] ?? false);
  $ok = ($res['http'] === 200) && $success;

  return ['ok'=>$ok,'success'=>$success,'res'=>$res,'campaignIds'=>$campaignIds];
}

function kadam_activate_campaigns(string $bearer, array $campaignIds): array {
  $campaignIds = array_values(array_unique(array_filter(array_map('intval', $campaignIds), fn($x)=>$x>0)));
  if (!$campaignIds) return ['ok'=>false,'skip'=>true,'why'=>'no campaignIds'];

  $res = kadam_post('/campaigns/activate', ['campaignIds'=>$campaignIds], $bearer);
  $success = (bool)($res['json']['success'] ?? false);
  $ok = ($res['http'] === 200) && $success;

  return ['ok'=>$ok,'success'=>$success,'res'=>$res,'campaignIds'=>$campaignIds];
}

/* ---------------- CSS ---------------- */
echo '<style>
:root{--fg:#111;--mut:#6b7280;--bd:rgba(0,0,0,.08)}
table{border-collapse:collapse;width:100%;max-width:1600px;font-family:Segoe UI,Arial,sans-serif;font-size:13px}
th,td{border:1px solid #ddd;padding:4px 6px;text-align:right;font-variant-numeric:tabular-nums}
th{background:#f5f5f5;text-align:center}
td.name{text-align:left}
td.center{text-align:center}
.small-muted{color:#777;font-size:12px}
.badge{display:inline-block;padding:1px 6px;border-radius:10px;font-size:11px;line-height:1.2;border:1px solid transparent;min-width:36px;text-align:center}
.badge-ok{background:#eef7ee;border-color:#c8e6c9;color:#256029;}
.badge-bad{background:#fdecea;border-color:#f5c6cb;color:#721c24;}
a.btn{display:inline-block;padding:2px 6px;border-radius:6px;border:1px solid #888;font-size:11px;text-decoration:none;color:#004;background:#f0f3ff;}
a.btn:hover{background:#dde4ff;}
th.compact,td.compact{padding:1px 3px;font-size:11px;white-space:nowrap;text-align:center}
code{font-size:12px}

.wrap{max-width:1600px}
.dashTop{display:grid;grid-template-columns:1.1fr 1fr 1fr;gap:10px;align-items:stretch;margin:10px 0 14px 0}
.glass{
  border:1px solid rgba(255,255,255,.35);
  background:linear-gradient(180deg, rgba(255,255,255,.72), rgba(255,255,255,.48));
  box-shadow:0 10px 30px rgba(0,0,0,.08);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-radius:16px;
  padding:14px 14px;
  overflow:hidden;
}
.glass h4{margin:0 0 10px 0;font-size:14px;font-weight:900;color:var(--fg);display:flex;gap:8px;align-items:center}
.kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.kpi{border:1px solid var(--bd);border-radius:14px;padding:10px 10px;background:rgba(255,255,255,.36)}
.kpi .k{font-size:12px;color:var(--mut);line-height:1}
.kpi .v{margin-top:5px;font-size:16px;font-weight:900;color:var(--fg);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
canvas{width:100% !important;}
.canvasSmall{height:160px !important;}
@media (max-width:1200px){
  .dashTop{grid-template-columns:1fr;}
  .kpis{grid-template-columns:repeat(2,minmax(0,1fr));}
}
.status-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-weight:900;font-size:12px;letter-spacing:.2px}
.status-work{background:#e7f7ee;color:#137a39;border:1px solid #bfe6cd;}
.status-stop{background:#fdecea;color:#a61b1b;border:1px solid #f5c6cb;}
.verdict-badge{display:inline-block;padding:3px 9px;border-radius:4px;font-weight:800;font-size:11px;letter-spacing:.4px;text-transform:uppercase}
.v-test{background:#eef1f5;color:#4a5966;border:1px solid #ccd5de;}
.v-bad {background:#fdecea;color:#a61b1b;border:1px solid #f5c6cb;}
.v-ok  {background:#fff8e1;color:#8a6516;border:1px solid #f0dfae;}
.v-good{background:#e7f7ee;color:#137a39;border:1px solid #bfe6cd;}
.pool-badge{display:inline-block;margin-left:6px;vertical-align:middle;padding:2px 6px;border-radius:3px;font-size:10px;font-weight:700;letter-spacing:.3px}
.pool-perm{background:#e7f7ee;color:#137a39;border:1px solid #bfe6cd;}
.pool-rot {background:#eef1f5;color:#4a5966;border:1px solid #ccd5de;}
.debug-card{margin:8px 0;padding:10px;border:1px solid rgba(0,0,0,.08);border-radius:12px;background:rgba(255,255,255,.45)}
</style>';

/* ---------------- header + filter ---------------- */
echo '<div class="wrap">';
echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
echo '<h3 style="margin:0">💰 Финансы</h3>';
echo '<div class="small-muted">День: <b>'.h($day).'</b> | <a class="btn" href="menu.php?page=cron_jobs">Cron</a></div>';
echo '</div>';

$domains = db_all($db, "SELECT id, domain, finance_mode FROM crm_domains WHERE status='live' ORDER BY domain");

// If user accidentally passes campaign_id as domain_id -> resolve to domain_id via crm_buy_campaigns
if ($domain_id > 0) {
  $exists = db_all($db, "SELECT id FROM crm_domains WHERE id=".(int)$domain_id." LIMIT 1");
  if (!$exists) {
    $row = db_all($db, "SELECT domain_id FROM crm_buy_campaigns WHERE campaign_id=".(int)$domain_id." LIMIT 1");
    if ($row && isset($row[0]['domain_id'])) $domain_id = (int)$row[0]['domain_id'];
  }
}

echo '<form method="get" style="margin:8px 0 12px 0;display:flex;gap:8px;align-items:center">';
echo '<input type="hidden" name="page" value="finance">';
echo '<input type="date" name="d" value="'.h($day).'" class="form-control" style="max-width:180px">';
echo '<select name="domain_id" class="form-select" style="max-width:360px">';
echo '<option value="0">Все домены</option>';
foreach ($domains as $d) {
  $sel = ((int)$d['id'] === $domain_id) ? 'selected' : '';
  echo '<option value="'.h($d['id']).'" '.$sel.'>'.h($d['domain']).'</option>';
}
echo '</select>';
echo '<button class="btn btn-primary">Показать</button>';
echo '</form>';

if ($DBG_FIN) {
  $base = 'menu.php?page=finance&d='.urlencode($day).'&domain_id='.urlencode((string)$domain_id).'&dbg=1';
  echo '<div class="small-muted" style="margin:-4px 0 10px 0">';
  echo 'DEBUG: <b>ON</b> | ';
  echo '<a class="btn" href="'.$base.'&force_pause=1">force_pause</a> ';
  echo '<a class="btn" href="'.$base.'&force_act=1">force_act</a> ';
  echo '<a class="btn" href="'.$base.'&force_pause=1&cid=878062">force_pause+cid</a>';
  echo '</div>';
}

$dom_map = [];
/* Клики за последний час и фактически уплаченная цена клика за сегодня.
   Берём из журналов самих сайтов: там у каждого клика своя отметка времени и
   своя цена, тогда как отчёт сетки даёт только накопительный итог за сутки. */
/* Состояние кампаний прямо из кабинета Kadam: текущая ставка и работает ли
   кампания. Кешируем на две минуты — кабинет отдаёт данные четырьмя запросами
   по сто кампаний, и делать это на каждое открытие страницы незачем. */
function kadam_live_state(mysqli $db): array {
    $cacheFile = dirname(__DIR__) . '/var/cache/kadam_state.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($c)) return $c;
    }
    $row = $db->query("SELECT params_json FROM crm_cron_jobs
                        WHERE connector='kadam_cost' AND is_enabled=1 LIMIT 1")->fetch_assoc();
    if (!$row) return [];
    $p = json_decode($row['params_json'], true);
    $acc = (int)($p['app_id'] ?? 0);
    $bearer = (string)($p['bearer'] ?? '');
    if ($acc <= 0 || $bearer === '') return [];

    $out = []; $off = 0;
    for ($i = 0; $i < 12; $i++) {
        $ch = curl_init("https://api.kadam.net/ads.campaigns.get?" . http_build_query(
            ['app_id'=>$acc, 'client_id'=>$acc, 'limit'=>100, 'offset'=>$off]));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20,
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_HTTPHEADER=>["Authorization: Bearer $bearer"]]);
        $d = json_decode((string)curl_exec($ch), true);
        $items = $d['response']['items'] ?? [];
        if (!$items) break;
        foreach ($items as $x) {
            $bids = [];
            foreach ((array)($x['countries'] ?? []) as $cn)
                if (($b = (float)($cn['bid'] ?? 0)) > 0) $bids[] = $b;
            $out[(string)$x['id']] = [
                'status'  => (string)($x['status_msg'] ?? ''),
                'bid_avg' => $bids ? array_sum($bids) / count($bids) : 0.0,
                'bid_max' => $bids ? max($bids) : 0.0,
                'geos'    => count($bids),
            ];
        }
        if (count($items) < 100) break;
        $off += 100;
    }
    if ($out) @file_put_contents($cacheFile, json_encode($out));
    return $out;
}
$kadam_state = kadam_live_state($db);

/* Состояние секвенсера: кто из льющихся сидит в постоянном пуле, а кто занял
   слот обычной ротации. Без этого «WORK» у домена с невысокой доходностью
   выглядит необъяснимо. */
$pool_perm = [];
$seqState = json_decode((string)@file_get_contents('/var/lib/kadam-sequence.json'), true);
foreach (($seqState['currents'] ?? []) as $c)
    if (!empty($c['always'])) $pool_perm[(string)$c['domain']] = true;

$per_hour = $real_cpc = [];
$dom_db = [];
$q = $db->query("SELECT domain, db_name FROM crm_domains WHERE status='live' AND db_name<>''");
while ($q && ($x = $q->fetch_assoc())) $dom_db[$x['domain']] = $x['db_name'];
/* одним запросом по всем базам сразу: раньше здесь был цикл на три десятка
   отдельных запросов, и страница собиралась двадцать секунд */
$parts = [];
$unreadable = [];
foreach ($dom_db as $dom => $dbn) {
    $d = str_replace('`', '', $dbn);
    $q = "'" . $db->real_escape_string($dom) . "'";
    /* база домена может быть недоступна или без таблицы clicks — тогда
       этот домен просто не попадает в отчёт, а не роняет всю страницу */
    try {
        $chk = @$db->query("SELECT 1 FROM `$d`.clicks LIMIT 1");
        if ($chk === false) { $unreadable[$dom] = 'нет clicks'; continue; }
        $chk->free();
    } catch (\Throwable $e) {
        $unreadable[$dom] = 'нет доступа к базе';
        continue;
    }
    $parts[] = "SELECT $q AS dom, ts, COALESCE(NULLIF(c4,'')+0, cost, 0) AS cpc
                  FROM `$d`.clicks WHERE ts >= CURDATE()";
}
if ($parts) {
    $res = @$db->query("SELECT dom,
                               SUM(ts >= NOW() - INTERVAL 1 HOUR) h,
                               COUNT(*) n, SUM(cpc) sp
                          FROM (" . implode(' UNION ALL ', $parts) . ") u
                         GROUP BY dom");
    while ($res && ($x = $res->fetch_assoc())) {
        $per_hour[$x['dom']] = (int)$x['h'];
        $real_cpc[$x['dom']] = (int)$x['n'] > 0 ? (float)$x['sp'] / (int)$x['n'] : 0.0;
    }
}

$domain_status = []; // did => WORK|STOP
foreach ($domains as $d) {
  $did = (int)$d['id'];
  $dom_map[$did] = (string)$d['domain'];
  $st = strtoupper((string)($d['finance_mode'] ?? 'WORK'));
  $domain_status[$did] = ($st === 'STOP') ? 'STOP' : 'WORK';
}

/* ---------------- campaigns (buy) ---------------- */
$campaigns = db_all($db, "
  SELECT c.domain_id, c.campaign_id, n.code AS network_code, d.domain AS domain_name
  FROM crm_buy_campaigns c
  JOIN crm_networks n ON n.id=c.network_id
  JOIN crm_domains d ON d.id=c.domain_id AND d.status='live'
  ".($domain_id>0 ? "WHERE c.domain_id=".(int)$domain_id : "")."
");

$has_buy_campaign = [];
foreach ($campaigns as $c) $has_buy_campaign[(int)$c['domain_id'].'|'.(string)$c['campaign_id']] = true;

/* ---------------- COST daily (with clicks from meta_json) ---------------- */
$cost_raw = db_all($db, "
  SELECT domain_id, external_id, amount, connector, meta_json
  FROM crm_finance_daily
  WHERE d=? AND kind='cost' AND domain_id IN (SELECT id FROM crm_domains WHERE status='live')
    ".($domain_id>0 ? "AND domain_id=".(int)$domain_id : "")."
", [$day]);

$cost_by = [];
$cost_by_domain = [];
$clicks_by = [];
$cost_connector_by = [];

foreach ($cost_raw as $r) {
  $did = (int)$r['domain_id'];
  $ext = (string)$r['external_id'];
  $amt = (float)$r['amount'];
  $k = $did.'|'.$ext;

  $cost_by[$k] = ($cost_by[$k] ?? 0.0) + $amt;
  $cost_by_domain[$did] = ($cost_by_domain[$did] ?? 0.0) + $amt;

  $conn = (string)($r['connector'] ?? '');
  if (!isset($cost_connector_by[$k]) || $cost_connector_by[$k] === '') $cost_connector_by[$k] = connector_label($conn);

  $meta = json_decode((string)($r['meta_json'] ?? ''), true);
  if (is_array($meta)) {
    $cl = (float)($meta['clicks'] ?? 0);
    if ($cl > 0) $clicks_by[$k] = ($clicks_by[$k] ?? 0.0) + $cl;
  }
}

/* ---------------- REVENUE daily ---------------- */
$rev_raw = db_all($db, "
  SELECT domain_id, amount, connector
  FROM crm_finance_daily
  WHERE d=? AND kind='revenue' AND domain_id IN (SELECT id FROM crm_domains WHERE status='live')
    ".($domain_id>0 ? "AND domain_id=".(int)$domain_id : "")."
", [$day]);

$rev_by_domain = [];
$rev_main_net = [];
$rev_by_domain_net = [];

foreach ($rev_raw as $r) {
  $did  = (int)$r['domain_id'];
  $amt  = (float)$r['amount'];
  $conn = connector_label((string)($r['connector'] ?? ''));

  $rev_by_domain[$did] = ($rev_by_domain[$did] ?? 0.0) + $amt;
  if (!isset($rev_by_domain_net[$did])) $rev_by_domain_net[$did] = [];
  $rev_by_domain_net[$did][$conn] = ($rev_by_domain_net[$did][$conn] ?? 0.0) + $amt;
}
foreach ($rev_by_domain_net as $did => $m) { arsort($m); $rev_main_net[$did] = (string)array_key_first($m); }

/* ---------------- METRIKA daily ---------------- */
$metrika_by_domain = [];
/* Источник визитов — СВОЯ метрика (own_metrika_today), Яндекс остаётся
   запасным. Берём последний тик за день, а не строку crm_finance_daily:
   раннер metric в дневную таблицу не пишет вовсе (её должен был собирать
   /root/metric-day.php, которого на машине нет), поэтому колонка визитов
   пустовала независимо от источника. */
$met_rows = db_all($db, "
  SELECT t.domain_id, t.amount, t.meta_json
  FROM crm_finance_ticks t
  JOIN (
    SELECT domain_id, MAX(ts) AS ts
    FROM crm_finance_ticks
    WHERE d=? AND kind='metric' AND connector IN ('own_metrika_today','yandex_metrika_today')
    GROUP BY domain_id
  ) last ON last.domain_id = t.domain_id AND last.ts = t.ts
  WHERE t.d=? AND t.kind='metric'
    AND t.connector IN ('own_metrika_today','yandex_metrika_today')
    AND t.domain_id IN (SELECT id FROM crm_domains WHERE status='live')
    ".($domain_id>0 ? "AND t.domain_id=".(int)$domain_id : "")."
  ORDER BY FIELD(t.connector,'own_metrika_today','yandex_metrika_today')
", [$day, $day]);

foreach ($met_rows as $r) {
  $did = (int)$r['domain_id'];
  /* Порядок в запросе ставит свою метрику первой; если по домену пришло две
     строки (оба источника тикнули в одну секунду), выигрывает своя. */
  if (isset($metrika_by_domain[$did])) continue;
  $meta = json_decode((string)($r['meta_json'] ?? ''), true);
  if (!is_array($meta)) $meta = [];
  $visits    = (float)($meta['visits']    ?? ($r['amount'] ?? 0));
  $pageviews = (float)($meta['pageviews'] ?? 0);
  $avgTime   = (float)($meta['avg_time']  ?? 0);
  $depth     = (float)($meta['depth']     ?? (($visits > 0) ? ($pageviews / $visits) : 0));
  $metrika_by_domain[$did] = ['visits'=>$visits,'pageviews'=>$pageviews,'avg_time'=>$avgTime,'depth'=>$depth];
}

/* ---------------- job flags ---------------- */
$jobs_flags = [];
$job_rows = db_all($db, "
  SELECT domain_id, job_type, COUNT(*) AS cnt
  FROM crm_cron_jobs
  WHERE is_enabled=1
    AND domain_id IN (SELECT id FROM crm_domains WHERE status='live')
    ".($domain_id>0 ? "AND domain_id=".(int)$domain_id : "")."
  GROUP BY domain_id, job_type
");
foreach ($job_rows as $r) {
  $did = (int)$r['domain_id'];
  if (!isset($jobs_flags[$did])) $jobs_flags[$did] = ['has_cost'=>false,'has_rev'=>false,'has_metric'=>false];
  $jt = (string)$r['job_type'];
  if ($jt === 'cost')    $jobs_flags[$did]['has_cost']   = ((int)$r['cnt'] > 0);
  if ($jt === 'revenue') $jobs_flags[$did]['has_rev']    = ((int)$r['cnt'] > 0);
  if ($jt === 'metric')  $jobs_flags[$did]['has_metric'] = ((int)$r['cnt'] > 0);
}

/* ---------------- Kadam: campaignIds + bearer ---------------- */
/**
 * campaignIds:
 *  1) from buy_campaigns where network_code contains 'kadam'
 *  2) fallback from cost daily where connector label is 'kadam' and external_id looks like campaign id
 *
 * bearer:
 *  1) override if $KADAM_BEARER_OVERRIDE set
 *  2) from enabled cron job: job_type='cost' AND connector='kadam_cost' params_json.bearer
 */
$kadam_campaigns_by_domain = [];
foreach ($campaigns as $c) {
  $did = (int)$c['domain_id'];
  $net = strtolower((string)$c['network_code']);
  if (strpos($net, 'kadam') === false) continue;
  $cid = (int)$c['campaign_id'];
  if ($cid > 0) $kadam_campaigns_by_domain[$did][] = $cid;
}

// fallback from cost daily external_id
$kadam_cids_from_cost = [];
foreach ($cost_raw as $r) {
  $did = (int)$r['domain_id'];
  $conn = connector_label((string)($r['connector'] ?? ''));
  if ($conn !== 'kadam') continue;
  $cid = (int)($r['external_id'] ?? 0);
  if ($cid > 0) $kadam_cids_from_cost[$did][$cid] = true;
}
foreach ($kadam_cids_from_cost as $did => $set) {
  $list = array_map('intval', array_keys($set));
  if (!isset($kadam_campaigns_by_domain[$did])) $kadam_campaigns_by_domain[$did] = [];
  $kadam_campaigns_by_domain[$did] = array_values(array_unique(array_merge($kadam_campaigns_by_domain[$did], $list)));
}

// bearer by domain
$kadam_bearer_by_domain = [];
if (trim($KADAM_BEARER_OVERRIDE) !== '') {
  foreach ($domains as $d) {
    $did = (int)$d['id'];
    if ($domain_id > 0 && $did !== $domain_id) continue;
    $kadam_bearer_by_domain[$did] = trim($KADAM_BEARER_OVERRIDE);
  }
} else {
  $kadam_job_rows = db_all($db, "
    SELECT domain_id, params_json
    FROM crm_cron_jobs
    WHERE is_enabled=1
      AND domain_id IN (SELECT id FROM crm_domains WHERE status='live')
      AND job_type='cost'
      AND connector='kadam_cost'
      ".($domain_id>0 ? "AND domain_id=".(int)$domain_id : "")."
  ");
  foreach ($kadam_job_rows as $jr) {
    $did = (int)$jr['domain_id'];
    $p = json_decode((string)($jr['params_json'] ?? ''), true);
    if (!is_array($p)) $p = [];
    $bearer = trim((string)($p['bearer'] ?? ''));
    if ($bearer !== '') $kadam_bearer_by_domain[$did] = $bearer;
  }
}

/* ---------------- AUTO STOP/WORK ---------------- */
/*
 * DISABLED 2026-07-31 at Martin's request.
 *
 * This block fired on every page load and could pause or activate Kadam campaigns
 * by itself. While the sequential traffic tests run (/root/kadam-sequence.php),
 * two controllers acting on the same campaigns made spend untrackable: the
 * sequencer would pause a campaign after its test and this would restart it, with
 * the sequencer still believing nothing was running.
 *
 * Set to true to restore the old behaviour. The status table below still renders;
 * only the automatic transitions and the Kadam pause/activate calls are skipped.
 */
const AUTO_MODE_ENABLED = false;

$AUTO_STOP_LOSS = -0.10;      // <= -0.10$ => STOP
$AUTO_RESUME_PROFIT = 0.00;   // >  0.00$ => WORK

$transitions_log = [];
$transitions_raw = [];

// mapping debug
if ($DBG_FIN) {
  $dbgLines = [];
  foreach ($domains as $d) {
    $did = (int)$d['id'];
    if ($domain_id > 0 && $did !== $domain_id) continue;
    $dom = (string)($dom_map[$did] ?? '');
    $st  = (string)($domain_status[$did] ?? 'WORK');
    $sp  = (float)($cost_by_domain[$did] ?? 0.0);
    $rv  = (float)($rev_by_domain[$did] ?? 0.0);
    $pf  = $rv - $sp;
    $cids = $kadam_campaigns_by_domain[$did] ?? [];
    $bearer_present = isset($kadam_bearer_by_domain[$did]) && trim((string)$kadam_bearer_by_domain[$did]) !== '';
    $dbgLines[] = "{$dom} status={$st} profit=".number_format($pf,6,'.','')." kadam_cids=[".implode(',',$cids)."] bearer=".($bearer_present?'yes':'NO');
  }
  echo '<div class="glass" style="margin:10px 0">';
  echo '<h4>🔎 DEBUG mapping</h4>';
  echo '<pre style="margin:0;white-space:pre-wrap;font-size:12px">'.h(implode("\n",$dbgLines)).'</pre>';
  echo '</div>';
}

foreach ($domains as $d) {
  $did = (int)$d['id'];
  if ($domain_id > 0 && $did !== $domain_id) continue;

  $dom = (string)($dom_map[$did] ?? '');
  if ($dom === '') continue;

  $sp = (float)($cost_by_domain[$did] ?? 0.0);
  $rv = (float)($rev_by_domain[$did] ?? 0.0);
  $profit = $rv - $sp;

  $cur = $domain_status[$did] ?? 'WORK';
  $next = $cur;

  if (AUTO_MODE_ENABLED) {
    if ($cur === 'WORK' && $profit <= $AUTO_STOP_LOSS) $next = 'STOP';
    elseif ($cur === 'STOP' && $profit > $AUTO_RESUME_PROFIT) $next = 'WORK';
  }

  $bearer = (string)($kadam_bearer_by_domain[$did] ?? '');
  $cids   = $kadam_campaigns_by_domain[$did] ?? [];

  // manual override (like your curl)
  if ($MANUAL_CID > 0) $cids = [$MANUAL_CID];

  // transition action
  if ($next !== $cur) {
    $reason = "auto profit=".number_format($profit, 6, '.', '');

    // update status in crm_domains (safe even if columns missing -> just ignore failure)
    @db_exec($db, "
      UPDATE crm_domains
      SET finance_mode=?, finance_mode_changed_at=NOW(), finance_mode_reason=?
      WHERE id=?
    ", [$next, $reason, $did]);

    $domain_status[$did] = $next;

    if ($next === 'STOP') {
      if (!$cids) {
        log_action($transitions_log, $transitions_raw,
          "STOP {$dom}: SKIP no campaignIds profit=".number_format($profit, 6, '.', ''),
          ['dom'=>$dom,'profit'=>$profit,'cids'=>$cids,'bearer_present'=>($bearer!=='')]
        );
      } else {
        $r = kadam_pause_campaigns($bearer, $cids);
        log_action($transitions_log, $transitions_raw,
          "STOP {$dom}: ".($r['ok'] ? 'OK' : 'FAIL')." http=".($r['res']['http'] ?? 0)." success=".((int)($r['success'] ?? 0))." profit=".number_format($profit, 6, '.', ''),
          ['dom'=>$dom,'profit'=>$profit,'pause'=>$r,'bearer_present'=>($bearer!=='')]
        );
      }

      // TG notify STOP (always, even if API failed)
      $cidTxt = $cids ? implode(',', array_map('intval',$cids)) : '—';
      $tgText =
        "🛑 <b>STOP</b>\n".
        "<b>{$dom}</b>\n".
        "Причина: <code>profit ".number_format($profit, 6, '.', '')." <= ".number_format($AUTO_STOP_LOSS,2,'.','')."</code>\n".
        "Spend: <code>".tg_m($sp,6)."</code>\n".
        "Rev: <code>".tg_m($rv,6)."</code>\n".
        "Profit: <code>".tg_m($profit,6)."</code>\n".
        "campaignIds: <code>{$cidTxt}</code>\n".
        "Day: <code>{$day}</code>";
      $tgRes = tg_send($tgToken, $tgChat, $tgText);
      if ($DBG_FIN) log_action($transitions_log, $transitions_raw, "TG STOP {$dom}: ".($tgRes['ok']?'OK':'FAIL')." http=".($tgRes['http']??0), ['tg'=>$tgRes]);

    } else { // WORK
      $resetUrl = 'https://' . $dom . '/cmd/menu.php?page=campaign_851694&reset=1&apply=1';
      $g = http_get_url($resetUrl, 12);

      if (!$cids) {
        log_action($transitions_log, $transitions_raw,
          "WORK {$dom}: reset_apply={$g['code']} SKIP no campaignIds profit=".number_format($profit, 6, '.', ''),
          ['dom'=>$dom,'profit'=>$profit,'reset'=>$g,'cids'=>$cids,'bearer_present'=>($bearer!=='')]
        );
      } else {
        $r = kadam_activate_campaigns($bearer, $cids);
        log_action($transitions_log, $transitions_raw,
          "WORK {$dom}: reset_apply={$g['code']} ".($r['ok'] ? 'OK' : 'FAIL')." http=".($r['res']['http'] ?? 0)." success=".((int)($r['success'] ?? 0))." profit=".number_format($profit, 6, '.', ''),
          ['dom'=>$dom,'profit'=>$profit,'reset'=>$g,'activate'=>$r,'bearer_present'=>($bearer!=='')]
        );
      }

      // TG notify WORK
      $cidTxt = $cids ? implode(',', array_map('intval',$cids)) : '—';
      $tgText =
        "✅ <b>WORK</b>\n".
        "<b>{$dom}</b>\n".
        "Причина: <code>profit ".number_format($profit, 6, '.', '')." > ".number_format($AUTO_RESUME_PROFIT,2,'.','')."</code>\n".
        "Spend: <code>".tg_m($sp,6)."</code>\n".
        "Rev: <code>".tg_m($rv,6)."</code>\n".
        "Profit: <code>".tg_m($profit,6)."</code>\n".
        "campaignIds: <code>{$cidTxt}</code>\n".
        "Day: <code>{$day}</code>";
      $tgRes = tg_send($tgToken, $tgChat, $tgText);
      if ($DBG_FIN) log_action($transitions_log, $transitions_raw, "TG WORK {$dom}: ".($tgRes['ok']?'OK':'FAIL')." http=".($tgRes['http']??0), ['tg'=>$tgRes]);
    }
  }

  // ---- FORCE re-apply actions (even without transition) ----
  $stNow = (string)($domain_status[$did] ?? 'WORK');

  if ($stNow === 'STOP' && $FORCE_PAUSE) {
    if (!$cids) {
      log_action($transitions_log, $transitions_raw,
        "FORCE_PAUSE {$dom}: SKIP no campaignIds",
        ['dom'=>$dom,'cids'=>$cids,'bearer_present'=>($bearer!=='')]
      );
    } else {
      $r = kadam_pause_campaigns($bearer, $cids);
      log_action($transitions_log, $transitions_raw,
        "FORCE_PAUSE {$dom}: ".($r['ok'] ? 'OK' : 'FAIL')." http=".($r['res']['http'] ?? 0)." success=".((int)($r['success'] ?? 0)),
        ['dom'=>$dom,'pause'=>$r,'bearer_present'=>($bearer!=='')]
      );

      // TG for FORCE pause (so you see it)
      $cidTxt = implode(',', array_map('intval', $r['campaignIds'] ?? $cids));
      $tgText =
        "⚠️ <b>FORCE_PAUSE</b>\n".
        "<b>{$dom}</b>\n".
        "campaignIds: <code>{$cidTxt}</code>\n".
        "Result: <code>".($r['ok']?'OK':'FAIL')."</code>\n".
        "HTTP: <code>".((int)($r['res']['http'] ?? 0))."</code>\n".
        "Day: <code>{$day}</code>";
      $tgRes = tg_send($tgToken, $tgChat, $tgText);
      if ($DBG_FIN) log_action($transitions_log, $transitions_raw, "TG FORCE_PAUSE {$dom}: ".($tgRes['ok']?'OK':'FAIL')." http=".($tgRes['http']??0), ['tg'=>$tgRes]);
    }
  }

  if ($stNow === 'WORK' && $FORCE_ACT) {
    if (!$cids) {
      log_action($transitions_log, $transitions_raw,
        "FORCE_ACT {$dom}: SKIP no campaignIds",
        ['dom'=>$dom,'cids'=>$cids,'bearer_present'=>($bearer!=='')]
      );
    } else {
      $r = kadam_activate_campaigns($bearer, $cids);
      log_action($transitions_log, $transitions_raw,
        "FORCE_ACT {$dom}: ".($r['ok'] ? 'OK' : 'FAIL')." http=".($r['res']['http'] ?? 0)." success=".((int)($r['success'] ?? 0)),
        ['dom'=>$dom,'activate'=>$r,'bearer_present'=>($bearer!=='')]
      );

      $cidTxt = implode(',', array_map('intval', $r['campaignIds'] ?? $cids));
      $tgText =
        "⚠️ <b>FORCE_ACT</b>\n".
        "<b>{$dom}</b>\n".
        "campaignIds: <code>{$cidTxt}</code>\n".
        "Result: <code>".($r['ok']?'OK':'FAIL')."</code>\n".
        "HTTP: <code>".((int)($r['res']['http'] ?? 0))."</code>\n".
        "Day: <code>{$day}</code>";
      $tgRes = tg_send($tgToken, $tgChat, $tgText);
      if ($DBG_FIN) log_action($transitions_log, $transitions_raw, "TG FORCE_ACT {$dom}: ".($tgRes['ok']?'OK':'FAIL')." http=".($tgRes['http']??0), ['tg'=>$tgRes]);
    }
  }
}

/* ---------------- build rows (A/B/C like before) ---------------- */
$rows = [];

// A) buy_campaigns
foreach ($campaigns as $c) {
  $did  = (int)$c['domain_id'];
  $camp = (string)$c['campaign_id'];
  $k    = $did.'|'.$camp;

  $spend  = $cost_by[$k] ?? 0.0;
  $clicks = $clicks_by[$k] ?? 0.0;

  $rev     = $rev_by_domain[$did] ?? 0.0;
  $rev_net = $rev_main_net[$did] ?? '—';

  $m = $metrika_by_domain[$did] ?? ['visits'=>0,'pageviews'=>0,'avg_time'=>0,'depth'=>0];
  $visits    = (float)$m['visits'];
  $pageviews = (float)$m['pageviews'];
  $avgTime   = (float)$m['avg_time'];
  $depth     = (float)$m['depth'];

  $profit = $rev - $spend;
  $roi    = ($spend > 0) ? ($profit / $spend * 100.0) : 0.0;

  $spv = ($visits > 0) ? ($spend / $visits) : 0.0;
  $rvv = ($visits > 0) ? ($rev   / $visits) : 0.0;

  $kc  = ($visits > 0) ? ($clicks / $visits) : 0.0;
  $v2k = ($visits > 0) ? ($visits / 2000.0 * 100.0) : 0.0;

  $jf = $jobs_flags[$did] ?? ['has_cost'=>false,'has_rev'=>false,'has_metric'=>false];

  $rows[] = [
    'did'=>$did,'topic'=>(string)$c['domain_name'],
    'spend_net'=>(string)$c['network_code'],'rev_net'=>$rev_net,'campaign_id'=>$camp,
    'spend'=>$spend,'clicks'=>$clicks,'rev'=>$rev,'profit'=>$profit,'roi'=>$roi,
    'spv'=>$spv,'rvv'=>$rvv,'visits'=>$visits,'pageviews'=>$pageviews,'avg_time'=>$avgTime,'depth'=>$depth,
    'kc'=>$kc,'v2k'=>$v2k,
    'missing_cost'=>!$jf['has_cost'],'missing_rev'=>!$jf['has_rev'],'missing_metric'=>!$jf['has_metric'],
    'no_cost_data'=>($jf['has_cost'] && $spend<=0.0),'no_rev_data'=>($jf['has_rev'] && $rev<=0.0),'no_met_data'=>($jf['has_metric'] && $visits<=0.0),
  ];
}

// B) cost rows not in buy_campaigns
foreach ($cost_by as $key => $spend) {
  [$didS, $ext] = explode('|', $key, 2);
  $did = (int)$didS;
  if (isset($has_buy_campaign[$did.'|'.$ext])) continue;

  $rev     = $rev_by_domain[$did] ?? 0.0;
  $rev_net = $rev_main_net[$did] ?? '—';

  $m = $metrika_by_domain[$did] ?? ['visits'=>0,'pageviews'=>0,'avg_time'=>0,'depth'=>0];
  $visits    = (float)$m['visits'];
  $pageviews = (float)$m['pageviews'];
  $avgTime   = (float)$m['avg_time'];
  $depth     = (float)$m['depth'];

  $profit = $rev - (float)$spend;
  $roi    = ((float)$spend > 0) ? ($profit / (float)$spend * 100.0) : 0.0;

  $spv = ($visits > 0) ? ((float)$spend / $visits) : 0.0;
  $rvv = ($visits > 0) ? ($rev / $visits) : 0.0;

  $clicks = (float)($clicks_by[$did.'|'.$ext] ?? 0.0);
  $kc  = ($visits > 0) ? ($clicks / $visits) : 0.0;
  $v2k = ($visits > 0) ? ($visits / 2000.0 * 100.0) : 0.0;

  $jf = $jobs_flags[$did] ?? ['has_cost'=>false,'has_rev'=>false,'has_metric'=>false];

  $rows[] = [
    'did'=>$did,'topic'=>$dom_map[$did] ?? ('domain#'.$did),
    'spend_net'=>$cost_connector_by[$did.'|'.$ext] ?? '—','rev_net'=>$rev_net,'campaign_id'=>$ext,
    'spend'=>(float)$spend,'clicks'=>$clicks,'rev'=>$rev,'profit'=>$profit,'roi'=>$roi,
    'spv'=>$spv,'rvv'=>$rvv,'visits'=>$visits,'pageviews'=>$pageviews,'avg_time'=>$avgTime,'depth'=>$depth,
    'kc'=>$kc,'v2k'=>$v2k,
    'missing_cost'=>!$jf['has_cost'],'missing_rev'=>!$jf['has_rev'],'missing_metric'=>!$jf['has_metric'],
    'no_cost_data'=>($jf['has_cost'] && (float)$spend<=0.0),'no_rev_data'=>($jf['has_rev'] && $rev<=0.0),'no_met_data'=>($jf['has_metric'] && $visits<=0.0),
  ];
}

// C) domains with revenue/metric but no cost
foreach ($domains as $d) {
  $did = (int)$d['id'];
  if ($domain_id > 0 && $did !== $domain_id) continue;

  $hasRow = false;
  foreach ($rows as $rr) { if ((int)$rr['did'] === $did) { $hasRow = true; break; } }
  if ($hasRow) continue;

  $rev     = $rev_by_domain[$did] ?? 0.0;
  $rev_net = $rev_main_net[$did] ?? '—';
  $m = $metrika_by_domain[$did] ?? ['visits'=>0,'pageviews'=>0,'avg_time'=>0,'depth'=>0];
  if ($rev <= 0.0 && (float)$m['visits'] <= 0.0) continue;

  $visits    = (float)$m['visits'];
  $pageviews = (float)$m['pageviews'];
  $avgTime   = (float)$m['avg_time'];
  $depth     = (float)$m['depth'];

  $jf = $jobs_flags[$did] ?? ['has_cost'=>false,'has_rev'=>false,'has_metric'=>false];

  $rows[] = [
    'did'=>$did,'topic'=>(string)$d['domain'],
    'spend_net'=>'—','rev_net'=>$rev_net,'campaign_id'=>'—',
    'spend'=>0.0,'clicks'=>0.0,'rev'=>$rev,'profit'=>$rev,'roi'=>0.0,
    'spv'=>0.0,'rvv'=>($visits>0?($rev/$visits):0.0),
    'visits'=>$visits,'pageviews'=>$pageviews,'avg_time'=>$avgTime,'depth'=>$depth,
    'kc'=>0.0,'v2k'=>($visits>0?($visits/2000.0*100.0):0.0),
    'missing_cost'=>!$jf['has_cost'],'missing_rev'=>!$jf['has_rev'],'missing_metric'=>!$jf['has_metric'],
    'no_cost_data'=>($jf['has_cost']),'no_rev_data'=>($jf['has_rev'] && $rev<=0.0),'no_met_data'=>($jf['has_metric'] && $visits<=0.0),
  ];
}

usort($rows, fn($a,$b) => ($a['spend'] == $b['spend']) ? 0 : (($a['spend'] < $b['spend']) ? 1 : -1));

/* ---------------- KPI "NOW" snapshot from ticks ---------------- */
$last_ticks = db_all($db, "
  SELECT t.domain_id, t.kind, t.connector, t.external_id, t.amount, t.meta_json, t.ts
  FROM crm_finance_ticks t
  JOIN (
    SELECT domain_id, kind, connector, external_id, MAX(ts) AS ts
    FROM crm_finance_ticks
    WHERE d=?
      AND kind IN ('cost','revenue','metric')
      ".($domain_id>0 ? "AND domain_id=".(int)$domain_id : "")."
    GROUP BY domain_id, kind, connector, external_id
  ) x
    ON x.domain_id=t.domain_id
   AND x.kind=t.kind
   AND x.connector=t.connector
   AND x.external_id=t.external_id
   AND x.ts=t.ts
", [$day]);

$kpi_domains = [];
$kpi_nets    = [];
$kpi_spend   = 0.0;
$kpi_rev     = 0.0;
$kpi_visits  = 0.0;
$kpi_pv      = 0.0;
$kpi_last_ts = '';

foreach ($last_ticks as $r) {
  $did = (int)$r['domain_id'];
  $kpi_domains[$did] = true;

  $ts = (string)$r['ts'];
  if ($kpi_last_ts === '' || $ts > $kpi_last_ts) $kpi_last_ts = $ts;

  $kind = (string)$r['kind'];
  $conn = connector_label((string)($r['connector'] ?? ''));
  if ($conn !== '' && $conn !== '—') $kpi_nets[$conn] = true;

  if ($kind === 'cost') { $kpi_spend += (float)$r['amount']; continue; }
  if ($kind === 'revenue') { $kpi_rev += (float)$r['amount']; continue; }

  $meta = json_decode((string)($r['meta_json'] ?? ''), true);
  if (!is_array($meta)) $meta = [];

  $v  = (float)($meta['visits'] ?? ($r['amount'] ?? 0));
  $pv = (float)($meta['pageviews'] ?? 0);

  $kpi_visits += $v;
  $kpi_pv     += $pv;
}

$kpi_profit = $kpi_rev - $kpi_spend;
$kpi_roi    = ($kpi_spend > 0) ? ($kpi_profit / $kpi_spend * 100.0) : 0.0;
$kpi_depth  = ($kpi_visits > 0) ? ($kpi_pv / $kpi_visits) : 0.0;

$kpi_domains_cnt = count($kpi_domains);
$kpi_nets_cnt    = count($kpi_nets);

/* ---------------- charts from ticks (10m buckets) ---------------- */
$bucket_rows = db_all($db, "
  SELECT
    FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/600)*600) AS bucket_ts,
    t.kind,
    t.domain_id,
    t.connector,
    t.external_id,
    t.amount,
    t.meta_json,
    t.ts
  FROM crm_finance_ticks t
  JOIN (
    SELECT
      FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/600)*600) AS bucket_ts,
      kind, domain_id, connector, external_id,
      MAX(ts) AS ts
    FROM crm_finance_ticks
    WHERE d=?
      AND kind IN ('cost','revenue','metric')
      ".($domain_id>0 ? "AND domain_id=".(int)$domain_id : "")."
    GROUP BY bucket_ts, kind, domain_id, connector, external_id
  ) x
    ON x.bucket_ts = FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/600)*600)
   AND x.kind = t.kind
   AND x.domain_id = t.domain_id
   AND x.connector = t.connector
   AND x.external_id = t.external_id
   AND x.ts = t.ts
  ORDER BY bucket_ts, t.kind
", [$day]);

$b = []; // bucket_ts => ['cost'=>0,'revenue'=>0,'visits'=>0,'pageviews'=>0]
foreach ($bucket_rows as $r) {
  $bucket = (string)$r['bucket_ts'];
  if (!isset($b[$bucket])) $b[$bucket] = ['cost'=>0.0,'revenue'=>0.0,'visits'=>0.0,'pageviews'=>0.0];

  $kind = (string)$r['kind'];
  if ($kind === 'cost')    { $b[$bucket]['cost']    += (float)$r['amount']; continue; }
  if ($kind === 'revenue') { $b[$bucket]['revenue'] += (float)$r['amount']; continue; }

  $meta = json_decode((string)($r['meta_json'] ?? ''), true);
  if (!is_array($meta)) $meta = [];

  $vis = (float)($meta['visits'] ?? ($r['amount'] ?? 0));
  $pv  = (float)($meta['pageviews'] ?? 0);

  $b[$bucket]['visits']    += $vis;
  $b[$bucket]['pageviews'] += $pv;
}

$labels = [];
$chartVisits = [];
$chartCost = [];
$chartRev  = [];

foreach ($b as $bucket => $v) {
  $labels[]      = date('H:i', strtotime($bucket));
  $chartVisits[] = round((float)$v['visits'], 2);
  $chartCost[]   = round((float)$v['cost'], 6);
  $chartRev[]    = round((float)$v['revenue'], 6);
}

/* ---------------- dashboard top ---------------- */
echo '<div class="dashTop">';

echo '<div class="glass">';
echo '<h4>🧊 KPI (снимок сейчас)</h4>';
echo '<div class="kpis">';
echo '<div class="kpi"><div class="k">Доменов</div><div class="v">'.h((string)$kpi_domains_cnt).'</div></div>';
echo '<div class="kpi"><div class="k">Сеток</div><div class="v">'.h((string)$kpi_nets_cnt).'</div></div>';
echo '<div class="kpi"><div class="k">Трафик</div><div class="v">'.h(money_fmt($kpi_visits,0)).'</div></div>';
echo '<div class="kpi"><div class="k">Показы</div><div class="v">'.h(money_fmt($kpi_pv,0)).'</div></div>';
echo '<div class="kpi"><div class="k">Глубина</div><div class="v">'.h(number_format($kpi_depth,2,'.',' ')).'</div></div>';
echo '<div class="kpi"><div class="k">Спенд</div><div class="v">'.h(money_fmt($kpi_spend,4)).'</div></div>';
echo '<div class="kpi"><div class="k">Ревенью</div><div class="v">'.h(money_fmt($kpi_rev,6)).'</div></div>';
echo '<div class="kpi"><div class="k">ROI</div><div class="v">'.h(roi_fmt($kpi_roi)).'%</div></div>';
echo '</div>';
echo '<div class="small-muted" style="margin-top:8px">Обновлено: <b>'.h($kpi_last_ts ? date('H:i:s', strtotime($kpi_last_ts)) : '—').'</b> (ticks)</div>';
echo '</div>';

echo '<div class="glass">';
echo '<h4>👥 Посетители (10м, факт)</h4>';
if (!$labels) echo '<div class="small-muted">Нет данных в crm_finance_ticks за этот день.</div>';
else echo '<canvas class="canvasSmall" id="chartMetric"></canvas>';
echo '</div>';

echo '<div class="glass">';
echo '<h4>💸 Доход / Расход (10м, факт)</h4>';
if (!$labels) echo '<div class="small-muted">Нет данных в crm_finance_ticks за этот день.</div>';
else echo '<canvas class="canvasSmall" id="chartMoney"></canvas>';
echo '</div>';

echo '</div>';

if ($labels) {
  echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
  echo '<script>
    const labels = '.json_encode($labels).';
    const visits = '.json_encode($chartVisits).';
    const rev    = '.json_encode($chartRev).';
    const cost   = '.json_encode($chartCost).';

    new Chart(document.getElementById("chartMetric"), {
      type:"line",
      data:{ labels, datasets:[{ label:"Visits", data:visits, borderWidth:2, tension:0.25, pointRadius:0 }] },
      options:{ responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
    });

    new Chart(document.getElementById("chartMoney"), {
      type:"line",
      data:{ labels, datasets:[
        { label:"Revenue", data:rev,  borderWidth:2, tension:0.25, pointRadius:0 },
        { label:"Cost",    data:cost, borderWidth:2, tension:0.25, pointRadius:0 }
      ]},
      options:{ responsive:true, plugins:{legend:{display:true}}, scales:{y:{beginAtZero:true}} }
    });
  </script>';
}

/* ---------------- FULL TABLE ---------------- */
$total_spend   = 0.0;
$total_clicks  = 0.0;
$total_rev     = 0.0;
$total_visits  = 0.0;
$total_pv      = 0.0;
$total_time_w  = 0.0;

foreach ($rows as $r) {
  $total_spend  += (float)$r['spend'];
  $total_clicks += (float)$r['clicks'];
  $total_rev    += (float)$r['rev'];
  $total_visits += (float)$r['visits'];
  $total_pv     += (float)$r['pageviews'];
  $total_time_w += (float)$r['avg_time'] * (float)$r['visits'];
}

$total_profit = $total_rev - $total_spend;
$total_roi    = ($total_spend > 0) ? ($total_profit / $total_spend * 100.0) : 0.0;
$total_spv    = ($total_visits > 0) ? ($total_spend / $total_visits) : 0.0;
$total_rvv    = ($total_visits > 0) ? ($total_rev / $total_visits) : 0.0;
$total_depth  = ($total_visits > 0) ? ($total_pv / $total_visits) : 0.0;
$total_avg_t  = ($total_visits > 0) ? ($total_time_w / $total_visits) : 0.0;
$total_kc     = ($total_visits > 0) ? ($total_clicks / $total_visits) : 0.0;
$total_v2k    = ($total_visits > 0) ? ($total_visits / 2000.0 * 100.0) : 0.0;

echo '<table>';
echo '<tr>
  <th>Topic</th>
  <th>Отлив</th>
  <th>Вердикт</th>
  <th>Spend<br>сеткa</th>
  <th>Rev<br>сеткa</th>
  <th>Campaign<br>ID</th>
  <th>Spend</th>
  <th>Clicks<br>(Kadam)</th>
  <th>Клики/час</th>
  <th>Ставка<br>в Kadam</th>
  <th>Ext<br>revenue</th>
  <th>Profit</th>
  <th>ROI&nbsp;%</th>
  <th class="compact" title="Spend / visits (×1000)">Sp/V</th>
  <th class="compact" title="Revenue / visits (×1000)">Rv/V</th>
  <th>Visits</th>
  <th>Pageviews</th>
  <th>Avg&nbsp;time,<br>s</th>
  <th>Depth</th>
  <th class="compact" title="Kadam clicks / Metrika visits">K/C</th>
  <th class="compact" title="Visits vs 2000 users, %">V/2k,%</th>
  <th>GEO</th>
  <th>BL&nbsp;pubs</th>
</tr>';

foreach ($rows as $r) {
  $roi = (float)$r['roi'];

  /* деньги решают цвет: расход и доход — база, остальное косвенное.
     Красным заливаем только настоящую поломку настройки — когда у домена
     вообще нет коннектора расхода или дохода и считать нечего. Всё прочее,
     включая отвалившуюся метрику, уходит в метку рядом с именем. */
  if (!empty($r['missing_cost']) || !empty($r['missing_rev'])) $rowStyle = fail_bg_style();
  else $rowStyle = roi_bg_style($roi);

  $notes = [];
  if (!empty($r['missing_metric']))                        $notes[] = 'нет метрики';
  elseif (!empty($r['no_met_data']))                       $notes[] = 'визиты не собрались';
  if (!empty($r['no_cost_data']))                          $notes[] = 'расход 0';
  if (!empty($r['no_rev_data']))                           $notes[] = 'доход 0';
  $noteBadge = $notes
    ? ' <span class="badge badge-bad" title="'.h(implode(', ', $notes)).'">!</span>'
    : '';

  $geoBtn = '<a class="btn" target="_blank" href="https://'.h($r['topic']).'/cmd/menu.php?page=campaign_851694">GEO</a>';
  $blBtn  = '<a class="btn" target="_blank" href="https://'.h($r['topic']).'/cmd/menu.php?page=publishers">BL</a>';
  $predBtn = '<a class="btn" href="menu.php?page=ltv_predict&domain='.h($r['topic']).'" title="LTV разгон предикт">🚀 LTV</a>';

  $clicks = (float)$r['clicks'];
  $clicksBadge = ($clicks > 0)
    ? '<span class="badge badge-ok">'.h(money_fmt($clicks, 0)).'</span>'
    : '<span class="badge badge-bad">0</span>';

  $kcBadge = ((float)$r['kc'] > 0)
    ? '<span class="badge badge-ok">'.h(number_format((float)$r['kc'], 2, '.', ' ')).'</span>'
    : '<span class="badge badge-bad">0.00</span>';

  $v2kBadge = ((float)$r['v2k'] > 0)
    ? '<span class="badge badge-ok">'.h(number_format((float)$r['v2k'], 1, '.', ' ')).'</span>'
    : '<span class="badge badge-bad">0.0</span>';

  /* статус берём из кабинета: наш расчётный показывал намерение, а не факт.
     «Активна» значит кампания реально откручивается, всё остальное — стоит. */
  $ks2 = $kadam_state[(string)$r['campaign_id']] ?? null;
  if ($ks2 !== null && $ks2['status'] !== '') {
      $live = mb_stripos($ks2['status'], 'ктивна') !== false;
      $stBadge = '<span class="status-badge ' . ($live ? 'status-work' : 'status-stop') . '" title="'
               . h($ks2['status']) . '">' . ($live ? 'WORK' : 'STOP') . '</span>';
      if ($live) {
          $perm = isset($pool_perm[(string)$r['topic']]);
          $stBadge .= ' <span class="pool-badge ' . ($perm ? 'pool-perm' : 'pool-rot') . '" title="'
                    . ($perm ? 'льётся постоянно, слот в ротации не занимает'
                             : 'занял слот обычной ротации, по кругу') . '">'
                    . ($perm ? 'постоянно' : 'ротация') . '</span>';
      }
  } else {
      $st = $domain_status[(int)$r['did']] ?? 'WORK';
      $stBadge = ($st === 'STOP')
        ? '<span class="status-badge status-stop">STOP</span>'
        : '<span class="status-badge status-work">WORK</span>';
  }

  echo '<tr style="'.$rowStyle.'">';
  echo '<td class="name">'.h($r['topic']).$noteBadge.'</td>';
  echo '<td class="center">'.$stBadge.'</td>';

  /* Вердикт по деньгам за сегодня.
     test  — пролив меньше 0.05, судить не о чем: на копейках любая случайность
             даёт сотни процентов
     bad   — в минусе, правило снимает такой домен до выхода в плюс
     ok    — в плюсе, но доходность ниже 70%: льётся в общей очереди
     good  — доходность от 70%: льётся постоянно, вне очереди за слотами */
  $vs = (float)$r['spend'];
  $vr = (float)$r['rev'];
  /* Доходность выше порога признаём сразу, не дожидаясь конца разведки: домен
     уже показал, что отбивается, и держать его в очереди незачем. Но совсем
     без расхода судить нельзя — при нуле потраченного любая копейка дохода
     даёт бесконечную доходность, и в good попала бы вся сетка. */
  $vRoiEarly = $vs > 0 ? ($vr - $vs) / $vs : -1;
  if ($vs >= 0.01 && $vRoiEarly >= 0.80) {
      $vClass = 'v-good'; $vText = 'good';
      $vTitle = sprintf('доходность %+.0f%% при расходе %.3f', $vRoiEarly * 100, $vs);
  } elseif ($vs < 0.05) {
      $vClass = 'v-test'; $vText = 'test';
      $vTitle = 'пролив ' . number_format($vs, 3) . ' — меньше порога 0.05, вердикта нет';
  } else {
      $vRoi = ($vr - $vs) / $vs;
      if ($vRoi <= 0)          { $vClass = 'v-bad';  $vText = 'bad'; }
      elseif ($vRoi >= 0.80)   { $vClass = 'v-good'; $vText = 'good'; }
      else                     { $vClass = 'v-ok';   $vText = 'ok'; }
      $vTitle = sprintf('доходность %+.0f%% при расходе %.3f', $vRoi * 100, $vs);
  }
  echo '<td class="center"><span class="verdict-badge '.$vClass.'" title="'.h($vTitle).'">'
     . $vText . '</span></td>';
  echo '<td>'.h($r['spend_net']).'</td>';
  echo '<td>'.h($r['rev_net']).'</td>';
  echo '<td class="center"><code>'.h($r['campaign_id']).'</code></td>';
  echo '<td><b>'.h(money_fmt($r['spend'], 4)).'</b></td>';
  echo '<td class="center">'.$clicksBadge.'</td>';
  $ph = $per_hour[$r['topic']] ?? 0;
  echo '<td>'.($ph > 0 ? h(number_format($ph, 0, '.', ' ')) : '—').'</td>';
  /* ставка, которую кампания держит в кабинете прямо сейчас: средняя по гео
     и максимум — по ним видно и общий уровень, и не уехало ли что-то вверх */
  $ks = $kadam_state[(string)$r['campaign_id']] ?? null;
  if ($ks && $ks['bid_avg'] > 0) {
      /* показываем среднюю и максимум вместе: одна средняя вводит в
         заблуждение, потому что внутри кампании ставки разведены от 0.00002
         на дешёвых рынках до потолка на рабочих */
      echo '<td title="'.(int)$ks['geos'].' гео, средняя и максимум">'
         . h(number_format($ks['bid_avg'], 5)) . ' <span style="opacity:.6">/ '
         . h(number_format($ks['bid_max'], 5)) . '</span></td>';
  } else {
      echo '<td>—</td>';
  }
  echo '<td><b>'.h(money_fmt($r['rev'], 6)).'</b></td>';
  echo '<td><b>'.h(money_fmt($r['profit'], 6)).'</b></td>';
  echo '<td><b>'.h(roi_fmt($r['roi'])).'</b></td>';
  echo '<td class="compact"><b>'.h(money_fmt($r['spv']*1000, 4)).'</b></td>';
  echo '<td class="compact"><b>'.h(money_fmt($r['rvv']*1000, 4)).'</b></td>';
  echo '<td>'.h(money_fmt($r['visits'], 0)).'</td>';
  echo '<td>'.h(money_fmt($r['pageviews'], 0)).'</td>';
  echo '<td>'.h(money_fmt($r['avg_time'], 1)).'</td>';
  echo '<td>'.h(money_fmt($r['depth'], 2)).'</td>';
  echo '<td class="compact">'.$kcBadge.'</td>';
  echo '<td class="compact">'.$v2kBadge.'</td>';
  echo '<td class="center">'.$geoBtn.'</td>';
  echo '<td class="center">'.$blBtn.' '.$predBtn.'</td>';
  echo '</tr>';
}

// TOTAL row
echo '<tr style="font-weight:bold;background:#eef;">';
echo '<td class="name">TOTAL</td>';
echo '<td></td><td></td>';
echo '<td></td><td></td><td></td>';
echo '<td>'.h(money_fmt($total_spend, 4)).'</td>';
echo '<td class="center">'.($total_clicks>0 ? '<span class="badge badge-ok">'.h(money_fmt($total_clicks,0)).'</span>' : '<span class="badge badge-bad">0</span>').'</td>';
/* поток по сетке и средняя ставка: две колонки, добавленные в шапку.
   Без них итоговая строка была короче остальных и таблица съезжала. */
$total_per_hour = array_sum($per_hour);
/* только наши кампании: в кабинете лежит три сотни чужих и архивных, их
   ставки к средней по сетке отношения не имеют */
$our_cids = [];
foreach ($rows as $rr) if (!empty($rr['campaign_id'])) $our_cids[(string)$rr['campaign_id']] = true;
$bids_now = [];
foreach ($kadam_state as $cid => $st2)
    if (isset($our_cids[(string)$cid]) && (float)($st2['bid_avg'] ?? 0) > 0)
        $bids_now[] = (float)$st2['bid_avg'];
$total_bid = $bids_now ? array_sum($bids_now) / count($bids_now) : 0.0;
echo '<td>'.h(number_format($total_per_hour, 0, '.', ' ')).'</td>';
echo '<td>'.($total_bid > 0 ? h(number_format($total_bid, 5)) : '—').'</td>';
echo '<td>'.h(money_fmt($total_rev, 6)).'</td>';
echo '<td>'.h(money_fmt($total_profit, 6)).'</td>';
echo '<td>'.h(roi_fmt($total_roi)).'</td>';
echo '<td class="compact">'.h(money_fmt($total_spv*1000, 4)).'</td>';
echo '<td class="compact">'.h(money_fmt($total_rvv*1000, 4)).'</td>';
echo '<td>'.h(money_fmt($total_visits, 0)).'</td>';
echo '<td>'.h(money_fmt($total_pv, 0)).'</td>';
echo '<td>'.h(money_fmt($total_avg_t, 1)).'</td>';
echo '<td>'.h(money_fmt($total_depth, 2)).'</td>';
echo '<td class="compact">'.h(number_format($total_kc, 2, '.', ' ')).'</td>';
echo '<td class="compact">'.h(number_format($total_v2k, 1, '.', ' ')).'</td>';
echo '<td></td><td></td>';
echo '</tr>';

echo '</table>';

/* ---------------- DEBUG bottom ---------------- */
if ($DBG_FIN) {
  echo '<div class="glass" style="margin:12px 0">';
  echo '<h4>🪵 DEBUG stop/work</h4>';

  echo '<div class="small-muted" style="margin-bottom:8px">';
  echo 'dbg=1 | force_pause='.(int)$FORCE_PAUSE.' | force_act='.(int)$FORCE_ACT.' | cid='.(int)$MANUAL_CID;
  echo '</div>';

  if (!$transitions_log) {
    echo '<div class="small-muted">Нет событий. (Если хочешь форс как curl — добавь &cid=878062)</div>';
  } else {
    echo '<pre style="margin:0;white-space:pre-wrap;font-size:12px">'.h(implode("\n",$transitions_log)).'</pre>';
  }

  echo '<hr style="border:none;border-top:1px solid rgba(0,0,0,.08);margin:10px 0">';
  echo '<div class="small-muted" style="margin-bottom:6px">RAW (raw обрезка до 1500 символов)</div>';

  if (!$transitions_raw) {
    echo '<div class="small-muted">Нет RAW событий.</div>';
  } else {
    foreach ($transitions_raw as $x) {
      $url = '';
      $raw = '';
      $err = '';

      if (isset($x['pause']['res']['url'])) $url = (string)$x['pause']['res']['url'];
      if (isset($x['activate']['res']['url'])) $url = (string)$x['activate']['res']['url'];
      if (isset($x['tg']['url'])) $url = (string)$x['tg']['url'];

      if (isset($x['pause']['res']['raw'])) $raw = (string)$x['pause']['res']['raw'];
      if (isset($x['activate']['res']['raw'])) $raw = (string)$x['activate']['res']['raw'];
      if (isset($x['tg']['raw'])) $raw = (string)$x['tg']['raw'];

      if (isset($x['pause']['res']['err'])) $err = (string)$x['pause']['res']['err'];
      if (isset($x['activate']['res']['err'])) $err = (string)$x['activate']['res']['err'];
      if (isset($x['tg']['err'])) $err = (string)$x['tg']['err'];

      echo '<div class="debug-card">';
      echo '<div style="font-weight:900;margin-bottom:6px">'.h((string)($x['msg'] ?? 'event')).'</div>';
      if ($url) echo '<div class="small-muted" style="margin-bottom:6px">'.h($url).'</div>';
      if ($err) echo '<div class="small-muted" style="margin-bottom:6px;color:#b00">err: '.h($err).'</div>';
      if ($raw !== '') echo '<pre style="margin:0;white-space:pre-wrap;font-size:12px">'.h(mb_substr($raw, 0, 1500)).'</pre>';
      else echo '<div class="small-muted">no raw</div>';
      echo '</div>';
    }
  }

  echo '</div>';
}

echo '</div>'; // wrap

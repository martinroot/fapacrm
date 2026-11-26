#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Kadam v1 publisher/site tool (self-contained, no secrets inside).
 *
 * Modes:
 *   --self-test                       pure logic tests, no network/DB
 *   --list --kadam=ID [--view=all|blacklist] [--query=SITE_ID]
 *                                     read-only POST /stats/sites
 *   --check --camp=TRACKER --kadam=ID  read-only candidate scan in push.click
 *   (default)                         worker: candidate scan -> block new losers
 *   --block --kadam=ID --zones=ID,ID [--apply]
 *   --unblock --kadam=ID --zones=ID,ID [--apply]   (owner-approved only)
 *
 * Writes require --apply. UI marks in push.black_list_ts are written ONLY after
 * Kadam API readback confirms the site state. No countryTarget writes here.
 *
 * Token source (first hit wins): env KADAM_BEARER, --token-file=PATH,
 * local CRM fapacrm.crm_cron_jobs (connector='kadam_cost', is_enabled=1).
 */
const KP_API = 'https://partners.kadam.net/api/v1';
const KP_PAIRS = [1290 => 955610, 1291 => 966929, 1292 => 966931]; // tracker => Kadam
const KP_WINDOW_DAYS = 90;
const KP_MIN_CLICKS = 200;   // strict "more than"
const KP_MIN_SPEND = 0.0;    // must be > 0
const KP_MAX_ROI = 0.0;      // strict "less than" (%)
const KP_BATCH_ZONES = 500;
const KP_LOCK = '/var/lock/kadam-publisher-blacklist-v1.lock';

function kp_args(): array {
    $a = [];
    foreach (array_slice($GLOBALS['argv'], 1) as $s) {
        if (str_starts_with($s, '--')) {
            $p = explode('=', substr($s, 2), 2);
            $a[$p[0]] = $p[1] ?? true;
        }
    }
    return $a;
}
function kp_get(array $a, string $k, $default = null) {
    return array_key_exists($k, $a) ? $a[$k] : $default;
}
function kp_flag(array $a, string $k): bool {
    return !empty($a[$k]);
}
function kp_log(string $msg, array $a): void {
    $line = '[' . gmdate('Y-m-d H:i:s') . " UTC] " . $msg . "\n";
    $log = kp_get($a, 'log');
    if (is_string($log) && $log !== '') file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
    fwrite(STDOUT, $line);
}
function kp_fail(string $msg): never {
    fwrite(STDERR, "ERROR: " . $msg . "\n");
    exit(1);
}
function kp_eligible(int $clicks, float $spend, float $revenue): bool {
    return $clicks > KP_MIN_CLICKS && $spend > KP_MIN_SPEND
        && (($revenue - $spend) / $spend * 100) < KP_MAX_ROI;
}
function kp_roi(float $spend, float $revenue): float {
    return $spend > 0 ? ($revenue - $spend) / $spend * 100 : 0.0;
}
function kp_api(string $method, string $path, string $token, ?array $body = null): array {
    $ch = curl_init(KP_API . $path);
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json', 'User-Agent: curl/8.5.0'];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_HTTPHEADER => $headers];
    if ($method !== 'GET') {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $http !== 200) throw new RuntimeException("Kadam $method $path HTTP $http $err");
    $d = json_decode((string)$raw, true);
    if (!is_array($d) || empty($d['success'])) throw new RuntimeException("Kadam $method $path rejected: " . substr((string)$raw, 0, 300));
    return $d;
}
function kp_token(array $a, int $probeKadam): string {
    $t = getenv('KADAM_BEARER');
    if (is_string($t) && $t !== '') return $t;
    $file = kp_get($a, 'token-file');
    if (is_string($file) && $file !== '') {
        $t = @file_get_contents($file);
        if ($t !== false && trim($t) !== '') return trim($t);
        kp_fail('token-file is empty or unreadable');
    }
    try {
        $crm = new mysqli('localhost', 'root', '', 'fapacrm');
        if ($crm->connect_errno) throw new RuntimeException('CRM connect failed: ' . $crm->connect_error);
        $r = $crm->query("SELECT params_json FROM crm_cron_jobs WHERE connector='kadam_cost' AND is_enabled=1 ORDER BY updated_at DESC LIMIT 100");
        if (!$r) throw new RuntimeException('CRM token query failed');
        while ($row = $r->fetch_assoc()) {
            $cfg = json_decode((string)$row['params_json'], true);
            $cand = (string)($cfg['bearer'] ?? '');
            if ($cand === '') continue;
            try {
                $d = kp_api('GET', '/campaigns/' . $probeKadam, $cand);
                if ((int)($d['data']['id'] ?? 0) === $probeKadam) { $crm->close(); return $cand; }
            } catch (Throwable $ignore) { /* try next; never print token */ }
        }
        $crm->close();
    } catch (Throwable $e) {
        throw new RuntimeException('Token lookup failed: ' . $e->getMessage());
    }
    kp_fail('No enabled Kadam v1 bearer can read owned campaign ' . $probeKadam);
}
function kp_db(array $a): mysqli {
    $db = new mysqli(
        (string)(kp_get($a, 'db-host') ?: 'localhost'),
        (string)(kp_get($a, 'db-user') ?: 'root'),
        (string)(kp_get($a, 'db-pass') ?: ''),
        (string)(kp_get($a, 'db-name') ?: 'push')
    );
    if ($db->connect_errno) kp_fail('DB connect failed: ' . $db->connect_error);
    $db->set_charset('utf8mb4');
    $db->query("SET SESSION time_zone='+03:00'");
    $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    return $db;
}
function kp_site_row(string $token, int $kadam, string $zone, string $view): ?array {
    $views = $view === 'blacklist' ? ['blacklist', 'all'] : [$view];
    foreach ($views as $try) {
        $d = kp_api('POST', '/stats/sites', $token, [
            'page' => 1, 'perPage' => 10,
            'filters' => ['campaignIds' => [$kadam], 'view' => $try, 'searchQuery' => $zone],
        ]);
        foreach (($d['data']['rows'] ?? []) as $row) {
            if ((string)($row['macrosId'] ?? '') !== $zone) continue;
            if ($view !== 'blacklist' || !empty($row['isInBlackList'])) return $row;
        }
    }
    if ($view !== 'blacklist') return null;
    // Fresh blocks can be missed by searchQuery: scan blacklist pages by exact macrosId.
    $seen = []; $total = PHP_INT_MAX;
    for ($page = 1; $page <= 20; $page++) {
        $d = kp_api('POST', '/stats/sites', $token, [
            'page' => $page, 'perPage' => 100,
            'filters' => ['campaignIds' => [$kadam], 'view' => 'blacklist'],
        ]);
        $data = $d['data'] ?? [];
        $rows = $data['rows'] ?? [];
        $total = (int)($data['totalRows'] ?? $total);
        foreach ($rows as $row) {
            $id = (string)($row['macrosId'] ?? '');
            if ($id !== '') $seen[$id] = $row;
            if ($id === $zone && !empty($row['isInBlackList'])) return $row;
        }
        if (count($seen) >= $total || !$rows) break;
    }
    return null;
}
function kp_upsert_ui(mysqli $db, string $zone, int $sourceId, int $trackerCamp, bool $blocked): void {
    $val = $blocked ? 1 : 0;
    $st = $db->prepare('SELECT id FROM black_list_ts WHERE pub_name=? AND camp_id=? ORDER BY id DESC LIMIT 1');
    $st->bind_param('si', $zone, $trackerCamp); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if ($row) {
        $u = $db->prepare('UPDATE black_list_ts SET source_id=?, status_api=? WHERE id=?');
        $u->bind_param('iii', $sourceId, $val, (int)$row['id']); $u->execute(); $u->close();
    } else {
        $i = $db->prepare('INSERT INTO black_list_ts (pub_name,source_id,camp_id,status_api) VALUES (?,?,?,?)');
        $i->bind_param('siii', $zone, $sourceId, $trackerCamp, $val); $i->execute(); $i->close();
    }
}
function kp_candidates(mysqli $db, int $trackerCamp): array {
    $q = 'SELECT token1 placement, COUNT(*) clicks, SUM(cpc) spend, COALESCE(SUM(rev),0) revenue
          FROM click WHERE camp_id=? AND click_time>=NOW()-INTERVAL ' . KP_WINDOW_DAYS . ' DAY
            AND token1 REGEXP \'^[0-9]+$\'
          GROUP BY token1 HAVING COUNT(*)>' . KP_MIN_CLICKS . ' AND SUM(cpc)>0';
    $st = $db->prepare($q); $st->bind_param('i', $trackerCamp); $st->execute();
    $rs = $st->get_result(); $out = [];
    while ($r = $rs->fetch_assoc()) {
        $clicks = (int)$r['clicks']; $spend = (float)$r['spend']; $rev = (float)$r['revenue'];
        if (!kp_eligible($clicks, $spend, $rev)) continue;
        // PHP auto-casts numeric string array keys to int -> strict_types TypeError downstream.
        $out[] = ['zone' => (string)$r['placement'], 'clicks' => $clicks, 'spend' => $spend, 'revenue' => $rev, 'roi' => kp_roi($spend, $rev)];
    }
    $st->close();
    return $out;
}
function kp_campaign_map(mysqli $db, int $trackerCamp, int $kadam): ?array {
    $st = $db->prepare('SELECT source_id,source_campaing_id FROM campaings WHERE id=? LIMIT 1');
    $st->bind_param('i', $trackerCamp); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if (!$row || (int)$row['source_campaing_id'] !== $kadam) return null;
    return ['source_id' => (int)$row['source_id']];
}
function kp_zones($v): array {
    if (!is_string($v)) kp_fail('--zones=ID,ID is required');
    $out = [];
    foreach (explode(',', $v) as $z) {
        $z = trim($z);
        if (preg_match('/^[0-9]+$/', $z)) $out[] = $z;
    }
    if (!$out) kp_fail('no numeric zones in --zones');
    return array_values(array_unique($out));
}
function kp_write_block(string $token, int $kadam, array $zones, ?mysqli $db, int $sourceId, int $trackerCamp, array $a): void {
    $apply = kp_flag($a, 'apply');
    foreach (array_chunk($zones, KP_BATCH_ZONES) as $batch) {
        $ids = array_map('intval', $batch);
        if ($apply) kp_api('POST', '/stats/sites/block', $token, ['campaignIds' => [$kadam], 'zones' => $ids]);
        foreach ($batch as $zone) {
            if (!$apply) { kp_log("DRY block kadam=$kadam zone=$zone", $a); continue; }
            $rb = kp_site_row($token, $kadam, $zone, 'blacklist');
            if ($rb && !empty($rb['isInBlackList'])) {
                if ($db) kp_upsert_ui($db, $zone, $sourceId, $trackerCamp, true);
                kp_log("BLOCKED kadam=$kadam zone=$zone readback=BL", $a);
            } else {
                kp_log("VERIFY_FAIL kadam=$kadam zone=$zone; no UI mark saved", $a);
            }
        }
    }
}
function kp_write_unblock(string $token, int $kadam, array $zones, ?mysqli $db, int $sourceId, int $trackerCamp, array $a): void {
    $apply = kp_flag($a, 'apply');
    if ($apply) kp_api('POST', '/stats/sites/unblock', $token, ['campaignIds' => [$kadam], 'zones' => array_map('intval', $zones)]);
    foreach ($zones as $zone) {
        if (!$apply) { kp_log("DRY unblock kadam=$kadam zone=$zone", $a); continue; }
        $row = kp_site_row($token, $kadam, $zone, 'all');
        if ($row && empty($row['isInBlackList'])) {
            if ($db) kp_upsert_ui($db, $zone, $sourceId, $trackerCamp, false);
            kp_log("UNBLOCKED kadam=$kadam zone=$zone readback=not-BL", $a);
        } else {
            kp_log("VERIFY_FAIL unblock kadam=$kadam zone=$zone", $a);
        }
    }
}

$a = kp_args();

if (kp_flag($a, 'self-test')) {
    $cases = [
        [200, .1, 0, false], [201, .1, .099, true], [201, .1, .1, false],
        [201, .1, .12, false], [201, 0, 1, false], [200, .1, .01, false],
    ];
    foreach ($cases as [$n, $sp, $rev, $want]) {
        if (kp_eligible($n, $sp, $rev) !== $want) kp_fail('self-test failed on threshold case');
    }
    if (count(array_chunk(range(1, 1201), KP_BATCH_ZONES)) !== 3) kp_fail('self-test failed on batching');
    echo "PASS publishers rule: clicks>200, ROI<0 strict, batches<=500\n";
    exit(0);
}
if (kp_flag($a, 'help')) {
    echo file_get_contents(__FILE__, false, null, 2, 1100);
    exit(0);
}

$probe = (int)(kp_get($a, 'kadam') ?: KP_PAIRS[1290]);
$token = kp_token($a, $probe);

if (kp_flag($a, 'list')) {
    $view = (string)(kp_get($a, 'view') ?: 'all');
    if (!in_array($view, ['all', 'blacklist'], true)) kp_fail('--view must be all or blacklist');
    $filters = ['campaignIds' => [$probe], 'view' => $view];
    $q = kp_get($a, 'query');
    if (is_string($q) && $q !== '') $filters['searchQuery'] = $q;
    $d = kp_api('POST', '/stats/sites', $token, [
        'page' => (int)(kp_get($a, 'page') ?: 1),
        'perPage' => (int)(kp_get($a, 'per-page') ?: 50),
        'filters' => $filters,
    ]);
    echo json_encode($d['data'] ?? $d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$db = kp_db($a);

if (kp_flag($a, 'check')) {
    $camp = (int)kp_get($a, 'camp', 0);
    if ($camp <= 0 || $probe <= 0) kp_fail('--camp=TRACKER --kadam=ID required');
    $map = kp_campaign_map($db, $camp, $probe);
    if (!$map) kp_fail("safety check failed: campaings.source_campaing_id != $probe");
    foreach (kp_candidates($db, $camp) as $m) {
        $zone = $m['zone'];
        $row = kp_site_row($token, $probe, $zone, 'all');
        printf("%s\tclicks=%d\tspend=%.5f\trev=%.5f\tROI=%.1f%%\tAPI=%s\tBL=%s\n",
            $zone, $m['clicks'], $m['spend'], $m['revenue'], $m['roi'],
            $row ? 'mapped' : 'UNMAPPED', $row && !empty($row['isInBlackList']) ? 'yes' : 'no');
    }
    exit(0);
}

if (kp_flag($a, 'block') || kp_flag($a, 'unblock')) {
    $camp = (int)kp_get($a, 'camp', 0);
    if ($camp <= 0) kp_fail('--camp=TRACKER required for UI mirror');
    $map = kp_campaign_map($db, $camp, $probe);
    if (!$map) kp_fail("safety check failed: campaings.source_campaing_id != $probe");
    $zones = kp_zones(kp_get($a, 'zones'));
    if (kp_flag($a, 'block')) {
        $todo = [];
        foreach ($zones as $zone) {
            $row = kp_site_row($token, $probe, $zone, 'all');
            if (!$row || (string)$row['macrosId'] !== $zone) { kp_log("SKIP zone=$zone: macrosId mapping not found", $a); continue; }
            if (!empty($row['isInBlackList'])) { kp_log("SKIP zone=$zone: already BL", $a); continue; }
            $todo[] = $zone;
        }
        kp_write_block($token, $probe, $todo, $db, $map['source_id'], $camp, $a);
    } else {
        kp_write_unblock($token, $probe, $zones, $db, $map['source_id'], $camp, $a);
    }
    exit(0);
}

// Default: single-writer worker over owned pairs (or one pair with --camp/--kadam).
$lock = fopen(KP_LOCK, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { kp_log('lock busy; skipped', $a); exit(0); }
try {
    $pairs = KP_PAIRS;
    if (kp_get($a, 'camp')) {
        $camp = (int)kp_get($a, 'camp');
        $pairs = [$camp => $probe];
    }
    foreach ($pairs as $camp => $kadam) {
        $map = kp_campaign_map($db, (int)$camp, (int)$kadam);
        if (!$map) { kp_log("camp $camp/$kadam: safety check failed, mapping mismatch", $a); continue; }
        $cand = kp_candidates($db, (int)$camp);
        $new = []; $already = 0; $unmapped = 0;
        foreach ($cand as $m) {
            $zone = $m['zone'];
            $row = kp_site_row($token, (int)$kadam, $zone, 'all');
            if (!$row || (string)$row['macrosId'] !== $zone) { $unmapped++; kp_log("camp $camp zone $zone: API mapping not found", $a); continue; }
            if (!empty($row['isInBlackList'])) { $already++; continue; }
            $new[] = $zone;
        }
        kp_write_block($token, (int)$kadam, $new, $db, $map['source_id'], (int)$camp, $a);
        kp_log(sprintf('camp %d/%d candidates=%d new=%d already-BL=%d unmapped=%d%s',
            $camp, $kadam, count($cand), count($new), $already, $unmapped, kp_flag($a, 'apply') ? '' : ' dry-run'), $a);
    }
} catch (Throwable $e) {
    kp_log('ERROR: ' . $e->getMessage(), $a);
    exit(1);
} finally {
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
}

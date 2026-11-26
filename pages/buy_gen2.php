<?php
declare(strict_types=1);

/**
 * pages/taco_geo_plan_gen.php
 *
 * Генератор (CRM) -> пишет /var/www/{domain}/cmd/pages/buy_gen2.php
 * ВНУТРИ лежит "TacoLoco + Kadam GEO Planner — ONE TABLE" (скрипт сверху).
 *
 * ВАЖНО:
 * - DB для SITE берётся из /var/www/{domain}/cmd/config.php (HP_DB_HOST/HP_DB_USER/HP_DB_PASS/HP_DB_NAME)
 * - KADAM/TACO берём из enabled cron_jobs домена (как в примере генератора)
 *
 * Требует helpers CRM:
 *   csrf_check(), csrf_token(), db_connect(), db_all(),
 *   req_int(), req_str(), req_bool(), flash_set(), h()
 */

csrf_check();
$db = db_connect();

/** ---------- tiny helpers ---------- */
function safe_domain(string $domain): string {
  $domain = trim($domain);
  if ($domain === '' || !preg_match('~^[a-z0-9.-]+$~i', $domain)) return '';
  return $domain;
}
function decode_params(string $json): array {
  $j = json_decode($json, true);
  return is_array($j) ? $j : [];
}
function pick_job(array $jobs, array $prefer): ?array {
  if (!$jobs) return null;
  foreach ($prefer as $code) {
    foreach ($jobs as $j) {
      if ((string)($j['connector'] ?? '') === $code) return $j;
    }
  }
  return $jobs[0] ?? null;
}

/** ---------- builder (NOWDOC + strtr) ---------- */
function build_taco_geo_plan_php(array $costJob, array $revJob): string
{
  $costP = decode_params((string)($costJob['params_json'] ?? '{}'));
  $revP  = decode_params((string)($revJob['params_json'] ?? '{}'));

  // Kadam from costJob
  $k_app  = (int)($costP['app_id'] ?? 0);
  $k_cli  = (int)($costP['client_id'] ?? 0);
  $k_bear = (string)($costP['bearer'] ?? '');
  $k_tz   = (int)($costP['timezone'] ?? 3);
  $k_camp = (int)($costP['campaign_id'] ?? 0);

  // Taco from revenueJob
  $t_token = (string)($revP['token'] ?? '');
  $t_base  = (string)($revP['base_url'] ?? 'https://api.tacolo.co');
  $t_tz    = (string)($revP['timezone'] ?? 'Europe/Berlin');

  $t_pl = $revP['placements'] ?? [];
  if (!is_array($t_pl)) $t_pl = [];
  $t_pl_json = json_encode($t_pl, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if ($t_pl_json === false) $t_pl_json = '[]';

  $tpl = <<<'PHP'
<?php
declare(strict_types=1);

/**
 * TacoLoco + Kadam GEO Planner — ONE TABLE (CRM include-friendly)
 *
 * ✅ One table:
 *   - Kadam TODAY: views/clicks/spend + CPC
 *   - TacoLoco TODAY: revenue + ROI
 *   - TacoLoco 30d: Rev30 + Impr30 + eCPM30 (CPM) + EPC30
 *   - Plan controls in same row: Target/day, Base BID, Status (AJAX)
 *
 * ✅ Buttons:
 *   - "Заполнить ТОП-20 TacoLoco ревенью (30д)" (Rev30): set Status=ON, base_bid=MIN if empty, AJAX push
 *   - "ROI > -30%: +0.00001 BID и +30% таргета" (today ROI): set Status=ON, adjust, AJAX push
 *
 * ✅ Sorting: strictly by Kadam spend today (desc)
 *
 * ✅ DB:
 *   - берём из /cmd/config.php (HP_DB_HOST/HP_DB_USER/HP_DB_PASS/HP_DB_NAME)
 */

ini_set('display_errors','1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

/*** SITE CONFIG (DB from config.php) ***/

$__cfg = __DIR__ . '/../../config.php';      // /var/www/{domain}/config.php
//$__cfg2 = __DIR__ . '/../config.user.php';   // /var/www/{domain}/cmd/config.user.php (fallback)
if (is_file($__cfg)) require_once $__cfg;

// Маппим HP_DB_* -> DB_* (если нужно)
if (defined('HP_DB_HOST') && !defined('DB_HOST')) define('DB_HOST', (string)HP_DB_HOST);
if (defined('HP_DB_USER') && !defined('DB_USER')) define('DB_USER', (string)HP_DB_USER);
if (defined('HP_DB_PASS') && !defined('DB_PASS')) define('DB_PASS', (string)HP_DB_PASS);
if (defined('HP_DB_NAME') && !defined('DB_NAME')) define('DB_NAME', (string)HP_DB_NAME);

/*** KADAM CONFIG (from CRM generator) ***/
if (!defined('KADAM_APP_ID'))      define('KADAM_APP_ID', __KADAM_APP_ID__);
if (!defined('KADAM_CLIENT_ID'))   define('KADAM_CLIENT_ID', __KADAM_CLIENT_ID__);
if (!defined('KADAM_BEARER'))      define('KADAM_BEARER', '__KADAM_BEARER__');
if (!defined('KADAM_TZ'))          define('KADAM_TZ', __KADAM_TZ__);
if (!defined('KADAM_CAMPAIGN_ID')) define('KADAM_CAMPAIGN_ID', __KADAM_CAMPAIGN_ID__);

/*** TACO CONFIG (from CRM generator) ***/
if (!defined('TACO_TOKEN'))    define('TACO_TOKEN', '__TACO_TOKEN__');
if (!defined('TACO_BASE_URL')) define('TACO_BASE_URL', '__TACO_BASE_URL__');
if (!defined('TACO_TZ'))       define('TACO_TZ', '__TACO_TZ__');
// !!! ВАЖНО: валидный JSON
if (!defined('TACO_PL_JSON'))  define('TACO_PL_JSON', '__TACO_PL_JSON__');

// Global bid constraints
if (!defined('MIN_BID_KADAM')) define('MIN_BID_KADAM', 0.00011);
if (!defined('MAX_BID_KADAM')) define('MAX_BID_KADAM', 0.00500);

/*** detect include (menu.php require) ***/
$is_included = false;
if (!empty($_SERVER['SCRIPT_FILENAME'])) {
  $is_included = (realpath(__FILE__) !== realpath((string)$_SERVER['SCRIPT_FILENAME']));
}

/* ---------------- tiny helpers (guarded) ---------------- */

if (!function_exists('db')) {
  function db(): mysqli {
    static $m;
    if ($m instanceof mysqli) return $m;
    if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_NAME')) {
      throw new RuntimeException('DB config missing (need DB_HOST/DB_USER/DB_NAME via /cmd/config.php)');
    }
    $m = new mysqli((string)DB_HOST, (string)DB_USER, (string)(defined('DB_PASS')?DB_PASS:''), (string)DB_NAME);
    if ($m->connect_error) throw new RuntimeException('DB: '.$m->connect_error);
    $m->set_charset('utf8mb4');
    return $m;
  }
}
if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('self_url')) {
  function self_url(array $override = []): string {
    $q = $_GET;
    foreach ($override as $k => $v) {
      if ($v === null) unset($q[$k]);
      else $q[$k] = $v;
    }
    $qs = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
    return $path . ($qs ? ('?' . $qs) : '');
  }
}
if (!function_exists('safe_redirect')) {
  function safe_redirect(string $url): void {
    if (!headers_sent()) header('Location: '.$url);
    else echo '<script>location.href='.json_encode($url).';</script>';
    exit;
  }
}
if (!function_exists('fmt')) {
  function fmt(float $v, int $dec = 2): string { return number_format($v, $dec, '.', ' '); }
}
if (!function_exists('ymd')) {
  function ymd(string $tz): string {
    return (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
  }
}
if (!function_exists('ymd_days_ago')) {
  function ymd_days_ago(string $tz, int $days): string {
    $dt = new DateTime('now', new DateTimeZone($tz));
    $dt->modify('-' . $days . ' days');
    return $dt->format('Y-m-d');
  }
}

if (!function_exists('roi_css')) {
  // ПАСТЕЛЬНАЯ раскраска по ROI
  function roi_css($roi): string {
    if ($roi === null) return '';
    $roi = max(-100.0, min(100.0, (float)$roi));
    $mix = function(array $a, array $b, float $t): array {
      $t = max(0.0, min(1.0, $t));
      return [
        (int)round($a[0] + ($b[0]-$a[0])*$t),
        (int)round($a[1] + ($b[1]-$a[1])*$t),
        (int)round($a[2] + ($b[2]-$a[2])*$t),
      ];
    };
    $white = [255,255,255];
    if ($roi < 0) {
      $neg = [255,231,234];
      $t = ($roi + 100.0) / 100.0;
      [$r,$g,$b] = $mix($neg, $white, $t);
    } else {
      $pos = [232,255,241];
      $t = $roi / 100.0;
      [$r,$g,$b] = $mix($white, $pos, $t);
    }
    return "background: rgb($r,$g,$b);";
  }
}

if (!function_exists('http_get')) {
  function http_get(string $url, array $h = [], int $t = 45): array {
    $c = curl_init($url);
    curl_setopt_array($c, [
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_TIMEOUT => $t,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_HTTPHEADER => $h,
      CURLOPT_ENCODING => '',
    ]);
    $raw = curl_exec($c);
    $err = curl_error($c);
    $hc  = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return ['http'=>$hc,'raw'=>$raw===false?'':(string)$raw,'err'=>$err?:null,'url'=>$url];
  }
}

/* ---------------- Kadam HTTP ---------------- */

if (!function_exists('kadam_get')) {
  function kadam_get(string $endpoint, array $params, string $bearer, int $timeout = 30): array {
    $url = 'https://api.kadam.net/' . $endpoint . '?' . http_build_query($params, '', '&');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $bearer],
      CURLOPT_TIMEOUT        => $timeout,
    ]);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['http'=>$http,'err'=>$err?:null,'raw'=>$raw,'json'=>json_decode((string)$raw, true)];
  }
}
if (!function_exists('kadam_post_update_campaign')) {
  function kadam_post_update_campaign(array $data, int $timeout = 30): array {
    $url = 'https://api.kadam.net/ads.campaigns.update';
    $fields = [
      'app_id' => (int)KADAM_APP_ID,
      'data'   => json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . (string)KADAM_BEARER,
        'Content-Type: application/x-www-form-urlencoded; charset=utf-8'
      ],
      CURLOPT_POSTFIELDS     => http_build_query($fields, '', '&'),
      CURLOPT_TIMEOUT        => $timeout,
    ]);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['http'=>$http,'err'=>$err?:null,'raw'=>$raw,'json'=>json_decode((string)$raw, true)];
  }
}

/* ---------------- TacoLoco HTTP + AGG ---------------- */

if (!function_exists('taco_country_range')) {
  function taco_country_range(string $from, string $to, string $type, array $extra=[]): array {
    $type = ($type==='inpagepush') ? 'inpagepush' : 'push';
    $q = http_build_query($extra + ['token'=>TACO_TOKEN,'startDate'=>$from,'endDate'=>$to],'','&',PHP_QUERY_RFC3986);
    $url = rtrim((string)TACO_BASE_URL,'/')."/publisher/v1/statistics/{$type}/country?".$q;
    $r = http_get($url, ['accept: */*','user-agent: FapaCRM/1.0'], 45);
    return ['http'=>$r['http'],'raw'=>$r['raw'],'err'=>$r['err'],'url'=>$url];
  }
}

if (!function_exists('taco_parse')) {
  function taco_parse(string $raw): array {
    $j=json_decode($raw,true); $out=[];
    foreach ((array)$j as $row) {
      $cc=strtoupper(trim((string)($row['Code2']??$row['code2']??$row['Code']??$row['country']??'')));
      if ($cc==='') continue;
      $rev=(float)($row['Revenue']??$row['revenue']??0.0);
      $impr=(int)($row['Impressions']??$row['impressions']??0);
      $out[$cc]['rev']=($out[$cc]['rev']??0.0)+$rev;
      $out[$cc]['impr']=($out[$cc]['impr']??0)+$impr;
      $out[$cc]['clicks']=($out[$cc]['clicks']??0);
    }
    return $out;
  }
}

if (!function_exists('taco_account_range')) {
  function taco_account_range(string $from, string $to): array {
    $byTotal=[]; $calls=[];
    foreach (['push','inpagepush'] as $type) {
      $resp=taco_country_range($from,$to,$type,[]);
      $calls[]=['type'=>$type,'http'=>$resp['http'],'err'=>$resp['err'],'url'=>$resp['url']];
      if ($resp['http']>=200 && $resp['http']<300) {
        $part=taco_parse($resp['raw']);
        foreach ($part as $cc=>$d) {
          $byTotal[$cc]['rev']=($byTotal[$cc]['rev']??0.0)+(float)$d['rev'];
          $byTotal[$cc]['impr']=($byTotal[$cc]['impr']??0)+(int)$d['impr'];
          $byTotal[$cc]['clicks']=($byTotal[$cc]['clicks']??0)+(int)($d['clicks']??0);
        }
      }
    }
    return ['by_total'=>$byTotal,'dbg'=>['calls'=>$calls,'mode'=>'account']];
  }
}

if (!function_exists('taco_site_placements_range')) {
  function taco_site_placements_range(string $from, string $to): array {
    $pls=json_decode((string)TACO_PL_JSON,true);
    if (!is_array($pls) || !count($pls)) {
      $acc=taco_account_range($from,$to);
      $acc['dbg']['fallback']='account';
      return $acc;
    }

    $byTotal=[]; $calls=[];
    foreach ($pls as $pl) {
      $id=(int)($pl['id']??0); if ($id<=0) continue;
      $type=(($pl['type']??'push')==='inpagepush')?'inpagepush':'push';
      $resp=taco_country_range($from,$to,$type,['placement'=>$id]);
      $calls[]=['pl'=>$id,'type'=>$type,'http'=>$resp['http'],'err'=>$resp['err'],'url'=>$resp['url']];
      if ($resp['http']<200||$resp['http']>=300) continue;

      $part=taco_parse($resp['raw']);
      foreach ($part as $cc=>$d) {
        $byTotal[$cc]['rev']=($byTotal[$cc]['rev']??0.0)+(float)$d['rev'];
        $byTotal[$cc]['impr']=($byTotal[$cc]['impr']??0)+(int)$d['impr'];
        $byTotal[$cc]['clicks']=($byTotal[$cc]['clicks']??0)+(int)($d['clicks']??0);
      }
    }
    return ['by_total'=>$byTotal,'dbg'=>['calls'=>$calls,'mode'=>'placements']];
  }
}

if (!function_exists('taco_get_country_agg')) {
  function taco_get_country_agg(string $date1, string $date2, bool $use_placements): array {
    $resp = $use_placements ? taco_site_placements_range($date1, $date2) : taco_account_range($date1, $date2);
    $by = $resp['by_total'] ?? [];
    if (!is_array($by)) $by = [];

    $agg = [];
    foreach ($by as $cc => $d) {
      $cc = strtoupper((string)$cc);
      if ($cc === '') continue;

      $rev  = (float)($d['rev'] ?? 0.0);
      $impr = (int)($d['impr'] ?? 0);
      $clk  = (int)($d['clicks'] ?? 0);

      $agg[$cc] = [
        'rev'    => $rev,
        'impr'   => $impr,
        'clicks' => $clk,
        'ecpm'   => ($impr > 0) ? ($rev / $impr * 1000.0) : 0.0,
        'epc'    => ($clk  > 0) ? ($rev / $clk) : 0.0,
      ];
    }

    return ['by'=>$agg, 'dbg'=>['ok'=>1] + ($resp['dbg'] ?? [])];
  }
}

/* ---------------- MAIN ---------------- */

try {
  $m = db();

  // Days: Kadam spend day = Moscow; Taco revenue day = TACO_TZ
  $today_cost = ymd('Europe/Moscow');
  $today_rev  = ymd((string)TACO_TZ);
  $from30     = ymd_days_ago((string)TACO_TZ, 29);

  $apply = ((int)($_GET['apply'] ?? 0) === 1);

  // Plan table (per campaign)
  $cid = (int)KADAM_CAMPAIGN_ID;
  $planTable = 'tacoloco_kadam_geo_plan_' . $cid;

  $m->query("
    CREATE TABLE IF NOT EXISTS `$planTable` (
      cc            VARCHAR(5) NOT NULL PRIMARY KEY,
      label         VARCHAR(64) NOT NULL,
      target_clicks INT NOT NULL DEFAULT 0,
      base_bid      DECIMAL(12,6) NOT NULL DEFAULT 0,
      min_bid       DECIMAL(12,6) NOT NULL DEFAULT 0,
      max_bid       DECIMAL(12,6) NOT NULL DEFAULT 0,
      current_bid   DECIMAL(12,6) NOT NULL DEFAULT 0,
      status        ENUM('ON','OFF','LIMIT') NOT NULL DEFAULT 'OFF',
      updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  @ $m->query("ALTER TABLE `$planTable` MODIFY COLUMN status ENUM('ON','OFF','LIMIT') NOT NULL DEFAULT 'OFF'");

  /* ---------- AJAX update (single row) ---------- */
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['ajax'] ?? '') === '1') {
    $out = ['ok'=>0];
    try {
      $cc = strtoupper(trim((string)($_POST['cc'] ?? '')));
      if ($cc === '') throw new RuntimeException('Empty CC');

      $targetClicks = (int)($_POST['target_clicks'] ?? 0);
      if ($targetClicks < 0) $targetClicks = 0;

      $baseBidRaw = (string)($_POST['base_bid'] ?? '0');
      $baseBid = (float)str_replace(',', '.', $baseBidRaw);
      if ($baseBid < 0) $baseBid = 0.0;

      if ($baseBid > 0 && $baseBid < (float)MIN_BID_KADAM) $baseBid = (float)MIN_BID_KADAM;
      if ($baseBid > (float)MAX_BID_KADAM)                 $baseBid = (float)MAX_BID_KADAM;

      $status = strtoupper(trim((string)($_POST['status'] ?? 'OFF')));
      if (!in_array($status, ['ON','OFF','LIMIT'], true)) $status = 'OFF';

      // label from kadam_geolist
      $label = $cc;
      $cc_e = $m->real_escape_string($cc);
      $resLabel = $m->query("SELECT title FROM kadam_geolist WHERE cc='$cc_e' LIMIT 1");
      if ($resLabel && ($rowL = $resLabel->fetch_assoc())) {
        $t = trim((string)$rowL['title']);
        if ($t !== '') $label = $t;
      }
      $label_e = $m->real_escape_string($label);

      $sql = sprintf(
        "INSERT INTO `$planTable`
          (cc,label,target_clicks,base_bid,min_bid,max_bid,current_bid,status,updated_at)
         VALUES ('%s','%s',%d,%.6f,0,0,%.6f,'%s',NOW())
         ON DUPLICATE KEY UPDATE
          label=VALUES(label),
          target_clicks=VALUES(target_clicks),
          base_bid=VALUES(base_bid),
          current_bid=VALUES(current_bid),
          status=VALUES(status),
          updated_at=NOW()",
        $cc_e, $label_e, $targetClicks, $baseBid, $baseBid, $m->real_escape_string($status)
      );

      if (!$m->query($sql)) throw new RuntimeException('DB error: '.$m->error);
      $out = ['ok'=>1,'cc'=>$cc,'target_clicks'=>$targetClicks,'base_bid'=>$baseBid,'status'=>$status];
    } catch (Throwable $e) {
      $out = ['ok'=>0,'error'=>$e->getMessage()];
    }

    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }

  /* ---------- GEO map (kadam_geolist) ---------- */
  $geoMapByRegion = [];
  $geoMapByCc     = [];

  $resMap = $m->query("SELECT kadam_id, cc, title FROM kadam_geolist WHERE kadam_id>0 AND cc<>''");
  if ($resMap) {
    while ($r = $resMap->fetch_assoc()) {
      $rid = (int)$r['kadam_id'];
      $cc  = strtoupper(trim((string)$r['cc']));
      $ttl = (string)$r['title'];
      if ($rid > 0 && $cc !== '') {
        $label = trim($ttl) !== '' ? $ttl : $cc;
        $geoMapByRegion[$rid] = ['cc'=>$cc,'title'=>$label];
        $geoMapByCc[$cc] = ['kadam_id'=>$rid,'title'=>$label];
      }
    }
  }

  /* ---------- Kadam TODAY spend by geo ---------- */
  $kTodayParams = [
    'app_id'    => (int)KADAM_APP_ID,
    'client_id' => (int)KADAM_CLIENT_ID,
    'campaigns' => (int)KADAM_CAMPAIGN_ID,
    'group'     => 'region',
    'date_from' => $today_cost,
    'date_to'   => $today_cost,
    'timezone'  => (int)KADAM_TZ,
    'limit'     => 5000,
  ];
  $kTodayRes = kadam_get('ads.stats.campaign.get', $kTodayParams, (string)KADAM_BEARER);

  $kadam_today_by_cc = [];
  if (($kTodayRes['http'] ?? 0) === 200 && is_array(($kTodayRes['json']['response']['items'] ?? null))) {
    foreach ($kTodayRes['json']['response']['items'] as $it) {
      $regionId = (int)($it['region_id'] ?? 0);
      if ($regionId <= 0) continue;
      if (!isset($geoMapByRegion[$regionId])) continue;

      $cc = $geoMapByRegion[$regionId]['cc'];

      $views  = (int)($it['views'] ?? 0);
      $clicks = (int)($it['clicks'] ?? 0);
      $spend  = (float)($it['moneyOut'] ?? 0.0);

      if (!isset($kadam_today_by_cc[$cc])) $kadam_today_by_cc[$cc] = ['views'=>0,'clicks'=>0,'spend'=>0.0];
      $kadam_today_by_cc[$cc]['views']  += $views;
      $kadam_today_by_cc[$cc]['clicks'] += $clicks;
      $kadam_today_by_cc[$cc]['spend']  += $spend;
    }
  }

  /* ---------- TacoLoco TODAY + 30d ---------- */
  $tToday = taco_get_country_agg($today_rev, $today_rev, true);
  $t30    = taco_get_country_agg($from30, $today_rev, false);

  $taco_today = $tToday['by'];
  $taco_30d   = $t30['by'];

  /* ---------- Plan from DB ---------- */
  $planByCc = [];
  $resPlan = $m->query("SELECT * FROM `$planTable`");
  if ($resPlan) {
    while ($r = $resPlan->fetch_assoc()) {
      $cc = strtoupper((string)$r['cc']);
      $planByCc[$cc] = $r;
    }
  }

  /* ---------- Union CC ---------- */
  $ccAll = [];
  foreach ($geoMapByCc as $cc => $_) $ccAll[$cc]=1;
  foreach ($kadam_today_by_cc as $cc => $_) $ccAll[$cc]=1;
  foreach ($taco_today as $cc => $_) $ccAll[$cc]=1;
  foreach ($taco_30d as $cc => $_) $ccAll[$cc]=1;
  foreach ($planByCc as $cc => $_) $ccAll[$cc]=1;

  /* ---------- Ensure plan rows exist for all CC ---------- */
  foreach (array_keys($ccAll) as $cc) {
    $ccUp = strtoupper($cc);
    if (isset($planByCc[$ccUp])) continue;

    $label = $geoMapByCc[$ccUp]['title'] ?? $ccUp;

    $kt = $kadam_today_by_cc[$ccUp] ?? null;
    $rev30 = (float)($taco_30d[$ccUp]['rev'] ?? 0.0);
    $defStatus = (($kt && ((((int)$kt['clicks']>0) || ((float)$kt['spend']>0.0))) ) || $rev30>0.0) ? 'LIMIT' : 'OFF';

    $cc_e = $m->real_escape_string($ccUp);
    $label_e = $m->real_escape_string($label);
    $defStatus_e = $m->real_escape_string($defStatus);

    $m->query("
      INSERT INTO `$planTable` (cc,label,target_clicks,base_bid,min_bid,max_bid,current_bid,status,updated_at)
      VALUES ('$cc_e','$label_e',0,0,0,0,0,'$defStatus_e',NOW())
      ON DUPLICATE KEY UPDATE label=VALUES(label), updated_at=NOW()
    ");

    $planByCc[$ccUp] = [
      'cc'=>$ccUp,'label'=>$label,'target_clicks'=>0,'base_bid'=>0.0,'min_bid'=>0.0,'max_bid'=>0.0,'current_bid'=>0.0,'status'=>$defStatus,'updated_at'=>$today_cost.' 00:00:00'
    ];
  }

  /* ---------- Build rows + Kadam countryTarget (base_bid only) ---------- */
  $rows = [];
  $countryTarget = [];
  $newCurrentBids = [];

  $totClicksToday = 0;
  $totSpendToday  = 0.0;
  $totRevToday    = 0.0;

  foreach ($planByCc as $cc => $plan) {
    $ccUp = strtoupper($cc);

    $label = (string)($plan['label'] ?? ($geoMapByCc[$ccUp]['title'] ?? $ccUp));

    $kt = $kadam_today_by_cc[$ccUp] ?? ['views'=>0,'clicks'=>0,'spend'=>0.0];
    $tt = $taco_today[$ccUp] ?? ['rev'=>0.0,'impr'=>0,'clicks'=>0,'ecpm'=>0.0,'epc'=>0.0];
    $t3 = $taco_30d[$ccUp]   ?? ['rev'=>0.0,'impr'=>0,'clicks'=>0,'ecpm'=>0.0,'epc'=>0.0];

    $viewsToday  = (int)$kt['views'];
    $clicksToday = (int)$kt['clicks'];
    $spendToday  = (float)$kt['spend'];
    $revToday    = (float)$tt['rev'];

    $profitToday = $revToday - $spendToday;
    $roiToday    = ($spendToday > 0) ? ($profitToday / $spendToday * 100.0) : null;
    $cpcToday    = ($clicksToday > 0) ? ($spendToday / $clicksToday) : 0.0;

    $rev30   = (float)$t3['rev'];
    $impr30  = (int)$t3['impr'];
    $ecpm30  = (float)$t3['ecpm'];
    $epc30   = (float)$t3['epc'];

    $totClicksToday += $clicksToday;
    $totSpendToday  += $spendToday;
    $totRevToday    += $revToday;

    $targetClicks = (int)($plan['target_clicks'] ?? 0);
    $baseBid      = (float)($plan['base_bid'] ?? 0.0);
    $curBid       = (float)($plan['current_bid'] ?? 0.0);
    $statusPlan   = (string)($plan['status'] ?? 'OFF');

    // runtime: auto LIMIT when target reached and ROI not positive
    $runtimeStatus = $statusPlan;
    if ($statusPlan === 'ON' && $targetClicks > 0 && $clicksToday >= $targetClicks && !($roiToday !== null && $roiToday > 0.0)) {
      $runtimeStatus = 'LIMIT';
    }

    // Kadam push: only when runtime ON and base_bid >0 and CC mapped
    if ($runtimeStatus === 'ON' && $baseBid > 0 && isset($geoMapByCc[$ccUp])) {
      $kid = (int)$geoMapByCc[$ccUp]['kadam_id'];
      if ($kid > 0) {
        $bid = $baseBid;
        if ($bid < (float)MIN_BID_KADAM) $bid = (float)MIN_BID_KADAM;
        if ($bid > (float)MAX_BID_KADAM) $bid = (float)MAX_BID_KADAM;

        $countryTarget[$kid] = ['bid'=>$bid,'leadCost'=>0.01];
        $newCurrentBids[$ccUp] = $bid;
      }
    }

    $rows[$ccUp] = [
      'cc'=>$ccUp,'label'=>$label,

      'views_today'=>$viewsToday,'clicks_today'=>$clicksToday,'spend_today'=>$spendToday,'cpc_today'=>$cpcToday,
      'rev_today'=>$revToday,'profit_today'=>$profitToday,'roi_today'=>$roiToday,

      'rev30'=>$rev30,'impr30'=>$impr30,'ecpm30'=>$ecpm30,'epc30'=>$epc30,

      'target_clicks'=>$targetClicks,'base_bid'=>$baseBid,'current_bid'=>$curBid,
      'status_plan'=>$statusPlan,'status_runtime'=>$runtimeStatus,
    ];
  }

  // ✅ SORT: strictly by Kadam spend today desc
  uksort($rows, function(string $a, string $b) use ($rows) {
    $sa = (float)($rows[$a]['spend_today'] ?? 0.0);
    $sb = (float)($rows[$b]['spend_today'] ?? 0.0);
    if ($sa !== $sb) return ($sa < $sb) ? 1 : -1;

    $ra = (float)($rows[$a]['rev30'] ?? 0.0);
    $rb = (float)($rows[$b]['rev30'] ?? 0.0);
    if ($ra !== $rb) return ($ra < $rb) ? 1 : -1;

    return strcmp($a, $b);
  });

  /* ---------- Apply push to Kadam ---------- */
  $kadamUpdateResult = null;
  if ($apply && $countryTarget) {
    foreach ($newCurrentBids as $cc => $bidVal) {
      $cc_e = $m->real_escape_string($cc);
      $m->query(sprintf(
        "UPDATE `$planTable` SET current_bid=%.6f, updated_at=NOW() WHERE cc='%s'",
        $bidVal, $cc_e
      ));
    }
    $payload = [
      'client_id'     => (int)KADAM_CLIENT_ID,
      'campaign_id'   => (int)KADAM_CAMPAIGN_ID,
      'countryTarget' => $countryTarget,
    ];
    $kadamUpdateResult = kadam_post_update_campaign($payload, 30);
  }

  /* ---------- RAW mode ---------- */
  if (((int)($_GET['raw'] ?? 0)) === 1) {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'meta'=>[
        'today_cost'=>$today_cost,'today_rev'=>$today_rev,'from30'=>$from30,'apply'=>$apply?1:0,'plan_table'=>$planTable
      ],
      'kadam'=>['params'=>$kTodayParams,'resp'=>$kTodayRes],
      'taco'=>['dbg'=>['today'=>$tToday['dbg'],'acc30'=>$t30['dbg']]],
      'rows'=>$rows,
      'countryTarget'=>$countryTarget,
      'kadam_push'=>$kadamUpdateResult
    ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    exit;
  }

  /* ---------- HTML (include-friendly) ---------- */
  if (!$is_included) {
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html><head><meta charset="utf-8"><title>TacoLoco + Kadam GEO Plan</title></head><body><?php
  } else {
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
  }

  $totProfitToday = $totRevToday - $totSpendToday;

  ?>
  <style>
    body{font:13px system-ui;margin:16px;background:#f5f7fb}
    a{color:#0b65c2;text-decoration:none}
    a:hover{text-decoration:underline}
    h1{font-size:18px;margin:0 0 6px 0}
    .meta{font-size:12px;color:#777;margin:6px 0 12px 0;line-height:1.35}
    .topbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
    .pill{display:inline-block;padding:4px 8px;border:1px solid #ddd;border-radius:999px;background:#fff}
    .danger{border-color:#ff6b6b;background:#fff0f0}
    table{border-collapse:collapse;width:100%;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 8px 25px rgba(0,0,0,0.06);margin-bottom:18px}
    th,td{padding:6px 8px;text-align:right;font-size:11px;border-bottom:1px solid #eee;white-space:nowrap}
    th{background:#f0f3fa;font-weight:700;color:#555}
    th:first-child,td:first-child{text-align:left}
    tr:last-child td{border-bottom:none}
    .badge{display:inline-block;padding:2px 6px;border-radius:6px;background:#000;font-size:11px;color:#fff}
    .total-row{font-weight:700;background:#eef2ff}
    .runtime-limit{font-weight:800;color:#c00}
    .target-input{width:70px;text-align:right}
    .bid-input{width:90px;text-align:right}
    .status-select{width:84px}
    pre{background:#111;color:#eee;padding:10px;font-size:10px;overflow:auto;max-height:260px;border-radius:10px}
    .btn{padding:6px 10px;font-size:12px;border-radius:8px;border:1px solid #999;background:#f5f5f5;cursor:pointer}
    .btn:hover{background:#e8e8e8}
    .top-row-updating{outline:2px solid #f0ad4e}
    .top-row-ok{outline:2px solid #5cb85c;transition:outline .5s}
    .top-row-error{outline:2px solid #d9534f;transition:outline .5s}
    .muted{color:#666}
  </style>

  <div class="topbar">
    <span class="pill">campaign=<b><?=h((string)KADAM_CAMPAIGN_ID)?></b></span>
    <span class="pill">cost day=<b><?=h($today_cost)?></b></span>
    <span class="pill">rev day=<b><?=h($today_rev)?></b></span>
    <span class="pill">acc30=<b><?=h($from30)?>→<?=h($today_rev)?></b></span>
    <span class="pill">apply=<b><?= $apply?'1':'0' ?></b></span>
    <span class="pill">sent=<b><?=count($countryTarget)?></b></span>

    <span class="pill">Spend=<b><?=fmt($totSpendToday,4)?></b></span>
    <span class="pill">Rev=<b><?=fmt($totRevToday,4)?></b></span>
    <span class="pill <?=($totProfitToday<0)?'danger':''?>">Profit=<b><?=fmt($totProfitToday,4)?></b></span>

    <span style="flex:1 1 auto"></span>

    <button type="button" id="btn-fill-top20" class="btn">Заполнить ТОП-20 TacoLoco ревенью (30д)</button>
    <button type="button" id="btn-push-roi-gt--30" class="btn">ROI &gt; -30%: +0.00001 BID и +30% таргета</button>

    <a class="pill" href="<?=h(self_url(['apply'=>0]))?>">Только посмотреть</a>
    <a class="pill danger" href="<?=h(self_url(['apply'=>1]))?>">Применить (push в Kadam)</a>
    <a class="pill" href="<?=h(self_url(['raw'=>1]))?>" target="_blank">RAW</a>
  </div>

  <h1>TacoLoco + Kadam GEO план — одна таблица</h1>
  <div class="meta">
    Таблица отсортирована <b>строго по Spend Kadam (Today)</b>.<br>
    В строке: <b>Today Kadam</b>, <b>Today TacoLoco</b> (ROI), <b>TacoLoco 30d</b> (Rev30 + eCPM30), и план (Target/Base/Status) — всё в одном месте.
  </div>

  <table id="main-table">
    <thead>
      <tr>
        <th>CC</th>
        <th>Label</th>

        <th>ViewsT</th>
        <th>ClicksT</th>
        <th>SpendT</th>
        <th>CPC</th>

        <th>RevT</th>
        <th>ROI%</th>

        <th>Rev30</th>
        <th>Impr30</th>
        <th>eCPM30</th>
        <th>EPC30</th>

        <th>Target/day</th>
        <th>Base BID</th>
        <th>Current</th>

        <th>Status plan</th>
        <th>Runtime</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $cc => $r):
      $roiToday = $r['roi_today'];
      $rowBg = roi_css($roiToday);
      $runtimeSt = (string)$r['status_runtime'];
      $statusPlan = (string)$r['status_plan'];
    ?>
      <tr
        data-cc="<?=h($cc)?>"
        data-roi="<?=h($roiToday === null ? '' : (string)$roiToday)?>"
        data-spend="<?=h((string)((float)$r['spend_today']))?>"
        data-rev30="<?=h((string)((float)$r['rev30']))?>"
        style="<?=$rowBg?>"
      >
        <td><span class="badge"><?=h($cc)?></span></td>
        <td class="muted"><?=h((string)$r['label'])?></td>

        <td><?=number_format((int)$r['views_today'],0,'.',' ')?></td>
        <td><?=number_format((int)$r['clicks_today'],0,'.',' ')?></td>
        <td><b><?=fmt((float)$r['spend_today'],4)?></b></td>
        <td><?=fmt((float)$r['cpc_today'],5)?></td>

        <td><?=fmt((float)$r['rev_today'],4)?></td>
        <td><?=($roiToday===null?'-':fmt((float)$roiToday,2))?></td>

        <td><b><?=fmt((float)$r['rev30'],4)?></b></td>
        <td><?=number_format((int)$r['impr30'],0,'.',' ')?></td>
        <td><b><?=fmt((float)$r['ecpm30'],4)?></b></td>
        <td><?=fmt((float)$r['epc30'],5)?></td>

        <td>
          <input type="number" class="target-input inp-target" value="<?=h((string)(int)$r['target_clicks'])?>" min="0" step="1">
        </td>
        <td>
          <input type="number" class="bid-input inp-bid" value="<?=h(number_format((float)$r['base_bid'], 5, '.', ''))?>" step="0.00001" min="0" max="<?=h((string)MAX_BID_KADAM)?>">
        </td>
        <td><?=fmt((float)$r['current_bid'],5)?></td>

        <td>
          <select class="status-select inp-status">
            <option value="ON"    <?=$statusPlan==='ON'?'selected':''?>>ON</option>
            <option value="OFF"   <?=$statusPlan==='OFF'?'selected':''?>>OFF</option>
            <option value="LIMIT" <?=$statusPlan==='LIMIT'?'selected':''?>>LIMIT</option>
          </select>
        </td>

        <td>
          <?php if ($runtimeSt === 'LIMIT'): ?>
            <span class="runtime-limit">LIMIT (auto)</span>
          <?php else: ?>
            <?=h($runtimeSt)?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>

      <tr class="total-row">
        <td colspan="3">TOTAL</td>
        <td><?=number_format($totClicksToday,0,'.',' ')?></td>
        <td><?=fmt($totSpendToday,4)?></td>
        <td><?=($totClicksToday>0?fmt($totSpendToday/$totClicksToday,5):'-')?></td>
        <td><?=fmt($totRevToday,4)?></td>
        <td><?=($totSpendToday>0?fmt(($totProfitToday)/$totSpendToday*100,2):'-')?></td>
        <td colspan="9"></td>
      </tr>
    </tbody>
  </table>

  <h1 style="font-size:13px;margin-top:0">Kadam update response (только при apply=1)</h1>
  <pre><?=h(json_encode($kadamUpdateResult, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre>

  <script>
  const MIN_BID_KADAM = <?=json_encode((float)MIN_BID_KADAM)?>;
  const MAX_BID_KADAM = <?=json_encode((float)MAX_BID_KADAM)?>;

  function sendRowUpdate(tr) {
    const cc = tr.dataset.cc || '';
    if (!cc) return;

    const targetInput = tr.querySelector('.inp-target');
    const bidInput    = tr.querySelector('.inp-bid');
    const statusSel   = tr.querySelector('.inp-status');

    const fd = new FormData();
    fd.append('ajax','1');
    fd.append('cc', cc);
    fd.append('target_clicks', targetInput ? targetInput.value : '0');
    fd.append('base_bid', bidInput ? bidInput.value : '0');
    fd.append('status', statusSel ? statusSel.value : 'OFF');

    tr.classList.remove('top-row-ok','top-row-error');
    tr.classList.add('top-row-updating');

    fetch(window.location.pathname + window.location.search, { method:'POST', body:fd })
      .then(r => r.json().catch(() => ({})))
      .then(js => {
        tr.classList.remove('top-row-updating');
        if (!js || !js.ok) { tr.classList.add('top-row-error'); return; }

        if (targetInput && typeof js.target_clicks !== 'undefined') targetInput.value = js.target_clicks;
        if (bidInput && typeof js.base_bid !== 'undefined') bidInput.value = (+js.base_bid).toFixed(5);
        if (statusSel && typeof js.status !== 'undefined') statusSel.value = js.status;

        tr.classList.add('top-row-ok');
        setTimeout(()=>tr.classList.remove('top-row-ok','top-row-error'), 800);
      })
      .catch(() => {
        tr.classList.remove('top-row-updating');
        tr.classList.add('top-row-error');
        setTimeout(()=>tr.classList.remove('top-row-ok','top-row-error'), 800);
      });
  }

  // Wire per-row inputs
  document.querySelectorAll('#main-table tbody tr[data-cc]').forEach(function(tr){
    const targetInput = tr.querySelector('.inp-target');
    const bidInput    = tr.querySelector('.inp-bid');
    const statusSel   = tr.querySelector('.inp-status');

    if (targetInput) {
      targetInput.addEventListener('change', ()=>sendRowUpdate(tr));
      targetInput.addEventListener('input', ()=>targetInput.dataset._dirty='1');
      targetInput.addEventListener('blur', ()=>{ if (targetInput.dataset._dirty==='1'){ sendRowUpdate(tr); targetInput.dataset._dirty='0'; }});
    }
    if (bidInput) {
      bidInput.addEventListener('change', ()=>sendRowUpdate(tr));
      bidInput.addEventListener('input', ()=>bidInput.dataset._dirty='1');
      bidInput.addEventListener('blur', ()=>{ if (bidInput.dataset._dirty==='1'){ sendRowUpdate(tr); bidInput.dataset._dirty='0'; }});
    }
    if (statusSel) statusSel.addEventListener('change', ()=>sendRowUpdate(tr));
  });

  // Button: Fill TOP-20 by Taco Rev30
  (function(){
    const btn = document.getElementById('btn-fill-top20');
    if (!btn) return;

    btn.addEventListener('click', function(){
      const trs = Array.from(document.querySelectorAll('#main-table tbody tr[data-cc]'));
      trs.sort((a,b) => (parseFloat(b.dataset.rev30 || '0')||0) - (parseFloat(a.dataset.rev30 || '0')||0));

      let cnt = 0;
      for (const tr of trs) {
        const rev30 = parseFloat(tr.dataset.rev30 || '0') || 0;
        if (rev30 <= 0) continue;

        const statusSel = tr.querySelector('.inp-status');
        const bidInput  = tr.querySelector('.inp-bid');

        if (statusSel) statusSel.value = 'ON';

        if (bidInput) {
          let bid = parseFloat(bidInput.value || '0') || 0;
          if (bid <= 0) bid = MIN_BID_KADAM;
          bid = Math.max(MIN_BID_KADAM, Math.min(MAX_BID_KADAM, bid));
          bidInput.value = bid.toFixed(5);
        }

        sendRowUpdate(tr);

        cnt++;
        if (cnt >= 20) break;
      }
    });
  })();

  // ROI > -30% => +0.00001 bid and +30% target, set ON
  (function(){
    const btn = document.getElementById('btn-push-roi-gt--30');
    if (!btn) return;

    btn.addEventListener('click', function(){
      const trs = Array.from(document.querySelectorAll('#main-table tbody tr[data-cc]'));
      trs.sort((a,b) => (parseFloat(b.dataset.spend || '0')||0) - (parseFloat(a.dataset.spend || '0')||0));

      trs.forEach(function(tr){
        const roiStr = tr.dataset.roi || '';
        if (!roiStr) return;

        const roi = parseFloat(roiStr);
        if (isNaN(roi)) return;
        if (roi <= -30) return;

        const targetInput = tr.querySelector('.inp-target');
        const bidInput    = tr.querySelector('.inp-bid');
        const statusSel   = tr.querySelector('.inp-status');

        if (statusSel) statusSel.value = 'ON';

        if (targetInput) {
          let clicks = parseInt(targetInput.value || '0', 10);
          if (!isNaN(clicks) && clicks > 0) {
            clicks = Math.round(clicks * 1.3);
            targetInput.value = String(clicks);
          }
        }

        if (bidInput) {
          let bid = parseFloat(bidInput.value || '0');
          if (isNaN(bid) || bid <= 0) bid = MIN_BID_KADAM;

          bid += 0.00001;
          bid = Math.max(MIN_BID_KADAM, Math.min(MAX_BID_KADAM, bid));
          bidInput.value = bid.toFixed(5);
        }

        sendRowUpdate(tr);
      });
    });
  })();
  </script>

  <?php
  if (!$is_included) { ?></body></html><?php }

} catch (Throwable $e) {
  if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
  echo "ERROR: ".$e->getMessage()."\n".$e->getTraceAsString()."\n";
}
PHP;

  $repl = [
    '__KADAM_APP_ID__'      => (string)$k_app,
    '__KADAM_CLIENT_ID__'   => (string)$k_cli,
    '__KADAM_BEARER__'      => addslashes($k_bear),
    '__KADAM_TZ__'          => (string)$k_tz,
    '__KADAM_CAMPAIGN_ID__' => (string)$k_camp,

    '__TACO_TOKEN__'        => addslashes($t_token),
    '__TACO_BASE_URL__'     => addslashes($t_base),
    '__TACO_TZ__'           => addslashes($t_tz),
    '__TACO_PL_JSON__'      => addslashes($t_pl_json),
  ];

  return strtr($tpl, $repl);
}

/** ---------- UI ---------- */
$domains = db_all($db, "SELECT id, domain FROM crm_domains ORDER BY domain");

$sel_domain_id = req_int('domain_id', 0);
$domainName = '';
foreach ($domains as $d) if ((int)$d['id'] === $sel_domain_id) { $domainName = (string)$d['domain']; break; }
$domainSafe = safe_domain($domainName);

$costJobs = [];
$revJobs  = [];
if ($sel_domain_id > 0) {
  $jobs = db_all($db, "SELECT * FROM crm_cron_jobs WHERE domain_id=? AND is_enabled=1 ORDER BY id DESC", [$sel_domain_id]);
  foreach ($jobs as $j) {
    $jt = (string)($j['job_type'] ?? '');
    if ($jt === 'cost') $costJobs[] = $j;
    if ($jt === 'revenue') $revJobs[] = $j;
  }
}

$costPick = pick_job($costJobs, ['kadam_cost']);
$revPick  = pick_job($revJobs,  ['tacoloco_revenue']); // строго TacoLoco

$msg = '';
$preview = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && req_bool('do_generate', false)) {
  $sel_domain_id = req_int('domain_id', $sel_domain_id);

  $domainName = '';
  foreach ($domains as $d) if ((int)$d['id'] === $sel_domain_id) { $domainName = (string)$d['domain']; break; }
  $domainSafe = safe_domain($domainName);

  if ($sel_domain_id <= 0 || $domainSafe === '') {
    $msg = 'Ошибка: выбери домен';
  } elseif (!$costPick) {
    $msg = 'Ошибка: нет enabled cost-job (ожидаю kadam_cost)';
  } elseif (!$revPick) {
    $msg = 'Ошибка: нет enabled revenue-job (ожидаю tacoloco_revenue)';
  } else {
    $target = '/var/www/' . $domainSafe . '/cmd/pages/campaign_851694.php';
    $dir = dirname($target);
    if (!is_dir($dir)) {
      $msg = 'Ошибка: нет каталога ' . $dir;
    } else {
      $php = build_taco_geo_plan_php($costPick, $revPick);
      $preview = $php;

      if (is_file($target)) @copy($target, $target.'.bak.'.date('Ymd_His'));
      $ok = @file_put_contents($target, $php, LOCK_EX);

      $msg = ($ok === false) ? ('Ошибка записи: ' . $target) : ('OK: записан ' . $target . ' (' . (int)$ok . ' bytes)');
    }
  }
}

?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">🧰 Taco GEO Planner Generator</h3>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="menu.php?page=finance">Финансы</a>
    <a class="btn btn-sm btn-outline-primary" href="menu.php?page=cron_jobs<?=($sel_domain_id?('&domain_id='.(int)$sel_domain_id):'')?>">Cron Jobs</a>
  </div>
</div>

<?php if ($msg): ?>
  <div class="alert <?= (strpos($msg,'OK:')===0 ? 'alert-success' : 'alert-danger') ?>">
    <?=h($msg)?>
  </div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-header"><b>Выбор домена</b></div>
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="page" value="buy_gen2">
      <div class="col-md-6">
        <label class="form-label small text-muted mb-0">Домен</label>
        <select class="form-select" name="domain_id" onchange="this.form.submit()">
          <option value="0">— выбрать —</option>
          <?php foreach ($domains as $d): ?>
            <option value="<?=h($d['id'])?>" <?=((int)$d['id']===$sel_domain_id)?'selected':''?>><?=h($d['domain'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 d-flex gap-2">
        <button class="btn btn-outline-primary">Показать</button>
        <a class="btn btn-outline-secondary" href="menu.php?page=buy_gen2">Сброс</a>
      </div>
    </form>

    <?php if ($sel_domain_id>0): ?>
      <hr>
      <div class="row g-2">
        <div class="col-md-6">
          <div class="p-2 border rounded">
            <div><b>Cost job</b></div>
            <?php if ($costPick): ?>
              <div class="small text-muted">connector: <code><?=h((string)$costPick['connector'])?></code></div>
              <pre style="max-height:180px;overflow:auto;background:#f7f7f7;border:1px solid #eee;padding:8px;border-radius:6px;"><?=h((string)$costPick['params_json'])?></pre>
            <?php else: ?>
              <div class="text-danger">нет cost-job</div>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-md-6">
          <div class="p-2 border rounded">
            <div><b>Revenue job</b> (TacoLoco)</div>
            <?php if ($revPick): ?>
              <div class="small text-muted">connector: <code><?=h((string)$revPick['connector'])?></code></div>
              <pre style="max-height:180px;overflow:auto;background:#f7f7f7;border:1px solid #eee;padding:8px;border-radius:6px;"><?=h((string)$revPick['params_json'])?></pre>
            <?php else: ?>
              <div class="text-danger">нет revenue-job tacoloco_revenue</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <hr>

      <form method="post" onsubmit="return confirm('Перезаписать /var/www/<?=h($domainSafe)?>/cmd/pages/buy_gen2.php ?');">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="do_generate" value="1">
        <input type="hidden" name="domain_id" value="<?=h((string)$sel_domain_id)?>">
        <button class="btn btn-success" <?=(!$costPick || !$revPick || $domainSafe==='')?'disabled':''?>>
          СГЕНЕРИРОВАТЬ / ОБНОВИТЬ GEO PLANNER
        </button>
        <span class="small text-muted ms-2">target: <code>/var/www/<?=h($domainSafe)?>/cmd/pages/buy_gen2.php</code></span>
        <div class="small text-muted mt-2">
          Открывать на домене: <code>https://<?=h($domainSafe)?>/cmd/pages/buy_gen2.php</code>
        </div>
      </form>

      <?php if ($preview): ?>
        <div class="mt-3">
          <div class="small text-muted mb-1"><b>Preview (что записали):</b></div>
          <pre style="max-height:360px;overflow:auto;background:#0b1020;color:#d7e0ff;border-radius:8px;padding:10px;"><?=h($preview)?></pre>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

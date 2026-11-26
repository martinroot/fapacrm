<?php
declare(strict_types=1);

/**
 * pages/mvp_buy_gen.php
 *
 * Генератор (CRM) -> пишет /var/www/{domain}/cmd/pages/campaign_851694.php
 * ВНУТРИ лежит новый алгоритм (с daily reset / meta / hard block / BID_*).
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
function load_site_db_cfg(string $domain): array {
  $p = "/var/www/{$domain}/cmd/config.user.php";
  if (!is_file($p)) return [];
  $cfg = @require $p;
  if (!is_array($cfg)) return [];
  $db = $cfg['DB'] ?? null;
  if (!is_array($db)) return [];
  $host = (string)($db['host'] ?? '');
  $user = (string)($db['user'] ?? '');
  $pass = (string)($db['pass'] ?? '');
  $name = (string)($db['name'] ?? '');
  if ($host==='' || $user==='' || $name==='') return [];
  return ['host'=>$host,'user'=>$user,'pass'=>$pass,'name'=>$name];
}

/** ---------- builder (NOWDOC + strtr) ---------- */
function build_campaign_851694_php(array $costJob, array $revJob, array $siteDb): string
{
  $costP = decode_params((string)($costJob['params_json'] ?? '{}'));
  $revP  = decode_params((string)($revJob['params_json'] ?? '{}'));

  $k_app  = (int)($costP['app_id'] ?? 0);
  $k_cli  = (int)($costP['client_id'] ?? 0);
  $k_bear = (string)($costP['bearer'] ?? '');
  $k_tz   = (int)($costP['timezone'] ?? 3);
  $k_camp = (int)($costP['campaign_id'] ?? 0);

  $t_token = (string)($revP['token'] ?? '');
  $t_base  = (string)($revP['base_url'] ?? 'https://api.tacolo.co');
  $t_tz    = (string)($revP['timezone'] ?? 'Europe/Berlin');

  $t_pl = $revP['placements'] ?? [];
  if (!is_array($t_pl)) $t_pl = [];
  $t_pl_json = json_encode($t_pl, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if ($t_pl_json === false) $t_pl_json = '[]';

  $db_host = (string)($siteDb['host'] ?? '');
  $db_user = (string)($siteDb['user'] ?? '');
  $db_pass = (string)($siteDb['pass'] ?? '');
  $db_name = (string)($siteDb['name'] ?? '');

  // IMPORTANT: This template is written to /var/www/{domain}/cmd/pages/campaign_851694.php
  $tpl = <<<'PHP'
<?php
declare(strict_types=1);

/**
 * BUY ALGO (Hot-fix for running inside menu.php include)
 *
 * Fixes:
 *  - no fatal redeclare (all functions guarded)
 *  - no "headers already sent" breakage: redirect falls back to JS
 *  - when included from menu.php: does NOT output <html><head><body> wrapper (only content)
 *  - config values are defined via define() (no const redeclare issues)
 */

ini_set('display_errors','1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

/*** CONFIG (auto) ***/
if (!defined('KADAM_APP_ID'))      define('KADAM_APP_ID', __KADAM_APP_ID__);
if (!defined('KADAM_CLIENT_ID'))   define('KADAM_CLIENT_ID', __KADAM_CLIENT_ID__);
if (!defined('KADAM_BEARER'))      define('KADAM_BEARER', '__KADAM_BEARER__');
if (!defined('KADAM_TZ'))          define('KADAM_TZ', __KADAM_TZ__);
if (!defined('KADAM_CAMPAIGN_ID')) define('KADAM_CAMPAIGN_ID', __KADAM_CAMPAIGN_ID__);

if (!defined('TACO_TOKEN'))    define('TACO_TOKEN', '__TACO_TOKEN__');
if (!defined('TACO_BASE_URL')) define('TACO_BASE_URL', '__TACO_BASE_URL__');
if (!defined('TACO_TZ'))       define('TACO_TZ', '__TACO_TZ__');
// !!! ВАЖНО: валидный JSON
if (!defined('TACO_PL_JSON'))  define('TACO_PL_JSON', '__TACO_PL_JSON__');

if (!defined('DB_HOST')) define('DB_HOST','__DB_HOST__');
if (!defined('DB_USER')) define('DB_USER','__DB_USER__');
if (!defined('DB_PASS')) define('DB_PASS','__DB_PASS__');
if (!defined('DB_NAME')) define('DB_NAME','__DB_NAME__');

if (!defined('TACO_SLEEP_MIN_SEC')) define('TACO_SLEEP_MIN_SEC', 1);
if (!defined('TACO_SLEEP_MAX_SEC')) define('TACO_SLEEP_MAX_SEC', 2);

/*** HARD BLOCK ***/
if (!defined('HARD_BLOCK_CC')) define('HARD_BLOCK_CC', ['IN'=>1]);

/*** BIDS ***/
if (!defined('BID_MIN'))  define('BID_MIN', 0.00001);
if (!defined('BID_MAX'))  define('BID_MAX', 0.00030);
if (!defined('BID_STEP')) define('BID_STEP', 0.00001);

// старт теста
if (!defined('TEST_START_BID')) define('TEST_START_BID', 0.00007);

/*** detect include (menu.php require) ***/
$is_included = false;
if (!empty($_SERVER['SCRIPT_FILENAME'])) {
  $is_included = (realpath(__FILE__) !== realpath((string)$_SERVER['SCRIPT_FILENAME']));
}

/*** tiny utils (guarded) ***/
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

if (!function_exists('db')) {
  function db(): mysqli {
    static $m;
    if ($m instanceof mysqli) return $m;
    $m = new mysqli((string)DB_HOST, (string)DB_USER, (string)DB_PASS, (string)DB_NAME);
    if ($m->connect_error) throw new RuntimeException('DB: '.$m->connect_error);
    $m->set_charset('utf8mb4');
    return $m;
  }
}

if (!function_exists('taco_sleep')) {
  function taco_sleep(): void {
    $a = (int)TACO_SLEEP_MIN_SEC; $b = (int)TACO_SLEEP_MAX_SEC;
    $s = ($a === $b) ? $a : random_int($a, $b);
    if ($s > 0) sleep($s);
  }
}

if (!function_exists('clampf')) {
  function clampf(float $x, float $lo, float $hi): float { return max($lo, min($hi, $x)); }
}

if (!function_exists('stepf')) {
  function stepf(float $x, float $st): float { return floor($x/$st + 1e-12) * $st; }
}

if (!function_exists('h')) {
  function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
  }
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
    if (!headers_sent()) {
      header('Location: '.$url);
    } else {
      echo '<script>location.href='.json_encode($url).';</script>';
    }
    exit;
  }
}

/*** state/meta tables ***/
if (!function_exists('ensure_state_table')) {
  function ensure_state_table(mysqli $m): void {
    $m->query("CREATE TABLE IF NOT EXISTS buy_state_country(
      cc CHAR(2) PRIMARY KEY,
      mode VARCHAR(16) NOT NULL DEFAULT 'upbid',
      bid DECIMAL(10,6) NOT NULL DEFAULT 0.000010,
      last_raise_ts INT NOT NULL DEFAULT 0,
      last_check_ts INT NOT NULL DEFAULT 0,
      last_clicks   INT NOT NULL DEFAULT 0,
      last_spend    DECIMAL(14,6) NOT NULL DEFAULT 0.000000,
      last_rev      DECIMAL(14,6) NOT NULL DEFAULT 0.000000,
      test_clicks0  INT NOT NULL DEFAULT 0,
      last_day      CHAR(10) NOT NULL DEFAULT '',
      scale_start_ts   INT NOT NULL DEFAULT 0,
      scale_from_bid   DECIMAL(10,6) NOT NULL DEFAULT 0.000010,
      scale_target_bid DECIMAL(10,6) NOT NULL DEFAULT 0.000010
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @ $m->query("ALTER TABLE buy_state_country ADD COLUMN last_day CHAR(10) NOT NULL DEFAULT ''");
    @ $m->query("ALTER TABLE buy_state_country ADD COLUMN scale_start_ts INT NOT NULL DEFAULT 0");
    @ $m->query("ALTER TABLE buy_state_country ADD COLUMN scale_from_bid DECIMAL(10,6) NOT NULL DEFAULT 0.000010");
    @ $m->query("ALTER TABLE buy_state_country ADD COLUMN scale_target_bid DECIMAL(10,6) NOT NULL DEFAULT 0.000010");
  }
}

if (!function_exists('ensure_meta_table')) {
  function ensure_meta_table(mysqli $m): void {
    $m->query("CREATE TABLE IF NOT EXISTS buy_state_meta(
      k VARCHAR(64) PRIMARY KEY,
      v VARCHAR(255) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
}

if (!function_exists('meta_get')) {
  function meta_get(mysqli $m, string $k): string {
    $k = $m->real_escape_string($k);
    $res = $m->query("SELECT v FROM buy_state_meta WHERE k='$k' LIMIT 1");
    if ($res && ($r = $res->fetch_assoc())) return (string)$r['v'];
    return '';
  }
}

if (!function_exists('meta_set')) {
  function meta_set(mysqli $m, string $k, string $v): void {
    $k = $m->real_escape_string($k);
    $v = $m->real_escape_string($v);
    $m->query("INSERT INTO buy_state_meta(k,v) VALUES('$k','$v')
      ON DUPLICATE KEY UPDATE v=VALUES(v)");
  }
}

/** settings mini-table */
if (!function_exists('ensure_settings_table')) {
  function ensure_settings_table(mysqli $m): void {
    $m->query("CREATE TABLE IF NOT EXISTS buy_settings(
      k VARCHAR(64) PRIMARY KEY,
      v VARCHAR(255) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
}

if (!function_exists('set_get')) {
  function set_get(mysqli $m, string $k, string $def=''): string {
    $k = $m->real_escape_string($k);
    $res = $m->query("SELECT v FROM buy_settings WHERE k='$k' LIMIT 1");
    if ($res && ($r = $res->fetch_assoc())) return (string)$r['v'];
    return $def;
  }
}

if (!function_exists('set_set')) {
  function set_set(mysqli $m, string $k, string $v): void {
    $k = $m->real_escape_string($k);
    $v = $m->real_escape_string($v);
    $m->query("INSERT INTO buy_settings(k,v) VALUES('$k','$v')
      ON DUPLICATE KEY UPDATE v=VALUES(v)");
  }
}

if (!function_exists('set_get_float')) {
  function set_get_float(mysqli $m, string $k, float $def): float {
    $v = trim(set_get($m, $k, ''));
    if ($v === '') return $def;
    $v = str_replace(',','.', $v);
    return is_numeric($v) ? (float)$v : $def;
  }
}

/** daily reset */
if (!function_exists('daily_global_reset')) {
  function daily_global_reset(mysqli $m, string $today_cost, int $now): void {
    $d = $m->real_escape_string($today_cost);
    $start = number_format((float)TEST_START_BID, 6, '.', '');
    $m->query("UPDATE buy_state_country SET
      mode='upbid',
      bid={$start},
      last_raise_ts={$now},
      last_check_ts=0,
      last_clicks=0,
      last_spend=0.0,
      last_rev=0.0,
      test_clicks0=0,
      last_day='{$d}',
      scale_start_ts=0,
      scale_from_bid={$start},
      scale_target_bid={$start}
    ");
    $m->query("DELETE FROM buy_state_country WHERE cc='IN'");
  }
}

/*** load/save state ***/
if (!function_exists('st_load_all')) {
  function st_load_all(mysqli $m): array {
    $out = [];
    $res = $m->query("SELECT * FROM buy_state_country");
    while ($res && ($r = $res->fetch_assoc())) {
      $cc = strtoupper(trim((string)$r['cc']));
      $out[$cc] = [
        'mode'=>(string)$r['mode'],
        'bid'=>(float)$r['bid'],
        'last_raise_ts'=>(int)$r['last_raise_ts'],
        'last_check_ts'=>(int)$r['last_check_ts'],
        'last_clicks'=>(int)$r['last_clicks'],
        'last_spend'=>(float)$r['last_spend'],
        'last_rev'=>(float)$r['last_rev'],
        'test_clicks0'=>(int)$r['test_clicks0'],
        'last_day'=>(string)($r['last_day'] ?? ''),
        'scale_start_ts'=>(int)($r['scale_start_ts'] ?? 0),
        'scale_from_bid'=>(float)($r['scale_from_bid'] ?? (float)TEST_START_BID),
        'scale_target_bid'=>(float)($r['scale_target_bid'] ?? (float)TEST_START_BID),
      ];
    }
    return $out;
  }
}

if (!function_exists('st_upsert')) {
  function st_upsert(mysqli $m, string $cc, array $st): void {
    $cc = strtoupper(trim($cc)); if ($cc === '') return;
    $cc_e = $m->real_escape_string($cc);
    $mode_e = $m->real_escape_string((string)$st['mode']);

    $bid = number_format((float)$st['bid'],6,'.','');
    $lr  = (int)$st['last_raise_ts'];
    $lc  = (int)$st['last_check_ts'];
    $lcl = (int)$st['last_clicks'];
    $lsp = number_format((float)$st['last_spend'],6,'.','');
    $lrv = number_format((float)$st['last_rev'],6,'.','');
    $tc0 = (int)$st['test_clicks0'];
    $ld  = $m->real_escape_string((string)($st['last_day'] ?? ''));

    $ss  = (int)($st['scale_start_ts'] ?? 0);
    $sfb = number_format((float)($st['scale_from_bid'] ?? (float)TEST_START_BID),6,'.','');
    $stb = number_format((float)($st['scale_target_bid'] ?? (float)TEST_START_BID),6,'.','');

    $m->query("INSERT INTO buy_state_country(
        cc,mode,bid,last_raise_ts,last_check_ts,last_clicks,last_spend,last_rev,test_clicks0,last_day,
        scale_start_ts,scale_from_bid,scale_target_bid
      ) VALUES(
        '$cc_e','$mode_e',$bid,$lr,$lc,$lcl,$lsp,$lrv,$tc0,'$ld',
        $ss,$sfb,$stb
      )
      ON DUPLICATE KEY UPDATE
        mode=VALUES(mode),bid=VALUES(bid),
        last_raise_ts=VALUES(last_raise_ts),last_check_ts=VALUES(last_check_ts),
        last_clicks=VALUES(last_clicks),last_spend=VALUES(last_spend),last_rev=VALUES(last_rev),
        test_clicks0=VALUES(test_clicks0),last_day=VALUES(last_day),
        scale_start_ts=VALUES(scale_start_ts),scale_from_bid=VALUES(scale_from_bid),scale_target_bid=VALUES(scale_target_bid)
    ");
  }
}

/*** GEO (kadam_geolist in SITE DB) ***/
if (!function_exists('load_geo')) {
  function load_geo(mysqli $m): array {
    $rid2cc=[]; $cc2rid=[]; $all=[];
    $res = $m->query("SELECT kadam_id,cc FROM kadam_geolist WHERE kadam_id>0 AND cc<>''");
    while ($res && ($r=$res->fetch_assoc())) {
      $rid=(int)$r['kadam_id']; $cc=strtoupper(trim((string)$r['cc']));
      if ($cc==='' || (is_array(HARD_BLOCK_CC) && isset(HARD_BLOCK_CC[$cc]))) continue;
      $rid2cc[$rid]=$cc; $cc2rid[$cc]=$rid; $all[$cc]=1;
    }
    return [$rid2cc,$cc2rid,array_keys($all)];
  }
}

/*** KADAM ***/
if (!function_exists('kadam_today')) {
  function kadam_today(array $rid2cc, string $d): array {
    $q=http_build_query([
      'app_id'=>KADAM_APP_ID,'client_id'=>KADAM_CLIENT_ID,'campaigns'=>(string)KADAM_CAMPAIGN_ID,
      'group'=>'region','date_from'=>$d,'date_to'=>$d,'timezone'=>KADAM_TZ,'limit'=>5000
    ],'','&',PHP_QUERY_RFC3986);

    $r=http_get('https://api.kadam.net/ads.stats.campaign.get?'.$q, ['Authorization: Bearer '.KADAM_BEARER], 45);
    $j=json_decode($r['raw'], true); $by=[];
    foreach (($j['response']['items'] ?? []) as $it) {
      $rid=(int)($it['region_id']??0); if ($rid<=0) continue;
      $cc=$rid2cc[$rid] ?? '';
      if ($cc==='' || (is_array(HARD_BLOCK_CC) && isset(HARD_BLOCK_CC[$cc]))) continue;

      $by[$cc]['clicks']=($by[$cc]['clicks']??0)+(int)($it['clicks']??0);
      $by[$cc]['spend']=($by[$cc]['spend']??0.0)+(float)($it['moneyOut']??0.0);
    }
    return ['by'=>$by,'dbg'=>['http'=>$r['http'],'err'=>$r['err'],'url'=>$r['url']]];
  }
}

if (!function_exists('kadam_update')) {
  function kadam_update(array $ct): array {
    $payload=['client_id'=>KADAM_CLIENT_ID,'campaign_id'=>KADAM_CAMPAIGN_ID,'countryTarget'=>$ct];
    $c=curl_init('https://api.kadam.net/ads.campaigns.update');
    curl_setopt_array($c,[
      CURLOPT_POST=>1, CURLOPT_RETURNTRANSFER=>1,
      CURLOPT_HTTPHEADER=>[
        'Authorization: Bearer '.KADAM_BEARER,
        'Content-Type: application/x-www-form-urlencoded; charset=utf-8'
      ],
      CURLOPT_POSTFIELDS=>http_build_query(['app_id'=>KADAM_APP_ID,'data'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],'','&'),
      CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>45
    ]);
    $raw=curl_exec($c); $err=curl_error($c); $hc=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);
    curl_close($c);
    return ['http'=>$hc,'err'=>$err?:null,'raw'=>$raw===false?'':(string)$raw];
  }
}

/*** TACO ***/
if (!function_exists('taco_country_range')) {
  function taco_country_range(string $from, string $to, string $type, array $extra=[]): array {
    $type = ($type==='inpagepush') ? 'inpagepush' : 'push';
    $q = http_build_query($extra + ['token'=>TACO_TOKEN,'startDate'=>$from,'endDate'=>$to],'','&',PHP_QUERY_RFC3986);
    $url = rtrim((string)TACO_BASE_URL,'/')."/publisher/v1/statistics/{$type}/country?".$q;
    $r = http_get($url, ['accept: */*'], 45);
    return ['http'=>$r['http'],'raw'=>$r['raw'],'err'=>$r['err'],'url'=>$url];
  }
}

if (!function_exists('taco_parse')) {
  function taco_parse(string $raw): array {
    $j=json_decode($raw,true); $out=[];
    foreach ((array)$j as $row) {
      $cc=strtoupper(trim((string)($row['Code2']??$row['code2']??$row['Code']??$row['country']??'')));
      if ($cc==='' || (is_array(HARD_BLOCK_CC) && isset(HARD_BLOCK_CC[$cc]))) continue;
      $rev=(float)($row['Revenue']??$row['revenue']??0.0);
      $impr=(int)($row['Impressions']??$row['impressions']??0);
      $out[$cc]['rev']=($out[$cc]['rev']??0.0)+$rev;
      $out[$cc]['impr']=($out[$cc]['impr']??0)+$impr;
    }
    return $out;
  }
}

if (!function_exists('taco_account_range')) {
  function taco_account_range(string $from, string $to): array {
    $byTotal=[]; $byType=['push'=>[],'inpagepush'=>[]]; $calls=[];
    foreach (['push','inpagepush'] as $type) {
      $resp=taco_country_range($from,$to,$type,[]);
      $calls[]=['type'=>$type,'http'=>$resp['http'],'err'=>$resp['err']];
      if ($resp['http']>=200 && $resp['http']<300) {
        $part=taco_parse($resp['raw']);
        foreach ($part as $cc=>$d) {
          $byTotal[$cc]['rev']=($byTotal[$cc]['rev']??0.0)+(float)$d['rev'];
          $byTotal[$cc]['impr']=($byTotal[$cc]['impr']??0)+(int)$d['impr'];
          $byType[$type][$cc]['rev']=($byType[$type][$cc]['rev']??0.0)+(float)$d['rev'];
          $byType[$type][$cc]['impr']=($byType[$type][$cc]['impr']??0)+(int)$d['impr'];
        }
      }
      taco_sleep();
    }
    return ['by_total'=>$byTotal,'by_type'=>$byType,'dbg'=>['calls'=>$calls]];
  }
}

if (!function_exists('taco_site_placements_range')) {
  function taco_site_placements_range(string $from, string $to): array {
    $pls=json_decode((string)TACO_PL_JSON,true);
    if (!is_array($pls) || !count($pls)) {
      $acc=taco_account_range($from,$to);
      $acc['dbg']['fallback']='account';
      return ['by_total'=>$acc['by_total'],'by_type'=>$acc['by_type'],'dbg'=>$acc['dbg']];
    }
    $byTotal=[]; $byType=['push'=>[],'inpagepush'=>[]]; $calls=[];
    foreach ($pls as $pl) {
      $id=(int)($pl['id']??0); if ($id<=0) continue;
      $type=(($pl['type']??'push')==='inpagepush')?'inpagepush':'push';
      $resp=taco_country_range($from,$to,$type,['placement'=>$id]);
      $calls[]=['pl'=>$id,'type'=>$type,'http'=>$resp['http'],'err'=>$resp['err']];
      if ($resp['http']<200||$resp['http']>=300) { taco_sleep(); continue; }
      $part=taco_parse($resp['raw']);
      foreach ($part as $cc=>$d) {
        $byTotal[$cc]['rev']=($byTotal[$cc]['rev']??0.0)+(float)$d['rev'];
        $byTotal[$cc]['impr']=($byTotal[$cc]['impr']??0)+(int)$d['impr'];
        $byType[$type][$cc]['rev']=($byType[$type][$cc]['rev']??0.0)+(float)$d['rev'];
        $byType[$type][$cc]['impr']=($byType[$type][$cc]['impr']??0)+(int)$d['impr'];
      }
      taco_sleep();
    }
    return ['by_total'=>$byTotal,'by_type'=>$byType,'dbg'=>['calls'=>$calls,'fallback'=>null]];
  }
}

/*** ALGO ***/
if (!function_exists('algo_apply')) {
  function algo_apply(array $ctx): array {
    $allcc   = $ctx['geo']['all_cc'];
    $cc2rid  = $ctx['geo']['cc_to_kadam_id'];
    $kT      = $ctx['kadam']['today'];

    $tT_total  = $ctx['taco']['today_total'];
    $t30_total = $ctx['taco']['acc30_total'];

    $stAll = $ctx['state'];
    $now = time();

    $UPBID_STEP  = 0.00001;
    $UPBID_EVERY = 15*60;
    $TEST_MIN_CLICKS = 100;

    // ramp: 10 шагов, каждые 10 минут => 100 минут
    $RAMP_EVERY = 10*60;
    $RAMP_STEPS = 10;

    $roi = function(float $rev, float $sp): ?float {
      return $sp>0 ? (($rev-$sp)/$sp*100.0) : null;
    };

    $cpm_thr = (float)($ctx['settings']['cpm_thr'] ?? 0.0);

    $rows=[]; $ct=[]; $state_updates=[];
    $sum_spend=0.0; $sum_rev=0.0;

    foreach ($allcc as $cc0) {
      $cc=strtoupper(trim((string)$cc0));
      if ($cc==='' || (is_array(HARD_BLOCK_CC) && isset(HARD_BLOCK_CC[$cc]))) continue;

      $clkT=(int)($kT[$cc]['clicks']??0);
      $spT=(float)($kT[$cc]['spend']??0.0);
      $sum_spend += $spT;

      $rvT_total=(float)($tT_total[$cc]['rev']??0.0);
      $sum_rev += $rvT_total;

      $roiT=$roi($rvT_total,$spT);

      $rev30=(float)($t30_total[$cc]['rev']??0.0);
      $impr30=(int)($t30_total[$cc]['impr']??0);
      $eCPM30=($impr30>0)?($rev30/$impr30*1000):0.0;

      $allow = ($eCPM30 >= $cpm_thr) || ($rvT_total > 0.0);

      $st=$stAll[$cc] ?? [
        'mode'=>'upbid','bid'=>(float)TEST_START_BID,'last_raise_ts'=>0,'last_check_ts'=>0,'last_clicks'=>0,'last_spend'=>0.0,'last_rev'=>0.0,
        'test_clicks0'=>0,'last_day'=>'',
        'scale_start_ts'=>0,'scale_from_bid'=>(float)TEST_START_BID,'scale_target_bid'=>(float)TEST_START_BID
      ];
      $mode=(string)$st['mode'];

      $bid_state=(float)$st['bid'];
      $bid_state=clampf($bid_state,(float)BID_MIN,(float)BID_MAX);
      $bid_state=stepf($bid_state,(float)BID_STEP);

      if ($mode==='upbid' || $mode==='test') {
        if ($bid_state < (float)TEST_START_BID) $bid_state = (float)TEST_START_BID;
      }

      if (!$allow) {
        $mode='bad';
        $bid_state=(float)BID_MIN;
        $st['scale_start_ts']=0;
        $st['scale_from_bid']=(float)TEST_START_BID;
        $st['scale_target_bid']=(float)TEST_START_BID;
      } else {
        if ($mode==='upbid' && $clkT>0) {
          $mode='test';
          $st['test_clicks0']=0;
          $bid_state=max($bid_state, (float)TEST_START_BID);
        }

        $test_clicks = ($mode==='test') ? $clkT : 0;

        if ($mode==='upbid' && $clkT===0) {
          if (((int)$st['last_raise_ts']===0) || ($now-(int)$st['last_raise_ts'] >= $UPBID_EVERY)) {
            $bid_state=clampf($bid_state+$UPBID_STEP,(float)TEST_START_BID,(float)BID_MAX);
            $bid_state=stepf($bid_state,(float)BID_STEP);
            $st['last_raise_ts']=$now;
          }
        }

        if ($mode==='test') {
          if ($test_clicks >= $TEST_MIN_CLICKS) {
            if ($roiT !== null && $roiT > 0.0) {
              $mode='scale';

              $from = clampf(stepf($bid_state,(float)BID_STEP), (float)BID_MIN, (float)BID_MAX);
              $target = $from;

              // Bid_for_ROI_100% (модель spend ~ bid)
              if ($spT>0.000001 && $rvT_total>0.0) {
                $mult = $rvT_total / (2.0*$spT);
                if ($mult < 1.0) $mult = 1.0;
                $target = $from * $mult;
              }

              $target = clampf(stepf($target,(float)BID_STEP), (float)BID_MIN, (float)BID_MAX);

              $st['scale_start_ts']=$now;
              $st['scale_from_bid']=$from;
              $st['scale_target_bid']=$target;

              $bid_state=$from;
            } else {
              $mode='bad';
              $bid_state=(float)BID_MIN;
              $st['scale_start_ts']=0;
              $st['scale_from_bid']=(float)TEST_START_BID;
              $st['scale_target_bid']=(float)TEST_START_BID;
            }
          }
        }

        if ($mode==='bad') {
          if ($roiT!==null && $roiT>0.0) {
            $mode='scale';
            $from = clampf(stepf($bid_state,(float)BID_STEP), (float)BID_MIN, (float)BID_MAX);
            if ($from < (float)BID_MIN) $from = (float)BID_MIN;
            $target = $from;

            if ($spT>0.000001 && $rvT_total>0.0) {
              $mult = $rvT_total / (2.0*$spT);
              if ($mult < 1.0) $mult = 1.0;
              $target = $from * $mult;
            }

            $target = clampf(stepf($target,(float)BID_STEP), (float)BID_MIN, (float)BID_MAX);
            $st['scale_start_ts']=$now;
            $st['scale_from_bid']=$from;
            $st['scale_target_bid']=$target;
            $bid_state=$from;
          }
        }

        if ($mode==='scale') {
          $from   = clampf(stepf((float)($st['scale_from_bid'] ?? $bid_state),(float)BID_STEP), (float)BID_MIN, (float)BID_MAX);
          $target = clampf(stepf((float)($st['scale_target_bid'] ?? $from),(float)BID_STEP), (float)BID_MIN, (float)BID_MAX);
          $start  = (int)($st['scale_start_ts'] ?? $now);

          if ($target <= $from) {
            $bid_state = $target;
            $st['scale_from_bid']=$bid_state;
            $st['scale_start_ts']=$now;
          } else {
            $passed=max(0, intdiv(max(0,$now-$start), $RAMP_EVERY));
            if ($passed >= $RAMP_STEPS) {
              $bid_state=$target;
              $st['scale_from_bid']=$bid_state;
              $st['scale_start_ts']=$now;
            } else {
              $bid_state = $from + ($target-$from)*($passed/$RAMP_STEPS);
              $bid_state = clampf(stepf($bid_state,(float)BID_STEP), (float)BID_MIN, (float)BID_MAX);
            }
          }
        } else {
          $st['scale_start_ts']=0;
          $st['scale_from_bid']=(float)TEST_START_BID;
          $st['scale_target_bid']=(float)TEST_START_BID;
        }
      }

      $status='LIMIT'; $bid=0.0;
      $test_clicks = ($mode==='test') ? $clkT : 0;

      if ($mode==='bad') {
        $status='OFF';
        $bid=(float)BID_MIN;
      } elseif ($mode==='test' || $mode==='upbid') {
        $status='LIMIT';
        $bid=$bid_state;
      } else {
        $bid=$bid_state;
        $status=($roiT!==null && $roiT>0.0)?'ON':'LIMIT';
      }

      $bid=clampf($bid,(float)BID_MIN,(float)BID_MAX);
      $bid=stepf($bid,(float)BID_STEP);
      $bid=(float)number_format($bid,6,'.','');
      $bid_state=(float)number_format($bid_state,6,'.','');

      $rid=(int)($cc2rid[$cc]??0);
      if ($rid>0) {
        $ct[$rid]=['bid'=>($status==='OFF'?(float)BID_MIN:$bid),'leadCost'=>0.01];
      }

      $st['mode']=$mode;
      $st['bid']=$bid_state;
      $st['last_check_ts']=$now;
      $st['last_clicks']=$clkT;
      $st['last_spend']=$spT;
      $st['last_rev']=$rvT_total;

      $state_updates[$cc]=$st;

      $rows[$cc]=[
        'cc'=>$cc,'mode'=>$mode,'test_clicks'=>$test_clicks,
        'clkT'=>$clkT,'spT'=>$spT,'rvT_total'=>$rvT_total,'roiT'=>$roiT,
        'eCPM30'=>$eCPM30,'allow'=>$allow?1:0,
        'bid'=>$bid,'bid_state'=>$bid_state,'status'=>$status
      ];
    }

    return [
      'rows'=>$rows,
      'countryTarget'=>$ct,
      'state_updates'=>$state_updates,
      'totals'=>['spend'=>$sum_spend,'rev'=>$sum_rev,'profit'=>$sum_rev-$sum_spend],
      'dbg'=>['ramp_steps'=>$RAMP_STEPS,'ramp_every'=>$RAMP_EVERY,'test_min_clicks'=>$TEST_MIN_CLICKS]
    ];
  }
}

/*** RUN ***/
try {
  $m=db();
  ensure_state_table($m);
  ensure_meta_table($m);
  ensure_settings_table($m);

  // SAVE SETTINGS (works inside menu too)
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['action'] ?? '') === 'save_settings') {
    $cpm = trim((string)($_POST['cpm_thr'] ?? ''));
    $ml  = trim((string)($_POST['max_loss'] ?? ''));
    $cpm = str_replace(',','.', $cpm);
    $ml  = str_replace(',','.', $ml);
    if ($cpm === '' || !is_numeric($cpm)) $cpm = (string)set_get_float($m,'cpm_thr',0.02);
    if ($ml === ''  || !is_numeric($ml))  $ml  = (string)set_get_float($m,'max_loss',10.0);

    set_set($m,'cpm_thr', (string)$cpm);
    set_set($m,'max_loss', (string)$ml);

    safe_redirect(self_url(['saved'=>'1']));
  }

  $cpm_thr  = set_get_float($m,'cpm_thr', 0.02);
  $max_loss = set_get_float($m,'max_loss', 10.0);

  $now=time();
  $today_cost=ymd('Europe/Moscow');
  $today_rev=ymd((string)TACO_TZ);

  $from30=ymd_days_ago((string)TACO_TZ, 29);

  $force_reset=((int)($_GET['reset']??0)===1);
  $reset_day=meta_get($m,'daily_reset_day');

  $did_reset=false;
  if ($force_reset || $reset_day!==$today_cost) {
    daily_global_reset($m,$today_cost,$now);
    meta_set($m,'daily_reset_day',$today_cost);
    $did_reset=true;
  }

  [$rid2cc,$cc2rid,$allcc]=load_geo($m);

  $kT=kadam_today($rid2cc,$today_cost);
  $tT=taco_site_placements_range($today_rev,$today_rev);
  $t30=taco_account_range($from30,$today_rev);

  $ctx=[
    'geo'=>['all_cc'=>$allcc,'cc_to_kadam_id'=>$cc2rid],
    'kadam'=>['today'=>$kT['by']],
    'taco'=>[
      'today_total'=>$tT['by_total'],'today_type'=>$tT['by_type'],
      'acc30_total'=>$t30['by_total'],'acc30_type'=>$t30['by_type'],
    ],
    'state'=>st_load_all($m),
    'settings'=>['cpm_thr'=>$cpm_thr,'max_loss'=>$max_loss],
    'meta'=>['today_cost'=>$today_cost,'today_rev'=>$today_rev,'from30'=>$from30,'daily_reset'=>$did_reset?1:0]
  ];

  if (($_GET['mode']??'')==='json') {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit;
  }

  $dec=algo_apply($ctx);

  // stop-loss by campaign
  $profit = (float)($dec['totals']['profit'] ?? 0.0);
  $loss   = max(0.0, -$profit);
  $stop_all = ($loss > $max_loss && $max_loss > 0);

  foreach ($dec['state_updates'] as $cc=>$st) {
    $st['last_day']=$today_cost;
    st_upsert($m,(string)$cc,(array)$st);
  }

  $apply=((int)($_GET['apply']??0)===1);

  $countryTarget = $dec['countryTarget'];
  if ($stop_all) {
    foreach ($countryTarget as $rid=>$v) {
      $countryTarget[$rid]['bid']=(float)BID_MIN;
    }
  }

  $upd=$apply ? kadam_update($countryTarget) : null;

  if (!$is_included) {
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html><head><meta charset="utf-8"><title>MVP Buy Algo компакт</title><?php
  }

  ?>
  <style>
    body{font:13px system-ui;margin:16px}
    a{color:#0b65c2;text-decoration:none}
    a:hover{text-decoration:underline}
    table{border-collapse:collapse}
    td,th{border:1px solid #ddd;padding:4px 6px;text-align:right;white-space:nowrap}
    th:first-child,td:first-child{text-align:left}
    .on{background:#eaffea}.lim{background:#fff6da}.off{background:#ffecec}
    .stop{background:#ffd6d6}
    pre{background:#111;color:#eee;padding:10px;max-height:320px;overflow:auto}
    .topbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
    .pill{display:inline-block;padding:4px 8px;border:1px solid #ddd;border-radius:999px;background:#fafafa}
    .danger{border-color:#ff6b6b;background:#fff0f0}
    .settings{margin:10px 0 14px 0}
    .settings td,.settings th{text-align:left}
    .settings input{width:120px;padding:4px 6px}
    .btn{padding:5px 10px;border:1px solid #bbb;border-radius:8px;background:#f7f7f7;cursor:pointer}
  </style>

  <div class="topbar">
    <span class="pill">cost=<b><?=h($today_cost)?></b></span>
    <span class="pill">rev=<b><?=h($today_rev)?></b></span>
    <span class="pill">acc30=<b><?=h($from30)?>→<?=h($today_rev)?></b></span>
    <span class="pill">apply=<b><?= $apply?'1':'0' ?></b></span>
    <span class="pill">sent=<b><?=count($countryTarget)?></b></span>
    <span class="pill">daily_reset=<b><?= (int)($ctx['meta']['daily_reset']??0) ?></b></span>

    <span class="pill">Spend=<b><?=number_format((float)$dec['totals']['spend'],4,'.','')?></b></span>
    <span class="pill">Rev=<b><?=number_format((float)$dec['totals']['rev'],6,'.','')?></b></span>
    <span class="pill <?= $stop_all?'danger':'' ?>">Profit=<b><?=number_format((float)$profit,4,'.','')?></b></span>

    <?php if($ctx['meta']['daily_reset']??0): ?><span class="pill danger"><b>RESET DONE</b></span><?php endif; ?>
    <?php if($stop_all): ?><span class="pill danger"><b>STOP-LOSS TRIGGERED</b> (loss <?=number_format($loss,4,'.','')?> &gt; max_loss <?=number_format($max_loss,4,'.','')?>)</span><?php endif; ?>

    <span style="flex:1 1 auto"></span>

    <a class="pill" href="<?=h(self_url(['reset'=>1]))?>">Сбросить</a>
    <a class="pill" href="<?=h(self_url(['apply'=>1]))?>">Применить</a>
    <a class="pill" href="<?=h(self_url(['reset'=>1,'apply'=>1]))?>">Сбросить+Применить</a>
    <a class="pill" href="<?=h(self_url(['mode'=>'json']))?>" target="_blank">JSON</a>
  </div>

  <form method="post" class="settings">
    <input type="hidden" name="action" value="save_settings">
    <table class="settings">
      <tr><th colspan="4">Настройки</th></tr>
      <tr>
        <td><b>CPM threshold (eCPM30)</b></td>
        <td><input name="cpm_thr" value="<?=h((string)$cpm_thr)?>"></td>
        <td><b>Max loss (stop-loss, $)</b></td>
        <td><input name="max_loss" value="<?=h((string)$max_loss)?>"></td>
      </tr>
      <tr>
        <td colspan="4">
          <button class="btn" type="submit">Сохранить</button>
          <span class="pill">TEST_START_BID=<b><?=number_format((float)TEST_START_BID,6,'.','')?></b></span>
        </td>
      </tr>
    </table>
  </form>

  <table>
    <tr>
      <th>CC</th><th>Mode</th><th>ClkT</th><th>SpT</th><th>RvT</th><th>ROI</th>
      <th>TestClk</th><th>eCPM30</th><th>Allow</th><th>Bid</th><th>BidState</th><th>Status</th>
    </tr>
    <?php foreach($dec['rows'] as $r):
      $status = (string)$r['status'];
      if($stop_all) $status = 'STOP';
      $cls=($status==='ON'?'on':($status==='LIMIT'?'lim':($status==='STOP'?'stop':'off')));
    ?>
      <tr class="<?=$cls?>">
        <td><?=h($r['cc'])?></td>
        <td><?=h($r['mode'])?></td>
        <td><?=intval($r['clkT'])?></td>
        <td><?=number_format((float)$r['spT'],4,'.','')?></td>
        <td><?=number_format((float)$r['rvT_total'],6,'.','')?></td>
        <td><?=($r['roiT']===null?'-':number_format((float)$r['roiT'],2,'.',''))?></td>
        <td><?=intval($r['test_clicks'])?></td>
        <td><?=number_format((float)$r['eCPM30'],4,'.','')?></td>
        <td><?=intval($r['allow'])?></td>
        <td><?=number_format((float)$r['bid'],6,'.','')?></td>
        <td><?=number_format((float)$r['bid_state'],6,'.','')?></td>
        <td><?=h($status)?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <pre><?=h(json_encode([
    'kadam_dbg'=>$kT['dbg'],
    'taco_today_dbg'=>$tT['dbg'],
    'taco_acc30_dbg'=>$t30['dbg'],
    'apply'=>$apply,
    'countryTarget_cnt'=>count($countryTarget),
    'stop_all'=>$stop_all ? 1 : 0,
    'stop_loss'=>['max_loss'=>$max_loss,'loss'=>$loss,'profit'=>$profit],
    'kadam_update'=>$upd,
    'algo_dbg'=>$dec['dbg'],
    'meta'=>$ctx['meta'],
    'settings'=>$ctx['settings'],
    'meta_reset_day'=>meta_get($m,'daily_reset_day')
  ],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))?></pre>
  <?php

  if (!$is_included) {
    ?></head><body></body></html><?php
  }

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

    '__DB_HOST__'           => addslashes($db_host),
    '__DB_USER__'           => addslashes($db_user),
    '__DB_PASS__'           => addslashes($db_pass),
    '__DB_NAME__'           => addslashes($db_name),
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
$revPick  = pick_job($revJobs,  ['tacoloco_revenue','clickadu_revenue','mybid_revenue','kadam_pub_revenue']);

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
    $msg = 'Ошибка: нет enabled revenue-job (ожидаю tacoloco_revenue / clickadu / mybid / kadam_pub)';
  } else {
    $siteDb = load_site_db_cfg($domainSafe);
    if (!$siteDb) {
      $msg = 'Ошибка: не прочитал DB из /var/www/'.$domainSafe.'/cmd/config.user.php';
    } else {
      $target = '/var/www/' . $domainSafe . '/cmd/pages/campaign_851694.php';
      $dir = dirname($target);
      if (!is_dir($dir)) {
        $msg = 'Ошибка: нет каталога ' . $dir;
      } else {
        $php = build_campaign_851694_php($costPick, $revPick, $siteDb);
        $preview = $php;

        if (is_file($target)) @copy($target, $target.'.bak.'.date('Ymd_His'));
        $ok = @file_put_contents($target, $php, LOCK_EX);

        $msg = ($ok === false) ? ('Ошибка записи: ' . $target) : ('OK: записан ' . $target . ' (' . (int)$ok . ' bytes)');
      }
    }
  }
}

?>


<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">🧰 MVP Buy Algo Generator</h3>
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
      <input type="hidden" name="page" value="mvp_buy_gen">
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
        <a class="btn btn-outline-secondary" href="menu.php?page=mvp_buy_gen">Сброс</a>
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
            <div><b>Revenue job</b></div>
            <?php if ($revPick): ?>
              <div class="small text-muted">connector: <code><?=h((string)$revPick['connector'])?></code></div>
              <pre style="max-height:180px;overflow:auto;background:#f7f7f7;border:1px solid #eee;padding:8px;border-radius:6px;"><?=h((string)$revPick['params_json'])?></pre>
            <?php else: ?>
              <div class="text-danger">нет revenue-job</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <hr>

      <form method="post" onsubmit="return confirm('Перезаписать /var/www/<?=h($domainSafe)?>/cmd/pages/campaign_851694.php ?');">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="do_generate" value="1">
        <input type="hidden" name="domain_id" value="<?=h((string)$sel_domain_id)?>">
        <button class="btn btn-success" <?=(!$costPick || !$revPick || $domainSafe==='')?'disabled':''?>>
          СГЕНЕРИРОВАТЬ / ОБНОВИТЬ MVP
        </button>
        <span class="small text-muted ms-2">target: <code>/var/www/<?=h($domainSafe)?>/cmd/pages/campaign_851694.php</code></span>
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

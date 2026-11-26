<?php
/************************************************************
 * kadam_today_with_revenue.php
 *
 * 1) Kadam: расход (moneyOut) по нужным кампаниям за сегодня,
 *    сохранение в tracker.kadam_daily_cost.
 * 2) Clickadu: общий доход аккаунта за сегодня → кампания 851550.
 * 3) TacoLoco: доход за сегодня по push + inpagepush для:
 *      - tronostok (кампания 858663) — токен 59abbec3f6f94ab28184cfe947596d8f
 *      - sex-mex-wife.ru (кампания 851554) — токен 5c26d29ad4aa4a36a388560b96c17ade
 *      - moondickgame.ru (кампания 851692) — токен 5c26d29ad4aa4a36a388560b96c17ade
 *      - fapa.io, gaka.io, ffmtubesex.ru, threesometube.ru, dickretube.ru, tronosex.ru, analwifetube.ru
 * 4) MyBid: доход за сегодня по hotpornmoms.ru → кампания 851694.
 * 5) ROTATOR: revenue за сегодня из push.click.
 * 6) Яндекс.Метрика: визиты/просмотры/время/глубина по сайтам.
 * 7) Считаем ROI по кампаниям и темам.
 ************************************************************/

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

// ===== DB CONFIG =====
define('DB_HOST', 'localhost');
define('DB_USER', 'pma');
define('DB_PASS', 'WhoviQuayb4');
define('DB_NAME', 'tracker');

// ===== DB CONN =====
function db() {
    static $mysqli = null;
    if ($mysqli instanceof mysqli) return $mysqli;
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_error) {
        die("DB connection failed: " . $mysqli->connect_error);
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

/************************************************************
 * Kadam CONFIG
 ************************************************************/
$KADAM = [
    'app_id'    => 141381,
    'client_id' => 141381,
    'bearer'    => '532568fbfb2630a8af12c1d00889c3bf',
    'campaigns' => [
        851550, // xxx-asiantube.ru (Clickadu)
        851554, // sex-mex-wife.ru (TacoLoco push + inpage)
        851065, // ротатор
        806525, // ротатор
        858663, // tronostok (TacoLoco push)
        851694, // hotpornmoms.ru (MyBid)
        851692, // moondickgame.ru (TacoLoco push + inpage)
        862608, // gaka.io
        862575, // fapa.io
        863432, // ffmtubesex.ru
        863436, // threesometube.ru
        863440, // dickretube.ru
        863445, // tronosex.ru
        863448, // analwifetube.ru
    ],
];

/************************************************************
 * Маппинг кампаний → тема/лейбл/сетка
 ************************************************************/
$TOPIC_META = [
    851550 => ['topic' => 'xxx-asiantube.ru',   'label' => 'xxx-asiantube.ru',     'network' => 'Clickadu'],
    851554 => ['topic' => 'sex-mex-wife.ru',    'label' => 'sex-mex-wife.ru',      'network' => 'Kadam'],
    851065 => ['topic' => 'rotator',            'label' => 'rotator 851065',       'network' => 'offerGate'],
    806525 => ['topic' => 'rotator',            'label' => 'rotator 806525',       'network' => 'offerGate'],
    858663 => ['topic' => 'tronostok.ru',       'label' => 'tronostok',            'network' => 'TacoLoco_A'],
    851694 => ['topic' => 'hotpornmoms.ru',     'label' => 'hotpornmoms.ru',       'network' => 'MyBid'],
    851692 => ['topic' => 'moondickgame.ru',    'label' => 'moondickgame.ru',      'network' => 'TacoLoco_2'],
    862608 => ['topic' => 'gaka.io',            'label' => 'gaka.io',              'network' => 'ReachEffect'],
    862575 => ['topic' => 'fapa.io',            'label' => 'fapa.io',              'network' => 'RichAds'],
    863432 => ['topic' => 'ffmtubesex.ru',      'label' => 'ffmtubesex.ru',        'network' => 'TacoLoco'],
    863436 => ['topic' => 'threesometube.ru',   'label' => 'threesometube.ru',     'network' => 'TacoLoco'],
    863440 => ['topic' => 'dickretube.ru',      'label' => 'dickretube.ru',        'network' => 'PushAdvert'],
    863445 => ['topic' => 'tronosex.ru',        'label' => 'tronosex.ru',          'network' => 'ClickTimes'],
    863448 => ['topic' => 'analwifetube.ru',    'label' => 'analwifetube.ru',      'network' => 'TacoLoco'],
];

/************************************************************
 * Clickadu CONFIG (доход для xxx-asiantube.ru — берём весь аккаунт)
 ************************************************************/
$CLICKADU_TOKEN   = '6UfmN0be9DJ0ogrMytrWnAS0DPoMM8rstGpaCUCxwVw5ULJN0f1hSbuo2rYarnMmj_QnCbQHeWYhTmtZxvLrNOxevuFtDT3vzSh6kfXVgcKJGH42K4HGZlVp-hgFZqkh';
$CLICKADU_TZ      = 'Europe/Berlin'; // считаем весь доход аккаунта за день

/************************************************************
 * TacoLoco CONFIG (2 аккаунта, один токен на 3+ сайтов)
 ************************************************************/
$TACO_BASE_URL = 'https://api.tacolo.co';
$TACO_TZ       = 'Europe/Berlin';

$TACO_ACCOUNTS = [
    858663 => [ // tronostok — считаем весь аккаунт по токену
        'token'  => '59abbec3f6f94ab28184cfe947596d8f',
        'cohort' => 'active',
    ],
    851554 => [ // sex-mex-wife.ru — плейсменты
        'token'  => '5c26d29ad4aa4a36a388560b96c17ade',
        'cohort' => 'active',
        'placements' => [
            ['id' => 228377, 'type' => 'push'],
            ['id' => 228378, 'type' => 'inpagepush'],
        ],
    ],
    // 851694 → теперь MyBid, а не TacoLoco
    851692 => [ // moondickgame.ru — плейсменты
        'token'  => '5c26d29ad4aa4a36a388560b96c17ade',
        'cohort' => 'active',
        'placements' => [
            ['id' => 228440, 'type' => 'push'],
            ['id' => 228441, 'type' => 'inpagepush'],
        ],
    ],
    862575 => [ // fapa.io
        'token'  => '5c26d29ad4aa4a36a388560b96c17ade',
        'cohort' => 'active',
        'placements' => [
            ['id' => 228468, 'type' => 'push'],
            ['id' => 228469, 'type' => 'inpagepush'],
        ],
    ],
    862608 => [ // gaka.io
        'token'  => '5c26d29ad4aa4a36a388560b96c17ade',
        'cohort' => 'active',
        'placements' => [
            ['id' => 228470, 'type' => 'push'],
            ['id' => 228471, 'type' => 'inpagepush'],
        ],
    ],
    863432 => [ // ffmtubesex.ru
        'token'  => '5c26d29ad4aa4a36a388560b96c17ade',
        'cohort' => 'active',
        'placements' => [
            ['id' => 228524, 'type' => 'push'],
            ['id' => 228525, 'type' => 'inpagepush'],
        ],
    ],
    863436 => [ // threesometube.ru
        'token'  => '5c26d29ad4aa4a36a388560b96c17ade',
        'cohort' => 'active',
        'placements' => [
            ['id' => 228522, 'type' => 'push'],
            ['id' => 228523, 'type' => 'inpagepush'],
        ],
    ],
    863440 => [ // dickretube.ru
        'token'  => '5c26d29ad4aa4a36a388560b96c17ade',
        'cohort' => 'active',
        'placements' => [
            ['id' => 228520, 'type' => 'push'],
            ['id' => 228521, 'type' => 'inpagepush'],
        ],
    ],
    863445 => [ // tronosex.ru
        'token'  => '5c26d29ad4aa4a36a388560b96c17ade',
        'cohort' => 'active',
        'placements' => [
            ['id' => 228518, 'type' => 'push'],
            ['id' => 228519, 'type' => 'inpagepush'],
        ],
    ],
    863448 => [ // analwifetube.ru
        'token'  => '5c26d29ad4aa4a36a388560b96c17ade',
        'cohort' => 'active',
        'placements' => [
            ['id' => 228516, 'type' => 'push'],
            ['id' => 228517, 'type' => 'inpagepush'],
        ],
    ],
];

/************************************************************
 * MyBid CONFIG (hotpornmoms.ru)
 ************************************************************/
$MYBID_TOKEN     = 'a65d532ac3dc2c5528aa2dc77e3422f4';
$MYBID_STATS_URL = 'https://publishers.mybid.io/backend/api/public/stats';

/************************************************************
 * Yandex.Metrika CONFIG
 ************************************************************/
$YANDEX_OAUTH = 'y0__xDrvdk-GLfsOyCqs_mtFTDJxMPdB_fLbKMQHKHA53rZdIsC8XeSHUBV';

/**
 * Маппинг кампаний Kadam → счётчик Метрики
 */
$YANDEX_METRIKA_MAP = [
    851550 => ['counter_id' => 100701669, 'host' => 'xxx-asiantube.ru'],
    851554 => ['counter_id' => 105201324, 'host' => 'sex-mex-wife.ru'],
    851694 => ['counter_id' => 105201635, 'host' => 'hotpornmoms.ru'],
    851692 => ['counter_id' => 105201651, 'host' => 'moondickgame.ru'],
    862608 => ['counter_id' => 105339085, 'host' => 'gaka.io'],
    862575 => ['counter_id' => 97866307,  'host' => 'fapa.io'],
    863432 => ['counter_id' => 105412999, 'host' => 'ffmtubesex.ru'],
    863436 => ['counter_id' => 105412879, 'host' => 'threesometube.ru'],
    863440 => ['counter_id' => 105412727, 'host' => 'dickretube.ru'],
    863445 => ['counter_id' => 105412674, 'host' => 'tronosex.ru'],
    863448 => ['counter_id' => 105412623, 'host' => 'analwifetube.ru'],
    858663 => ['counter_id' => 105324347, 'host' => 'tronostok.ru'],
];

/************************************************************
 * Kadam API helper (GET + Bearer)
 ************************************************************/
function kadam_get($endpoint, array $params, $bearer, $timeout = 30) {
    $url = 'https://api.kadam.net/' . $endpoint . '?' . http_build_query($params, '', '&');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $bearer,
        ],
        CURLOPT_TIMEOUT        => $timeout,
    ]);

    $raw  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'http' => $http,
        'err'  => $err ? $err : null,
        'raw'  => $raw,
        'json' => json_decode($raw, true),
    ];
}

/************************************************************
 * Clickadu API helper
 ************************************************************/
function clickadu_api_call(array $p, string $token, string $tz): array {
    $base = 'https://v2.api.clickadu.com/partner/stats';
    $p['token']    = $token;
    $p['timezone'] = $tz;

    $url  = $base.'?'.http_build_query($p,'','&',PHP_QUERY_RFC3986);
    $ch   = curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: KadamClickaduROI/1.0'
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => ''
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $hs   = (int)curl_getinfo($ch,CURLINFO_HEADER_SIZE);
    $body = substr((string)$resp,$hs);
    curl_close($ch);

    if($resp === false || $code < 200 || $code >= 300){
        return ['_error'=>"http_$code",'_body'=>$body,'_url'=>$url];
    }
    $j = json_decode($body,true);
    return is_array($j) ? $j+['_url'=>$url] : ['_error'=>'json_failed','_body'=>$body,'_url'=>$url];
}

/************************************************************
 * TacoLoco API helpers
 ************************************************************/
function taco_http_get_raw(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['accept: */*'],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("HTTP error: {$err}");
    }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Unexpected HTTP status {$code} for {$url}");
    }
    return $body;
}

/**
 * Общий фетчер TacoLoco: push / inpagepush по дням.
 */
function taco_fetch_day_stats(
    string $startDate,
    string $endDate,
    string $token,
    string $baseUrl,
    string $type = 'push',    // 'push' | 'inpagepush'
    array $extra = []
): array {
    $type = ($type === 'inpagepush') ? 'inpagepush' : 'push';

    $params = array_merge($extra, [
        'startDate' => $startDate,
        'endDate'   => $endDate,
        'token'     => $token,
    ]);

    $url = rtrim($baseUrl,'/')
        . '/publisher/v1/statistics/' . $type . '/date'
        . '?' . http_build_query($params,'','&',PHP_QUERY_RFC3986);

    $raw  = taco_http_get_raw($url);
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON in TacoLoco response (' . $type . ')');
    }

    $out = [];
    foreach ($json as $row) {
        $out[] = [
            'Date'        => (string)($row['Date'] ?? $row['date'] ?? ''),
            'New'         => (int)($row['New'] ?? $row['new'] ?? 0),
            'Active'      => (int)($row['Active'] ?? $row['active'] ?? 0),
            'Impressions' => (int)($row['Impressions'] ?? $row['impressions'] ?? 0),
            'Revenue'     => (float)($row['Revenue'] ?? $row['revenue'] ?? 0),
            'ScriptHits'  => (int)($row['ScriptHits'] ?? $row['scriptHits'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Суммарный доход TacoLoco по датам: push + inpagepush (по токену целиком).
 */
function taco_revenue_by_day(
    string $startDate,
    string $endDate,
    string $cohort,
    string $token,
    string $baseUrl
): array {
    $rows = array_merge(
        taco_fetch_day_stats($startDate, $endDate, $token, $baseUrl, 'push'),
        taco_fetch_day_stats($startDate, $endDate, $token, $baseUrl, 'inpagepush')
    );

    $result = [];
    foreach ($rows as $r) {
        $date = (string)($r['Date'] ?? $r['date'] ?? '');
        if ($date === '') {
            continue;
        }

        if (!isset($result[$date])) {
            $result[$date] = [
                'date'        => $date,
                'revenue'     => 0.0,
                'users'       => 0,
                'impressions' => 0,
            ];
        }

        if ($cohort === 'active') {
            $users = (int)($r['Active'] ?? $r['active'] ?? 0);
        } elseif ($cohort === 'new') {
            $users = (int)($r['New'] ?? $r['new'] ?? 0);
        } else {
            $users = (int)($r['Active'] ?? $r['active'] ?? 0)
                   + (int)($r['New'] ?? $r['new'] ?? 0);
        }

        $result[$date]['revenue']     += (float)($r['Revenue'] ?? $r['revenue'] ?? 0);
        $result[$date]['users']       += $users;
        $result[$date]['impressions'] += (int)($r['Impressions'] ?? $r['impressions'] ?? 0);
    }

    ksort($result);
    return $result;
}

/************************************************************
 * MyBid helper: суммарный money за период
 ************************************************************/
function mybid_total_revenue(string $token, string $date1, string $date2, string $baseUrl): float
{
    $params = [
        'token'   => $token,
        'date1'   => $date1,
        'date2'   => $date2,
        'fields'  => 'date,impressions,clicks,money', // группировка по дате
        'orderBy' => '-date',
        'limit'   => 1000,
        'offset'  => 0,
    ];

    $url = $baseUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("MyBid cURL error: {$err}");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("MyBid HTTP {$code}: {$body}");
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("MyBid JSON error: " . json_last_error_msg());
    }

    if (isset($data['data']) && is_array($data['data'])) {
        $rows = $data['data'];
    } else {
        $rows = $data;
    }

    $sum = 0.0;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $sum += (float)($r['money'] ?? 0);
    }

    return $sum;
}

/************************************************************
 * Yandex.Metrika helper: визиты / просмотры / время за сегодня
 ************************************************************/
function metrika_get_today(int $counterId, string $oauthToken, ?string $host = null): array
{
    $baseUrl = 'https://api-metrika.yandex.net/stat/v1/data';

    $params = [
        'ids'     => $counterId,
        'metrics' => 'ym:s:visits,ym:s:pageviews,ym:s:avgVisitDurationSeconds',
        'date1'   => 'today',
        'date2'   => 'today',
        'limit'   => 1,
    ];

    $url = $baseUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: OAuth ' . $oauthToken,
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("Metrika cURL error: {$err}");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Metrika HTTP {$code}: {$body}");
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Metrika JSON error: " . json_last_error_msg());
    }

    $metrics = $data['data'][0]['metrics'] ?? null;
    if (!is_array($metrics)) {
        return [
            'visits'     => 0.0,
            'pageviews'  => 0.0,
            'avg_time'   => 0.0,
            'depth'      => 0.0,
        ];
    }

    $visits    = (float)($metrics[0] ?? 0);
    $pageviews = (float)($metrics[1] ?? 0);
    $avgTime   = (float)($metrics[2] ?? 0);
    $depth     = ($visits > 0) ? $pageviews / $visits : 0.0;

    return [
        'visits'     => $visits,
        'pageviews'  => $pageviews,
        'avg_time'   => $avgTime,
        'depth'      => $depth,
    ];
}

/************************************************************
 * Нежный градиент по ROI (-100%..+100%)
 ************************************************************/
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

/************************************************************
 * Таблица под ежедневные расходы по кампаниям (Kadam)
 ************************************************************/
db()->query("
    CREATE TABLE IF NOT EXISTS kadam_daily_cost (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        d           DATE NOT NULL,
        campaign_id INT  NOT NULL,
        views       INT  NOT NULL DEFAULT 0,
        clicks      INT  NOT NULL DEFAULT 0,
        conversions INT  NOT NULL DEFAULT 0,
        money_out   DECIMAL(14,4) NOT NULL DEFAULT 0,
        money_in    DECIMAL(14,4) NOT NULL DEFAULT 0,
        cpc         DECIMAL(14,6) NOT NULL DEFAULT 0,
        ctr         DECIMAL(14,6) NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_d_camp (d, campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/************************************************************
 * ROTATOR daily revenue table (tracker.rotator_daily_revenue)
 ************************************************************/
db()->query("
    CREATE TABLE IF NOT EXISTS rotator_daily_revenue (
        id       INT AUTO_INCREMENT PRIMARY KEY,
        d        DATE NOT NULL,
        revenue  DECIMAL(14,6) NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_d (d)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$today = date('Y-m-d');
$campaignsCsv = implode(',', $KADAM['campaigns']);

/************************************************************
 * 1) Kadam: stats по кампаниям за сегодня
 ************************************************************/
$params = [
    'app_id'    => $KADAM['app_id'],
    'client_id' => $KADAM['client_id'],
    'campaigns' => $campaignsCsv,
    'group'     => 'campaign',
    'date_from' => $today,
    'date_to'   => $today,
    'timezone'  => 3,
    'limit'     => 500,
];

$res = kadam_get('ads.stats.campaign.get', $params, $KADAM['bearer']);

if ($res['http'] !== 200) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Kadam HTTP error: " . $res['http'] . "\n";
    echo "cURL err: " . $res['err'] . "\n";
    echo "Raw response:\n" . $res['raw'];
    exit;
}

$data = $res['json'];
if (!is_array($data) || !isset($data['response']['items'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Bad JSON from Kadam:\n";
    var_dump($data);
    exit;
}

$items = $data['response']['items'];

/************************************************************
 * 2) Сохраняем Kadam в БД + собираем строки по кампаниям
 ************************************************************/
$mysqli = db();
$stmt = $mysqli->prepare("
    INSERT INTO kadam_daily_cost
      (d, campaign_id, views, clicks, conversions, money_out, money_in, cpc, ctr)
    VALUES (?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      views       = VALUES(views),
      clicks      = VALUES(clicks),
      conversions = VALUES(conversions),
      money_out   = VALUES(money_out),
      money_in    = VALUES(money_in),
      cpc         = VALUES(cpc),
      ctr         = VALUES(ctr)
");

if (!$stmt) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Prepare failed: " . $mysqli->error;
    exit;
}

$rows = []; // кампании для вывода (только со spend > 0)

foreach ($items as $row) {
    $cid   = isset($row['id']) ? (int)$row['id'] : 0;
    $views = isset($row['views']) ? (int)$row['views'] : 0;
    $click = isset($row['clicks']) ? (int)$row['clicks'] : 0;
    $conv  = isset($row['conversions']) ? (int)$row['conversions'] : 0;
    $mo    = isset($row['moneyOut']) ? (float)$row['moneyOut'] : 0.0;
    $mi    = isset($row['moneyIn']) ? (float)$row['moneyIn'] : 0.0;
    $cpc   = isset($row['cpc']) ? (float)$row['cpc'] : 0.0;
    $ctr   = isset($row['ctr']) ? (float)$row['ctr'] : 0.0;

    $stmt->bind_param(
        'siiiidddd',
        $today,
        $cid,
        $views,
        $click,
        $conv,
        $mo,
        $mi,
        $cpc,
        $ctr
    );
    $stmt->execute();

    if ($mo <= 0) {
        continue;
    }

    $meta = $TOPIC_META[$cid] ?? ['topic' => 'unknown', 'label' => 'unknown', 'network' => ''];

    $rows[] = [
        'cid'         => $cid,
        'topic_key'   => $meta['topic'],
        'topic_label' => $meta['label'],
        'network'     => $meta['network'],
        'views'       => $views,
        'click'       => $click,
        'conv'        => $conv,
        'mo'          => $mo,
        'mi'          => $mi,
        'cpc'         => $cpc,
        'ctr'         => $ctr,
        'rev_ext'     => 0.0,

        // Метрика
        'm_visits'    => 0.0,
        'm_pageviews' => 0.0,
        'm_avg_time'  => 0.0,
        'm_depth'     => 0.0,
    ];
}

$stmt->close();

/************************************************************
 * 2.1) ROTATOR revenue за сегодня из push.click
 ************************************************************/
$rotator_revenue_today = 0.0;

$from = $today . ' 00:00:00';
$to   = date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00';

$stmtRot = $mysqli->prepare("
    SELECT SUM(rev) AS revenue
    FROM push.click
    WHERE click_time >= ? AND click_time < ?
");
if ($stmtRot) {
    $stmtRot->bind_param('ss', $from, $to);
    if ($stmtRot->execute()) {
        $resRot = $stmtRot->get_result();
        if ($resRot && ($rowRot = $resRot->fetch_assoc())) {
            $rotator_revenue_today = (float)($rowRot['revenue'] ?? 0.0);
        }
    }
    $stmtRot->close();
}

$stmtRotIns = $mysqli->prepare("
    INSERT INTO rotator_daily_revenue (d, revenue)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE revenue = VALUES(revenue)
");
if ($stmtRotIns) {
    $stmtRotIns->bind_param('sd', $today, $rotator_revenue_today);
    $stmtRotIns->execute();
    $stmtRotIns->close();
}

// распределяем rotator revenue по ротатор-кампаниям пропорционально spend
if ($rotator_revenue_today > 0) {
    $rotator_spend = 0.0;
    foreach ($rows as $r) {
        if ($r['topic_key'] === 'rotator') {
            $rotator_spend += $r['mo'];
        }
    }

    if ($rotator_spend > 0) {
        foreach ($rows as &$r) {
            if ($r['topic_key'] === 'rotator') {
                $share = $r['mo'] / $rotator_spend;
                $r['rev_ext'] += $rotator_revenue_today * $share;
            }
        }
        unset($r);
    } else {
        foreach ($rows as &$r) {
            if ($r['topic_key'] === 'rotator') {
                $r['rev_ext'] += $rotator_revenue_today;
                break;
            }
        }
        unset($r);
    }
}

/************************************************************
 * 3) Clickadu: общий доход аккаунта за сегодня
 ************************************************************/
$clickadu_revenue = 0.0;
$clickadu_error   = null;
$clickadu_date    = (new DateTime('now', new DateTimeZone($CLICKADU_TZ)))->format('Y-m-d');

$clickResp = clickadu_api_call([
    'dateFrom' => $clickadu_date,
    'dateTo'   => $clickadu_date,
    'groupBy'  => 'zone',
    'format'   => 'json',
], $CLICKADU_TOKEN, $CLICKADU_TZ);

if (isset($clickResp['_error'])) {
    $clickadu_error = $clickResp['_error'];
} else {
    $stats = $clickResp['stats'] ?? [];
    foreach ($stats as $z) {
        $money = (float)($z['money'] ?? 0.0);
        $clickadu_revenue += $money;
    }
}

/************************************************************
 * 4) TacoLoco: доход за сегодня для Taco-кампаний
 ************************************************************/
$taco_revenues = [];
$taco_errors   = [];

$taco_date = (new DateTime('now', new DateTimeZone($TACO_TZ)))->format('Y-m-d');

foreach ($TACO_ACCOUNTS as $campId => $cfg) {
    $rev  = 0.0;
    $err  = null;

    try {
        if (!empty($cfg['placements']) && is_array($cfg['placements'])) {
            foreach ($cfg['placements'] as $pl) {
                $plId = isset($pl['id']) ? (int)$pl['id'] : 0;
                if ($plId <= 0) {
                    continue;
                }
                $plType = ($pl['type'] ?? 'push') === 'inpagepush' ? 'inpagepush' : 'push';

                $rowsPl = taco_fetch_day_stats(
                    $taco_date,
                    $taco_date,
                    $cfg['token'],
                    $TACO_BASE_URL,
                    $plType,
                    ['placement' => $plId]
                );

                foreach ($rowsPl as $r) {
                    $rev += (float)($r['Revenue'] ?? $r['revenue'] ?? 0);
                }
            }
        } else {
            $stats = taco_revenue_by_day($taco_date, $taco_date, $cfg['cohort'], $cfg['token'], $TACO_BASE_URL);
            if (isset($stats[$taco_date])) {
                $rev = (float)$stats[$taco_date]['revenue'];
            }
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }

    $taco_revenues[$campId] = $rev;
    if ($err) $taco_errors[$campId] = $err;
}

/************************************************************
 * 5) MyBid: доход за сегодня (hotpornmoms.ru)
 ************************************************************/
$mybid_revenue_hotporn = 0.0;
$mybid_error_hotporn   = null;

try {
    $mybid_revenue_hotporn = mybid_total_revenue($MYBID_TOKEN, $today, $today, $MYBID_STATS_URL);
} catch (Throwable $e) {
    $mybid_error_hotporn = $e->getMessage();
}

/************************************************************
 * 6) Вписываем внешний доход в кампании
 ************************************************************/
foreach ($rows as &$r) {
    if ($r['cid'] === 851550) {
        $r['rev_ext'] += $clickadu_revenue;
    }
    if (isset($taco_revenues[$r['cid']])) {
        $r['rev_ext'] += $taco_revenues[$r['cid']];
    }
    if ($r['cid'] === 851694) {
        $r['rev_ext'] += $mybid_revenue_hotporn;
    }
}
unset($r);

/************************************************************
 * 6.1) Подтягиваем Метрику: визиты/просмотры/время/глубина
 ************************************************************/
if (!empty($YANDEX_OAUTH) && !empty($YANDEX_METRIKA_MAP)) {
    $metrikaCache = [];

    foreach ($rows as &$r) {
        $cid = $r['cid'];

        if (!isset($YANDEX_METRIKA_MAP[$cid])) {
            continue;
        }

        $cfg = $YANDEX_METRIKA_MAP[$cid];
        $counterId = (int)($cfg['counter_id'] ?? 0);
        if ($counterId <= 0) continue;

        $host = $cfg['host'] ?? null;
        $cacheKey = $counterId . '|' . ($host ?? '');

        if (!isset($metrikaCache[$cacheKey])) {
            try {
                $metrikaCache[$cacheKey] = metrika_get_today($counterId, $YANDEX_OAUTH, $host);
            } catch (Throwable $e) {
                $metrikaCache[$cacheKey] = [
                    'visits'     => 0.0,
                    'pageviews'  => 0.0,
                    'avg_time'   => 0.0,
                    'depth'      => 0.0,
                ];
            }
        }

        $m = $metrikaCache[$cacheKey];

        $r['m_visits']    = $m['visits'];
        $r['m_pageviews'] = $m['pageviews'];
        $r['m_avg_time']  = $m['avg_time'];
        $r['m_depth']     = $m['depth'];
    }
    unset($r);
}

/************************************************************
 * 7) Агрегация по темам
 ************************************************************/
$topicAgg = [];
foreach ($rows as $r) {
    $tk = $r['topic_key'];
    if (!isset($topicAgg[$tk])) {
        $topicAgg[$tk] = [
            'topic'    => $tk,
            'mo'       => 0.0,
            'rev_ext'  => 0.0,
        ];
    }
    $topicAgg[$tk]['mo']      += $r['mo'];
    $topicAgg[$tk]['rev_ext'] += $r['rev_ext'];
}

foreach ($topicAgg as $k => $v) {
    $mo   = $v['mo'];
    $rev  = $v['rev_ext'];
    $prof = $rev - $mo;
    $roi  = ($mo > 0) ? ($prof / $mo * 100) : 0.0;

    $topicAgg[$k]['profit'] = $prof;
    $topicAgg[$k]['roi']    = $roi;
}

/************************************************************
 * HTML-вывод
 ************************************************************/
header('Content-Type: text/html; charset=utf-8');

echo '<style>
table{border-collapse:collapse;width:100%;max-width:1200px;font-family:Segoe UI,Arial,sans-serif;font-size:13px;margin-bottom:18px}
th,td{border:1px solid #ccc;padding:4px 6px;text-align:right;font-variant-numeric:tabular-nums}
th{background:#f5f5f5;text-align:center}
td.name{text-align:left}
h2,h3{font-family:Segoe UI,Arial,sans-serif}
a.btn-geo{display:inline-block;padding:2px 6px;border-radius:4px;border:1px solid #888;font-size:11px;text-decoration:none;color:#004;background:#f0f3ff;}
a.btn-geo:hover{background:#dde4ff;}

th.compact,td.compact{padding:1px 3px;font-size:11px;white-space:nowrap;text-align:center}

/* псевдо-bootstrаp бейджи */
.badge{display:inline-block;padding:1px 5px;border-radius:10px;font-size:11px;line-height:1.2;border:1px solid transparent;min-width:32px;text-align:center}
.badge-ok{background:#eef7ee;border-color:#c8e6c9;color:#256029;}
.badge-warn{background:#fff8e1;border-color:#ffe0b2;color:#8a6d3b;}
.badge-bad{background:#fdecea;border-color:#f5c6cb;color:#721c24;}
</style>';

echo '<h2>Kadam spend + внешние сети + Мetrika — за сегодня (' . htmlspecialchars($today, ENT_QUOTES, 'UTF-8') . ')</h2>';
echo '<div style="margin-bottom:10px;font-family:Segoe UI,Arial,sans-serif;font-size:13px;color:#555;">';
echo 'Clickadu (весь аккаунт, xxx-asiantube.ru): <b>' . number_format($clickadu_revenue, 6, '.', ' ') . '</b>';
if ($clickadu_error) {
    echo ' &nbsp;|&nbsp; <span style="color:#c00;">Clickadu error: ' . htmlspecialchars($clickadu_error, ENT_QUOTES, 'UTF-8') . '</span>';
}
echo '<br>TacoLoco revenues: ';
foreach ($TACO_ACCOUNTS as $cid => $_cfg) {
    $revTmp = $taco_revenues[$cid] ?? 0.0;
    echo ' Кампания ' . $cid . ': <b>' . number_format($revTmp, 6, '.', ' ') . '</b>';
    if (isset($taco_errors[$cid])) {
        echo ' <span style="color:#c00;">(' . htmlspecialchars($taco_errors[$cid], ENT_QUOTES, 'UTF-8') . ')</span>';
    }
}
echo '<br>MyBid (hotpornmoms.ru, кампания 851694): <b>' . number_format($mybid_revenue_hotporn, 6, '.', ' ') . '</b>';
if ($mybid_error_hotporn) {
    echo ' &nbsp;|&nbsp; <span style="color:#c00;">MyBid error: ' . htmlspecialchars($mybid_error_hotporn, ENT_QUOTES, 'UTF-8') . '</span>';
}
echo '<br>ROTATOR revenue (push.click) за сегодня: <b>' . number_format($rotator_revenue_today, 6, '.', ' ') . '</b>';
echo '</div>';

/*********************** Таблица по кампаниям ************************/
echo '<h3>По кампаниям (Kadam spend + клики + внешний доход + Мetrika + отношения + ROI)</h3>';
echo '<table>';
echo '<tr>
        <th>Topic</th>
        <th>Сетка</th>
        <th>Campaign<br>ID</th>
        <th>Spend</th>
        <th>Clicks<br>(Kadam)</th>
        <th>Ext<br>revenue</th>
        <th>Profit</th>
        <th>ROI&nbsp;%</th>
        <th class="compact" title="Spend / visits">Sp/V</th>
        <th class="compact" title="Revenue / visits">Rv/V</th>
        <th>Visits</th>
        <th>Pageviews</th>
        <th>Avg&nbsp;time,<br>s</th>
        <th>Depth</th>
        <th class="compact" title="Kadam clicks / Metrika visits">K/C</th>
        <th class="compact" title="Visits vs 2000 users, %">V/2k,%</th>
        <th>GEO</th>
        <th>BL&nbsp;pubs</th>
      </tr>';

$total_spend       = 0.0;
$total_rev         = 0.0;
$total_clicks      = 0.0;
$total_visits      = 0.0;
$total_pageviews   = 0.0;
$total_time_weight = 0.0;

usort($rows, function($a, $b) {
    if ($a['mo'] == $b['mo']) return 0;
    return ($a['mo'] < $b['mo']) ? 1 : -1;
});

foreach ($rows as $r) {
    $mo   = $r['mo'];
    $rev  = $r['rev_ext'];
    $prof = $rev - $mo;
    $roi  = ($mo > 0) ? ($prof / $mo * 100) : 0.0;

    $visits    = (float)$r['m_visits'];
    $pageviews = (float)$r['m_pageviews'];
    $avgTime   = (float)$r['m_avg_time'];
    $depth     = (float)$r['m_depth'];
    $clicks    = (float)$r['click'];

    // агрегаты
    $total_spend       += $mo;
    $total_rev         += $rev;
    $total_clicks      += $clicks;
    $total_visits      += $visits;
    $total_pageviews   += $pageviews;
    $total_time_weight += $avgTime * $visits;

    // отношения
    $kClicksRatio   = ($visits > 0) ? $clicks / $visits : 0.0;
    $visitsVs2k     = ($visits > 0) ? ($visits / 2000.0 * 100.0) : 0.0;
    $cost_per_visit = ($visits > 0) ? $mo / $visits : 0.0;      // цена за 1 посетителя
    $rev_per_visit  = ($visits > 0) ? $rev / $visits : 0.0;     // доход на 1 посетителя

    // бейджи для K/C
    $kcFormatted = number_format($kClicksRatio, 2, '.', '');
    if ($kClicksRatio > 2.0) {
        $kcBadge = '<span class="badge badge-bad">'.$kcFormatted.'</span>';
    } else {
        $kcBadge = '<span class="badge badge-ok">'.$kcFormatted.'</span>';
    }

    // бейджи для V/2k
    $v2kFormatted = number_format($visitsVs2k, 1, '.', '');
    if ($visitsVs2k < 100.0) {
        $v2kBadge = '<span class="badge badge-bad">'.$v2kFormatted.'</span>';
    } else {
        $v2kBadge = '<span class="badge badge-ok">'.$v2kFormatted.'</span>';
    }

    $trStyle = roi_bg_style($roi);

    // Кнопки GEO-отчётов:
    //  - Clickadu: отдельный скрипт
    //  - MyBid: отдельный скрипт
    //  - ВСЕ TacoLoco Kadam кампании: единый kadam_tacoloco_auto.php?cid=ID
    $geoBtn = '';
    if ($r['cid'] === 851550) {
        $geoBtn = '<a class="btn-geo" target="_blank" href="kadam_clicadu.php?date=' . urlencode($today) . '">GEO Clickadu</a>';
    } elseif ($r['cid'] === 851694) {
        $geoBtn = '<a class="btn-geo" target="_blank" href="kadam_mybid_851694.php?date=' . urlencode($today) . '">GEO MyBid</a>';
    } elseif (isset($TACO_ACCOUNTS[$r['cid']])) {
        // универсальный TacoLoco автоскрипт
        $geoBtn = '<a class="btn-geo" target="_blank" href="kadam_tacoloco_auto.php?cid=' . $r['cid'] . '">GEO Taco</a>';
    }

    // Кнопки BL паблишеров — пока как было (tronostok → auto2.php, остальным заглушка)
    $blBtn = '';
    if ($r['cid'] === 858663) {
        // tronostok — отдельный скрипт BL
        $blBtn = '<a class="btn-geo" target="_blank" href="https://tronostok.ru/auto2.php">BL</a>';
    } else {
        $blBtn = 'BL';
    }

    echo '<tr style="' . $trStyle . '">';
    echo '<td class="name">' . htmlspecialchars($r['topic_label'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars($r['network'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . $r['cid'] . '</td>';
    echo '<td>' . number_format($mo, 4, '.', '') . '</td>';
    echo '<td>' . number_format($clicks, 0, '.', '') . '</td>';
    echo '<td>' . number_format($rev, 6, '.', '') . '</td>';
    echo '<td>' . number_format($prof, 6, '.', '') . '</td>';
    echo '<td>' . number_format($roi, 2, '.', '') . '</td>';
    echo '<td class="compact"><b>' . number_format($cost_per_visit*1000, 4, '.', '') . '</b></td>';
    echo '<td class="compact"><b>' . number_format($rev_per_visit*1000, 4, '.', '') . '</b></td>';
    echo '<td>' . number_format($visits, 0, '.', '') . '</td>';
    echo '<td>' . number_format($pageviews, 0, '.', '') . '</td>';
    echo '<td>' . number_format($avgTime, 1, '.', '') . '</td>';
    echo '<td>' . number_format($depth, 2, '.', '') . '</td>';
    echo '<td class="compact">' . $kcBadge . '</td>';
    echo '<td class="compact">' . $v2kBadge . '</td>';
    echo '<td>' . $geoBtn . '</td>';
    echo '<td>' . $blBtn . '</td>';
    echo '</tr>';
}

$tot_prof = $total_rev - $total_spend;
$tot_roi  = ($total_spend > 0) ? ($tot_prof / $total_spend * 100) : 0.0;

$total_avg_time = ($total_visits > 0) ? ($total_time_weight / $total_visits) : 0.0;
$total_depth    = ($total_visits > 0) ? ($total_pageviews / $total_visits) : 0.0;
$total_kc       = ($total_visits > 0) ? ($total_clicks / $total_visits) : 0.0;
$total_v2k      = ($total_visits > 0) ? ($total_visits / 2000.0 * 100.0) : 0.0;

// новые агрегаты: цена и доход за 1 визит по всем сайтам
$total_cost_per_visit = ($total_visits > 0) ? ($total_spend / $total_visits) : 0.0;
$total_rev_per_visit  = ($total_visits > 0) ? ($total_rev   / $total_visits) : 0.0;

$tot_kc_formatted  = number_format($total_kc, 2, '.', '');
$tot_v2k_formatted = number_format($total_v2k, 1, '.', '');

if ($total_kc > 2.0) {
    $tot_kc_badge = '<span class="badge badge-bad">'.$tot_kc_formatted.'</span>';
} else {
    $tot_kc_badge = '<span class="badge badge-ok">'.$tot_kc_formatted.'</span>';
}

if ($total_v2k < 100.0) {
    $tot_v2k_badge = '<span class="badge badge-bad">'.$tot_v2k_formatted.'</span>';
} else {
    $tot_v2k_badge = '<span class="badge badge-ok">'.$tot_v2k_formatted.'</span>';
}

echo '<tr style="font-weight:bold;background:#eef;">
        <td class="name">TOTAL</td>
        <td></td>
        <td></td>
        <td>' . number_format($total_spend, 4, '.', '') . '</td>
        <td>' . number_format($total_clicks, 0, '.', '') . '</td>
        <td>' . number_format($total_rev, 6, '.', '') . '</td>
        <td>' . number_format($tot_prof, 6, '.', '') . '</td>
        <td class="compact">' . number_format($total_cost_per_visit*1000, 4, '.', '') . '</td>
        <td class="compact">' . number_format($total_rev_per_visit*1000, 4, '.', '') . '</td>
        <td>' . number_format($total_visits, 0, '.', '') . '</td>
        <td>' . number_format($total_pageviews, 0, '.', '') . '</td>
        <td>' . number_format($total_avg_time, 1, '.', '') . '</td>
        <td>' . number_format($total_depth, 2, '.', '') . '</td>
        <td class="compact">' . $tot_kc_badge . '</td>
        <td class="compact">' . $tot_v2k_badge . '</td>
        <td></td>
        <td></td>
      </tr>';

echo '</table>';

/*********************** Сводка по темам ************************/
echo '<h3>Сводка по темам (суммарный Spend/Revenue/ROI)</h3>';

echo '<table>';
echo '<tr>
        <th>Topic</th>
        <th>Spend (Kadam)</th>
        <th>Ext revenue</th>
        <th>Profit</th>
        <th>ROI %</th>
      </tr>';

usort($topicAgg, function($a, $b) {
    if ($a['mo'] == $b['mo']) return 0;
    return ($a['mo'] < $b['mo']) ? 1 : -1;
});

foreach ($topicAgg as $t) {
    $trStyle = roi_bg_style((float)$t['roi']);

    echo '<tr style="' . $trStyle . '">';
    echo '<td class="name">' . htmlspecialchars($t['topic'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . number_format($t['mo'], 4, '.', '') . '</td>';
    echo '<td>' . number_format($t['rev_ext'], 6, '.', '') . '</td>';
    echo '<td>' . number_format($t['profit'], 6, '.', '') . '</td>';
    echo '<td>' . number_format($t['roi'], 2, '.', '') . '</td>';
    echo '</tr>';
}

echo '</table><br>';

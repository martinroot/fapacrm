<?php
declare(strict_types=1);

require_once __DIR__ . '/ConnectorInterface.php';

final class YandexMetrikaToday implements ConnectorInterface
{
  public static function code(): string { return 'yandex_metrika_today'; }

  private function http_get(string $url, string $oauth, int $timeout=30): array
  {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => $timeout,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_HTTPHEADER     => [
        'Authorization: OAuth ' . $oauth,
        'Accept: application/json',
        'User-Agent: FapaCRM/1.0',
      ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
      'code' => $code,
      'err'  => $err ?: null,
      'body' => $body, // string|false
      'url'  => $url,
    ];
  }

  public function run(array $params): array
  {
    $oauth = trim((string)($params['oauth_token'] ?? ''));
    $counterId = (int)($params['counter_id'] ?? 0);
    $tz = (string)($params['timezone'] ?? 'Europe/Moscow');

    // host filter is OFF by default (because it often triggers 400)
    $host = trim((string)($params['host'] ?? ''));
    $useHostFilter = (bool)($params['use_host_filter'] ?? false);

    if ($oauth === '') throw new RuntimeException('YandexMetrikaToday: missing oauth_token');
    if ($counterId <= 0) throw new RuntimeException('YandexMetrikaToday: missing counter_id');

    $date = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');

    $metrics = 'ym:s:visits,ym:s:pageviews,ym:s:avgVisitDurationSeconds';

    $q = [
      'ids'     => $counterId,
      'metrics' => $metrics,
      'date1'   => 'today',
      'date2'   => 'today',
      'limit'   => 1,
    ];

    // Safe filter: only if explicitly enabled
    if ($useHostFilter && $host !== '') {
      // Filters syntax: ym:s:hostname=='example.com'
      $q['filters'] = "ym:s:hostname=='" . str_replace("'", "\\'", $host) . "'";
    }

    $url = 'https://api-metrika.yandex.net/stat/v1/data?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    $r = $this->http_get($url, $oauth);

    $raw = ($r['body'] === false) ? '' : (string)$r['body'];
    $debug = [
      'url' => $r['url'],
      'http_code' => (int)$r['code'],
      'curl_err' => (string)($r['err'] ?? ''),
      'raw_snippet' => mb_substr($raw, 0, 4000, 'UTF-8'),
      'counter_id' => $counterId,
      'tz' => $tz,
      'date' => $date,
      'host' => $host,
      'use_host_filter' => $useHostFilter,
    ];

    // if curl failed
    if ($r['body'] === false) {
      /* не пишем ноль: молчание сети — это не «визитов не было» */
      throw new RuntimeException('YandexMetrikaToday: запрос не ушёл (curl), счётчик ' . $counterId);
    }

    // if HTTP not OK, return debug so you can see exact error JSON from Yandex
    if ($r['code'] < 200 || $r['code'] >= 300) {
      /* не пишем ноль: 429 значит «Яндекс не ответил», а не «визитов нет».
         Нулевой тик стал бы последним снимком дня и стёр бы всю статистику. */
      throw new RuntimeException(sprintf(
        'YandexMetrikaToday: HTTP %d по счётчику %d%s', (int)$r['code'], $counterId,
        (int)$r['code'] === 429 ? ' — упёрлись в суточную квоту Яндекса' : ''));
    }

    $j = json_decode((string)$r['body'], true);
    if (!is_array($j)) {
      /* не пишем ноль: битый ответ — не повод стереть день */
      throw new RuntimeException('YandexMetrikaToday: ответ не разобрался, счётчик ' . $counterId);
    }

    $metricsArr = $j['data'][0]['metrics'] ?? null;

    if (!is_array($metricsArr)) {
      $visits = 0.0; $pageviews = 0.0; $avgTime = 0.0;
    } else {
      $visits    = (float)($metricsArr[0] ?? 0);
      $pageviews = (float)($metricsArr[1] ?? 0);
      $avgTime   = (float)($metricsArr[2] ?? 0);
    }

    $depth = ($visits > 0) ? ($pageviews / $visits) : 0.0;

    return [
      'date'  => $date,
      'items' => [
        [
          'external_id' => 'counter:' . $counterId,
          'amount'      => $visits,
          'meta'        => [
            'visits' => $visits,
            'pageviews' => $pageviews,
            'avg_time' => $avgTime,
            'depth' => $depth,
            'host' => $host,
            'use_host_filter' => $useHostFilter,
          ],
        ],
      ],
      'debug' => $debug,
    ];
  }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/ConnectorInterface.php';

/**
 * Визиты из СВОЕЙ метрики (analfluxtube.ru/metrika) вместо Яндекса.
 *
 * Зачем: счётчик Яндекса остаётся стоять на сайтах, но данные для CRM берём
 * у себя. Свой источник не упирается в суточную квоту (Яндекс отдавал 429 и
 * рвал сбор), считает по всем доменам сети одинаково и знает то, чего Яндекс
 * не покажет: разрез по площадкам Kadam.
 *
 * Ноль при сбое не пишем — молчание сети это не «визитов не было». Нулевой
 * тик стал бы последним снимком дня и стёр бы статистику, ровно как у Яндекса.
 *
 * params: site (домен), опционально base (адрес метрики), tz, timeout
 */
final class OwnMetrikaToday implements ConnectorInterface
{
  public static function code(): string { return 'own_metrika_today'; }

  public function run(array $params): array
  {
    $site = strtolower(trim((string)($params['site'] ?? '')));
    $base = rtrim((string)($params['base'] ?? 'https://analfluxtube.ru/metrika'), '/');
    $tz   = (string)($params['timezone'] ?? 'Europe/Moscow');
    $to   = (int)($params['timeout'] ?? 25);

    if ($site === '' || !preg_match('/^[a-z0-9.\-]{3,80}$/', $site))
      throw new RuntimeException('OwnMetrikaToday: не задан или битый site');

    $date = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
    $url  = $base . '/api.php?' . http_build_query([
      'm' => 'summary', 'site' => $site, 'from' => $date, 'to' => $date, 'tz' => $tz,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => $to,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: FapaCRM/1.0'],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $raw   = ($body === false) ? '' : (string)$body;
    $debug = ['url' => $url, 'http_code' => $code, 'curl_err' => $err ?: '',
              'raw_snippet' => mb_substr($raw, 0, 2000, 'UTF-8'), 'site' => $site, 'date' => $date];

    if ($body === false)
      throw new RuntimeException('OwnMetrikaToday: запрос не ушёл (curl) по ' . $site);
    if ($code < 200 || $code >= 300)
      throw new RuntimeException(sprintf('OwnMetrikaToday: HTTP %d по %s', $code, $site));

    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['rows'][0]))
      throw new RuntimeException('OwnMetrikaToday: ответ не разобрался по ' . $site);

    $r = $j['rows'][0];
    $visits    = (float)($r['visits'] ?? 0);
    $pageviews = (float)($r['pageviews'] ?? 0);
    $avgTime   = (float)($r['avg_seconds'] ?? 0);
    $depth     = $visits > 0 ? $pageviews / $visits : 0.0;

    return [
      'date'  => $date,
      'items' => [[
        'external_id' => 'own:' . $site,
        'amount'      => $visits,
        /* Ключи те же, что у яндексового коннектора: интерфейс CRM читает
           именно их, и переключение источника не должно ломать таблицы. */
        'meta' => [
          'visits'    => $visits,
          'pageviews' => $pageviews,
          'avg_time'  => $avgTime,
          'depth'     => $depth,
          'visitors'  => (int)($r['visitors'] ?? 0),
          'new'       => (int)($r['new_visitors'] ?? 0),
          'bounce'    => (float)($r['bounce_rate'] ?? 0),
          'source'    => 'own_metrika',
        ],
      ]],
      'debug' => $debug,
    ];
  }
}

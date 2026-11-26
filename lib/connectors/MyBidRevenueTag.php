<?php
declare(strict_types=1);

require_once __DIR__ . '/ConnectorInterface.php';

/**
 * Реальный доход MyBid по конкретному домену.
 *
 * Заменяет mybid_revenue_share. В шапке того коннектора написано, что MyBid
 * якобы отдаёт статистику только по аккаунту целиком и не умеет разбивку по
 * доменам. Это не так: он не принимает поля site/adcode/domain/admpid, но
 * понимает измерение tag_name, а tag_name в этом аккаунте — это имя домена.
 * Проверено на всех тегах: запрос fields=tag_name,money возвращает построчную
 * разбивку с именами вида "wifepornhub.ru (450944)".
 *
 * Никаких оценок и долей: берём ровно ту сумму, которую заработал свой тег.
 *
 * КЭШ. Один запрос отдаёт статистику сразу по всем доменам, а заданий у нас
 * по одному на домен, и раннер запускает их подряд. Без кэша это два десятка
 * одинаковых запросов за секунду, и MyBid отвечает 429 Too Many Requests —
 * поймано на боевом прогоне. Поэтому ответ кладётся в файл на CACHE_TTL секунд,
 * первое задание ходит в API, остальные читают готовое.
 *
 * Если API недоступен, а просроченный кэш есть — берём его и помечаем это в
 * meta. Лучше цифра пятиминутной давности, чем упавшее задание и дыра в графике.
 */
final class MyBidRevenueTag implements ConnectorInterface
{
  private const CACHE_TTL = 120;

  public static function code(): string { return 'mybid_revenue_tag'; }

  /** "wifepornhub.ru (450944)" -> "wifepornhub.ru" */
  private static function clean_tag(string $s): string
  {
    return trim((string)preg_replace('/\s*\(\d+\)\s*$/', '', $s));
  }

  private static function cache_path(string $date): string
  {
    return sys_get_temp_dir() . '/mybid_tag_stats_' . preg_replace('/[^0-9-]/', '', $date) . '.json';
  }

  /** @return array{rows:array,cached:bool,stale:bool} */
  private function fetch_rows(string $token, string $url, string $date): array
  {
    $cf = self::cache_path($date);

    if (is_file($cf) && (time() - (int)filemtime($cf)) < self::CACHE_TTL) {
      $j = json_decode((string)file_get_contents($cf), true);
      if (is_array($j)) return ['rows' => $j, 'cached' => true, 'stale' => false];
    }

    $q = [
      'token'   => $token,
      'date1'   => $date,
      'date2'   => $date,
      'fields'  => 'tag_name,impressions,clicks,money',
      'orderBy' => '-money',   // обязателен, без него 422
      'limit'   => 1000,       // тоже обязателен
      'offset'  => 0,
    ];

    $ch = curl_init($url . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986));
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => ['Accept: application/json'],
      CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = ($body !== false && $code >= 200 && $code < 300);
    if ($ok) {
      $data = json_decode((string)$body, true);
      if (is_array($data)) {
        $rows = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : $data;
        @file_put_contents($cf, json_encode($rows), LOCK_EX);
        @chmod($cf, 0666);   // задания ходят и от root, и от www-data
        return ['rows' => $rows, 'cached' => false, 'stale' => false];
      }
    }

    // API не ответил — пробуем просроченный кэш, чтобы не ронять задание
    if (is_file($cf)) {
      $j = json_decode((string)file_get_contents($cf), true);
      if (is_array($j)) return ['rows' => $j, 'cached' => true, 'stale' => true];
    }

    throw new RuntimeException("MyBid HTTP {$code}: " . substr((string)$body, 0, 200));
  }

  public function run(array $params): array
  {
    $token = trim((string)($params['token'] ?? ''));
    $url   = (string)($params['stats_url'] ?? 'https://publishers.mybid.io/backend/api/public/stats');
    $tag   = self::clean_tag((string)($params['tag_name'] ?? ''));

    if ($token === '') throw new RuntimeException('MyBidRevenueTag: нет токена');
    if ($tag === '')   throw new RuntimeException('MyBidRevenueTag: не задан tag_name');

    $tz    = (string)($params['timezone'] ?? 'UTC');
    $today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');

    $r    = $this->fetch_rows($token, $url, $today);
    $rows = $r['rows'];

    $mine = 0.0; $impr = 0; $clicks = 0; $account = 0.0; $found = false;
    foreach ($rows as $x) {
      if (!is_array($x)) continue;
      $money    = (float)($x['money'] ?? 0);
      $account += $money;
      if (strcasecmp(self::clean_tag((string)($x['tag_name'] ?? '')), $tag) === 0) {
        $found   = true;
        $mine   += $money;
        $impr   += (int)($x['impressions'] ?? 0);
        $clicks += (int)($x['clicks'] ?? 0);
      }
    }

    // Тега нет в выдаче — честный ноль: у домена либо нет спота в кабинете,
    // либо он сегодня ничего не принёс. Различить помогает "тег_найден".
    return [
      'date'  => $today,
      'items' => [[
        'external_id' => 'tag:' . $tag,
        'amount'      => round($mine, 6),
        'meta'        => [
          'оценка'         => false,
          'тег'            => $tag,
          'тег_найден'     => $found,
          'показы'         => $impr,
          'клики'          => $clicks,
          'доход_аккаунта' => round($account, 6),
          'из_кэша'        => $r['cached'],
          'кэш_просрочен'  => $r['stale'],
        ],
      ]],
      'debug' => ['tag' => $tag, 'found' => $found, 'amount' => $mine, 'cached' => $r['cached']],
    ];
  }
}

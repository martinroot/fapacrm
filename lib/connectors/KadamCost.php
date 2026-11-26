<?php
declare(strict_types=1);

require_once __DIR__ . '/ConnectorInterface.php';

final class KadamCost implements ConnectorInterface
{
  public static function code(): string { return 'kadam_cost'; }

  /* Ждём ответ не дольше 12 секунд. Раннер обходит задания последовательно,
     по 50 за проход, и при 30-секундном ожидании пара зависших вызовов
     съедала всю минуту: сбор по остальным доменам вставал целиком.
     Лучше быстро признать неудачу и собрать остальные. */
  private function kadam_get(string $endpoint, array $params, string $bearer, int $timeout=12): array
  {
    $url = 'https://api.kadam.net/' . $endpoint . '?' . http_build_query($params, '', '&');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $bearer],
      CURLOPT_TIMEOUT        => $timeout,
      CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
      'http' => $http,
      'err'  => $err ?: null,
      'raw'  => $raw,
      'json' => json_decode((string)$raw, true),
      'url'  => $url,
    ];
  }

  public function run(array $params): array
  {
    $bearer     = trim((string)($params['bearer'] ?? ''));
    $app_id     = (int)($params['app_id'] ?? 0);
    $client_id  = (int)($params['client_id'] ?? 0);
    $timezone   = (int)($params['timezone'] ?? 3);
    $campaignId = (int)($params['campaign_id'] ?? 0);

    if ($bearer === '' || $app_id <= 0 || $client_id <= 0 || $campaignId <= 0) {
      throw new RuntimeException('KadamCost: missing bearer/app_id/client_id/campaign_id');
    }

    // Kadam у тебя в старом скрипте считался по Europe/Moscow, оставляем так
    $tzOffset = sprintf('%+03d:00', $timezone);   // label the day in the SAME zone we request
    $today = (new DateTime('now', new DateTimeZone($tzOffset)))->format('Y-m-d');

    $apiParams = [
      'app_id'    => $app_id,
      'client_id' => $client_id,
      'campaigns' => (string)$campaignId,
      'group'     => 'campaign',
      'date_from' => $today,
      'date_to'   => $today,
      'timezone'  => $timezone,
      'limit'     => 50,
    ];

    $res = $this->kadam_get('ads.stats.campaign.get', $apiParams, $bearer);

    if ($res['http'] !== 200) {
      throw new RuntimeException("KadamCost HTTP {$res['http']} err={$res['err']} url={$res['url']} raw=" . substr((string)$res['raw'], 0, 700));
    }

    $j = $res['json'];
    $items = $j['response']['items'] ?? null;
    if (!is_array($items)) {
      throw new RuntimeException('KadamCost: bad JSON structure (no response.items)');
    }

    $found = null;
    foreach ($items as $it) {
      if ((int)($it['id'] ?? 0) === $campaignId) { $found = $it; break; }
    }
    if (!$found) $found = $items[0] ?? [];

    $moneyOut = (float)($found['moneyOut'] ?? 0.0);

    return [
      'date'  => $today,
      'items' => [
        [
          'external_id' => (string)$campaignId,
          'amount'      => $moneyOut,
          'meta'        => [
            'views'       => (int)($found['views'] ?? 0),
            'clicks'      => (int)($found['clicks'] ?? 0),
            'conversions' => (int)($found['conversions'] ?? 0),
            'moneyIn'     => (float)($found['moneyIn'] ?? 0.0),
            'cpc'         => (float)($found['cpc'] ?? 0.0),
            'ctr'         => (float)($found['ctr'] ?? 0.0),
          ],
        ],
      ],
      'debug' => ['url' => $res['url']],
    ];
  }
}

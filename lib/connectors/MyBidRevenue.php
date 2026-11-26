<?php
declare(strict_types=1);

require_once __DIR__ . '/ConnectorInterface.php';

final class MyBidRevenue implements ConnectorInterface
{
  public static function code(): string { return 'mybid_revenue'; }

  private function total_revenue(string $token, string $date1, string $date2, string $baseUrl): float
  {
    $params = [
      'token'   => $token,
      'date1'   => $date1,
      'date2'   => $date2,
      'fields'  => 'date,impressions,clicks,money',
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
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) throw new RuntimeException("MyBid cURL error: {$err}");
    if ($code < 200 || $code >= 300) throw new RuntimeException("MyBid HTTP {$code}: " . substr((string)$body, 0, 700));

    $data = json_decode((string)$body, true);
    if (!is_array($data)) throw new RuntimeException("MyBid JSON error");

    $rows = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : $data;

    $sum = 0.0;
    foreach ($rows as $r) {
      if (is_array($r)) $sum += (float)($r['money'] ?? 0);
    }
    return $sum;
  }

  public function run(array $params): array
  {
    $token = trim((string)($params['token'] ?? ''));
    $url   = (string)($params['stats_url'] ?? 'https://publishers.mybid.io/backend/api/public/stats');
    if ($token === '') throw new RuntimeException('MyBidRevenue: missing token');

    $tzName = (string)($params['timezone'] ?? 'UTC');
    $today = (new DateTime('now', new DateTimeZone($tzName)))->format('Y-m-d');
    $rev = $this->total_revenue($token, $today, $today, $url);

    return [
      'date'  => $today,
      'items' => [
        [
          'external_id' => 'account',
          'amount'      => $rev,
          'meta'        => [],
        ],
      ],
      'debug' => ['stats_url' => $url],
    ];
  }
}

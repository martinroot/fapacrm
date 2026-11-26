<?php
declare(strict_types=1);

require_once __DIR__ . '/ConnectorInterface.php';

final class ClickaduRevenue implements ConnectorInterface
{
  public static function code(): string { return 'clickadu_revenue'; }

  private function api_call(array $p, string $token, string $tz): array
  {
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
        'User-Agent: FapaCRM/1.0'
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
      throw new RuntimeException("Clickadu HTTP {$code} url={$url} body=" . substr($body,0,700));
    }

    $j = json_decode($body,true);
    if (!is_array($j)) {
      throw new RuntimeException("Clickadu JSON failed url={$url} body=" . substr($body,0,200));
    }
    $j['_url'] = $url;
    return $j;
  }

  public function run(array $params): array
  {
    $token = trim((string)($params['token'] ?? ''));
    $tz    = (string)($params['timezone'] ?? 'Europe/Moscow');
    $group = (string)($params['groupBy'] ?? 'zone');

    if ($token === '') throw new RuntimeException('ClickaduRevenue: missing token');

    $date = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');

    $resp = $this->api_call([
      'dateFrom' => $date,
      'dateTo'   => $date,
      'groupBy'  => $group,
      'format'   => 'json',
    ], $token, $tz);

    $sum = 0.0;
    foreach (($resp['stats'] ?? []) as $row) {
      $sum += (float)($row['money'] ?? 0.0);
    }

    return [
      'date'  => $date,
      'items' => [
        [
          'external_id' => 'account',
          'amount'      => $sum,
          'meta'        => ['groupBy' => $group],
        ],
      ],
      'debug' => ['url' => $resp['_url'] ?? null],
    ];
  }
}

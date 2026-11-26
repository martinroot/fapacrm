<?php
declare(strict_types=1);

require_once __DIR__ . '/ConnectorInterface.php';

final class TacoLocoRevenue implements ConnectorInterface
{
  public static function code(): string { return 'tacoloco_revenue'; }

  private function http_get(string $url, int $timeout=30): array
  {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => $timeout,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_HTTPHEADER     => [
        'accept: */*',
        'user-agent: FapaCRM/1.0',
      ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
      'code' => $code,
      'err'  => $err ?: null,
      'body' => $body,
      'url'  => $url,
    ];
  }

  /**
   * Fetch stats for one type+placement (or without placement).
   * Endpoint (как в твоём скрипте):
   *   /publisher/v1/statistics/{push|inpagepush}/date?startDate=...&endDate=...&token=...&placement=...
   */
  private function fetch_day_stats(
    string $baseUrl,
    string $token,
    string $type,
    string $startDate,
    string $endDate,
    ?int $placementId = null
  ): array {
    $type = ($type === 'inpagepush') ? 'inpagepush' : 'push';

    $params = [
      'startDate' => $startDate,
      'endDate'   => $endDate,
      'token'     => $token,
    ];
    if ($placementId !== null && $placementId > 0) {
      $params['placement'] = $placementId;
    }

    $url = rtrim($baseUrl, '/')
      . '/publisher/v1/statistics/' . $type . '/date'
      . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $r = $this->http_get($url);
    if ($r['body'] === false) {
      throw new RuntimeException("TacoLoco cURL error: " . (string)$r['err']);
    }
    if ($r['code'] < 200 || $r['code'] >= 300) {
      throw new RuntimeException("TacoLoco HTTP {$r['code']} url={$r['url']} body=" . substr((string)$r['body'], 0, 700));
    }

    $json = json_decode((string)$r['body'], true);
    if (!is_array($json)) {
      throw new RuntimeException("TacoLoco JSON error url={$r['url']} body=" . substr((string)$r['body'], 0, 200));
    }

    // нормализуем
    $out = [];
    foreach ($json as $row) {
      if (!is_array($row)) continue;
      $out[] = [
        'Date'        => (string)($row['Date'] ?? $row['date'] ?? ''),
        'Revenue'     => (float)($row['Revenue'] ?? $row['revenue'] ?? 0),
        'Impressions' => (int)($row['Impressions'] ?? $row['impressions'] ?? 0),
        'Active'      => (int)($row['Active'] ?? $row['active'] ?? 0),
        'New'         => (int)($row['New'] ?? $row['new'] ?? 0),
        'ScriptHits'  => (int)($row['ScriptHits'] ?? $row['scriptHits'] ?? 0),
      ];
    }

    return [
      'rows' => $out,
      'debug' => [
        'url' => $r['url'],
        'placement' => $placementId,
        'type' => $type,
      ]
    ];
  }

  public function run(array $params): array
  {
    $token = trim((string)($params['token'] ?? ''));
    if ($token === '') throw new RuntimeException('TacoLocoRevenue: missing token');

    $baseUrl  = (string)($params['base_url'] ?? 'https://api.tacolo.co');
    $tz       = (string)($params['timezone'] ?? 'Europe/Moscow');

    // считаем "сегодня" в нужной таймзоне TacoLoco (как у тебя в скрипте)
    $date = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');

    // Варианты:
    // 1) placements задан → суммируем revenue по ним (как у тебя)
    // 2) placements нет → считаем весь аккаунт: push + inpagepush без placement фильтра
    $placements = $params['placements'] ?? null;

    $total = 0.0;
    $debug = ['date'=>$date,'tz'=>$tz,'mode'=>'all_account','calls'=>[]];

    if (is_array($placements) && $placements) {
      $debug['mode'] = 'placements';
      foreach ($placements as $pl) {
        if (!is_array($pl)) continue;
        $plId = (int)($pl['id'] ?? 0);
        $type = (string)($pl['type'] ?? 'push');
        if ($plId <= 0) continue;

        $res = $this->fetch_day_stats($baseUrl, $token, $type, $date, $date, $plId);
        $debug['calls'][] = $res['debug'];

        foreach ($res['rows'] as $row) {
          if ((string)$row['Date'] === $date) {
            $total += (float)$row['Revenue'];
          }
        }
      }
    } else {
      // весь аккаунт: push + inpagepush
      $res1 = $this->fetch_day_stats($baseUrl, $token, 'push', $date, $date, null);
      $res2 = $this->fetch_day_stats($baseUrl, $token, 'inpagepush', $date, $date, null);
      $debug['calls'][] = $res1['debug'];
      $debug['calls'][] = $res2['debug'];

      foreach (array_merge($res1['rows'], $res2['rows']) as $row) {
        if ((string)$row['Date'] === $date) {
          $total += (float)$row['Revenue'];
        }
      }
    }

    return [
      'date'  => $date,
      'items' => [
        [
          'external_id' => 'account',
          'amount'      => $total,
          'meta'        => [
            'tz'   => $tz,
            'mode' => $debug['mode'],
          ],
        ],
      ],
      'debug' => $debug,
    ];
  }
}

<?php
declare(strict_types=1);
require_once __DIR__ . '/ConnectorInterface.php';
/**
 * Kadam Advertiser API v1 spend per campaign. Legacy ads.stats.campaign.get is HTTP 410.
 * Spend = first top-level slice row of POST /stats/extended (timezone 3).
 */
final class KadamCostV1 implements ConnectorInterface
{
  public static function code(): string { return 'kadam_cost_v1'; }

  public function run(array $params): array
  {
    $cid = (int)($params['campaign_id'] ?? 0);
    $key = (string)($params['api_key'] ?? $params['bearer'] ?? '');
    $tz  = (int)($params['timezone'] ?? 3);
    if ($cid <= 0 || $key === '') throw new RuntimeException('KadamCostV1: bad params');
    $day  = gmdate('Y-m-d', time() + $tz * 3600);
    $body = json_encode([
      'campaignIds' => [$cid], 'perPage' => 20,
      'filters' => ['dateFrom' => $day, 'dateTo' => $day, 'timezone' => $tz],
    ], JSON_THROW_ON_ERROR);
    $ch = curl_init('https://partners.kadam.net/api/v1/stats/extended');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
      CURLOPT_TIMEOUT => 40,
      CURLOPT_ENCODING => '', // auto-decode gzip — без этого json_decode получает бинарник
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $key, 'Accept: application/json',
        'Content-Type: application/json', 'User-Agent: curl/8.5.0',
      ],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($raw)) throw new RuntimeException("KadamCostV1 HTTP $code");
    $j = json_decode($raw, true);
    if (!is_array($j)) throw new RuntimeException('KadamCostV1: bad JSON');
    $rows = $j['data']['rows'] ?? [];
    $spend = 0.0; $raw_spend = null;
    foreach ($rows as $r) {
      if (is_array($r) && ($r['name']['type'] ?? null) === 'slice') {
        $raw_spend = $r['spending'] ?? null;
        $spend = (float)str_replace([' ', '$'], '', (string)$raw_spend);
        break;
      }
    }
    return ['date' => $day, 'items' => [[
      'external_id' => 'kadam:' . $cid,
      'amount'      => $spend,
      'meta'        => ['source' => 'kadam_v1', 'campaign_id' => $cid, 'spending_raw' => $raw_spend],
    ]], 'debug' => ['http' => $code, 'rows' => count($rows)]];
  }
}

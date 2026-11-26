<?php
declare(strict_types=1);
require_once __DIR__ . '/ConnectorInterface.php';

/**
 * Поведенческие метрики домена из VibeMetrics (page_view/scroll/time по сниппетам).
 * params: site (домен), api_key (IP-bound ключ из /root/.vm_agent_key)
 * Возвращает items[0]: external_id='vm:<site>', amount=sessions,
 * meta: visits/pageviews/avg_time/depth/visitors/human/bot/unknown/conversions/source
 * Ключи meta совместимы с OwnMetrikaToday/YandexMetrikaToday (интерфейс CRM читает их же).
 */
final class VibeMetricsToday implements ConnectorInterface
{
  public static function code(): string { return 'vibemetrics_today'; }

  public function run(array $params): array
  {
    $site = strtolower(trim((string)($params['site'] ?? '')));
    $key  = (string)($params['api_key'] ?? '');
    $to   = (int)($params['timeout'] ?? 25);
    if ($site === '' || !preg_match('/^[a-z0-9.\-]{3,80}$/', $site))
      throw new RuntimeException('VibeMetricsToday: bad site');
    if ($key === '')
      throw new RuntimeException('VibeMetricsToday: no api_key');

    $date = gmdate('Y-m-d'); // VibeMetrics day cut assumed UTC
    $url = 'https://vibemetrics.click/api/agent/stats?' . http_build_query([
      'domains' => $site, 'from' => $date, 'to' => $date,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $to, CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json',
                             'User-Agent: FapaCRM/1.0'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('VibeMetricsToday: curl fail');
    if ($code === 503)   throw new RuntimeException('VibeMetricsToday: 503 storage, retry later');
    if ($code < 200 || $code >= 300) throw new RuntimeException('VibeMetricsToday: HTTP ' . $code);
    $j = json_decode((string)$body, true);
    if (!is_array($j) || !isset($j['stats'][0]))
      throw new RuntimeException('VibeMetricsToday: bad payload');
    $x = $j['stats'][0];
    $sess = (int)($x['sessions'] ?? 0);
    $pv   = (int)($x['pageviews'] ?? 0);
    return ['date' => $date, 'items' => [[
      'external_id' => 'vm:' . $site,
      'amount'      => (float)$sess,
      'meta' => [
        'visits' => $sess, 'pageviews' => $pv,
        'avg_time' => (int)($x['avg_time_seconds'] ?? 0),
        'depth' => (int)($x['avg_scroll_depth'] ?? 0),
        'visitors' => (int)($x['visitors'] ?? 0),
        'human' => (int)($x['human_visitors'] ?? 0),
        'bot' => (int)($x['bot_visitors'] ?? 0),
        'unknown' => (int)($x['unknown_visitors'] ?? 0),
        'conversions' => (int)($x['conversions'] ?? 0),
        'source' => 'vibemetrics',
      ],
    ]], 'debug' => ['url' => $url, 'http_code' => $code]];
  }
}

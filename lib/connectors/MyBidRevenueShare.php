<?php
declare(strict_types=1);

require_once __DIR__ . '/ConnectorInterface.php';

/**
 * MyBid отдаёт статистику только по аккаунту целиком: поля site, adcode, domain
 * и любые фильтры по admpid он принимает с кодом 200, но молча игнорирует и
 * возвращает одну и ту же сумму. Проверено на всех вариантах.
 *
 * Поэтому доход аккаунта раскладывается между доменами MyBid пропорционально их
 * сегодняшним визитам. Это оценка, а не факт, и она помечена в meta — но она
 * несравнимо ближе к правде, чем повесить весь доход на один домен, а остальным
 * показать прочерк при живом расходе.
 *
 * Если визитов нет ни у кого, делим поровну — иначе домен с нулём в Метрике
 * получил бы ноль дохода при реально работающей рекламе.
 */
final class MyBidRevenueShare implements ConnectorInterface
{
  public static function code(): string { return 'mybid_revenue_share'; }

  private function account_revenue(string $token, string $url, string $date): float
  {
    $params = [
      'token'   => $token,
      'date1'   => $date,
      'date2'   => $date,
      'fields'  => 'date,impressions,clicks,money',
      'orderBy' => '-date',      // обязателен, без него 422
      'limit'   => 1000,         // тоже обязателен
      'offset'  => 0,
    ];
    $ch = curl_init($url . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => ['Accept: application/json'],
      CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300)
      throw new RuntimeException("MyBid HTTP {$code}: " . substr((string)$body, 0, 300));

    $data = json_decode((string)$body, true);
    if (!is_array($data)) throw new RuntimeException('MyBid: ответ не JSON');

    $rows = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : $data;
    $sum = 0.0;
    foreach ($rows as $r) if (is_array($r)) $sum += (float)($r['money'] ?? 0);
    return $sum;
  }

  public function run(array $params): array
  {
    $token = trim((string)($params['token'] ?? ''));
    $url   = (string)($params['stats_url'] ?? 'https://publishers.mybid.io/backend/api/public/stats');
    $self  = (int)($params['domain_id'] ?? 0);
    if ($token === '') throw new RuntimeException('MyBidRevenueShare: нет токена');
    if ($self <= 0)    throw new RuntimeException('MyBidRevenueShare: не задан domain_id');

    $tz    = (string)($params['timezone'] ?? 'UTC');
    $today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
    $total = $this->account_revenue($token, $url, $today);

    /* все домены на этом же аккаунте MyBid */
    $db = db_connect();
    $ids = [];
    $res = $db->query("SELECT domain_id FROM crm_cron_jobs
                        WHERE connector='" . self::code() . "' AND is_enabled=1");
    while ($res && ($r = $res->fetch_row())) $ids[] = (int)$r[0];
    if (!$ids) $ids = [$self];

    /* доля по сегодняшним визитам: последний снимок Метрики на домен */
    $in = implode(',', array_map('intval', $ids));
    $vis = array_fill_keys($ids, 0.0);
    /* знаменатель берём из сводки за день: она одинакова для всех заданий,
       поэтому доли складываются в единицу независимо от того, кто когда
       запустился. Сырой последний тик для этого не годится — он меняется
       между запусками, и сумма долей уезжает. */
    $res = $db->query("
        SELECT domain_id, SUM(amount) AS amount
          FROM crm_finance_daily
         WHERE kind='metric' AND d=CURDATE() AND domain_id IN ($in)
         GROUP BY domain_id");
    while ($res && ($r = $res->fetch_assoc())) $vis[(int)$r['domain_id']] = (float)$r['amount'];

    $sumVis = array_sum($vis);
    if ($sumVis > 0) {
      $share = ($vis[$self] ?? 0.0) / $sumVis;
      $basis = 'визиты';
    } else {
      $share = 1.0 / max(1, count($ids));
      $basis = 'поровну (визитов нет)';
    }

    return [
      'date'  => $today,
      'items' => [[
        'external_id' => 'account_share',
        'amount'      => round($total * $share, 6),
        'meta'        => [
          'оценка'         => true,
          'доход_аккаунта' => round($total, 6),
          'доля'           => round($share, 4),
          'основа'         => $basis,
          'доменов'        => count($ids),
        ],
      ]],
      'debug' => ['stats_url' => $url, 'total' => $total, 'share' => $share],
    ];
  }
}

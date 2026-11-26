<?php declare(strict_types=1);

require_once __DIR__ . '/ConnectorInterface.php';

/**
 * KadamPublisherRevenue (PUB)
 *
 * Uses Kadam Publisher API:
 *   POST https://pub.kadam.net/api/custom-reports/data
 *
 * Notes:
 * - Bearer token is stored in params_json as "secret" (legacy name kept to avoid DB migration).
 * - API requires "groups" NOT to be blank. We use ["time_day"] as a neutral grouping.
 * - period=today is buggy on their side (returns code=0). We always use dateFrom/dateTo.
 *
 * Params (from crm_cron_jobs.params_json):
 *  - secret            (string)  REQUIRED. Bearer token
 *  - timezone          (string)  Optional. PHP timezone name (default Europe/Moscow) used only for "today" calculation
 *  - date_from         (string)  Optional. YYYY-MM-DD
 *  - date_to           (string)  Optional. YYYY-MM-DD
 *  - timezone_offset   (int)     Optional. -12..12. If missing, derived from "timezone" when possible (MSK=3)
 */
final class KadamPublisherRevenue implements ConnectorInterface
{
    public static function code(): string { return 'kadam_pub_revenue'; }

    private function must_string(array $params, string $key): string
    {
        $v = trim((string)($params[$key] ?? ''));
        if ($v === '') throw new RuntimeException("KadamPublisherRevenue: missing {$key}");
        return $v;
    }

    private function http_json_post(string $url, array $headers, array $payload, int $timeout=30): array
    {
        $ch = curl_init($url);

        $h = [];
        foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $h,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

    private function infer_offset(string $tzName): int
    {
        // Kadam expects offset -12..12. We keep it simple and safe.
        if ($tzName === 'Europe/Moscow') return 3;

        // Best-effort: derive current offset hours from timezone name.
        // (Not perfect for half-hour zones; if you need them, store timezone_offset explicitly.)
        try {
            $tz = new DateTimeZone($tzName);
            $dt = new DateTime('now', $tz);
            $sec = $tz->getOffset($dt);
            $h = (int)round($sec / 3600);
            if ($h < -12) $h = -12;
            if ($h > 12)  $h = 12;
            return $h;
        } catch (Throwable $e) {
            return 3; // sane default for your setup
        }
    }

    public function run(array $params): array
    {
        // Legacy param name "secret" is actually a Bearer token for pub.kadam.net
        $bearer = $this->must_string($params, 'secret');

        $tzName = (string)($params['timezone'] ?? 'Europe/Moscow');
        try { $tz = new DateTimeZone($tzName); }
        catch (Throwable $e) { $tzName = 'Europe/Moscow'; $tz = new DateTimeZone($tzName); }

        $today = (new DateTime('now', $tz))->format('Y-m-d');
        $date_from = (string)($params['date_from'] ?? $today);
        $date_to   = (string)($params['date_to'] ?? $today);

        $tzOffset = isset($params['timezone_offset'])
            ? (int)$params['timezone_offset']
            : $this->infer_offset($tzName);

        // API requires groups to be NOT blank -> use neutral grouping time_day
        $payload = [
            'groups'  => ['time_day'],
            'metrics' => ['finance_moneyIn'],
            'filters' => [
                'dateFrom' => $date_from,
                'dateTo'   => $date_to,
                'timezone' => $tzOffset,
                'filters'  => [],
            ],
            'page'    => 1,
            'perPage' => 50,
            'sort'    => [ 'finance_moneyIn' => 'desc' ],
        ];

        $url = 'https://pub.kadam.net/api/custom-reports/data';
        $headers = [
            'accept'        => 'application/json',
            'content-type'  => 'application/json',
            'authorization' => 'Bearer ' . $bearer,
            'user-agent'    => 'FapaCRM/1.0',
        ];

        $r = $this->http_json_post($url, $headers, $payload, 30);

        if ($r['body'] === false || $r['body'] === null) {
            throw new RuntimeException("KadamPub cURL error: " . (string)$r['err']);
        }
        if ($r['code'] < 200 || $r['code'] >= 300) {
            throw new RuntimeException("KadamPub HTTP {$r['code']} url={$r['url']} body=" . substr((string)$r['body'], 0, 900));
        }

        $j = json_decode((string)$r['body'], true);
        if (!is_array($j)) {
            throw new RuntimeException("KadamPub: response is not JSON. url={$r['url']}");
        }
        if (!($j['success'] ?? false)) {
            $msg = $j['msg'] ?? null;
            throw new RuntimeException("KadamPub: api error: " . (is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
        }

        $moneyIn = 0.0;
        $data = $j['data'] ?? null;

        if (is_array($data)) {
            // Prefer total if present
            if (isset($data['total']['finance_moneyIn']['value'])) {
                $moneyIn = (float)$data['total']['finance_moneyIn']['value'];
            } elseif (isset($data['rows'][0]['finance_moneyIn']['value'])) {
                // Fallback: first row
                $moneyIn = (float)$data['rows'][0]['finance_moneyIn']['value'];
            } elseif (isset($data['rows']) && is_array($data['rows'])) {
                // Fallback: sum rows
                foreach ($data['rows'] as $row) {
                    if (!is_array($row)) continue;
                    $fi = $row['finance_moneyIn'] ?? null;
                    if (is_array($fi) && isset($fi['value'])) $moneyIn += (float)$fi['value'];
                }
            }
        }

        return [
            'date'  => $today,
            'items' => [
                [
                    'external_id' => 'pub_total',
                    'amount'      => $moneyIn,
                    'meta'        => [
                        'date_from' => $date_from,
                        'date_to'   => $date_to,
                        'tz'        => $tzName,
                        'tz_offset' => $tzOffset,
                        'api'       => 'https://pub.kadam.net/api/custom-reports/data',
                        'group'     => 'time_day',
                        'metric'    => 'finance_moneyIn',
                    ],
                ],
            ],
            'debug' => [
                'url'       => $r['url'],
                'http_code' => $r['code'],
                'date_from' => $date_from,
                'date_to'   => $date_to,
                'tz'        => $tzName,
                'tz_offset' => $tzOffset,
                'request'   => $payload,
                'raw'       => $j,
            ],
        ];
    }
}

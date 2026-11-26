<?php
declare(strict_types=1);

/**
 * lib/cron/finance_alerts.php
 *
 * Проверяет финансы за день и шлёт Telegram-алерт, если домен ушёл в минус ниже порога.
 * Ограничение: 1 алерт в день на домен (через crm_finance_alerts uniq).
 *
 * ENV:
 *   TG_TOKEN   - токен бота
 *   TG_CHAT_ID - chat id (например -100...)
 *   FIN_ALERT_THRESHOLD - порог (например -0.3)
 *   CRM_BASE_URL - базовый URL для ссылки (например https://fapa.io/cmd/menu.php)
 */

if (!function_exists('db_connect')) {
  $try = __DIR__ . '/../../config.php';
  if (is_file($try)) require_once $try;
}

$db = db_connect();

$day = date('Y-m-d');
//$threshold = (float)(getenv('FIN_ALERT_THRESHOLD') !== false ? getenv('FIN_ALERT_THRESHOLD') : '-0.3');
$threshold = -0.2;

$tgToken = '356049721:AAFV49yK6ev-mPePw8pQRNKCBU8U3Ym_mgg';
$tgChat  = '-1003677160688';

if ($tgToken === '' || $tgChat === '') {
  // Без токена/чата просто тихо выходим (или логируй куда тебе надо)
  exit(0);
}

$baseUrl = (string)(getenv('CRM_BASE_URL') ?: '');
if ($baseUrl === '') {
  // Фоллбек: пытаемся угадать, но для cron лучше задавать CRM_BASE_URL в env
  $baseUrl = 'https://analfluxtube.ru/fapacrm/menu.php?page=finance';
}

// Берём домены (можно ограничить только активные, если у тебя есть флаг)
$domains = db_all($db, "SELECT id, domain FROM crm_domains ORDER BY domain");

// Собираем spend/rev по домену за день
$rows = db_all($db, "
  SELECT domain_id,
         SUM(CASE WHEN kind='cost' THEN amount ELSE 0 END) AS spend,
         SUM(CASE WHEN kind='revenue' THEN amount ELSE 0 END) AS revenue
  FROM crm_finance_daily
  WHERE d=?
  GROUP BY domain_id
", [$day]);

$byDid = [];
foreach ($rows as $r) {
  $did = (int)$r['domain_id'];
  $byDid[$did] = [
    'spend' => (float)$r['spend'],
    'rev'   => (float)$r['revenue'],
  ];
}

function tg_send(string $token, string $chatId, string $text, ?string $threadId=null): bool
{ return false; /* muted */
  $url = "https://api.telegram.org/bot{$token}/sendMessage";
  $post = [
    'chat_id' => $chatId,
    'text' => $text,
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => '1',
  ];
  if ($threadId !== null && $threadId !== '') $post['message_thread_id'] = $threadId;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($post),
  ]);
  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return ($err === '' && $code >= 200 && $code < 300 && is_string($body));
}

function money6(float $x): string { return number_format($x, 6, '.', ''); }
function money2(float $x): string { return number_format($x, 2, '.', ''); }

$alertType = 'profit_below';
$sent = 0;

foreach ($domains as $d) {
  $did = (int)$d['id'];
  $domain = (string)$d['domain'];

  $spend = (float)($byDid[$did]['spend'] ?? 0.0);
  $rev   = (float)($byDid[$did]['rev'] ?? 0.0);
  $profit = $rev - $spend;

  // Условие: домен ушёл в минус ниже порога (например -0.3$)
  if ($profit > $threshold) continue;

  // Пытаемся записать алерт (если уже был сегодня — UNIQUE не даст)
  // ВАЖНО: db_exec должен бросать/возвращать affected_rows; если у тебя другой хелпер — подстрой.
  $ok = false;
  try {
    // 1) проверим быстро существование, чтобы не ловить исключение на UNIQUE (если db_exec не умеет IGNORE)
    $exists = db_all($db, "SELECT id FROM crm_finance_alerts WHERE d=? AND domain_id=? AND alert_type=? LIMIT 1", [$day, $did, $alertType]);
    if ($exists) continue;

    db_exec($db, "
      INSERT INTO crm_finance_alerts (d, domain_id, alert_type, threshold, spend, revenue, profit)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ", [$day, $did, $alertType, $threshold, $spend, $rev, $profit]);

    $ok = true;
  } catch (\Throwable $e) {
    // если вставка упала из-за гонки/uniq — просто пропускаем
    $ok = false;
  }

  if (!$ok) continue;

  $linkAll = $baseUrl . '?page=finance&d=' . rawurlencode($day);
  $linkDom = $baseUrl . '?page=finance&d=' . rawurlencode($day) . '&domain_id=' . rawurlencode((string)$did);

  $text =
    "🔻 <b>Финансы: домен в минусе</b>\n" .
    "День: <b>{$day}</b>\n" .
    "Домен: <b>" . htmlspecialchars($domain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n" .
    "Порог: <b>" . money2($threshold) . "</b>\n" .
    "Spend: <b>" . money6($spend) . "</b>\n" .
    "Revenue: <b>" . money6($rev) . "</b>\n" .
    "Profit: <b>" . money6($profit) . "</b>\n\n" .
    "➡️ <a href=\"https://analfluxtube.ru/fapacrm/menu.php?page=finance\">Открыть финансы домена</a>\n";

  if (tg_send($tgToken, $tgChat, $text)) {
    $sent++;
  }
}

// Можно вывести в stdout для логов cron:
echo "finance_alerts: day={$day} threshold={$threshold} sent={$sent}\n";

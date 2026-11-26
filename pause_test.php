<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$cid = isset($_GET['cid']) ? (int)$_GET['cid'] : 878063;

// твой bearer (можешь менять через ?bearer=... )
$bearer = isset($_GET['bearer'])
  ? trim((string)$_GET['bearer'])
  : 'b706871c0869c9b42cb159ac90cec69f';

$url = 'https://partners.kadam.net/api/v1/campaigns/pause';
$body = json_encode(['campaignIds' => [$cid]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$headers = [
  'accept: application/json',
  'Content-Type: application/json',
  'User-Agent: curl/8.0 (PHP test)',
];

if ($bearer !== '') {
  $headers[] = 'Authorization: Bearer ' . $bearer;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HEADER         => true,
  CURLOPT_TIMEOUT        => 25,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_CUSTOMREQUEST  => 'POST',
  CURLOPT_HTTPHEADER     => $headers,
  CURLOPT_POSTFIELDS     => $body,
  CURLOPT_ENCODING       => '', // пусть распакует gzip
]);

$resp  = curl_exec($ch);
$errno = curl_errno($ch);
$err   = curl_error($ch);
$http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hdrSz = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$respHeaders = ($resp !== false) ? substr($resp, 0, $hdrSz) : '';
$respBody    = ($resp !== false) ? substr($resp, $hdrSz) : '';

header('Content-Type: text/plain; charset=utf-8');

echo "REQUEST\n";
echo "URL: {$url}\n";
echo "CID: {$cid}\n";
echo "Bearer: " . ($bearer !== '' ? 'YES (len='.strlen($bearer).')' : 'NO') . "\n";
echo "BODY: {$body}\n\n";

echo "RESPONSE\n";
echo "HTTP: {$http}\n";
echo "curl_errno: {$errno}\n";
echo "curl_error: ".($err ?: 'none')."\n\n";
echo "HEADERS:\n{$respHeaders}\n";
echo "BODY:\n{$respBody}\n";

<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/connectors/KadamCostV1.php';
function t(bool $ok, string $m): void { if (!$ok) { fwrite(STDERR, "FAIL $m\n"); exit(1); } }
t(KadamCostV1::code() === 'kadam_cost_v1', 'code');
$r = (new KadamCostV1())->run(['campaign_id'=>948645, 'api_key'=>getenv('KADAM_BEARER') ?: '']);
t(isset($r['items'][0]['external_id']) && $r['items'][0]['external_id'] === 'kadam:948645', 'external_id');
t(isset($r['items'][0]['meta']['source']) && $r['items'][0]['meta']['source'] === 'kadam_v1', 'meta');
t(isset($r['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['date']) === 1, 'date');
echo "PASS KadamCostV1\n";

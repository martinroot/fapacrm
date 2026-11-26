<?php
// /var/www/fapa.io/fapacrm/pages/deploy/13_branding.php
declare(strict_types=1);

/**
 * Шаг: Branding: замена донора на новый домен/бренд в ключевых файлах + генерация logo.png
 * Требует $ctx: path, domain, templateDomain, templateBrand, newBrand
 */

if (!isset($ctx) || !is_array($ctx)) {
  return ['rc'=>2, 'out'=>"No ctx\n"];
}

$sitePath  = (string)($ctx['path'] ?? '');
$newDomain = (string)($ctx['domain'] ?? '');
$tplDomain = (string)($ctx['templateDomain'] ?? '');
$tplBrand  = (string)($ctx['templateBrand'] ?? '');
$newBrand  = (string)($ctx['newBrand'] ?? '');

if ($sitePath === '' || $newDomain === '') {
  return ['rc'=>2, 'out'=>"branding: ctx.path/domain empty\n"];
}
if (!is_dir($sitePath)) {
  return ['rc'=>2, 'out'=>"branding: site path not found: {$sitePath}\n"];
}

$out = [];
$out[] = "Branding step";
$out[] = "sitePath: {$sitePath}";
$out[] = "newDomain: {$newDomain}";
$out[] = "tplDomain: {$tplDomain}";
$out[] = "tplBrand : {$tplBrand}";
$out[] = "newBrand : {$newBrand}";

function normalize_domain(string $d): string {
  $d = trim($d);
  $d = preg_replace('~^https?://~i', '', $d);
  $d = preg_replace('~^www\.~i', '', $d);
  $d = preg_replace('~[:/].*$~', '', $d);
  return strtolower($d);
}

$tplDomainN = normalize_domain($tplDomain);
$newDomainN = normalize_domain($newDomain);

// какие файлы правим
$targets = [
  $sitePath . '/index.php',
  $sitePath . '/cat.php',
  $sitePath . '/popular.php',
  $sitePath . '/video.php',
];

// подготовим набор замен (домен + www + бренд)
$repl = [];

// домены (как есть/в нижнем/с www)
if ($tplDomainN !== '' && $newDomainN !== '' && $tplDomainN !== $newDomainN) {
  $repl[$tplDomainN] = $newDomainN;
  $repl['www.'.$tplDomainN] = 'www.'.$newDomainN;
}

// брендовые замены (если вообще отличаются)
$tplBrandT = trim($tplBrand);
$newBrandT = trim($newBrand);
if ($tplBrandT !== '' && $newBrandT !== '' && mb_strtolower($tplBrandT, 'UTF-8') !== mb_strtolower($newBrandT, 'UTF-8')) {
  $repl[$tplBrandT] = $newBrandT;
  $repl[mb_strtolower($tplBrandT, 'UTF-8')] = mb_strtolower($newBrandT, 'UTF-8');
  $repl[mb_strtoupper($tplBrandT, 'UTF-8')] = mb_strtoupper($newBrandT, 'UTF-8');
}

// применить замены в файле
function replace_in_file(string $file, array $map, array &$log): bool {
  if (!is_file($file)) { $log[] = "SKIP missing: {$file}"; return true; }
  $src = file_get_contents($file);
  if ($src === false) { $log[] = "FAIL read: {$file}"; return false; }

  $before = $src;
  foreach ($map as $from => $to) {
    if ($from === '' || $from === $to) continue;
    $src = str_replace($from, $to, $src);
  }

  if ($src === $before) {
    $log[] = "OK no changes: {$file}";
    return true;
  }

  $tmp = $file . '.tmp_' . bin2hex(random_bytes(4));
  if (file_put_contents($tmp, $src) === false) { $log[] = "FAIL write tmp: {$file}"; return false; }
  @chmod($tmp, 0644);
  if (!rename($tmp, $file)) { @unlink($tmp); $log[] = "FAIL rename tmp: {$file}"; return false; }

  $log[] = "OK updated: {$file}";
  return true;
}

$ok = true;
foreach ($targets as $f) {
  if (!replace_in_file($f, $repl, $out)) $ok = false;
}
if (!$ok) {
  $out[] = "branding: file replace failed";
  return ['rc'=>1, 'out'=>implode("\n", $out)."\n"];
}

// ---------- LOGO ----------
function find_logo_target(string $sitePath): ?string {
  $cands = [
    $sitePath . '/assets/logo.png',
    $sitePath . '/asset/logo.png',
    $sitePath . '/img/logo.png',
    $sitePath . '/images/logo.png',
    $sitePath . '/static/logo.png',
    $sitePath . '/public/logo.png',
  ];
  foreach ($cands as $p) if (is_file($p)) return $p;

  // если не нашли — можно создать в assets по умолчанию
  $fallbackDir = $sitePath . '/assets';
  if (is_dir($fallbackDir)) return $fallbackDir . '/logo.png';

  return null;
}

function gen_logo_png(string $path, string $text, array &$log): bool {
  if (!function_exists('imagecreatetruecolor')) {
    $log[] = "SKIP logo: GD not available";
    return true; // не валим деплой
  }

  $w = 360; $h = 90;
  $im = imagecreatetruecolor($w, $h);
  if (!$im) { $log[] = "FAIL logo: cannot create image"; return false; }

  imagesavealpha($im, true);
  $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
  imagefill($im, 0, 0, $transparent);

  // простая подложка (полупрозрачная)
  $bg = imagecolorallocatealpha($im, 10, 10, 10, 40);
  imagefilledrectangle($im, 0, 0, $w-1, $h-1, $bg);

  $white = imagecolorallocate($im, 255, 255, 255);

  $text = trim($text);
  if ($text === '') $text = 'SITE';

  $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
  if (is_file($font) && function_exists('imagettftext')) {
    $size = 26;
    // уменьшаем шрифт если строка длинная
    if (mb_strlen($text, 'UTF-8') > 18) $size = 22;
    if (mb_strlen($text, 'UTF-8') > 24) $size = 18;

    // центрирование
    $bbox = imagettfbbox($size, 0, $font, $text);
    $tw = abs(($bbox[2] ?? 0) - ($bbox[0] ?? 0));
    $th = abs(($bbox[7] ?? 0) - ($bbox[1] ?? 0));
    $x = (int)(($w - $tw) / 2);
    $y = (int)(($h + $th) / 2);

    imagettftext($im, $size, 0, $x, $y, $white, $font, $text);
  } else {
    // fallback: встроенный шрифт
    imagestring($im, 5, 10, (int)($h/2 - 7), $text, $white);
  }

  // ensure dir
  $dir = dirname($path);
  if (!is_dir($dir)) {
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
      imagedestroy($im);
      $log[] = "FAIL logo: cannot create dir {$dir}";
      return false;
    }
  }

  $tmp = $path . '.tmp_' . bin2hex(random_bytes(4));
  $res = imagepng($im, $tmp);
  imagedestroy($im);
  if (!$res) { @unlink($tmp); $log[] = "FAIL logo: cannot write png"; return false; }

  @chmod($tmp, 0644);
  if (!@rename($tmp, $path)) { @unlink($tmp); $log[] = "FAIL logo: rename failed"; return false; }

  $log[] = "OK logo generated: {$path}";
  return true;
}

$logoPath = find_logo_target($sitePath);
if ($logoPath) {
  // если newBrand пустой — хотя бы домен на лого
  $logoText = $newBrand !== '' ? $newBrand : $newDomainN;
  if (!gen_logo_png($logoPath, $logoText, $out)) {
    $out[] = "branding: logo generation failed";
    return ['rc'=>1, 'out'=>implode("\n", $out)."\n"];
  }
} else {
  $out[] = "SKIP logo: logo target not found (no assets/img/images/static/public folder)";
}

return ['rc'=>0, 'out'=>implode("\n", $out)."\n"];

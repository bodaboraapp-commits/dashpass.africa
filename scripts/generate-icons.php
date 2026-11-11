<?php

declare(strict_types=1);

/**
 * MtaaPass brand icon generator (Blueprint §17 / §46, contract §10).
 *
 * Renders the five PWA icons with GD so the repository never depends on
 * binary assets from a designer or an external CDN:
 *
 *   public/assets/icons/icon-192.png         192x192  rounded, "any"
 *   public/assets/icons/icon-512.png         512x512  rounded, "any"
 *   public/assets/icons/icon-maskable-512.png 512x512 full-bleed, maskable safe zone
 *   public/assets/icons/apple-touch-icon.png 180x180  full-bleed (iOS masks it)
 *   public/assets/icons/favicon.png           32x32   rounded, simplified mark
 *
 * Brand mark: a forest gateway arch with a sunshine "pass" light inside it —
 * estate access, calm and legible down to 32px.
 *
 * Usage: php scripts/generate-icons.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "The GD extension is required (php -m | findstr gd).\n");
    exit(1);
}

const NAVY = '#0B1222';
const ACCENT = '#3F5CFF';
const ACCENT_LIGHT = '#A9B8FF';

/**
 * @return array{0:int,1:int,2:int,3:int} [r, g, b, alpha]
 */
function icon_rgba(string $hex, int $alpha = 0): array
{
    $hex = ltrim($hex, '#');

    return [
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
        max(0, min(127, $alpha)),
    ];
}

/**
 * @param resource|\GdImage $image
 * @param array{0:int,1:int,2:int,3:int} $rgba
 */
function icon_allocate($image, array $rgba): int
{
    $color = imagecolorallocatealpha($image, $rgba[0], $rgba[1], $rgba[2], $rgba[3]);

    if ($color === false) {
        fwrite(STDERR, "Unable to allocate a colour.\n");
        exit(1);
    }

    return $color;
}

/**
 * Filled rounded rectangle (colour is opaque; radius is in device pixels).
 *
 * @param resource|\GdImage $image
 */
function icon_rounded_rect($image, int $x, int $y, int $w, int $h, int $r, int $color): void
{
    $r = max(0, min($r, (int) floor(min($w, $h) / 2)));

    if ($r === 0) {
        imagefilledrectangle($image, $x, $y, $x + $w - 1, $y + $h - 1, $color);

        return;
    }

    // Body plus four corner circles.
    imagefilledrectangle($image, $x + $r, $y, $x + $w - $r - 1, $y + $h - 1, $color);
    imagefilledrectangle($image, $x, $y + $r, $x + $w - 1, $y + $h - $r - 1, $color);
    imagefilledellipse($image, $x + $r, $y + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($image, $x + $w - $r - 1, $y + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($image, $x + $r, $y + $h - $r - 1, $r * 2, $r * 2, $color);
    imagefilledellipse($image, $x + $w - $r - 1, $y + $h - $r - 1, $r * 2, $r * 2, $color);
}

/**
 * Draw the gateway mark. All coordinates are fractions of the canvas size so
 * the same geometry scales to every icon. `$scale` shrinks the mark about the
 * canvas centre so maskable icons stay inside the 80% safe zone.
 *
 * @param resource|\GdImage $image
 */
function icon_draw_mark($image, int $canvas, int $background, float $scale = 1.0): void
{
    $c = (float) $canvas;

    // Map a normalised (fraction of canvas) coordinate into the scaled mark.
    $px = static fn (float $fraction): int => (int) round($c * (0.5 + ($fraction - 0.5) * $scale));
    $len = static fn (float $fraction): int => (int) round($fraction * $c * $scale);

    $cx = $px(0.5);
    $radius = $len(0.27);
    $stroke = $len(0.085);
    $springY = $px(0.52);
    $baseY = $px(0.78);
    $innerRadius = max(1, $radius - $stroke);

    $accent = icon_allocate($image, icon_rgba(ACCENT));
    $accentLight = icon_allocate($image, icon_rgba(ACCENT_LIGHT));

    // Outer silhouette: semicircular arch on two posts.
    imagefilledellipse($image, $cx, $springY, $radius * 2, $radius * 2, $accent);
    imagefilledrectangle($image, $cx - $radius, $springY, $cx + $radius, $baseY, $accent);

    // Cut the inside back to the background colour, leaving an arch outline.
    imagefilledellipse($image, $cx, $springY, $innerRadius * 2, $innerRadius * 2, $background);
    imagefilledrectangle($image, $cx - $innerRadius, $springY, $cx + $innerRadius, $baseY, $background);

    // Accent "pass" signal inside the gateway.
    $dotRadius = max(1, $len(0.075));
    imagefilledellipse($image, $cx, $px(0.62), $dotRadius * 2, $dotRadius * 2, $accentLight);

    // Grounded accent line under the arch so the mark does not float.
    imagefilledrectangle(
        $image,
        $px(0.185),
        $px(0.815),
        $px(0.815),
        $px(0.86),
        $accent
    );
}

/**
 * Render one icon.
 *
 * @param 'rounded'|'square' $shape
 */
function generate_icon(string $path, int $size, string $shape, bool $withGlow = true, float $markScale = 1.0): bool
{
    // Supersample, then downscale: GD has no anti-aliased primitives.
    $factor = (int) max(4, min(16, (int) round(1536 / max(1, $size))));
    $canvas = $size * $factor;

    $image = imagecreatetruecolor($canvas, $canvas);

    if ($image === false) {
        return false;
    }

    imagealphablending($image, false);
    imagesavealpha($image, true);
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
    imagefilledrectangle($image, 0, 0, $canvas - 1, $canvas - 1, $transparent);
    imagealphablending($image, true);

    $navy = icon_allocate($image, icon_rgba(NAVY));
    $cornerRadius = (int) round($canvas * 0.225);

    if ($shape === 'rounded') {
        icon_rounded_rect($image, 0, 0, $canvas, $canvas, $cornerRadius, $navy);
    } else {
        imagefilledrectangle($image, 0, 0, $canvas - 1, $canvas - 1, $navy);
    }

    // Soft emerald and sunshine highlights — depth without a gradient bitmap.
    if ($withGlow) {
        imagefilledellipse(
            $image,
            (int) round($canvas * 0.72),
            (int) round($canvas * 0.16),
            (int) round($canvas * 0.95),
            (int) round($canvas * 0.75),
            icon_allocate($image, icon_rgba(ACCENT, 96))
        );
        imagefilledellipse(
            $image,
            (int) round($canvas * 0.10),
            (int) round($canvas * 0.95),
            (int) round($canvas * 0.65),
            (int) round($canvas * 0.55),
            icon_allocate($image, icon_rgba(ACCENT_LIGHT, 118))
        );

        // The glow also painted the transparent corners back in; restore them
        // with alpha blending disabled so transparent pixels really are clear.
        if ($shape === 'rounded') {
            imagealphablending($image, false);

            for ($y = 0; $y < $cornerRadius; $y++) {
                $dx = (int) round($cornerRadius - sqrt(max(0.0, ($cornerRadius ** 2) - (($cornerRadius - $y) ** 2))));

                if ($dx <= 0) {
                    continue;
                }

                imagefilledrectangle($image, 0, $y, $dx - 1, $y, $transparent);
                imagefilledrectangle($image, $canvas - $dx, $y, $canvas - 1, $y, $transparent);
                imagefilledrectangle($image, 0, $canvas - 1 - $y, $dx - 1, $canvas - 1 - $y, $transparent);
                imagefilledrectangle($image, $canvas - $dx, $canvas - 1 - $y, $canvas - 1, $canvas - 1 - $y, $transparent);
            }

            imagealphablending($image, true);
        }
    }

    // Cut the arch interior with the flat background colour: blended glows are
    // erased inside the gateway, which reads as depth.
    $cut = icon_allocate($image, icon_rgba(NAVY));
    icon_draw_mark($image, $canvas, $cut, $markScale);

    $out = imagecreatetruecolor($size, $size);

    if ($out === false) {
        imagedestroy($image);

        return false;
    }

    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefilledrectangle($out, 0, 0, $size - 1, $size - 1, $transparent);
    imagealphablending($out, true);

    imagecopyresampled($out, $image, 0, 0, 0, 0, $size, $size, $canvas, $canvas);

    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        fwrite(STDERR, "Unable to create directory: {$directory}\n");
        imagedestroy($out);
        imagedestroy($image);

        return false;
    }

    $ok = imagepng($out, $path, 6);

    imagedestroy($out);
    imagedestroy($image);

    return (bool) $ok;
}

$root = dirname(__DIR__);
$directory = $root . '/public/assets/icons';

$targets = [
    // path, size, shape, glow, mark scale
    [$directory . '/icon-192.png', 192, 'rounded', true, 1.0],
    [$directory . '/icon-512.png', 512, 'rounded', true, 1.0],
    [$directory . '/icon-maskable-512.png', 512, 'square', true, 0.78],
    [$directory . '/apple-touch-icon.png', 180, 'square', false, 1.0],
    [$directory . '/favicon.png', 32, 'rounded', false, 1.0],
];

$failed = 0;

foreach ($targets as [$path, $size, $shape, $glow, $markScale]) {
    if (!generate_icon($path, $size, $shape, $glow, $markScale)) {
        fwrite(STDERR, "FAILED  {$path}\n");
        $failed++;

        continue;
    }

    $info = @getimagesize($path);

    if ($info === false || (int) $info[0] !== $size || (int) $info[1] !== $size) {
        fwrite(STDERR, "FAILED  {$path} (unexpected dimensions)\n");
        $failed++;

        continue;
    }

    $bytes = filesize($path);
    printf("OK  %-28s %dx%d  %s\n", basename($path), $info[0], $info[1], $bytes === false ? '?' : $bytes . ' bytes');
}

if ($failed > 0) {
    fwrite(STDERR, "{$failed} icon(s) failed.\n");
    exit(1);
}

printf("\nGenerated %d icons in public/assets/icons\n", count($targets));
exit(0);

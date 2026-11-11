<?php

declare(strict_types=1);

/**
 * MtaaPass resident hero artwork generator (addendum §5).
 *
 * Renders public/assets/img/estate-hero.png — a 1600x900 dusk estate scene:
 * deep-navy sky, a warm horizon band, layered building silhouettes, a lit modern
 * glass facade, courtyard lamps and a water reflection. The lower part is
 * deliberately darkened so the white estate name overlaid by
 * app/Views/app/home.php remains legible.
 *
 * Deterministic and dependency-free apart from GD: no network, no external
 * images or fonts. Re-running produces a byte-identical file.
 *
 * Usage: php scripts/generate-estate-hero.php [--check]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "The GD extension is required (php -m | findstr gd).\n");
    exit(1);
}

const HERO_WIDTH = 1600;
const HERO_HEIGHT = 900;
const HERO_SEED = 20261111;

/**
 * Build a colour. The fourth argument is an OPACITY in 0..127 (0 = invisible,
 * 127 = fully opaque) — the inverse of GD's internal alpha (0 = opaque), which
 * this helper converts so drawing code reads naturally.
 *
 * @return array{0:int,1:int,2:int,3:int} [r, g, b, gd-alpha]
 */
function hero_rgba(int $r, int $g, int $b, int $opacity = 127): array
{
    return [
        max(0, min(255, $r)),
        max(0, min(255, $g)),
        max(0, min(255, $b)),
        127 - max(0, min(127, $opacity)),
    ];
}

/**
 * @param array{0:int,1:int,2:int,3:int} $rgba
 */
function hero_colour($image, array $rgba): int
{
    $color = imagecolorallocatealpha($image, $rgba[0], $rgba[1], $rgba[2], $rgba[3]);

    if ($color === false) {
        fwrite(STDERR, "Unable to allocate a colour.\n");
        exit(1);
    }

    return $color;
}

/**
 * @param array{0:int,1:int,2:int} $from
 * @param array{0:int,1:int,2:int} $to
 * @return array{0:int,1:int,2:int}
 */
function hero_lerp(array $from, array $to, float $t): array
{
    $t = max(0.0, min(1.0, $t));

    return [
        (int) round($from[0] + ($to[0] - $from[0]) * $t),
        (int) round($from[1] + ($to[1] - $from[1]) * $t),
        (int) round($from[2] + ($to[2] - $from[2]) * $t),
    ];
}

/**
 * Vertical multi-stop gradient across the whole canvas width.
 *
 * @param array<int, array{0:float,1:array{0:int,1:int,2:int}}> $stops
 */
function hero_vertical_gradient($image, int $width, int $height, array $stops): void
{
    $last = count($stops) - 1;

    for ($y = 0; $y < $height; $y++) {
        $position = $y / max(1, $height - 1);
        $rgb = $stops[$last][1];

        for ($i = 1; $i <= $last; $i++) {
            [$stop, $stopRgb] = $stops[$i];
            [$prevStop, $prevRgb] = $stops[$i - 1];

            if ($position <= $stop) {
                $span = $stop - $prevStop;
                $rgb = hero_lerp($prevRgb, $stopRgb, $span <= 0.0 ? 0.0 : ($position - $prevStop) / $span);
                break;
            }
        }

        imageline($image, 0, $y, $width, $y, hero_colour($image, hero_rgba($rgb[0], $rgb[1], $rgb[2])));
    }
}

/**
 * A soft warm halo around a tiny light source: a handful of low-alpha discs.
 * Deliberately small — large "glows" are expressed with gradient bands instead.
 *
 * @param array{0:int,1:int,2:int} $rgb
 */
function hero_halo($image, int $cx, int $cy, int $radius, array $rgb, int $alpha = 5): void
{
    for ($i = 4; $i >= 1; $i--) {
        $r = (int) round($radius * ($i / 4));
        imagefilledellipse(
            $image,
            $cx,
            $cy,
            $r * 2,
            $r * 2,
            hero_colour($image, hero_rgba($rgb[0], $rgb[1], $rgb[2], $alpha))
        );
    }
}

/**
 * Plain silhouette block.
 *
 * @param array{0:int,1:int,2:int} $rgb
 */
function hero_block($image, int $left, int $top, int $right, int $bottom, array $rgb): void
{
    imagefilledrectangle($image, $left, $top, $right, $bottom, hero_colour($image, hero_rgba($rgb[0], $rgb[1], $rgb[2])));
}

/**
 * Modern glass facade: dark shell, horizontal floor bands, a lit window grid and
 * an optional warm ground-floor lobby.
 *
 * @param array{0:int,1:int,2:int} $shell
 * @param array{0:int,1:int,2:int} $cool
 * @param array{0:int,1:int,2:int} $warm
 */
function hero_facade(
    $image,
    int $left,
    int $top,
    int $right,
    int $bottom,
    array $shell,
    array $cool,
    array $warm,
    int $cellWidth,
    int $cellHeight,
    int $gap,
    bool $lobby = true
): void {
    hero_block($image, $left, $top, $right, $bottom, $shell);

    // Parapet line.
    imagefilledrectangle($image, $left, $top, $right, $top + 3, hero_colour($image, hero_rgba(52, 68, 112, 120)));

    $bottomLimit = $bottom - ($lobby ? 44 : 12);

    // Floor bands for depth.
    for ($y = $top + 18; $y < $bottomLimit; $y += $cellHeight + $gap) {
        imageline($image, $left + 2, $y - 2, $right - 2, $y - 2, hero_colour($image, hero_rgba(5, 10, 22, 70)));
    }

    for ($y = $top + 22; $y + $cellHeight < $bottomLimit; $y += $cellHeight + $gap) {
        for ($x = $left + 16; $x + $cellWidth < $right - 16; $x += $cellWidth + $gap) {
            $roll = mt_rand(0, 100);

            if ($roll < 36) {
                continue; // dark pane
            }

            if ($roll > 86) {
                $rgb = $warm;
                $alpha = mt_rand(58, 92);
            } else {
                $rgb = $cool;
                $alpha = mt_rand(20, 72);
            }

            imagefilledrectangle(
                $image,
                $x,
                $y,
                $x + $cellWidth,
                $y + $cellHeight,
                hero_colour($image, hero_rgba($rgb[0], $rgb[1], $rgb[2], $alpha))
            );
        }
    }

    // Vertical mullions.
    for ($x = $left + 8; $x < $right - 6; $x += $cellWidth + $gap) {
        imageline($image, $x, $top + 8, $x, $bottomLimit, hero_colour($image, hero_rgba(5, 10, 22, 62)));
    }

    if ($lobby) {
        imagefilledrectangle($image, $left, $bottom - 42, $right, $bottom - 6, hero_colour($image, hero_rgba(247, 199, 128, 74)));
        imagefilledrectangle($image, $left, $bottom - 36, $right, $bottom - 12, hero_colour($image, hero_rgba(255, 230, 178, 34)));
        // Door/column frames.
        for ($x = $left + 24; $x < $right - 20; $x += 58) {
            imagefilledrectangle($image, $x, $bottom - 38, $x + 6, $bottom - 8, hero_colour($image, hero_rgba(12, 18, 34, 84)));
        }
    }
}

/**
 * @param array{0:int,1:int,2:int} $rgb
 */
function hero_tree($image, int $cx, int $baseY, int $height, array $rgb): void
{
    $colour = hero_colour($image, hero_rgba($rgb[0], $rgb[1], $rgb[2]));
    imagefilledrectangle($image, $cx - 2, $baseY - (int) round($height * 0.28), $cx + 2, $baseY, $colour);

    for ($i = 0; $i < 4; $i++) {
        $radius = (int) round($height * (0.22 - $i * 0.04));
        $cy = $baseY - (int) round($height * (0.28 + $i * 0.16));
        imagefilledellipse($image, $cx + ($i % 2 === 0 ? -4 : 6), $cy, $radius * 2, (int) round($radius * 1.35), $colour);
    }
}

$directory = dirname(__DIR__) . '/public/assets/img';
$path = $directory . '/estate-hero.png';

if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    fwrite(STDERR, "Unable to create directory: {$directory}\n");
    exit(1);
}

if (in_array('--check', $argv ?? [], true)) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL missing {$path}\n");
        exit(1);
    }

    $info = getimagesize($path);
    if ($info === false || (int) $info[0] !== HERO_WIDTH || (int) $info[1] !== HERO_HEIGHT) {
        fwrite(STDERR, "FAIL invalid hero image\n");
        exit(1);
    }

    echo "ok   estate-hero.png {$info[0]}x{$info[1]} (", (string) filesize($path), " bytes)\n";
    exit(0);
}

$image = imagecreatetruecolor(HERO_WIDTH, HERO_HEIGHT);
if ($image === false) {
    fwrite(STDERR, "Unable to create the hero canvas.\n");
    exit(1);
}

imagealphablending($image, true);
imagesavealpha($image, true);

mt_srand(HERO_SEED);

// ---------------------------------------------------------------------------
// 1. Dusk sky.
// ---------------------------------------------------------------------------
hero_vertical_gradient($image, HERO_WIDTH, 700, [
    [0.00, [4, 8, 20]],
    [0.14, [8, 14, 34]],
    [0.30, [16, 26, 56]],
    [0.44, [30, 40, 80]],
    [0.56, [54, 56, 98]],
    [0.66, [86, 70, 108]],
    [0.74, [122, 88, 108]],
    [0.80, [158, 108, 102]],
    [0.85, [172, 122, 106]],
    [0.90, [120, 88, 96]],
    [0.95, [58, 46, 66]],
    [1.00, [22, 20, 36]],
]);

// Warm horizon glow band: alpha peaks at the horizon, no hard disc edges.
for ($y = 500; $y < 700; $y++) {
    $t = ($y - 500) / 200;
    $alpha = (int) round(26 * sin(M_PI * $t));
    if ($alpha > 0) {
        imageline($image, 0, $y, HERO_WIDTH, $y, hero_colour($image, hero_rgba(214, 138, 110, $alpha)));
    }
}
for ($y = 430; $y < 700; $y++) {
    $t = ($y - 430) / 270;
    $alpha = (int) round(16 * sin(M_PI * $t));
    if ($alpha > 0) {
        imageline($image, 0, $y, HERO_WIDTH, $y, hero_colour($image, hero_rgba(96, 104, 176, $alpha)));
    }
}

// Stars.
for ($i = 0; $i < 120; $i++) {
    $x = mt_rand(0, HERO_WIDTH);
    $y = mt_rand(0, 330);
    imagefilledellipse(
        $image,
        $x,
        $y,
        mt_rand(1, 2),
        mt_rand(1, 2),
        hero_colour($image, hero_rgba(216, 228, 255, mt_rand(36, 96)))
    );
}

// ---------------------------------------------------------------------------
// 2. Layered skyline.
// ---------------------------------------------------------------------------
$distant = [
    [0, 574, 64, 700], [70, 556, 132, 700], [140, 580, 200, 700], [208, 534, 278, 700],
    [286, 562, 344, 700], [352, 544, 420, 700], [428, 574, 484, 700], [492, 528, 556, 700],
    [564, 558, 620, 700], [628, 538, 688, 700], [696, 566, 750, 700], [758, 530, 822, 700],
    [830, 556, 888, 700], [896, 542, 956, 700], [964, 570, 1020, 700], [1028, 536, 1092, 700],
    [1100, 562, 1156, 700], [1164, 542, 1228, 700], [1236, 566, 1292, 700], [1300, 534, 1368, 700],
    [1376, 560, 1434, 700], [1442, 544, 1600, 700],
];
foreach ($distant as [$left, $top, $right, $bottom]) {
    hero_block($image, $left, $top, $right, $bottom, [12, 20, 42]);
}

// Distant windows.
for ($i = 0; $i < 300; $i++) {
    $x = mt_rand(0, HERO_WIDTH);
    $y = mt_rand(540, 692);
    $warm = mt_rand(0, 2) === 0;
    imagefilledrectangle(
        $image,
        $x,
        $y,
        $x + mt_rand(1, 3),
        $y + mt_rand(1, 3),
        hero_colour($image, hero_rgba($warm ? 248 : 150, $warm ? 204 : 190, $warm ? 130 : 250, mt_rand(22, 68)))
    );
}

// ---------------------------------------------------------------------------
// 3. Focal modern estate building (centre-right).
// ---------------------------------------------------------------------------
hero_facade($image, 610, 344, 1508, 704, [10, 18, 36], [124, 160, 255], [247, 199, 128], 16, 18, 9, true);

// Rooftop detail and rim light.
imagefilledrectangle($image, 880, 326, 1050, 344, hero_colour($image, hero_rgba(9, 16, 32)));
imageline($image, 610, 344, 1508, 344, hero_colour($image, hero_rgba(96, 126, 210, 90)));
imageline($image, 610, 344, 610, 704, hero_colour($image, hero_rgba(74, 104, 188, 70)));
imageline($image, 1508, 344, 1508, 704, hero_colour($image, hero_rgba(74, 104, 188, 54)));

// Lobby warmth spilling onto the forecourt (band, not a disc).
for ($y = 700; $y < 760; $y++) {
    $t = ($y - 700) / 60;
    $alpha = (int) round(30 * (1.0 - $t));
    if ($alpha > 0) {
        imageline($image, 610, $y, 1508, $y, hero_colour($image, hero_rgba(247, 199, 128, $alpha)));
    }
}

// Left mid-rise.
hero_facade($image, 128, 424, 486, 698, [8, 15, 32], [108, 144, 238], [228, 184, 122], 14, 16, 8, true);

// Centre tower behind the focal block.
hero_facade($image, 512, 296, 648, 704, [8, 14, 30], [116, 152, 246], [238, 192, 128], 12, 14, 8, false);

// ---------------------------------------------------------------------------
// 4. Courtyard ground.
// ---------------------------------------------------------------------------
for ($y = 660; $y < HERO_HEIGHT; $y++) {
    $t = ($y - 660) / (HERO_HEIGHT - 660);
    $rgb = hero_lerp([20, 30, 56], [4, 7, 15], $t);
    imageline($image, 0, $y, HERO_WIDTH, $y, hero_colour($image, hero_rgba($rgb[0], $rgb[1], $rgb[2])));
}

// Paving lines.
for ($i = 0; $i <= 12; $i++) {
    $x = (int) round(($i / 12) * HERO_WIDTH);
    imageline($image, $x, 664, (int) round(HERO_WIDTH / 2 + ($x - HERO_WIDTH / 2) * 2.2), HERO_HEIGHT, hero_colour($image, hero_rgba(84, 104, 150, 24)));
}
for ($y = 706; $y < HERO_HEIGHT; $y += 52) {
    imageline($image, 0, $y, HERO_WIDTH, $y, hero_colour($image, hero_rgba(84, 104, 150, 14)));
}

// Reflecting pool.
imagefilledrectangle($image, 520, 750, 1110, 874, hero_colour($image, hero_rgba(10, 18, 38, 96)));
imagefilledrectangle($image, 544, 760, 1086, 860, hero_colour($image, hero_rgba(20, 32, 66, 70)));
for ($i = 0; $i < 40; $i++) {
    $x = mt_rand(560, 1070);
    $y = mt_rand(764, 858);
    imageline($image, $x, $y, $x + mt_rand(14, 60), $y, hero_colour($image, hero_rgba(104, 132, 255, mt_rand(20, 58))));
    if (mt_rand(0, 2) === 0) {
        imageline($image, $x, $y + 2, $x + mt_rand(8, 34), $y + 2, hero_colour($image, hero_rgba(247, 199, 128, mt_rand(14, 34))));
    }
}

// Trees.
hero_tree($image, 84, 772, 320, [6, 11, 23]);
hero_tree($image, 198, 758, 250, [7, 13, 26]);
hero_tree($image, 1524, 764, 300, [6, 11, 23]);
hero_tree($image, 1410, 752, 230, [7, 13, 26]);

// Courtyard lamp posts with small halos.
foreach ([[288, 730], [536, 714], [1152, 716], [1330, 732]] as [$x, $y]) {
    imagefilledrectangle($image, $x - 2, $y, $x + 2, $y + 90, hero_colour($image, hero_rgba(8, 13, 27)));
    hero_halo($image, $x, $y, 26, [255, 216, 150], 6);
    imagefilledellipse($image, $x, $y, 9, 9, hero_colour($image, hero_rgba(255, 238, 196, 40)));
}

// A few discreet bokeh points along the courtyard.
for ($i = 0; $i < 18; $i++) {
    $x = mt_rand(60, HERO_WIDTH - 60);
    $y = mt_rand(712, 792);
    $radius = mt_rand(2, 4);
    $warm = mt_rand(0, 2) !== 0;
    hero_halo($image, $x, $y, $radius * 3, $warm ? [255, 214, 146] : [150, 176, 255], 5);
    imagefilledellipse($image, $x, $y, $radius, $radius, hero_colour($image, hero_rgba(255, 240, 212, 54)));
}

// ---------------------------------------------------------------------------
// 5. Legibility overlays: bottom gradient for the estate name, gentle left
//    vignette for the two-line title.
// ---------------------------------------------------------------------------
for ($y = 430; $y < HERO_HEIGHT; $y++) {
    $t = ($y - 430) / (HERO_HEIGHT - 430);
    $alpha = (int) round(2 + 116 * pow($t, 1.75));
    imageline($image, 0, $y, HERO_WIDTH, $y, hero_colour($image, hero_rgba(4, 8, 18, min(127, $alpha))));
}

for ($x = 0; $x < 1200; $x++) {
    $t = $x / 1200;
    $alpha = (int) round(24 * (1.0 - $t) * (1.0 - $t));
    if ($alpha > 0) {
        imageline($image, $x, 0, $x, HERO_HEIGHT, hero_colour($image, hero_rgba(4, 8, 18, $alpha)));
    }
}

// ---------------------------------------------------------------------------
// 6. Write / verify.
// ---------------------------------------------------------------------------
if (!imagepng($image, $path, 6)) {
    fwrite(STDERR, "FAIL could not write {$path}\n");
    imagedestroy($image);
    exit(1);
}

imagedestroy($image);

$info = getimagesize($path);
if ($info === false) {
    fwrite(STDERR, "FAIL generated file is not a readable image\n");
    exit(1);
}

printf("OK  estate-hero.png  %dx%d  %s bytes\n", $info[0], $info[1], (string) filesize($path));

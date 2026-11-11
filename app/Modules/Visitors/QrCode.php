<?php

declare(strict_types=1);

namespace App\Modules\Visitors;

use RuntimeException;

/**
 * Self-contained QR Code encoder and PNG renderer.
 *
 * Scope: byte mode, error-correction level M, automatic version selection and
 * the standard mask-penalty selection. That is exactly what MtaaPass needs to
 * encode an opaque 64-hex-character visitor pass token on the server, with no
 * third-party library and no network dependency.
 *
 * The algorithm follows the ISO/IEC 18004 pipeline:
 *   bit stream -> terminator/padding -> Reed-Solomon ECC blocks -> interleaving
 *   -> module placement -> mask selection -> format/version information.
 *
 * Reference vectors in the test suite were produced with a temporary,
 * unshipped JS reference implementation (`qrcode` on Node) during development.
 */
final class QrCode
{
    /** Format-information error-correction indicator for level M (2 bits: 00). */
    private const ECL_M = 0b00;

    private const PENALTY_N1 = 3;

    private const PENALTY_N2 = 3;

    private const PENALTY_N3 = 40;

    private const PENALTY_N4 = 10;

    /**
     * EC level M block structure per version: number of blocks and ECC
     * codewords per block (ISO/IEC 18004 Table 9).
     *
     * @var array<int, array{blocks: int, ecc: int}>
     */
    private const EC_M = [
        1 => ['blocks' => 1, 'ecc' => 10],
        2 => ['blocks' => 1, 'ecc' => 16],
        3 => ['blocks' => 1, 'ecc' => 26],
        4 => ['blocks' => 2, 'ecc' => 18],
        5 => ['blocks' => 2, 'ecc' => 24],
        6 => ['blocks' => 4, 'ecc' => 16],
        7 => ['blocks' => 4, 'ecc' => 18],
        8 => ['blocks' => 4, 'ecc' => 22],
        9 => ['blocks' => 5, 'ecc' => 22],
        10 => ['blocks' => 5, 'ecc' => 26],
    ];

    /**
     * Alignment pattern centre coordinates per version.
     *
     * @var array<int, array<int, int>>
     */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    private int $size;

    /** @var array<int, array<int, bool>> */
    private array $modules = [];

    /** @var array<int, array<int, bool>> */
    private array $isFunction = [];

    private function __construct(private readonly int $version)
    {
        $this->size = $version * 4 + 17;

        for ($y = 0; $y < $this->size; $y++) {
            $this->modules[$y] = array_fill(0, $this->size, false);
            $this->isFunction[$y] = array_fill(0, $this->size, false);
        }

        $this->drawFunctionPatterns();
    }

    /**
     * Encode a string into the final QR module matrix (true = dark).
     *
     * `$forcedMask` (0-7) exists so tests can diff every mask against reference
     * matrices; production always uses the automatic penalty-based selection.
     *
     * @return array<int, array<int, bool>>
     */
    public static function matrix(string $text, ?int $forcedMask = null): array
    {
        if ($text === '') {
            throw new RuntimeException('The QR payload must not be empty.');
        }

        if (strlen($text) > self::maxByteCapacity()) {
            throw new RuntimeException('The QR payload is too long for this encoder.');
        }

        $version = self::chooseVersion(strlen($text));
        $qr = new self($version);
        $dataCodewords = self::buildDataCodewords($text, $version);
        $allCodewords = self::addEccAndInterleave($dataCodewords, $version);

        $qr->drawCodewords($allCodewords);

        $mask = $forcedMask === null ? $qr->chooseMask() : max(0, min(7, $forcedMask));
        $qr->applyMask($mask);
        $qr->drawFormatBits($mask);

        return $qr->modules;
    }

    /**
     * Alias kept for convenience/tests.
     *
     * @return array<int, array<int, bool>>
     */
    public static function encode(string $text): array
    {
        return self::matrix($text);
    }

    /**
     * The version the encoder chooses for a payload (smallest that fits).
     */
    public static function versionFor(string $text): int
    {
        return self::chooseVersion(strlen($text));
    }

    /**
     * Render a QR code as PNG bytes using GD. `$scale` is pixels per module and
     * `$margin` is the quiet zone in modules (the spec requires 4).
     */
    public static function png(string $text, int $scale = 8, int $margin = 4): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('The GD extension is required to render QR images.');
        }

        $scale = max(2, min(32, $scale));
        $margin = max(0, min(16, $margin));
        $matrix = self::matrix($text);
        $size = count($matrix);
        $pixels = ($size + $margin * 2) * $scale;

        $image = imagecreatetruecolor($pixels, $pixels);

        if ($image === false) {
            throw new RuntimeException('Unable to allocate the QR image.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 11, 18, 32);

        if ($white === false || $black === false) {
            imagedestroy($image);

            throw new RuntimeException('Unable to allocate the QR image colours.');
        }

        imagefilledrectangle($image, 0, 0, $pixels - 1, $pixels - 1, $white);

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if (!$dark) {
                    continue;
                }

                $left = ($x + $margin) * $scale;
                $top = ($y + $margin) * $scale;
                imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $black);
            }
        }

        ob_start();

        try {
            $ok = imagepng($image);
            $png = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if (!$ok || $png === '') {
            throw new RuntimeException('Unable to encode the QR image as PNG.');
        }

        return $png;
    }

    /* ------------------------------------------------------------------ */
    /* Capacity / version selection                                       */
    /* ------------------------------------------------------------------ */

    private static function maxByteCapacity(): int
    {
        $version = max(array_keys(self::EC_M));

        return self::dataCodewordCount($version) - 3;
    }

    private static function dataCodewordCount(int $version): int
    {
        return self::rawCodewordCount($version) - self::EC_M[$version]['blocks'] * self::EC_M[$version]['ecc'];
    }

    private static function rawCodewordCount(int $version): int
    {
        // Raw data modules minus remainder bits, divided by 8 (ISO/IEC 18004).
        return intdiv(self::rawDataModules($version), 8);
    }

    private static function rawDataModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;

        if ($version >= 2) {
            $numAlign = intdiv($version, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;

            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function charCountBits(int $version): int
    {
        return $version <= 9 ? 8 : 16;
    }

    private static function chooseVersion(int $byteLength): int
    {
        $neededBits = 4 + self::charCountBits(1) + $byteLength * 8;

        foreach (array_keys(self::EC_M) as $version) {
            $capacity = self::dataCodewordCount($version) * 8;
            $countBits = self::charCountBits($version);

            if ($capacity >= 4 + $countBits + $byteLength * 8) {
                return $version;
            }
        }

        throw new RuntimeException('The QR payload does not fit in any supported version.');
    }

    /* ------------------------------------------------------------------ */
    /* Data encoding                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, int> data codewords (bytes)
     */
    private static function buildDataCodewords(string $text, int $version): array
    {
        $bits = [];
        $length = strlen($text);

        // Mode indicator: byte mode (0100).
        self::appendBits($bits, 0b0100, 4);
        self::appendBits($bits, $length, self::charCountBits($version));

        for ($i = 0; $i < $length; $i++) {
            self::appendBits($bits, ord($text[$i]), 8);
        }

        $capacityBits = self::dataCodewordCount($version) * 8;

        // Terminator (up to four zero bits), then pad to a byte boundary.
        $terminator = min(4, $capacityBits - count($bits));

        for ($i = 0; $i < $terminator; $i++) {
            $bits[] = 0;
        }

        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        // Pad codewords 0xEC / 0x11 alternately.
        $pad = 0xEC;

        while (count($bits) < $capacityBits) {
            self::appendBits($bits, $pad, 8);
            $pad = $pad === 0xEC ? 0x11 : 0xEC;
        }

        $codewords = [];

        for ($i = 0; $i < count($bits); $i += 8) {
            $value = 0;

            for ($j = 0; $j < 8; $j++) {
                $value = ($value << 1) | $bits[$i + $j];
            }

            $codewords[] = $value;
        }

        return $codewords;
    }

    /**
     * @param array<int, int> $bits
     */
    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Reed-Solomon + interleaving                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<int, int> $data
     * @return array<int, int>
     */
    private static function addEccAndInterleave(array $data, int $version): array
    {
        $numBlocks = self::EC_M[$version]['blocks'];
        $blockEccLength = self::EC_M[$version]['ecc'];
        $rawCodewords = self::rawCodewordCount($version);
        $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;
        $shortBlockLength = intdiv($rawCodewords, $numBlocks);

        $blocks = [];
        $divisor = self::reedSolomonDivisor($blockEccLength);
        $offset = 0;

        for ($i = 0; $i < $numBlocks; $i++) {
            $dataLength = $shortBlockLength - $blockEccLength + ($i < $numShortBlocks ? 0 : 1);
            $blockData = array_slice($data, $offset, $dataLength);
            $offset += $dataLength;

            $ecc = self::reedSolomonRemainder($blockData, $divisor);

            if ($i < $numShortBlocks) {
                $blockData[] = 0; // placeholder so all blocks have equal length
            }

            $blocks[] = array_merge($blockData, $ecc);
        }

        $result = [];
        $blockCount = count($blocks[0]);

        for ($i = 0; $i < $blockCount; $i++) {
            for ($j = 0; $j < $numBlocks; $j++) {
                if ($i !== $shortBlockLength - $blockEccLength || $j >= $numShortBlocks) {
                    $result[] = $blocks[$j][$i];
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private static function reedSolomonDivisor(int $degree): array
    {
        $result = array_fill(0, $degree - 1, 0);
        $result[] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < count($result); $j++) {
                $result[$j] = self::gfMultiply($result[$j], $root);

                if ($j + 1 < count($result)) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = self::gfMultiply($root, 0x02);
        }

        return $result;
    }

    /**
     * @param array<int, int> $data
     * @param array<int, int> $divisor
     * @return array<int, int>
     */
    private static function reedSolomonRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);

        foreach ($data as $value) {
            $factor = $value ^ array_shift($result);
            $result[] = 0;

            foreach ($divisor as $index => $coefficient) {
                $result[$index] ^= self::gfMultiply($coefficient, $factor);
            }
        }

        return $result;
    }

    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }

    /* ------------------------------------------------------------------ */
    /* Module placement                                                   */
    /* ------------------------------------------------------------------ */

    private function setFunction(int $x, int $y, bool $dark): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
            return;
        }

        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        // Timing patterns.
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }

        // Finder patterns (with separators).
        $this->drawFinderPattern(3, 3);
        $this->drawFinderPattern($this->size - 4, 3);
        $this->drawFinderPattern(3, $this->size - 4);

        // Alignment patterns (skipping the finder corners).
        $positions = self::ALIGNMENT[$this->version] ?? [];

        foreach ($positions as $y) {
            foreach ($positions as $x) {
                if (!$this->isFunction[$y][$x]) {
                    $this->drawAlignmentPattern($x, $y);
                }
            }
        }

        // Reserve the format-information areas with a dummy mask (0).
        $this->drawFormatBits(0);

        // Version information (versions 7 and up).
        $this->drawVersion();
    }

    private function drawFinderPattern(int $centerX, int $centerY): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $distance = max(abs($dx), abs($dy));
                $this->setFunction($centerX + $dx, $centerY + $dy, $distance !== 2 && $distance !== 4);
            }
        }
    }

    private function drawAlignmentPattern(int $centerX, int $centerY): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction(
                    $centerX + $dx,
                    $centerY + $dy,
                    max(abs($dx), abs($dy)) !== 1
                );
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::ECL_M << 3) | $mask;
        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        $bits = (($data << 10) | $remainder) ^ 0x5412;

        // First copy, around the top-left finder.
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, self::getBit($bits, $i));
        }

        $this->setFunction(8, 7, self::getBit($bits, 6));
        $this->setFunction(8, 8, self::getBit($bits, 7));
        $this->setFunction(7, 8, self::getBit($bits, 8));

        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, self::getBit($bits, $i));
        }

        // Second copy, split between the other two finders.
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, self::getBit($bits, $i));
        }

        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, self::getBit($bits, $i));
        }

        // Dark module.
        $this->setFunction(8, $this->size - 8, true);
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }

        $remainder = $this->version;

        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }

        $bits = ($this->version << 12) | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $bit = self::getBit($bits, $i);
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $bit);
            $this->setFunction($b, $a, $bit);
        }
    }

    /**
     * @param array<int, int> $data
     */
    private function drawCodewords(array $data): void
    {
        $totalBits = count($data) * 8;
        $index = 0;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vertical = 0; $vertical < $this->size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vertical : $vertical;

                    if (!$this->isFunction[$y][$x] && $index < $totalBits) {
                        $this->modules[$y][$x] = self::getBit($data[$index >> 3], 7 - ($index & 7));
                        $index++;
                    }
                }
            }
        }
    }

    private static function getBit(int $value, int $index): bool
    {
        return (($value >> $index) & 1) !== 0;
    }

    /* ------------------------------------------------------------------ */
    /* Masking                                                            */
    /* ------------------------------------------------------------------ */

    private function chooseMask(): int
    {
        $bestMask = 0;
        $minPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penaltyScore();

            if ($penalty < $minPenalty) {
                $minPenalty = $penalty;
                $bestMask = $mask;
            }

            $this->applyMask($mask); // undo (XOR is its own inverse)
        }

        return $bestMask;
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }

                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0,
                    7 => ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0,
                    default => false,
                };

                if ($invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private function penaltyScore(): int
    {
        $penalty = 0;

        // N1: runs of five or more same-coloured modules.
        for ($y = 0; $y < $this->size; $y++) {
            $runColor = false;
            $runLength = 0;

            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x] === $runColor) {
                    $runLength++;

                    if ($runLength === 5) {
                        $penalty += self::PENALTY_N1;
                    } elseif ($runLength > 5) {
                        $penalty++;
                    }
                } else {
                    $runColor = $this->modules[$y][$x];
                    $runLength = 1;
                }
            }
        }

        for ($x = 0; $x < $this->size; $x++) {
            $runColor = false;
            $runLength = 0;

            for ($y = 0; $y < $this->size; $y++) {
                if ($this->modules[$y][$x] === $runColor) {
                    $runLength++;

                    if ($runLength === 5) {
                        $penalty += self::PENALTY_N1;
                    } elseif ($runLength > 5) {
                        $penalty++;
                    }
                } else {
                    $runColor = $this->modules[$y][$x];
                    $runLength = 1;
                }
            }
        }

        // N2: 2x2 blocks of the same colour.
        for ($y = 0; $y < $this->size - 1; $y++) {
            for ($x = 0; $x < $this->size - 1; $x++) {
                $color = $this->modules[$y][$x];

                if ($color === $this->modules[$y][$x + 1]
                    && $color === $this->modules[$y + 1][$x]
                    && $color === $this->modules[$y + 1][$x + 1]
                ) {
                    $penalty += self::PENALTY_N2;
                }
            }
        }

        // N3: finder-like 1:1:3:1:1 patterns with a light border.
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size - 10; $x++) {
                if ($this->matchesFinderLike($y, $x, 0, 1)) {
                    $penalty += self::PENALTY_N3;
                }
            }
        }

        for ($x = 0; $x < $this->size; $x++) {
            for ($y = 0; $y < $this->size - 10; $y++) {
                if ($this->matchesFinderLike($y, $x, 1, 0)) {
                    $penalty += self::PENALTY_N3;
                }
            }
        }

        // N4: deviation from 50% dark modules.
        $dark = 0;

        foreach ($this->modules as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $dark++;
                }
            }
        }

        $total = $this->size * $this->size;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        $penalty += $k * self::PENALTY_N4;

        return $penalty;
    }

    /**
     * Finder-like 1:1:3:1:1 patterns that attract a penalty, including the
     * required four light modules immediately before or after the pattern.
     *
     * @var array<int, array<int, bool>>
     */
    private const FINDER_LIKE_PATTERNS = [
        [true, false, true, true, true, false, true, false, false, false, false],
        [false, false, false, false, true, false, true, true, true, false, true],
    ];

    private function matchesFinderLike(int $y, int $x, int $dy, int $dx): bool
    {
        foreach (self::FINDER_LIKE_PATTERNS as $pattern) {
            $matches = true;

            foreach ($pattern as $index => $expected) {
                $cy = $y + $dy * $index;
                $cx = $x + $dx * $index;

                if ($cx < 0 || $cy < 0 || $cx >= $this->size || $cy >= $this->size) {
                    $matches = false;
                    break;
                }

                if ($this->modules[$cy][$cx] !== $expected) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}

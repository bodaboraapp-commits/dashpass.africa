<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Visitors\QrCode;
use RuntimeException;
use Tests\TestCase;

/**
 * Pure-PHP QR encoder (visitor-access-module-contract §3, §9): byte mode,
 * error-correction level M, fixed matrix conventions, forced-mask support for
 * reference diffing, and a real GD-rendered PNG.
 */
final class QrCodeTest extends TestCase
{
    public function testVersionSelectionFollowsByteModeEcMCapacities(): void
    {
        // Byte-mode, EC level M capacities per version: 14, 26, 42, 62, 84.
        $this->assertSame(1, QrCode::versionFor('A'));
        $this->assertSame(1, QrCode::versionFor(str_repeat('A', 14)));
        $this->assertSame(2, QrCode::versionFor(str_repeat('A', 15)));
        $this->assertSame(2, QrCode::versionFor(str_repeat('A', 26)));
        $this->assertSame(3, QrCode::versionFor(str_repeat('A', 27)));
        $this->assertSame(3, QrCode::versionFor(str_repeat('A', 42)));
        $this->assertSame(4, QrCode::versionFor(str_repeat('A', 43)));
        $this->assertSame(4, QrCode::versionFor(str_repeat('A', 62)));
        $this->assertSame(5, QrCode::versionFor(str_repeat('A', 63)));

        // Monotonic non-decreasing selection across a sweep of payload lengths.
        $previous = 1;

        for ($length = 1; $length <= 200; $length += 7) {
            $version = QrCode::versionFor(str_repeat('x', $length));
            $this->assertTrue($version >= $previous, 'QR version selection must be monotonic.');
            $previous = $version;
        }
    }

    public function testMatrixDimensionsAndBooleanModules(): void
    {
        $cases = [
            1 => 'HELLO',
            2 => 'MtaaPass visitor pass',
            3 => str_repeat('Z', 30),
        ];

        foreach ($cases as $expectedVersion => $payload) {
            $matrix = QrCode::matrix($payload);
            $size = count($matrix);

            $this->assertSame(17 + 4 * $expectedVersion, $size, 'QR module count must be 17 + 4×version.');
            $this->assertSame($matrix, QrCode::matrix($payload), 'Encoding must be deterministic.');

            $dark = 0;

            foreach ($matrix as $row) {
                $this->assertCount($size, $row, 'Matrix rows must be square.');

                foreach ($row as $module) {
                    $this->assertTrue(is_bool($module), 'Every QR module must be a boolean (true = dark).');

                    if ($module) {
                        $dark++;
                    }
                }
            }

            $total = $size * $size;
            $this->assertTrue($dark > 0 && $dark < $total, 'A QR matrix must contain both dark and light modules.');
        }
    }

    public function testFinderPatternsArePresentInThreeCorners(): void
    {
        $matrix = QrCode::matrix('https://mtaapass.test/v/' . str_repeat('a', 64));
        $size = count($matrix);
        $dark = $matrix[0][0];

        $origins = [[0, 0], [$size - 7, 0], [0, $size - 7]];

        foreach ($origins as [$ox, $oy]) {
            // Outer 7x7 ring is dark, inner 5x5 ring is light, centre 3x3 is dark.
            for ($x = 0; $x < 7; $x++) {
                $this->assertSame($dark, $matrix[$oy][$ox + $x], 'Finder outer ring must be dark.');
                $this->assertSame($dark, $matrix[$oy + 6][$ox + $x], 'Finder outer ring must be dark.');
                $this->assertSame($dark, $matrix[$oy + $x][$ox], 'Finder outer ring must be dark.');
                $this->assertSame($dark, $matrix[$oy + $x][$ox + 6], 'Finder outer ring must be dark.');
            }

            $this->assertNotSame($dark, $matrix[$oy + 1][$ox + 1], 'Finder inner ring must be light.');
            $this->assertSame($dark, $matrix[$oy + 3][$ox + 3], 'Finder centre must be dark.');
        }

        // Timing pattern alternates along row 6 between the finder patterns.
        $alternating = $matrix[6][8] !== $matrix[6][9];
        $this->assertTrue($alternating, 'Timing pattern must alternate between finder patterns.');
    }

    public function testForcedMasksPreserveFunctionPatternsAndChangeData(): void
    {
        $payload = 'MtaaPass QR reference vector';
        $matrices = [];

        for ($mask = 0; $mask <= 7; $mask++) {
            $matrices[$mask] = QrCode::matrix($payload, $mask);
        }

        $this->assertSame($matrices[0], QrCode::matrix($payload, 0), 'A forced mask must be deterministic.');

        $unique = [];
        foreach ($matrices as $matrix) {
            $unique[md5((string) json_encode($matrix))] = true;
        }

        $this->assertTrue(count($unique) >= 2, 'Different masks must produce different data modules.');

        // Functional patterns (finders + timing) are mask-independent.
        foreach ($matrices as $matrix) {
            $this->assertSame($matrices[0][0][0], $matrix[0][0]);
            $this->assertSame($matrices[0][6][0], $matrix[6][0]);
        }
    }

    public function testPngRenderingHasSignatureAndCorrectDimensions(): void
    {
        $png = QrCode::png('MtaaPass hello');
        $this->assertTrue(str_starts_with($png, "\x89PNG\r\n\x1a\n"), 'QR output must be a real PNG file.');
        $this->assertTrue(strlen($png) > 200, 'QR PNG must contain image data.');

        $info = getimagesizefromstring($png);
        $this->assertTrue(is_array($info), 'The QR PNG must be decodable by GD/getimagesize.');
        $this->assertSame('image/png', $info['mime']);

        $expectedSize = (count(QrCode::matrix('MtaaPass hello')) + 2 * 4) * 8;
        $this->assertSame($expectedSize, $info[0], 'PNG width = (modules + 2×quiet-zone) × scale.');
        $this->assertSame($expectedSize, $info[1], 'PNG height = (modules + 2×quiet-zone) × scale.');

        $payload = str_repeat('x', 20);
        $custom = QrCode::png($payload, 4, 4);
        $customInfo = getimagesizefromstring($custom);
        $this->assertTrue(is_array($customInfo));
        $this->assertSame((count(QrCode::matrix($payload)) + 8) * 4, $customInfo[0]);
    }

    public function testRejectsEmptyAndOversizedPayloads(): void
    {
        try {
            QrCode::matrix('');
            $this->fail('An empty QR payload must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertContains('must not be empty', $exception->getMessage());
        }

        try {
            QrCode::matrix(str_repeat('A', 5000));
            $this->fail('An oversized QR payload must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertContains('too long', $exception->getMessage());
        }
    }
}

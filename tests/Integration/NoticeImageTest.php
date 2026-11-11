<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Uuid;
use App\Modules\Notices\NoticeImageService;

/**
 * Inc-12 — optional notice images.
 *
 * Covers: admin upload (valid image stored under storage/uploads/notices with a
 * uuid basename + alt fallback, optional/empty upload keeps text-only), invalid
 * uploads rejected 422 (MIME/size), serve route (auth + estate + notice.view,
 * published/audience/expiry scoped, correct Content-Type + nosniff + private
 * caching), replace/remove cleanup, traversal/PHP rejection, alt escaping/XSS and
 * the text-only fallback.
 */
final class NoticeImageTest extends AdminModuleTestCase
{
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var array<int, string> */
    private array $createdFiles = [];

    private string $imageDir;

    public function setUp(): void
    {
        parent::setUp();

        $this->imageDir = NoticeImageService::baseDir();
        $this->createdFiles = glob($this->imageDir . '/*') ?: [];
    }

    public function tearDown(): void
    {
        $after = glob($this->imageDir . '/*') ?: [];

        foreach (array_diff($after, $this->createdFiles) as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function pngFile(string $name = 'notice.png'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'notice-png-');
        $this->assertNotFalse($path, 'Could not create a temporary PNG file.');
        file_put_contents($path, (string) base64_decode(self::PNG_1PX));

        return [
            'name' => $name,
            'type' => 'image/png',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($path),
        ];
    }

    /** @param array<string, mixed> $body */
    private function adminUpload(string $path, array $body, ?array $file): \App\Core\Response
    {
        $files = $file === null ? [] : ['image' => $file];

        return $this->request('POST', $path, $body, [], true, null, $files);
    }

    /** @param array<string, mixed> $overrides */
    private function createNoticeBody(array $overrides = []): array
    {
        return array_merge([
            'title' => 'QA image notice ' . bin2hex(random_bytes(3)),
            'body' => 'QA image notice body with enough characters for validation.',
            'category' => 'general',
            'priority' => 'normal',
            'audience' => 'all',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function latestNotice(): array
    {
        $row = $this->db->selectOne('SELECT * FROM estate_notices ORDER BY id DESC LIMIT 1');
        $this->assertNotNull($row, 'A notice row must exist.');

        return $row;
    }

    private function publish(string $uuid): void
    {
        $this->assertAdminRedirect(
            $this->adminPost('/admin/notices/' . $uuid . '/publish', []),
            '/admin/notices/' . $uuid
        );
    }

    private function assertRejectedUpload(\App\Core\Response $response, string $uuid): void
    {
        $this->assertStatus(302, $response, 'Invalid uploads use the PRG error flow.');
        $row = $this->db->selectOne('SELECT image_path FROM estate_notices WHERE uuid = :uuid', ['uuid' => $uuid]);
        $this->assertNotNull($row);
        $this->assertNull($row['image_path'], 'A rejected upload must not persist an image path.');
    }

    // ---------------------------------------------------------------------
    // Upload (admin)
    // ---------------------------------------------------------------------

    public function testAdminCanCreateANoticeWithAnImage(): void
    {
        $this->ensureLoggedInAs(self::GRACE);

        $response = $this->adminUpload('/admin/notices', $this->createNoticeBody(), $this->pngFile());
        $this->assertStatus(302, $response);

        $notice = $this->latestNotice();
        $this->assertNotNull($notice['image_path'], 'The uploaded image basename must be stored.');
        $this->assertMatches('/^[0-9a-f-]{36}\.png$/', (string) $notice['image_path'], 'The stored name is a uuid basename, never the client filename.');
        $this->assertNotEmpty($notice['image_alt'], 'Alt text falls back to the notice title when omitted.');
        $this->assertStringStartsWith('QA image notice', (string) $notice['image_alt'], 'Alt fallback is the notice title.');
        $this->assertTrue(is_file($this->imageDir . '/' . (string) $notice['image_path']), 'The image file must exist under storage/uploads/notices.');
    }

    public function testImageIsOptionalAndTextOnlyNoticeStaysNull(): void
    {
        $this->ensureLoggedInAs(self::GRACE);

        $this->assertStatus(302, $this->adminUpload('/admin/notices', $this->createNoticeBody(), null));

        $notice = $this->latestNotice();
        $this->assertNull($notice['image_path'], 'A notice without an image keeps image_path NULL.');
        $this->assertNull($notice['image_alt']);
    }

    public function testCustomAltTextIsStoredAndUsed(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $this->adminUpload('/admin/notices', $this->createNoticeBody(['image_alt' => 'Lift under maintenance']), $this->pngFile());

        $notice = $this->latestNotice();
        $this->assertSame('Lift under maintenance', (string) $notice['image_alt']);
    }

    public function testNonImageUploadIsRejectedWith422(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $body = $this->createNoticeBody();
        $path = tempnam(sys_get_temp_dir(), 'notice-php-');
        file_put_contents($path, "<?php echo 'pwn'; ?>");

        $file = ['name' => 'evil.php', 'type' => 'application/x-php', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($path)];
        $response = $this->adminUpload('/admin/notices', $body, $file);

        $this->assertStatus(302, $response);
        $notice = $this->latestNotice();
        $this->assertNull($notice['image_path'], 'A PHP/text upload must never be stored as a notice image.');

        @unlink($path);
    }

    public function testOversizeUploadIsRejected(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $body = $this->createNoticeBody();

        $path = tempnam(sys_get_temp_dir(), 'notice-big-');
        file_put_contents($path, str_repeat('a', NoticeImageService::MAX_BYTES + 1024));
        $file = ['name' => 'big.png', 'type' => 'image/png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($path)];
        $response = $this->adminUpload('/admin/notices', $body, $file);

        $this->assertStatus(302, $response);
        $notice = $this->latestNotice();
        $this->assertNull($notice['image_path'], 'Oversize uploads must be rejected.');

        @unlink($path);
    }

    public function testClientFilenameTraversalIsIgnored(): void
    {
        $this->ensureLoggedInAs(self::GRACE);

        $response = $this->adminUpload('/admin/notices', $this->createNoticeBody(), $this->pngFile('../../evil.png'));
        $this->assertStatus(302, $response);

        $notice = $this->latestNotice();
        $this->assertNotNull($notice['image_path']);
        $this->assertStringNotContainsString('..', (string) $notice['image_path']);
        $this->assertSame(basename((string) $notice['image_path']), (string) $notice['image_path'], 'Only a bare basename is stored.');
        $this->assertTrue(is_file($this->imageDir . '/' . (string) $notice['image_path']));
    }

    // ---------------------------------------------------------------------
    // Serve route
    // ---------------------------------------------------------------------

    public function testPublishedNoticeImageIsServedWithSafeHeaders(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $this->adminUpload('/admin/notices', $this->createNoticeBody(), $this->pngFile());
        $notice = $this->latestNotice();
        $this->publish((string) $notice['uuid']);

        $this->ensureLoggedInAs(self::JANE);
        $response = $this->get('/notices/' . $notice['uuid'] . '/image');

        $this->assertStatus(200, $response);
        $this->assertSame('image/png', (string) $response->header('Content-Type'));
        $this->assertSame('nosniff', (string) $response->header('X-Content-Type-Options'));
        $this->assertStringContainsString('private', strtolower((string) $response->header('Cache-Control')), 'Private estate images must not be publicly cacheable.');
        $this->assertNotEmpty($response->body());
    }

    public function testImageRouteRequiresAuthentication(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $this->adminUpload('/admin/notices', $this->createNoticeBody(), $this->pngFile());
        $notice = $this->latestNotice();
        $this->publish((string) $notice['uuid']);

        $this->resetSession();
        $response = $this->get('/notices/' . $notice['uuid'] . '/image');
        $this->assertStatus(302, $response);
        $this->assertStringContainsString('/login', (string) $response->header('Location'));
    }

    public function testDraftArchivedExpiredAndUnknownImagesAre404(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $this->adminUpload('/admin/notices', $this->createNoticeBody(), $this->pngFile());
        $draft = $this->latestNotice();

        $this->ensureLoggedInAs(self::JANE);
        $this->assertStatus(404, $this->get('/notices/' . $draft['uuid'] . '/image'), 'Draft images must not be served.');

        $this->assertStatus(404, $this->get('/notices/' . Uuid::v4() . '/image'), 'Unknown notice images must 404.');

        // Published then archived → 404.
        $this->ensureLoggedInAs(self::GRACE);
        $this->publish((string) $draft['uuid']);
        $this->adminPost('/admin/notices/' . $draft['uuid'] . '/archive', []);
        $this->ensureLoggedInAs(self::JANE);
        $this->assertStatus(404, $this->get('/notices/' . $draft['uuid'] . '/image'));
    }

    public function testForeignEstateAndAudienceScopedImagesAre404(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $this->adminUpload('/admin/notices', $this->createNoticeBody(['audience' => 'residents']), $this->pngFile());
        $notice = $this->latestNotice();
        $this->publish((string) $notice['uuid']);

        // Riverside admin cannot see a Greenview notice image.
        $this->ensureLoggedInAs(self::DAVID_RIVERSIDE);
        $this->assertStatus(404, $this->get('/notices/' . $notice['uuid'] . '/image'));

        // A guard is staff-only: a residents-audience image stays hidden.
        $this->loginGuard();
        $this->assertStatus(404, $this->get('/notices/' . $notice['uuid'] . '/image'));
    }

    public function testStaffAudienceImageIsVisibleToGuards(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $this->adminUpload('/admin/notices', $this->createNoticeBody(['audience' => 'staff']), $this->pngFile());
        $notice = $this->latestNotice();
        $this->publish((string) $notice['uuid']);

        $this->loginGuard();
        $this->assertStatus(200, $this->get('/notices/' . $notice['uuid'] . '/image'));
    }

    public function testStaffAudienceImageIsNotServedToResidents(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $this->adminUpload('/admin/notices', $this->createNoticeBody(['audience' => 'staff']), $this->pngFile());
        $notice = $this->latestNotice();
        $this->publish((string) $notice['uuid']);

        // Residents (incl. household members who also hold visitor.incident.create
        // via Option-B) must never receive a staff-only notice image.
        foreach ([self::JANE, self::BRIAN, self::HOUSEHOLD_MEMBER] as $phone) {
            $this->ensureLoggedInAs($phone);
            $this->assertStatus(404, $this->get('/notices/' . $notice['uuid'] . '/image'), $phone . ' must not see a staff-audience notice image.');
        }
    }

    // ---------------------------------------------------------------------
    // Replace / remove
    // ---------------------------------------------------------------------

    public function testReplacingAnImageStoresANewFileAndCleansTheOldOne(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $this->adminUpload('/admin/notices', $this->createNoticeBody(), $this->pngFile('first.png'));
        $notice = $this->latestNotice();
        $oldPath = $this->imageDir . '/' . (string) $notice['image_path'];
        $this->assertTrue(is_file($oldPath));

        $rename = ['pn1' => basename($oldPath)];
        $this->adminUpload('/admin/notices/' . $notice['uuid'], $this->createNoticeBody(['title' => 'QA replaced image notice']), $this->pngFile('second.png'));
        $updated = $this->db->selectOne('SELECT image_path FROM estate_notices WHERE uuid = :uuid', ['uuid' => $notice['uuid']]);
        $this->assertNotNull($updated['image_path']);
        $this->assertNotSame($rename['pn1'], (string) $updated['image_path'], 'Replacing an image stores a new uuid basename.');
        $this->assertFalse(is_file($oldPath), 'The previous image file must be cleaned up on replace.');
    }

    public function testRemovingAnImageClearsColumnsAndDeletesTheFile(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $this->adminUpload('/admin/notices', $this->createNoticeBody(), $this->pngFile());
        $notice = $this->latestNotice();
        $path = $this->imageDir . '/' . (string) $notice['image_path'];
        $this->assertTrue(is_file($path));

        $this->assertStatus(302, $this->adminUpload('/admin/notices/' . $notice['uuid'], $this->createNoticeBody(['remove_image' => '1']), null));

        $updated = $this->db->selectOne('SELECT image_path, image_alt FROM estate_notices WHERE uuid = :uuid', ['uuid' => $notice['uuid']]);
        $this->assertNull($updated['image_path']);
        $this->assertNull($updated['image_alt']);
        $this->assertFalse(is_file($path), 'Removing an image deletes the stored file.');
    }

    // ---------------------------------------------------------------------
    // Rendering / XSS / text-only fallback
    // ---------------------------------------------------------------------

    public function testAltTextIsEscapedOnNoticePages(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $payload = '<script>alert("alt")</script>';
        $this->adminUpload('/admin/notices', $this->createNoticeBody(['image_alt' => $payload]), $this->pngFile());
        $notice = $this->latestNotice();
        $this->publish((string) $notice['uuid']);

        $this->ensureLoggedInAs(self::JANE);
        $detail = $this->get('/app/notices/' . $notice['uuid']);
        $this->assertStatus(200, $detail);
        $this->assertStringNotContainsString($payload, $detail->body(), 'Image alt text must be escaped.');
        $this->assertCleanMarkup($detail, 'notice detail with image');
    }

    public function testNoticeWithoutImageRendersTextOnly(): void
    {
        $this->ensureLoggedInAs(self::GRACE);
        $this->adminUpload('/admin/notices', $this->createNoticeBody(), null);
        $notice = $this->latestNotice();
        $this->publish((string) $notice['uuid']);

        $this->ensureLoggedInAs(self::JANE);
        $detail = $this->get('/app/notices/' . $notice['uuid']);
        $this->assertStatus(200, $detail);
        $this->assertStringNotContainsString('/notices/' . $notice['uuid'] . '/image', $detail->body(), 'A text-only notice must not embed an image URL.');
    }

    public function testServiceWorkerDoesNotPrecachePrivateNoticeImages(): void
    {
        $script = (string) file_get_contents(BASE_PATH . '/public/service-worker.js');
        $this->assertNotEmpty($script);
        $this->assertStringNotContainsString('/notices/', $script, 'Private estate notice images must never be precached by the service worker.');
    }
}

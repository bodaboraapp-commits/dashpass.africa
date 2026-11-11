<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Global helpers. `e()` is the single escaping primitive every view must use to
 * satisfy the XSS requirement in Blueprint §60.
 */
final class HelpersTest extends TestCase
{
    public function testEscapesHtmlMarkup(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;',
            e('<script>alert("xss")</script>')
        );
    }

    public function testEscapesSingleQuotesForAttributeContexts(): void
    {
        $this->assertSame('O&#039;Reilly', e("O'Reilly"));
        $this->assertSame('&lt;img src=x onerror=&quot;alert(1)&quot;&gt;', e('<img src=x onerror="alert(1)">'));
    }

    public function testHandlesNullAndScalarValues(): void
    {
        $this->assertSame('', e(null));
        $this->assertSame('', e(false));
        $this->assertSame('1', e(true));
        $this->assertSame('42', e(42));
        $this->assertSame('3.5', e(3.5));
    }

    public function testPreservesUtf8Content(): void
    {
        $this->assertSame('MtaaPass — résumé ✓', e('MtaaPass — résumé ✓'));
    }

    public function testEscapesAmpersands(): void
    {
        $this->assertSame('Mtaa &amp; Pass', e('Mtaa & Pass'));
    }

    public function testUrlHelperReturnsRootRelativePath(): void
    {
        $this->assertSame('/login', url('/login'));
        $this->assertSame('/login', url('login'));
        $this->assertSame('/login/otp', url('login/otp'));
        $this->assertSame('/', url('/'));
        $this->assertSame('/app/estates', url('/app/estates/'));
        $this->assertFalse(str_starts_with(url('/login'), 'http'), 'url() must stay root-relative so any origin/port works.');
    }

    public function testAbsoluteUrlHelperUsesConfiguredOrigin(): void
    {
        $absolute = absolute_url('/login');

        $this->assertSame('http://localhost:8000/login', $absolute);
        $this->assertMatches('#^https?://#', $absolute);
    }

    public function testAssetHelperIsVersionedAndRootRelative(): void
    {
        $asset = asset('css/app.css');
        $version = (string) config('app.version');

        $this->assertStringStartsWith('/assets/css/app.css?v=' . rawurlencode($version) . '-', $asset);
        $this->assertTrue(str_starts_with($asset, '/assets/'), 'asset() must be root-relative for port/proxy independence.');
        $this->assertContains('?v=', $asset);

        // Stable when unchanged; changes when the file changes (deploy bust).
        $this->assertSame($asset, asset('css/app.css'));
        $file = BASE_PATH . '/public/assets/css/app.css';
        $original = filemtime($file);
        touch($file, $original + 2);
        clearstatcache(true, $file);
        $this->assertNotSame($asset, asset('css/app.css'), 'asset() must bust when the asset changes.');
        touch($file, $original);
        clearstatcache(true, $file);
        $this->assertSame($asset, asset('css/app.css'), 'asset() must be stable when the asset is unchanged.');
    }

    public function testFormatDatetimeConvertsUtcToEstateTimezone(): void
    {
        $this->assertSame('2026-11-11 11:00', format_datetime('2026-11-11 08:00:00.000', 'Africa/Nairobi', 'Y-m-d H:i'));
        $this->assertSame('—', format_datetime(null));
        $this->assertSame('—', format_datetime('not-a-date'));
    }

    public function testCsrfHelpers(): void
    {
        $this->resetSession();

        $token = csrf_token();
        $this->assertMatches('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, csrf_token(), 'The CSRF token is stable within a session.');

        $field = csrf_field();
        $this->assertContains('name="_token"', $field);
        $this->assertContains(e($token), $field);
        $this->assertContains('type="hidden"', $field);
    }
}

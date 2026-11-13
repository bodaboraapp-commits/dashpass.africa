<?php

declare(strict_types=1);

namespace Tests\Integration;

use DOMElement;

/**
 * Sign-in code entry speed: the OTP input advertises the browser's
 * one-time-code autofill, pasted SMS bodies are parsed down to the code, and the
 * development OTP is prefilled and auto-submitted instead of being retyped.
 *
 * The server-side dev OTP is only exposed in non-production (tests pin
 * AUTH_OTP_PROVIDER=server + SMS_DEV_EXPOSE_OTP=true); production never renders
 * the code and still relies on the browser's SMS autofill/paste.
 */
final class OtpSpeedTest extends IntegrationTestCase
{
    private const PHONE = '+254700000010';

    public function testOtpInputEnablesBrowserOneTimeCodeAutofill(): void
    {
        $response = $this->get('/login');
        $this->assertStatus(200, $response);

        $dom = $this->dom($response);
        $input = $this->xpath('//input[@name="code"]', $dom)->item(0);
        $this->assertNotNull($input, 'The verify step must render the code input.');
        $this->assertInstanceOf(DOMElement::class, $input);

        $this->assertSame('one-time-code', (string) $input->getAttribute('autocomplete'), 'The code input must request OS/SMS autofill.');
        $this->assertSame('numeric', (string) $input->getAttribute('inputmode'));
        $this->assertSame('off', (string) $input->getAttribute('autocapitalize'));
        $this->assertSame('false', (string) $input->getAttribute('spellcheck'));
        $this->assertSame('done', (string) $input->getAttribute('enterkeyhint'));
        $this->assertSame('6', (string) $input->getAttribute('maxlength'));
        $this->assertTrue($input->hasAttribute('data-otp'), 'The enhancers hook the code input through data-otp.');
    }

    public function testDevelopmentOtpIsPrefilledAndAutoSubmitted(): void
    {
        $request = $this->post('/login/otp', ['phone' => self::PHONE], true);
        $this->assertRedirect($request, '/login', 302);

        $page = $this->get('/login');
        $this->assertStatus(200, $page);
        $dom = $this->dom($page);

        $devCodeNode = $this->xpath('//*[@data-dev-otp-code]', $dom)->item(0);
        $this->assertNotNull($devCodeNode, 'The development OTP must be rendered in the dev panel.');
        $devCode = trim($this->text($devCodeNode));
        $this->assertMatches('/^\d{6}$/', $devCode, 'The development OTP must be rendered in the dev panel.');

        $input = $this->xpath('//input[@name="code"]', $dom)->item(0);
        $this->assertNotNull($input);
        $this->assertInstanceOf(DOMElement::class, $input);
        $this->assertSame($devCode, (string) $input->getAttribute('value'), 'The development OTP must be prefilled so no retyping is needed.');

        $this->assertCount(
            1,
            $this->xpath('//form[@data-login-form="verify"][@data-autosubmit]', $dom),
            'The server-OTP verify form must keep auto-submit once the code is complete.'
        );
    }

    public function testScriptsCarryDevAutofillAndClipboardParsing(): void
    {
        $appJs = (string) file_get_contents(BASE_PATH . '/public/assets/js/app.js');
        $this->assertStringContainsString('applyDevOtp', $appJs, 'app.js must auto-fill a known development OTP.');
        $this->assertStringContainsString('extractOtpDigits', $appJs, 'app.js must parse an OTP out of pasted text (SMS body).');
        $this->assertStringContainsString("on(otpInput, 'paste'", $appJs, 'app.js must hook paste on the OTP input.');
        $this->assertStringContainsString('otpInput.dispatchEvent(new Event(\'input\'', $appJs, 'Pasting/autofill must run the normal auto-submit path.');

        $firebaseJs = (string) file_get_contents(BASE_PATH . '/public/assets/js/firebase-auth.js');
        $this->assertStringContainsString("codeInput.addEventListener('paste'", $firebaseJs, 'The Firebase flow must parse a pasted OTP.');
        $this->assertStringContainsString("codeInput.dispatchEvent(new Event('input'", $firebaseJs, 'A pasted Firebase OTP must auto-confirm.');

        // The enhancement must stay internal-only: no external hosts beyond the
        // pinned Firebase modules (the security suite enforces this elsewhere too).
        $this->assertStringNotContainsString('innerHTML', $firebaseJs, 'The OTP enhancer must not build markup with innerHTML.');
    }
}

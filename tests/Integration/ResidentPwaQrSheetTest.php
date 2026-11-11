<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;
use Database\Seeds\DemoVisitorSeeder;
use DOMElement;

/**
 * Resident QR sheet after Increment 2 (addendum §2D + visitor-access contract
 * §7): the sheet shows the resident's next pass (visitor, validity, server-
 * rendered QR image, "Open pass" link) or an honest empty state, without ever
 * embedding the bearer pass token or a PIN in the app shell.
 */
final class ResidentPwaQrSheetTest extends ResidentPwaTestCase
{
    private function home(string $resident = self::JANE): Response
    {
        $this->loginAs($resident);

        return $this->get('/app');
    }

    public function testDialogSemanticsAndContent(): void
    {
        $response = $this->home();
        $dialog = $this->qrDialog($response);

        $this->assertNotNull($dialog, 'Home must include the QR sheet dialog (role="dialog").');
        $this->assertSame('dialog', strtolower($dialog->tagName), 'The sheet should use the native <dialog> element.');
        $this->assertSame('qr-sheet', $dialog->getAttribute('id'));
        $this->assertSame('true', strtolower($dialog->getAttribute('aria-modal')));
        $this->assertTrue($dialog->hasAttribute('hidden'), 'The QR sheet must be hidden by default so it degrades without JavaScript.');

        $dom = $this->dom($response);

        $this->assertSame('qr-sheet-title', $dialog->getAttribute('aria-labelledby'));
        $title = $this->xpath('//*[@id="qr-sheet-title"]', $dom);
        $this->assertCount(1, $title, 'The dialog must have a labelled title.');
        $titleNode = $title->item(0);
        $this->assertNotNull($titleNode);
        $this->assertSame('My access pass', $this->text($titleNode));

        $this->assertSame('qr-sheet-note', $dialog->getAttribute('aria-describedby'));

        // Two close affordances (icon button in the head, text button at the bottom).
        $closeControls = $this->xpath('//*[@data-qr-close]', $dom);
        $this->assertTrue($closeControls->length >= 2, 'The QR sheet must expose icon and text close controls.');

        foreach ($closeControls as $control) {
            if (!$control instanceof DOMElement) {
                continue;
            }

            $label = trim($control->getAttribute('aria-label') . ' ' . $control->getAttribute('title') . ' ' . $this->text($control));
            $this->assertTrue($label !== '', 'Each QR close control must have an accessible name.');
        }
    }

    public function testSheetShowsTheNextPassWithQrAndOpenPassLink(): void
    {
        $dialog = $this->qrDialog($this->home());
        $this->assertNotNull($dialog);

        $this->assertSame('1', $dialog->getAttribute('data-has-pass'), 'Jane has a seeded upcoming pass, so the sheet must show it.');
        $this->assertTrue(
            str_ends_with($dialog->getAttribute('data-pass-url'), '/app/passes/' . DemoVisitorSeeder::INVITATION_UPCOMING_UUID),
            'The sheet must reference the resident pass screen for the upcoming invitation.'
        );

        $dialogText = $this->text($dialog);
        $this->assertContains('Next visitor', $dialogText);
        $this->assertContains('Demo Visitor Three', $dialogText);
        $this->assertContains('Show this QR at the gate', $dialogText);

        // Server-rendered QR image hook (not an inline token/data-URI credential).
        $qrImages = $this->xpath('.//img[@data-qr-image]', $dialog);
        $this->assertCount(1, $qrImages, 'The sheet must show the resident QR image.');
        $qrImage = $qrImages->item(0);
        $this->assertNotNull($qrImage);
        $this->assertTrue(str_starts_with((string) $qrImage->getAttribute('src'), '/app/passes/'));
        $this->assertTrue(str_ends_with((string) $qrImage->getAttribute('src'), '/qr.png'));

        // "Open pass" link goes to the resident pass screen (token lives there).
        $openPass = $this->xpath('.//a[@data-qr-pass-link]', $dialog);
        $this->assertCount(1, $openPass, 'The sheet must link to the full pass screen.');
        $openPassNode = $openPass->item(0);
        $this->assertNotNull($openPassNode);
        $this->assertTrue(str_starts_with((string) $openPassNode->getAttribute('href'), '/app/passes/'));
        $this->assertContains('Open pass', $this->text($openPassNode));

        // The bearer pass token must never be embedded in the app shell / sheet.
        $dialogHtml = (string) $dialog->ownerDocument?->saveHTML($dialog);
        $this->assertFalse(str_contains($dialogHtml, '/v/'), 'The public bearer pass URL must not be embedded in the sheet.');
        $this->assertFalse(
            (bool) preg_match('/\b[0-9a-f]{64}\b/i', $dialogHtml),
            'The QR token/credential must not appear in the sheet markup.'
        );
        $this->assertFalse(str_contains($dialogHtml, DemoVisitorSeeder::DEMO_PIN), 'The PIN must never appear in the app shell.');
    }

    public function testSheetShowsHonestEmptyStateWhenResidentHasNoPass(): void
    {
        // Amina (B-001) has no seeded visitor invitations.
        $dialog = $this->qrDialog($this->home(self::AMINA));
        $this->assertNotNull($dialog);

        $this->assertSame('0', $dialog->getAttribute('data-has-pass'));
        $this->assertContains('No active visitor pass', $this->text($dialog));
        $this->assertContains('Pre-register a visitor', $this->text($dialog));
        $this->assertFalse(str_contains((string) $dialog->ownerDocument?->saveHTML($dialog), '/v/'));

        $invite = $this->xpath('.//a[@href="/app/visitors/invite"]', $dialog);
        $this->assertCount(1, $invite, 'The empty state must offer an invite shortcut.');
    }

    public function testDialogContainsNoCredentialsOrTokens(): void
    {
        $dialog = $this->qrDialog($this->home());
        $this->assertNotNull($dialog);

        $dialogHtml = (string) $dialog->ownerDocument?->saveHTML($dialog);

        foreach (['csrf', 'passcode', 'access_code', 'otp', 'bearer', 'eyj'] as $forbidden) {
            $this->assertFalse(
                stripos($dialogHtml, $forbidden) !== false,
                sprintf('The QR sheet must not contain credential-shaped text "%s".', $forbidden)
            );
        }

        $text = $this->text($dialog);
        $this->assertFalse(
            (bool) preg_match('/\b\d{6,8}\b/', $text),
            'The QR sheet must not render a PIN/OTP-like numeric credential.'
        );
    }

    public function testAccessTileAndCentreFabAreTriggers(): void
    {
        $response = $this->home();
        $dom = $this->dom($response);

        $dialog = $this->qrDialog($response);
        $this->assertNotNull($dialog);
        $dialogId = $dialog->getAttribute('id');
        $this->assertTrue($dialogId !== '');

        // Exactly two openers: the Home Access tile and the centre bottom-nav FAB.
        $openers = $this->xpath('//button[@data-qr-open]', $dom);
        $this->assertCount(2, $openers, 'Exactly two controls may open the QR sheet (Access tile + centre FAB).');

        foreach ($openers as $opener) {
            if (!$opener instanceof DOMElement) {
                continue;
            }

            $this->assertSame('dialog', strtolower($opener->getAttribute('aria-haspopup')));
            $this->assertSame($dialogId, $opener->getAttribute('aria-controls'), 'Each opener must reference the dialog id.');
        }

        $fab = $this->xpath('//button[@aria-label="Show my access QR"]', $dom);
        $this->assertCount(1, $fab, 'Exactly one centre QR FAB with aria-label="Show my access QR" is required.');
        $fabNode = $fab->item(0);
        $this->assertNotNull($fabNode);
        $this->assertTrue($fabNode->hasAttribute('data-qr-open'));
        $this->assertTrue(str_contains($fabNode->getAttribute('class'), 'tab-fab'));

        $access = $this->xpath('//section[@aria-label="Quick actions"]//button[@data-qr-open]', $dom);
        $this->assertCount(1, $access, 'The Access / My QR quick tile must open the sheet from a button.');
        $accessNode = $access->item(0);
        $this->assertNotNull($accessNode);
        $this->assertContains('Access', $this->text($accessNode));
    }

    public function testQrSheetJavascriptHandlesEscapeBackdropAndFocus(): void
    {
        $script = (string) @file_get_contents(BASE_PATH . '/public/assets/js/app.js');

        $this->assertTrue($script !== '', 'public/assets/js/app.js must exist.');
        $this->assertContains("getElementById('qr-sheet')", $script, 'The JS must target the QR dialog by id.');
        $this->assertContains('showModal', $script, 'The JS must use the native dialog showModal() API.');
        $this->assertContains('data-qr-open', $script, 'The JS must wire the QR openers.');
        $this->assertContains('data-qr-close', $script, 'The JS must wire the QR close controls.');
        $this->assertContains('Escape', $script, 'The JS must handle the Escape key.');
        $this->assertContains('focus', $script, 'The JS must manage focus (move in and return).');

        $this->assertTrue(
            stripos($script, 'backdrop') !== false
            || stripos($script, 'event.target') !== false
            || stripos($script, 'target ===') !== false
            || stripos($script, 'target==') !== false,
            'The JS must support backdrop-click close.'
        );
    }
}

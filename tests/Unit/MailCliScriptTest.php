<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * `scripts/mail-test.php` CLI contract (email-pwa-greeting-contract §1.2, T35):
 * --check connects/authenticates without sending anything, a message is only
 * ever sent to an explicit --to recipient, and output never contains credentials.
 *
 * Asserted statically from the script source because the suite must never make a
 * real SMTP connection (the sandbox has no network and the owner's credentials
 * must not be used in tests).
 */
final class MailCliScriptTest extends TestCase
{
    private function source(): string
    {
        $path = BASE_PATH . '/scripts/mail-test.php';
        $this->assertTrue(is_file($path), 'scripts/mail-test.php must exist.');
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        return $source;
    }

    public function testScriptIsCliOnly(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("PHP_SAPI !== 'cli'", $source);
        $this->assertStringContainsString('exit(1)', $source);
    }

    public function testCheckPerformsAuthOnlyAndNeverSendsMail(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("'--check'", $source);
        $this->assertStringContainsString('->check()', $source);
        $this->assertStringContainsString('hasCredentials()', $source);
        // The --check branch must be independent from the send branch.
        $this->assertStringContainsString('if ($check)', $source);
    }

    public function testSendRequiresAnExplicitValidRecipient(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("'--to='", $source);
        $this->assertStringContainsString('FILTER_VALIDATE_EMAIL', $source);
        $this->assertStringContainsString('valid email address', $source);
        // Passing a bare --to indicates usage error rather than guessing a recipient.
        $this->assertStringContainsString("'--to'", $source);
        $this->assertStringContainsString('explicit recipient required', $source);
    }

    public function testOutputNeverPrintsCredentials(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString('MAIL_SMTP_PASSWORD)', $source);
        $this->assertStringNotContainsString('RESEND_API_KEY)', $source);
        $this->assertStringNotContainsString('var_dump', $source);
        $this->assertStringNotContainsString('print_r', $source);

        // The connection failure path prints only the safe exception message.
        $this->assertStringContainsString("fwrite(STDERR, 'FAIL '", $source);
    }

    public function testScriptBootsTheApplicationAndUsesTheMailManager(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("require dirname(__DIR__) . '/app/bootstrap.php'", $source);
        $this->assertStringContainsString('new MailManager()', $source);
        $this->assertStringContainsString('new SmtpMailProvider()', $source);
        $this->assertStringContainsString('new MailMessage(', $source);
    }
}

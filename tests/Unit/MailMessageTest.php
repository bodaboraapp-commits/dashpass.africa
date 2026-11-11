<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Mail\MailMessage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Mail message value object (email-pwa-greeting-contract §1.2): construction
 * validation, recipient dedupe and privacy-safe masking for logs/audit.
 */
final class MailMessageTest extends TestCase
{
    public function testBuildsAValidMessage(): void
    {
        $message = new MailMessage(
            ['jane@example.com'],
            '  Visitor checked in  ',
            "Line one\nLine two",
            '<p>Line one</p>',
            'reply@example.com',
            ['event' => 'visitor.checked_in']
        );

        $this->assertSame(['jane@example.com'], $message->to);
        $this->assertSame('Visitor checked in', $message->subject);
        $this->assertSame("Line one\nLine two", $message->text);
        $this->assertSame('<p>Line one</p>', $message->html);
        $this->assertSame('reply@example.com', $message->replyTo);
        $this->assertSame(['event' => 'visitor.checked_in'], $message->metadata);
    }

    public function testRecipientsAreDeduplicatedCaseInsensitively(): void
    {
        $message = new MailMessage(
            ['Jane@Example.com', 'jane@example.com', 'ops@example.com', ' OPS@example.com '],
            'Subject',
            'Body'
        );

        $this->assertSame(['Jane@Example.com', 'ops@example.com'], $message->to);
    }

    public function testInvalidRecipientsAreRejected(): void
    {
        foreach ([['not-an-email'], ['jane@'], ['@example.com'], ['']] as $to) {
            try {
                new MailMessage($to, 'Subject', 'Body');
                $this->fail('Invalid recipient must be rejected: ' . json_encode($to));
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        try {
            new MailMessage([], 'Subject', 'Body');
            $this->fail('At least one recipient is required.');
        } catch (InvalidArgumentException $exception) {
            $this->assertContains('recipient', strtolower($exception->getMessage()));
        }
    }

    public function testSubjectAndTextAreRequired(): void
    {
        foreach ([['   ', 'Body'], ['Subject', '   ']] as [$subject, $text]) {
            try {
                new MailMessage(['jane@example.com'], $subject, $text);
                $this->fail('Empty subject/text must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testInvalidReplyToIsRejected(): void
    {
        try {
            new MailMessage(['jane@example.com'], 'Subject', 'Body', null, 'not-an-email');
            $this->fail('An invalid reply-to must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertContains('reply-to', strtolower($exception->getMessage()));
        }
    }

    public function testMaskedRecipientsNeverExposeTheRawAddress(): void
    {
        $message = new MailMessage(
            ['jane.smith@example.com', 'ops@example.org'],
            'Subject',
            'Body'
        );

        $masked = $message->maskedRecipients();

        $this->assertSame(['j***@example.com', 'o***@example.org'], $masked);
        $this->assertStringNotContainsString('jane.smith', implode(' ', $masked));
        $this->assertStringNotContainsString('ops@', implode(' ', $masked));
    }

    public function testMaskAddressHandlesMalformedInputSafely(): void
    {
        $this->assertSame('[masked]', MailMessage::maskAddress('not-an-address'));
        $this->assertSame('[masked]', MailMessage::maskAddress('@example.com'));
        $this->assertSame('[masked]', MailMessage::maskAddress('jane@'));
        $this->assertSame('j***@example.com', MailMessage::maskAddress('jane@example.com'));
    }
}

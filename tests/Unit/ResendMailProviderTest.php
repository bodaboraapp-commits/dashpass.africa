<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Integrations\Mail\MailMessage;
use App\Integrations\Mail\MailSendException;
use App\Integrations\Mail\ResendMailProvider;
use Tests\TestCase;

/**
 * Resend HTTP provider (email-pwa-greeting-contract §1.2/§4) added by parallel
 * QA (T47): the contract requires payload/headers construction and HTTP error
 * mapping via a fake transport, with no secret ever leaking. No real network
 * request is made — the transport seam is a closure.
 */
final class ResendMailProviderTest extends TestCase
{
    private const API_KEY = 're_qa_test_key_sentinel_value';

    private const API_URL = 'https://api.resend.test';

    /** @var array<string, mixed> */
    private array $originalMail = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->originalMail = (array) Config::get('mail', []);
        Config::set('mail.from', ['address' => 'alerts@dashpass.test', 'name' => 'DashPASS']);
    }

    public function tearDown(): void
    {
        Config::set('mail', $this->originalMail);
        parent::tearDown();
    }

    /**
     * @param array{status: int, body: string}|\Throwable $result
     * @param array<int, array<string, mixed>> $captured
     * @return callable(string, array<string, string>, string, int): array{status: int, body: string}
     */
    private function transport(array|\Throwable $result, array &$captured): callable
    {
        return static function (string $url, array $headers, string $body, int $timeout) use ($result, &$captured): array {
            $captured[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];

            if ($result instanceof \Throwable) {
                throw $result;
            }

            return $result;
        };
    }

    private function message(?string $html = null, ?string $replyTo = null): MailMessage
    {
        return new MailMessage(
            ['ops@example.test', 'host@example.test'],
            'MtaaPass: Visitor checked in — Alice Mwangi',
            "Visitor checked in — Alice Mwangi\nGate: Main Gate",
            $html,
            $replyTo
        );
    }

    public function testPayloadAndHeadersAreBuiltFromTheMessage(): void
    {
        $captured = [];
        $provider = new ResendMailProvider(
            $this->transport(['status' => 200, 'body' => '{"id":"email_123"}'], $captured),
            self::API_KEY,
            self::API_URL,
            9
        );

        $delivery = $provider->send($this->message());

        $this->assertSame('resend', $delivery['provider']);
        $this->assertSame('sent', $delivery['status']);
        $this->assertSame('email_123', $delivery['message_id']);
        $this->assertSame('resend', $provider->name());

        $this->assertCount(1, $captured);
        $request = $captured[0];

        $this->assertSame(self::API_URL . '/emails', $request['url']);
        $this->assertSame('Bearer ' . self::API_KEY, $request['headers']['Authorization']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertSame(9, $request['timeout']);

        $payload = (array) json_decode((string) $request['body'], true);
        $this->assertSame('DashPASS <alerts@dashpass.test>', $payload['from']);
        $this->assertSame(['ops@example.test', 'host@example.test'], $payload['to']);
        $this->assertSame('MtaaPass: Visitor checked in — Alice Mwangi', $payload['subject']);
        $this->assertSame("Visitor checked in — Alice Mwangi\nGate: Main Gate", $payload['text']);
        $this->assertFalse(array_key_exists('html', $payload), 'No html key when html is null.');
        $this->assertFalse(array_key_exists('reply_to', $payload), 'No reply_to key when null.');

        // The API key is only in the Authorization header, never in the payload.
        $this->assertStringNotContainsString(self::API_KEY, (string) $request['body']);
    }

    public function testHtmlAndReplyToAreIncludedWhenProvided(): void
    {
        $captured = [];
        $provider = new ResendMailProvider(
            $this->transport(['status' => 202, 'body' => '{"id":"email_456"}'], $captured),
            self::API_KEY,
            self::API_URL
        );

        $delivery = $provider->send($this->message('<p>Visitor checked in</p>', 'reply@example.test'));

        $this->assertSame('202', (string) (int) 202);
        $this->assertSame('email_456', $delivery['message_id']);

        $payload = (array) json_decode((string) $captured[0]['body'], true);
        $this->assertSame('<p>Visitor checked in</p>', $payload['html']);
        $this->assertSame('reply@example.test', $payload['reply_to']);
    }

    public function testHttpErrorsAreMappedWithoutLeakingTheKeyOrProviderBody(): void
    {
        foreach ([401, 422, 500] as $status) {
            $captured = [];
            $provider = new ResendMailProvider(
                $this->transport(
                    ['status' => $status, 'body' => '{"message":"Invalid API key or domain"}'],
                    $captured
                ),
                self::API_KEY,
                self::API_URL
            );

            try {
                $provider->send($this->message());
                $this->fail('HTTP ' . $status . ' must throw MailSendException.');
            } catch (MailSendException $exception) {
                $this->assertStringContainsString('MAIL_SEND_FAILED', $exception->getMessage());
                $this->assertSame($status, $exception->httpStatus());
                $this->assertSame('resend', $exception->provider());
                $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage(), 'The API key must never leak.');
                $this->assertStringNotContainsString('Invalid API key or domain', $exception->getMessage(), 'Provider body text must never leak.');
                $this->assertStringNotContainsString('Authorization', $exception->getMessage());
            }
        }
    }

    public function testNetworkFailureIsMappedToAnUnreachableProvider(): void
    {
        $captured = [];
        $provider = new ResendMailProvider(
            $this->transport(new \RuntimeException('cURL error 28: timed out with Bearer ' . self::API_KEY), $captured),
            self::API_KEY,
            self::API_URL
        );

        try {
            $provider->send($this->message());
            $this->fail('A transport failure must throw MailSendException.');
        } catch (MailSendException $exception) {
            $this->assertNull($exception->httpStatus(), 'A network timeout has no HTTP status.');
            $this->assertStringContainsString('MAIL_SEND_FAILED', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
            $this->assertStringNotContainsString('timed out', $exception->getMessage());
        }
    }

    public function testMissingApiKeyIsADisabledNoOpWithoutAnyRequest(): void
    {
        $captured = [];
        $provider = new ResendMailProvider(
            $this->transport(['status' => 200, 'body' => '{"id":"nope"}'], $captured),
            '',
            self::API_URL
        );

        $delivery = $provider->send($this->message());

        $this->assertSame('disabled', $delivery['status']);
        $this->assertSame('resend', $delivery['provider']);
        $this->assertSame('', $delivery['message_id']);
        $this->assertSame([], $captured, 'A missing key must never trigger an HTTP request.');
    }

    public function testMalformedSuccessBodyDegradesToAnEmptyMessageId(): void
    {
        $captured = [];
        $provider = new ResendMailProvider(
            $this->transport(['status' => 200, 'body' => 'not-json'], $captured),
            self::API_KEY,
            self::API_URL
        );

        $delivery = $provider->send($this->message());

        $this->assertSame('sent', $delivery['status']);
        $this->assertSame('', $delivery['message_id']);
    }
}

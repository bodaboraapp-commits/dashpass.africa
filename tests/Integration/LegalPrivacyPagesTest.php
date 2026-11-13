<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Response;
use DOMElement;

/**
 * Public legal + privacy centre.
 *
 * Covers the pages linked from the landing footer (Privacy Policy, Terms &
 * Conditions, Data Policy, Privacy choices, Delete my data) and the opt in/opt
 * out and deletion request flow: CSRF, validation, honeypot, persistence,
 * reference codes, audit trail and internal notification through the mail log
 * adapter.
 *
 * These are public pages: no auth, no seeded-user assumptions. Requests are
 * stored in `privacy_requests`; tests therefore start from a fresh seeded test
 * database like every other integration test.
 */
final class LegalPrivacyPagesTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $originalMail = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->originalMail = (array) Config::get('mail', []);
        Config::set('mail.driver', 'log');
        Config::set('mail.privacy_to', 'privacy@example.test');
        Config::set('mail.alert_to', []);
        Config::set('mail.contact_to', '');
    }

    public function tearDown(): void
    {
        Config::set('mail', $this->originalMail);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Pages                                                              */
    /* ------------------------------------------------------------------ */

    public function testEveryLegalPageRendersAsARealLinkedPage(): void
    {
        $expected = [
            '/privacy' => 'Privacy Policy',
            '/terms' => 'Terms & Conditions',
            '/data-policy' => 'Data Policy',
            '/privacy/choices' => 'Your privacy choices',
            '/data-deletion' => 'Delete my data',
        ];

        foreach ($expected as $path => $heading) {
            $response = $this->get($path);
            $this->assertStatus(200, $response, $path . ' must render a real page.');
            $this->assertCleanMarkup($response, $path . ' page');

            $dom = $this->dom($response);
            $this->assertCount(1, $this->xpath('//h1', $dom), $path . ' must render exactly one h1.');
            $this->assertTextContains($heading, $response);

            // The shared legal navigation links every notice page from every page.
            foreach (['/privacy', '/terms', '/data-policy', '/privacy/choices'] as $legalHref) {
                $this->assertTrue(
                    $this->xpath('//nav[contains(@class,"legal-nav")]//a[@href="' . $legalHref . '"]', $dom)->length === 1,
                    $path . ' must link ' . $legalHref . ' in the legal navigation.'
                );
            }

            $description = $this->xpath('//meta[@name="description"]', $dom)->item(0);
            $this->assertNotNull($description, $path . ' must carry a page-specific meta description.');
        }
    }

    public function testLandingFooterCarriesTheLegalAndPrivacyLinks(): void
    {
        $response = $this->get('/');
        $this->assertStatus(200, $response);

        $dom = $this->dom($response);
        $this->assertCount(
            1,
            $this->xpath('//footer[contains(@class,"guest-footer--landing")]', $dom),
            'The landing page must keep its slim landing footer.'
        );

        $links = [
            'Privacy Policy' => '/privacy',
            'Terms & Conditions' => '/terms',
            'Data Policy' => '/data-policy',
        ];

        foreach ($links as $label => $href) {
            $this->assertCount(
                1,
                $this->xpath(
                    '//footer[contains(@class,"guest-footer--landing")]//a[normalize-space(.)="' . $label . '"][@href="' . $href . '"]',
                    $dom
                ),
                'The landing footer must link "' . $label . '" to ' . $href . '.'
            );
        }

        // The slim footer intentionally carries only the three notices; the
        // privacy-choice tools stay one tap away from the Privacy Policy page.
        $this->assertCount(
            3,
            $this->xpath('//footer[contains(@class,"guest-footer--landing")]//nav[contains(@class,"legal-links")]//a', $dom),
            'The landing footer legal row must stay limited to the three policy pages.'
        );

        $privacy = $this->get('/privacy');
        $this->assertStatus(200, $privacy);
        foreach (['/privacy/choices', '/data-deletion'] as $href) {
            $this->assertTrue(
                $this->xpath('//a[@href="' . $href . '"]', $this->dom($privacy))->length >= 1,
                'The Privacy Policy page must keep the ' . $href . ' entry point.'
            );
        }
    }

    public function testAboutPageFooterAlsoCarriesTheLegalLinks(): void
    {
        $response = $this->get('/about');
        $this->assertStatus(200, $response);

        foreach (['/privacy', '/terms', '/data-policy'] as $href) {
            $this->assertCount(
                1,
                $this->xpath('//footer[contains(@class,"guest-footer")]//a[@href="' . $href . '"]', $this->dom($response)),
                'The guest footer on /about must link ' . $href . '.'
            );
        }
    }

    public function testPrivacyChoicesPageRendersBothRequestFormsAccessibly(): void
    {
        $response = $this->get('/privacy/choices');
        $this->assertStatus(200, $response);

        $dom = $this->dom($response);
        $forms = $this->xpath('//form[@action="/privacy/choices"][@method="post"]', $dom);
        $this->assertTrue($forms->length >= 2, 'The privacy choices page must render the communications and rights forms.');

        foreach ($forms as $form) {
            if (!$form instanceof DOMElement) {
                continue;
            }

            $this->assertCount(1, $this->xpath('.//input[@type="hidden"][@name="_token"]', $form), 'Every privacy form must carry a CSRF token.');
            $this->assertCount(1, $this->xpath('.//input[@name="website"]', $form), 'Every privacy form must keep its honeypot.');
        }

        // Opt in / opt out radios with real labels.
        $this->assertCount(1, $this->xpath('//input[@type="radio"][@name="consent_choice"][@value="opt_in"]', $dom));
        $this->assertCount(1, $this->xpath('//input[@type="radio"][@name="consent_choice"][@value="opt_out"]', $dom));

        // Access / correction options.
        $this->assertCount(1, $this->xpath('//select[@name="request_type"]//option[@value="access"]', $dom));
        $this->assertCount(1, $this->xpath('//select[@name="request_type"]//option[@value="correction"]', $dom));

        // Required name/phone fields are labelled and server-verified.
        foreach (['subject_name', 'subject_phone'] as $field) {
            $nodes = $this->xpath('//input[@name="' . $field . '"]', $dom);
            $this->assertTrue($nodes->length >= 1, 'The privacy forms must collect ' . $field . '.');
        }

        // The dedicated deletion page is advertised prominently.
        $this->assertCount(
            1,
            $this->xpath('//section[@id="delete"]//a[@href="/data-deletion"]', $dom),
            'The choices page must link the dedicated delete-my-data page from its deletion card.'
        );
    }

    public function testDeleteMyDataPageRendersAnAcknowledgedDeletionForm(): void
    {
        $response = $this->get('/data-deletion');
        $this->assertStatus(200, $response);

        $dom = $this->dom($response);
        $form = $this->xpath('//form[@action="/privacy/choices"][@method="post"]', $dom)->item(0);
        $this->assertNotNull($form, 'The deletion page must render its POST form.');

        $this->assertCount(1, $this->xpath('.//input[@type="hidden"][@name="_token"]', $form));
        $this->assertCount(1, $this->xpath('.//input[@type="hidden"][@name="form"][@value="deletion"]', $form));
        $this->assertCount(1, $this->xpath('.//input[@type="hidden"][@name="request_type"][@value="deletion"]', $form));
        $this->assertCount(1, $this->xpath('.//input[@type="checkbox"][@name="confirm"]', $form), 'Deletion must require an explicit acknowledgement.');
        $this->assertTextContains('What we may keep', $response);
    }

    /* ------------------------------------------------------------------ */
    /* Opt in / opt out                                                   */
    /* ------------------------------------------------------------------ */

    public function testOptOutRequestIsRecordedAuditedAndNotified(): void
    {
        $offset = $this->mailLogOffset();

        $response = $this->post('/privacy/choices', [
            'form' => 'communications',
            'consent_choice' => 'opt_out',
            'return_to' => '/privacy/choices',
            'subject_name' => 'QA Privacy Person',
            'subject_phone' => '0712 345 678',
            'subject_email' => 'qa.privacy@example.test',
            'message' => 'Please stop the optional product emails.',
        ], true);

        $this->assertRedirect($response, '/privacy/choices');

        $row = $this->db->selectOne(
            'SELECT * FROM privacy_requests ORDER BY id DESC LIMIT 1'
        );
        $this->assertNotNull($row, 'A submitted privacy request must be persisted.');
        $this->assertSame('opt_out', (string) $row['request_type']);
        $this->assertSame('pending', (string) $row['status']);
        $this->assertSame('+254712345678', (string) $row['subject_phone']);
        $this->assertSame('qa.privacy@example.test', (string) $row['subject_email']);
        $this->assertSame((string) Config::get('privacy.version', '1.0'), (string) $row['policy_version']);

        $reference = (string) $row['reference'];
        $this->assertMatches('/^DP-\d{8}-[0-9A-F]{6}$/', $reference, 'Every request needs an opaque reference code.');

        // The success panel shows the reference; it never echoes the contact data.
        $page = $this->get('/privacy/choices');
        $this->assertStatus(200, $page);
        $this->assertTextContains($reference, $page);
        $this->assertTextContains('Request received', $page);
        $this->assertTextNotContains('qa.privacy@example.test', $page);
        $this->assertCount(
            1,
            $this->xpath('//*[@role="status"][contains(normalize-space(.),"Request received")]', $this->dom($page)),
            'The confirmation must be announced in a status region.'
        );

        // Audit trail: the request is recorded, without PII in the metadata.
        $audit = $this->db->selectOne(
            "SELECT metadata FROM audit_logs WHERE action = 'privacy.request_created' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($audit, 'Creating a privacy request must be audited.');
        $metadata = (string) ($audit['metadata'] ?? '');
        $this->assertStringContainsString($reference, $metadata);
        $this->assertStringNotContainsString('qa.privacy@example.test', $metadata);
        $this->assertStringNotContainsString('QA Privacy Person', $metadata);

        // Internal notification through the configured privacy inbox.
        $lines = $this->mailLogLinesSince($offset);
        $this->assertNotEmpty($lines, 'A privacy request must notify the privacy inbox through the mail pipeline.');
        $privacyLine = null;

        foreach ($lines as $line) {
            if (str_contains((string) ($line['subject'] ?? ''), 'Privacy request')) {
                $privacyLine = $line;
                break;
            }
        }

        $this->assertNotNull($privacyLine, 'The mail log must contain the privacy request notification.');
        $this->assertSame('log', (string) ($privacyLine['driver'] ?? ''));
        $this->assertContains('event', (array) ($privacyLine['metadata_keys'] ?? []));
        $this->assertContains('p***@example.test', (array) ($privacyLine['to'] ?? []), 'The privacy inbox must be the configured, masked recipient.');
        $this->assertStringContainsString('Opt out', (string) ($privacyLine['subject'] ?? ''));
    }

    public function testOptInRequestIsRecorded(): void
    {
        $response = $this->post('/privacy/choices', [
            'form' => 'communications',
            'consent_choice' => 'opt_in',
            'return_to' => '/privacy/choices',
            'subject_name' => 'QA Opt In',
            'subject_phone' => '+254733000111',
        ], true);

        $this->assertRedirect($response, '/privacy/choices');
        $row = $this->db->selectOne('SELECT request_type, status FROM privacy_requests ORDER BY id DESC LIMIT 1');
        $this->assertNotNull($row);
        $this->assertSame('opt_in', (string) $row['request_type']);
        $this->assertSame('pending', (string) $row['status']);
    }

    /* ------------------------------------------------------------------ */
    /* Validation, CSRF, honeypot and throttle                            */
    /* ------------------------------------------------------------------ */

    public function testInvalidRequestIsRejectedWithAccessibleErrorsAndOldInput(): void
    {
        $response = $this->post('/privacy/choices', [
            'form' => 'communications',
            'return_to' => '/privacy/choices',
            'subject_name' => 'QA Privacy Person',
            'subject_phone' => 'not-a-phone',
            'subject_email' => 'not-an-email',
        ], true);

        $this->assertRedirect($response, '/privacy/choices');
        $this->assertSame(0, $this->privacyRequestCount(), 'Invalid requests must not be persisted.');

        $page = $this->get('/privacy/choices');
        $this->assertStatus(200, $page);
        $dom = $this->dom($page);

        $this->assertCount(1, $this->xpath('//*[contains(@class,"form-error-summary")][@role="alert"]', $dom));
        $this->assertTextContains('Please check the highlighted fields', $page);
        $this->assertTextContains('communication preference', $page);
        $this->assertBodyContains('value="QA Privacy Person"', $page, 'Old input must be preserved.');

        // Field-level errors are attached to the submitted form only.
        $this->assertTrue(
            $this->xpath('//*[contains(@class,"field__error")][contains(normalize-space(.),"phone number")]', $dom)->length >= 1,
            'The phone field must show its validation message.'
        );
    }

    public function testDeletionWithoutAcknowledgementIsRejected(): void
    {
        $response = $this->post('/privacy/choices', [
            'form' => 'deletion',
            'request_type' => 'deletion',
            'return_to' => '/data-deletion',
            'subject_name' => 'QA Delete',
            'subject_phone' => '0712 345 678',
        ], true);

        $this->assertRedirect($response, '/data-deletion');
        $this->assertSame(0, $this->privacyRequestCount(), 'Deletion without the acknowledgement must not be persisted.');

        $page = $this->get('/data-deletion');
        $this->assertStatus(200, $page);
        $this->assertTextContains('Please check the highlighted fields', $page);
        $this->assertTextContains('deletion acknowledgement', $page);
    }

    public function testPrivacyRequestsRequireCsrf(): void
    {
        $blocked = $this->post('/privacy/choices', [
            'form' => 'communications',
            'consent_choice' => 'opt_out',
            'subject_name' => 'QA No Csrf',
            'subject_phone' => '0712 345 678',
        ], false);

        $this->assertStatus(403, $blocked, 'The privacy request form must be CSRF-protected.');
        $this->assertSame(0, $this->privacyRequestCount());
    }

    public function testHoneypotSubmissionIsDroppedSilently(): void
    {
        $offset = $this->mailLogOffset();

        $response = $this->post('/privacy/choices', [
            'form' => 'communications',
            'consent_choice' => 'opt_in',
            'return_to' => '/privacy/choices',
            'subject_name' => 'QA Bot',
            'subject_phone' => '0712 345 678',
            'website' => 'https://spam.example',
        ], true);

        $this->assertRedirect($response, '/privacy/choices');
        $this->assertSame(0, $this->privacyRequestCount(), 'A honeypot submission must never create a request.');
        $this->assertCount(0, $this->mailLogLinesSince($offset), 'A honeypot submission must never notify anyone.');
    }

    public function testPrivacyRequestEndpointIsThrottled(): void
    {
        $payload = [
            'form' => 'communications',
            'consent_choice' => 'opt_in',
            'return_to' => '/privacy/choices',
            'subject_name' => 'QA Throttle',
            'subject_phone' => '0712 345 678',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->post('/privacy/choices', $payload, true);
            $this->assertRedirect($response, '/privacy/choices');
        }

        $blocked = $this->post('/privacy/choices', $payload, true);
        $this->assertStatus(429, $blocked, 'The sixth request inside the window must be throttled.');
        $this->assertSame(5, $this->privacyRequestCount(), 'Throttled requests must not be persisted.');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function privacyRequestCount(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM privacy_requests');
    }

    private function mailLogPath(): string
    {
        return BASE_PATH . '/' . ltrim((string) Config::get('mail.log_path', 'storage/logs/mail.log'), '/');
    }

    private function mailLogOffset(): int
    {
        clearstatcache();
        $path = $this->mailLogPath();

        return is_file($path) ? (int) filesize($path) : 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mailLogLinesSince(int $offset): array
    {
        clearstatcache();
        $path = $this->mailLogPath();

        if (!is_file($path)) {
            return [];
        }

        $contents = (string) file_get_contents($path);
        $new = substr($contents, $offset);
        $lines = [];

        foreach (preg_split('/\R/', trim($new)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }

        return $lines;
    }
}

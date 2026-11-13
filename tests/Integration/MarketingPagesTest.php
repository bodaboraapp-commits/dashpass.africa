<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Response;
use DOMElement;

/**
 * Public marketing pages: real About / Blog / Help pages, the guest header nav
 * (PASS · PAY · ABOUT · BLOG · HELP?) and the Help contact form
 * (validation, CSRF, safe mail delivery through the log adapter).
 *
 * These are public guest-layout screens: no auth, no database writes. The
 * contact form writes only a redacted line to storage/logs/mail.log in tests.
 */
final class MarketingPagesTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $originalMail = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->originalMail = (array) Config::get('mail', []);
        Config::set('mail.driver', 'log');
        Config::set('mail.alert_to', ['support@example.test']);
    }

    public function tearDown(): void
    {
        Config::set('mail', $this->originalMail);

        parent::tearDown();
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

    /**
     * @return array<int, string>
     */
    private function guestNavTexts(Response $response): array
    {
        $labels = [];

        foreach ($this->xpath(
            '//nav[contains(@class,"guest-nav")]//a[contains(@class,"guest-nav__link")]',
            $this->dom($response)
        ) as $link) {
            if ($link instanceof DOMElement) {
                $labels[] = $this->text($link);
            }
        }

        return $labels;
    }

    /* ------------------------------------------------------------------ */
    /* Pages                                                              */
    /* ------------------------------------------------------------------ */

    public function testAboutPageIsARealRenderedPage(): void
    {
        $response = $this->get('/about');

        $this->assertStatus(200, $response, '/about must render a real page (no redirect).');
        $this->assertCleanMarkup($response, 'About page');
        $dom = $this->dom($response);
        $this->assertCount(1, $this->xpath('//h1', $dom), 'The About page must render exactly one h1.');

        $h1 = $this->xpath('//h1', $dom)->item(0);
        $this->assertNotNull($h1);
        $this->assertTextContains('About DashPASS', $response);

        // Page-specific meta description flows through the guest layout to <head>.
        $description = $this->xpath('//meta[@name="description"]', $dom)->item(0);
        $this->assertNotNull($description);
        $this->assertStringContainsString('Pass & Pay', (string) $description->getAttribute('content'));

        // PASS & PAY positioning + the six value props (same icons/labels as Home).
        $this->assertTextContains('Pass & Pay', $response);
        foreach ([
            'Automated Access',
            'Frictionless Payment',
            'On spot Recognition',
            'Instant Alerts',
            'Financial & Security Logs',
            'Blacklisting',
        ] as $valueProp) {
            $this->assertTextContains($valueProp, $response);
        }

        // Who it serves.
        foreach (['Residents and tenants', 'Estate administrators', 'Security teams and guards', 'Platform operators'] as $audience) {
            $this->assertTextContains($audience, $response);
        }

        // CTAs to sign in / contact + the public support email.
        $this->assertTrue(
            $this->xpath('//a[@href="/login"][contains(normalize-space(.),"Check in")]', $dom)->length >= 1,
            'About must link to the sign-in flow.'
        );
        $this->assertTrue(
            $this->xpath('//a[@href="/help"][contains(normalize-space(.),"Contact us")]', $dom)->length >= 1,
            'About must link to the Help contact page.'
        );
        $this->assertTrue(
            $this->xpath('//a[starts-with(@href,"mailto:")]', $dom)->length >= 1,
            'About must expose the support email.'
        );
    }

    public function testBlogPageListsTheSixPublishedPosts(): void
    {
        $response = $this->get('/blog');

        $this->assertStatus(200, $response, '/blog must render a real page.');
        $this->assertCleanMarkup($response, 'Blog page');
        $dom = $this->dom($response);
        $this->assertCount(1, $this->xpath('//h1', $dom), 'The Blog page must render exactly one h1.');

        $this->assertTextContains('Insights & updates', $response);

        // The six SEO posts from BlogContent render as real article cards, newest-first.
        $posts = \App\Modules\Marketing\BlogContent::posts();
        $this->assertCount(6, $posts, 'Six posts must be published.');

        $listedHrefs = [];

        foreach ($this->xpath('//article//a[starts-with(@href,"/blog/")]', $dom) as $link) {
            if ($link instanceof DOMElement) {
                $href = (string) $link->getAttribute('href');

                if (!in_array($href, $listedHrefs, true)) {
                    $listedHrefs[] = $href;
                }
            }
        }

        $expectedHrefs = array_map(
            static fn (array $post): string => '/blog/' . (string) $post['slug'],
            $posts
        );

        $this->assertSame($expectedHrefs, $listedHrefs, 'The Blog page must list all six posts newest-first.');

        foreach ($posts as $post) {
            $this->assertTextContains((string) $post['title'], $response);
        }

        // The honest empty state is gone now that posts are published.
        $this->assertTextNotContains('No posts published yet', $response);

        $this->assertCount(
            1,
            $this->xpath('//a[@href="/help"][contains(normalize-space(.),"Contact us")]', $dom),
            'The Blog page must keep the contact CTA.'
        );
    }

    public function testHelpPageRendersFaqAndAccessibleContactForm(): void
    {
        $response = $this->get('/help');

        $this->assertStatus(200, $response, '/help must render a real page.');
        $this->assertCleanMarkup($response, 'Help page');
        $dom = $this->dom($response);
        $this->assertCount(1, $this->xpath('//h1', $dom), 'The Help page must render exactly one h1.');
        $this->assertTextContains('Help centre', $response);
        $this->assertTextContains('Frequently asked questions', $response);

        // Native <details>/<summary> FAQ (works without JavaScript).
        $details = $this->xpath('//details[contains(@class,"disclosure")]', $dom);
        $this->assertTrue($details->length >= 8, 'The Help page must render at least 8 FAQ <details> items (got ' . $details->length . ').');

        foreach ($details as $item) {
            if (!$item instanceof DOMElement) {
                continue;
            }

            $summary = $this->xpath('./summary', $item)->item(0);
            $this->assertNotNull($summary, 'Every FAQ <details> needs a <summary> question.');
            $this->assertTrue(trim((string) $summary->textContent) !== '', 'FAQ summaries must not be empty.');
            $this->assertCount(1, $this->xpath('.//*[contains(@class,"disclosure__body")]', $item), 'Every FAQ needs an answer body.');
        }

        // Contact form: POST to /help/contact, CSRF token, four labelled fields.
        $forms = $this->xpath('//form[@action="/help/contact"][@method="post"]', $dom);
        $this->assertCount(1, $forms, 'The Help page must render one POST contact form to /help/contact.');
        $form = $forms->item(0);
        $this->assertNotNull($form);

        $this->assertCount(1, $this->xpath('.//input[@type="hidden"][@name="_token"]', $form), 'The contact form must carry a CSRF token field.');

        foreach (['name' => 'text', 'email' => 'email', 'subject' => 'text', 'message' => 'textarea'] as $field => $tag) {
            $nodes = $this->xpath('.//' . ($tag === 'textarea' ? 'textarea' : 'input') . '[@name="' . $field . '"]', $form);
            $this->assertCount(1, $nodes, 'The contact form must contain the ' . $field . ' field.');
            $node = $nodes->item(0);
            $this->assertNotNull($node);
            $id = (string) $node->getAttribute('id');
            $this->assertTrue($id !== '', 'The ' . $field . ' field needs an id for its label.');
            $this->assertCount(1, $this->xpath('.//label[@for="' . $id . '"]', $form), 'The ' . $field . ' field needs an associated <label>.');
        }

        // Honeypot is present but hidden from humans/assistive tech.
        $honeypots = $this->xpath('.//input[@name="website"]', $form);
        $this->assertCount(1, $honeypots, 'The contact form must keep its anti-spam honeypot.');
        $honeypot = $honeypots->item(0);
        $this->assertNotNull($honeypot);
        $honeypotWrapper = $honeypot->parentNode;
        $this->assertInstanceOf(DOMElement::class, $honeypotWrapper);
        $this->assertTrue($honeypotWrapper->hasAttribute('hidden'), 'The honeypot wrapper must be hidden.');

        // The page exposes the support email as an alternative.
        $this->assertTrue(
            $this->xpath('//a[starts-with(@href,"mailto:")]', $dom)->length >= 1,
            'The Help page must expose the support email.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Guest nav                                                          */
    /* ------------------------------------------------------------------ */

    public function testGuestNavCarriesPassPayAboutBlogAndHelp(): void
    {
        foreach (['/about', '/blog', '/help'] as $path) {
            $response = $this->get($path);
            $this->assertStatus(200, $response, $path . ' must render.');
            $dom = $this->dom($response);

            $this->assertSame(
                ['PASS', 'PAY', 'ABOUT', 'BLOG', 'HELP?'],
                $this->guestNavTexts($response),
                'The guest header nav must be PASS · PAY · ABOUT · BLOG · HELP? on ' . $path . '.'
            );

            foreach ([
                'PASS' => '/login',
                'PAY' => '/login',
                'ABOUT' => '/about',
                'BLOG' => '/blog',
                'HELP?' => '/help',
            ] as $label => $href) {
                $this->assertCount(
                    1,
                    $this->xpath('//nav[contains(@class,"guest-nav")]//a[normalize-space(.)="' . $label . '"][@href="' . $href . '"]', $dom),
                    'The ' . $label . ' nav item must link to ' . $href . ' on ' . $path . '.'
                );
            }

            $this->assertCount(1, $this->xpath('//nav[contains(@class,"guest-nav")]//a[contains(normalize-space(.),"Login")][@href="/login"]', $dom), 'The guest nav must keep the Login pill.');
            $this->assertCount(0, $this->xpath('//nav[contains(@class,"guest-nav")]//a[starts-with(@href,"mailto:")]', $dom), 'HELP? must be a real page link, not mailto.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Contact form                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, string>
     */
    private function validContactData(): array
    {
        return [
            'name' => 'QA Contact Name',
            'email' => 'qa.contact@example.test',
            'subject' => 'QA contact subject',
            'message' => 'This is a QA contact message body with enough characters.',
        ];
    }

    public function testContactFormRejectsInvalidInputWithAccessibleErrorsAndOldInput(): void
    {
        $invalid = [
            'name' => 'QA Contact Name',
            'email' => 'not-an-email',
            'subject' => 'QA contact subject',
            'message' => 'short',
        ];

        $response = $this->post('/help/contact', $invalid, true);
        $this->assertRedirect($response, '/help');

        $page = $this->get('/help');
        $this->assertStatus(200, $page);
        $dom = $this->dom($page);

        $summaries = $this->xpath('//*[contains(@class,"form-error-summary")][@role="alert"]', $dom);
        $this->assertCount(1, $summaries, 'Validation failures must render an error summary with role="alert".');
        $this->assertTextContains('Please check the highlighted fields', $page);
        $this->assertTextContains('Enter a valid email address.', $page);
        $this->assertTextContains('at least 10 characters', $page);

        // Old input is preserved across the redirect.
        $this->assertBodyContains('value="QA Contact Name"', $page);
        $this->assertBodyContains('value="QA contact subject"', $page);
    }

    public function testContactFormSendsThroughTheMailLogAdapterOnSuccess(): void
    {
        $offset = $this->mailLogOffset();

        $response = $this->post('/help/contact', $this->validContactData(), true);
        $this->assertRedirect($response, '/help');

        $page = $this->get('/help');
        $this->assertStatus(200, $page);
        $this->assertTextContains('your message has been sent', $page);
        $this->assertCount(
            1,
            $this->xpath('//*[@role="status"][contains(normalize-space(.),"your message has been sent")]', $this->dom($page)),
            'The success thank-you must be announced in a status region.'
        );
        $this->assertCount(0, $this->xpath('//*[contains(@class,"form-error-summary")]', $this->dom($page)), 'A successful submission must not show the error summary.');

        $lines = $this->mailLogLinesSince($offset);
        $this->assertNotEmpty($lines, 'The contact form must deliver through the existing mail infrastructure (log adapter in tests).');

        $contactLine = null;
        foreach ($lines as $line) {
            if (str_contains((string) ($line['subject'] ?? ''), 'DashPASS contact form')) {
                $contactLine = $line;
                break;
            }
        }

        $this->assertNotNull($contactLine, 'The mail log must contain the contact-form email.');
        $this->assertSame('log', (string) ($contactLine['driver'] ?? ''), 'The test mail driver must be the log adapter.');
        $this->assertContains('event', (array) ($contactLine['metadata_keys'] ?? []), 'Contact mail must be tagged for the mail pipeline.');
        $this->assertContains('s***@example.test', (array) ($contactLine['to'] ?? []), 'The recipient must be the configured support inbox, masked in logs.');

        // Safety: no raw visitor email, no message body, no PIN/token-shaped data in the log.
        $rawLine = (string) json_encode($contactLine);
        $this->assertStringNotContainsString('qa.contact@example.test', $rawLine, 'The mail log must stay redacted (no raw visitor email).');
        $this->assertStringNotContainsString('QA contact message body', $rawLine, 'The mail log must never contain the contact message body.');
        $this->assertDoesNotMatch('/\b\d{4,8}\b/', (string) ($contactLine['subject'] ?? ''), 'The logged subject must not contain credential-shaped digit runs.');
    }

    public function testContactFormRequiresCsrfAndIgnoresHoneypotBotSubmissions(): void
    {
        $offset = $this->mailLogOffset();

        $blocked = $this->post('/help/contact', $this->validContactData(), false);
        $this->assertStatus(403, $blocked, 'The contact form must be CSRF-protected.');
        $this->assertCount(0, $this->mailLogLinesSince($offset), 'A CSRF-rejected submission must never be sent by email.');

        $offset = $this->mailLogOffset();
        $bot = $this->post('/help/contact', $this->validContactData() + ['website' => 'https://spam.example'], true);
        $this->assertRedirect($bot, '/help');
        $this->assertCount(0, $this->mailLogLinesSince($offset), 'A honeypot submission must be dropped silently (no email).');
    }
}

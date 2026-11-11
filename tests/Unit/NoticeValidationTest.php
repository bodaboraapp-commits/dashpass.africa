<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Core\Validator;
use App\Modules\Notices\NoticePresenter;
use Tests\TestCase;

/**
 * Estate-notices validation contract (docs/estate-notices-contract.md §3).
 *
 * PRE-WRITTEN by qa-mail (T55) for the Increment-7 gate: the notices module was
 * still landing while this file was written, so it was parked in
 * storage/temporary/pending-tests/ and moves to tests/Unit/ at the gate.
 *
 * The unit-visible rules are: canonical enums/labels/tones, excerpt/ISO shapes,
 * and the field rules the service applies (title 3-160, body 10-2000, enums,
 * expires_at string <= 40). The "expires_at must be >= now + 5 minutes"
 * threshold needs a clock/context and is covered at integration level in
 * NoticeModuleTest.
 */
final class NoticeValidationTest extends TestCase
{
    /**
     * The exact rule map NoticeService::validate() applies (frozen contract §3).
     *
     * @return array<string, string>
     */
    private function rules(): array
    {
        return [
            'title' => 'required|string|min:3|max:160',
            'body' => 'required|string|min:10|max:2000',
            'category' => 'required|in:' . implode(',', NoticePresenter::CATEGORIES),
            'priority' => 'required|in:' . implode(',', NoticePresenter::PRIORITIES),
            'audience' => 'required|in:' . implode(',', NoticePresenter::AUDIENCES),
            'expires_at' => 'nullable|string|max:40',
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        return Validator::validate($data, $this->rules(), [
            'title' => 'title',
            'body' => 'body',
            'category' => 'category',
            'priority' => 'priority',
            'audience' => 'audience',
            'expires_at' => 'expiry time',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, array<int, string>>
     */
    private function errorsFor(array $data): array
    {
        try {
            $this->validate($data);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('Expected the notice payload to be rejected.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Water supply maintenance on Saturday',
            'body' => 'Water will be interrupted from 09:00 to 13:00 for scheduled maintenance.',
            'category' => 'general',
            'priority' => 'normal',
            'audience' => 'all',
            'expires_at' => '',
        ], $overrides);
    }

    public function testCanonicalEnumsMatchTheContract(): void
    {
        $this->assertSame(['general', 'security', 'maintenance', 'event', 'emergency'], NoticePresenter::CATEGORIES);
        $this->assertSame(['normal', 'important', 'urgent'], NoticePresenter::PRIORITIES);
        $this->assertSame(['all', 'residents', 'staff'], NoticePresenter::AUDIENCES);
        $this->assertSame(['draft', 'published', 'archived'], NoticePresenter::STATUSES);
    }

    public function testOptionListsCarryLabelsAndTones(): void
    {
        // Option lists are instance methods; assert the frozen value/label pairs.
        $presenter = new NoticePresenter();
        $categoryValues = array_map(static fn (array $option): string => $option['value'], $presenter->categories());
        $priorityValues = array_map(static fn (array $option): string => $option['value'], $presenter->priorities());
        $audienceValues = array_map(static fn (array $option): string => $option['value'], $presenter->audiences());
        $statusValues = array_map(static fn (array $option): string => $option['value'], $presenter->statuses());

        $this->assertSame(NoticePresenter::CATEGORIES, $categoryValues);
        $this->assertSame(NoticePresenter::PRIORITIES, $priorityValues);
        $this->assertSame(NoticePresenter::AUDIENCES, $audienceValues);
        $this->assertSame(NoticePresenter::STATUSES, $statusValues);
        $this->assertSame('Emergency', $presenter->categories()[4]['label']);
        $this->assertSame('Urgent', $presenter->priorities()[2]['label']);
        $this->assertSame('danger', $presenter->priorities()[2]['tone']);
        $this->assertSame('Staff', $presenter->audiences()[2]['label']);
        $this->assertSame('Archived', $presenter->statuses()[2]['label']);
    }

    public function testPriorityToneMapping(): void
    {
        $this->assertSame('neutral', NoticePresenter::priorityTone('normal'));
        $this->assertSame('warn', NoticePresenter::priorityTone('important'));
        $this->assertSame('danger', NoticePresenter::priorityTone('urgent'));
        $this->assertSame('neutral', NoticePresenter::priorityTone('unexpected'));
    }

    public function testLabelFallbacksAreHumanised(): void
    {
        $this->assertSame('General', NoticePresenter::categoryLabel('general'));
        $this->assertSame('Important', NoticePresenter::priorityLabel('important'));
        $this->assertSame('Everyone', NoticePresenter::audienceLabel('all'));
        $this->assertSame('Published', NoticePresenter::statusLabel('published'));
        $this->assertSame('ok', NoticePresenter::statusTone('published'));
        $this->assertSame('Some Future', NoticePresenter::categoryLabel('some_future'));
        $this->assertSame('Oddvalue', NoticePresenter::priorityLabel('oddvalue'));
    }

    public function testExcerptIsPlainTextAndCapped(): void
    {
        $this->assertSame('', NoticePresenter::excerpt('   '));
        $this->assertSame('Short notice body', NoticePresenter::excerpt("Short\n notice   body"));

        $long = str_repeat('word ', 80);
        $excerpt = NoticePresenter::excerpt($long);
        $this->assertTrue(mb_strlen($excerpt) <= 140, 'Excerpt must be capped at 140 characters.');
        $this->assertStringEndsWith('…', $excerpt);
        $this->assertStringNotContainsString('<', $excerpt, 'Excerpts are plain text, never HTML.');
    }

    public function testIsoFormattingIsNullSafeAndUtc(): void
    {
        $this->assertNull(NoticePresenter::iso(null));
        $this->assertNull(NoticePresenter::iso(''));
        $this->assertSame('2026-05-25T10:00:00.000Z', NoticePresenter::iso('2026-05-25 10:00:00.000'));
    }

    public function testValidPayloadPassesAndIsTrimmed(): void
    {
        $clean = $this->validate($this->validPayload([
            'title' => '  Committee meeting  ',
            'body' => '  The residents committee meets on Saturday at the clubhouse.  ',
            'expires_at' => '',
        ]));

        $this->assertSame('Committee meeting', $clean['title']);
        $this->assertSame('The residents committee meets on Saturday at the clubhouse.', $clean['body']);
        $this->assertNull($clean['expires_at']);
    }

    public function testTitleLengthRules(): void
    {
        $this->assertArrayHasKey('title', $this->errorsFor($this->validPayload(['title' => 'ab'])));
        $this->assertArrayHasKey('title', $this->errorsFor($this->validPayload(['title' => str_repeat('a', 161)])));

        $accepted = $this->validate($this->validPayload(['title' => str_repeat('a', 160)]));
        $this->assertSame(160, mb_strlen($accepted['title']));
    }

    public function testBodyLengthRules(): void
    {
        $this->assertArrayHasKey('body', $this->errorsFor($this->validPayload(['body' => 'too short'])));
        $this->assertArrayHasKey('body', $this->errorsFor($this->validPayload(['body' => str_repeat('a', 2001)])));

        $accepted = $this->validate($this->validPayload(['body' => str_repeat('a', 2000)]));
        $this->assertSame(2000, mb_strlen($accepted['body']));
    }

    public function testEnumsRejectUnknownValues(): void
    {
        $this->assertArrayHasKey('category', $this->errorsFor($this->validPayload(['category' => 'gossip'])));
        $this->assertArrayHasKey('priority', $this->errorsFor($this->validPayload(['priority' => 'critical'])));
        $this->assertArrayHasKey('audience', $this->errorsFor($this->validPayload(['audience' => 'guests'])));
    }

    public function testRequiredFieldsAreEnforced(): void
    {
        $errors = $this->errorsFor([]);

        foreach (['title', 'body', 'category', 'priority', 'audience'] as $field) {
            $this->assertArrayHasKey($field, $errors, 'Missing ' . $field . ' must be rejected.');
        }
    }

    public function testExpiresAtRuleCapsRawInputLength(): void
    {
        $this->assertArrayHasKey(
            'expires_at',
            $this->errorsFor($this->validPayload(['expires_at' => str_repeat('x', 41)])),
            'The raw expiry input is capped at 40 characters (parsed later by the service).'
        );

        $accepted = $this->validate($this->validPayload(['expires_at' => '2026-12-01T18:00']));
        $this->assertSame('2026-12-01T18:00', $accepted['expires_at']);
    }
}

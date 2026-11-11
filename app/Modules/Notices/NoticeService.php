<?php

declare(strict_types=1);

namespace App\Modules\Notices;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Uuid;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Modules\Notifications\NotificationService;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Estate notices service (Increment 7 §3).
 *
 * Admin workflow: create draft -> publish (idempotent guard) -> archive.
 * Published notices are immutable except archiving; archived notices are closed.
 * Residents see published, audience-matched, unexpired notices ordered by priority
 * then recency; guards see the staff/all audiences. Foreign or non-visible uuids
 * return a generic 404. Every mutation audits uuid/enums/status only (never the
 * title or body). Escaping is a view concern (the service returns raw text).
 */
final class NoticeService
{
    private const EXPIRY_MIN_LEAD_MINUTES = 5;

    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly NoticeRepository $notices,
        private readonly NoticePresenter $presenter
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db, new NoticeRepository($context->db), new NoticePresenter());
    }

    /* ------------------------------------------------------------------ */
    /* Options                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categories(): array
    {
        return $this->presenter->categories();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function priorities(): array
    {
        return $this->presenter->priorities();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function audiences(): array
    {
        return $this->presenter->audiences();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function statusOptions(): array
    {
        return $this->presenter->statuses();
    }

    /* ------------------------------------------------------------------ */
    /* Resident / guard reads                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function residentList(): array
    {
        return [
            'notices' => array_map(
                static fn (array $row): array => NoticePresenter::item($row),
                $this->notices->visibleForResident($this->estateId())
            ),
            'filters' => ['category' => 'all', 'priority' => 'all'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function residentDetail(string $uuid): array
    {
        $row = $this->notices->findVisibleForResident($uuid, $this->estateId());

        if ($row === null) {
            throw HttpException::notFound('NOTICE_NOT_FOUND', 'That notice is not available.');
        }

        return NoticePresenter::detail($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function guardList(): array
    {
        return [
            'notices' => array_map(
                static fn (array $row): array => NoticePresenter::item($row),
                $this->notices->visibleForGuard($this->estateId())
            ),
            'filters' => ['category' => 'all', 'priority' => 'all'],
        ];
    }

    /**
     * Home announcement feed (<= 3 published, unexpired, resident-visible).
     *
     * @return array<int, array<string, mixed>>
     */
    public function homeAnnouncements(int $limit = 3): array
    {
        return array_map(
            static fn (array $row): array => NoticePresenter::homeAnnouncement($row),
            $this->notices->visibleForResident($this->estateId(), $limit)
        );
    }

    /* ------------------------------------------------------------------ */
    /* Admin reads                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function adminList(?string $status = null, ?string $category = null, ?string $priority = null): array
    {
        $status = $this->filterValue($status, NoticePresenter::STATUSES);
        $category = $this->filterValue($category, NoticePresenter::CATEGORIES);
        $priority = $this->filterValue($priority, NoticePresenter::PRIORITIES);

        return [
            'notices' => array_map(
                static fn (array $row): array => NoticePresenter::item($row),
                $this->notices->forAdmin($this->estateId(), $status, $category, $priority)
            ),
            'filters' => [
                'status' => $status ?? 'all',
                'category' => $category ?? 'all',
                'priority' => $priority ?? 'all',
            ],
            'counts' => $this->notices->counts($this->estateId()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminView(string $uuid): array
    {
        return NoticePresenter::detail($this->findAdmin($uuid));
    }

    /* ------------------------------------------------------------------ */
    /* Admin mutations                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $clean = $this->validate($input);
        $this->assertExpiryInFuture($clean['expires_at'] ?? null);

        $user = $this->context->auth->requireUser();
        $uuid = Uuid::v4();
        $now = Clock::nowDb();
        $image = $this->prepareImage($input, (string) $clean['title'], null);

        try {
            $this->notices->insert([
                'uuid' => $uuid,
                'estate_id' => $this->estateId(),
                'title' => (string) $clean['title'],
                'body' => (string) $clean['body'],
                'image_path' => $image['path'] ?? null,
                'image_alt' => $image['alt'] ?? null,
                'category' => (string) $clean['category'],
                'priority' => (string) $clean['priority'],
                'status' => 'draft',
                'audience' => (string) $clean['audience'],
                'published_at' => null,
                'expires_at' => $this->expiresAtOrNull($clean['expires_at'] ?? null),
                'created_by_user_id' => (int) $user['id'],
                'updated_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $exception) {
            if (isset($image['path'])) {
                (new NoticeImageService())->remove((string) $image['path']);
            }

            throw $exception;
        }

        $this->audit('notice.created', $uuid, [
            'category' => (string) $clean['category'],
            'priority' => (string) $clean['priority'],
            'audience' => (string) $clean['audience'],
            'status' => 'draft',
        ]);

        return $this->adminView($uuid);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(string $uuid, array $input): array
    {
        $row = $this->findAdmin($uuid);
        $this->assertEditable($row);
        $clean = $this->validate($input);
        $this->assertExpiryInFuture($clean['expires_at'] ?? null);

        $user = $this->context->auth->requireUser();
        $oldPath = isset($row['image_path']) && $row['image_path'] !== null ? (string) $row['image_path'] : null;
        $newImage = $this->prepareImage($input, (string) $clean['title'], $oldPath);
        $remove = !isset($input['_image']) && (bool) ($input['_remove_image'] ?? false);

        if ($remove) {
            $newImage = ['path' => null, 'alt' => null];
        }

        $fields = [
            'title' => (string) $clean['title'],
            'body' => (string) $clean['body'],
            'category' => (string) $clean['category'],
            'priority' => (string) $clean['priority'],
            'audience' => (string) $clean['audience'],
            'expires_at' => $this->expiresAtOrNull($clean['expires_at'] ?? null),
            'updated_by_user_id' => (int) $user['id'],
            'updated_at' => Clock::nowDb(),
        ];

        if ($newImage !== null) {
            $fields['image_path'] = $newImage['path'] ?? null;
            $fields['image_alt'] = $newImage['alt'] ?? null;
        }

        try {
            $this->notices->update((int) $row['id'], $fields);
        } catch (Throwable $exception) {
            if ($newImage !== null && ($newImage['path'] ?? null) !== null && $newImage['path'] !== $oldPath) {
                (new NoticeImageService())->remove((string) $newImage['path']);
            }

            throw $exception;
        }

        if ($newImage !== null && $oldPath !== null && $oldPath !== ($newImage['path'] ?? null)) {
            (new NoticeImageService())->remove($oldPath);
        }

        $this->audit('notice.updated', (string) $row['uuid'], [
            'category' => (string) $clean['category'],
            'priority' => (string) $clean['priority'],
            'audience' => (string) $clean['audience'],
            'status' => (string) $row['status'],
            'fields' => ['title', 'body', 'category', 'priority', 'audience', 'expires_at'],
        ]);

        return $this->adminView((string) $row['uuid']);
    }

    /**
     * @return array<string, mixed>
     */
    public function publish(string $uuid): array
    {
        $row = $this->findAdmin($uuid);
        $status = (string) $row['status'];

        if ($status === 'archived') {
            throw new HttpException(409, 'NOTICE_CLOSED', 'This notice is archived and cannot be published.');
        }

        if ($status === 'published') {
            throw new HttpException(409, 'NOTICE_ALREADY_PUBLISHED', 'This notice is already published.');
        }

        $user = $this->context->auth->requireUser();
        $now = Clock::nowDb();

        // Publish + per-user bell fanout are atomic: a failed fanout rolls the
        // notice back so the admin can retry instead of leaving a published
        // notice with a missing/partial bell state. The fanout itself is
        // idempotent (unique dedupe key), so a retry cannot duplicate rows.
        $this->db->transaction(function () use ($row, $user, $now): void {
            $this->notices->update((int) $row['id'], [
                'status' => 'published',
                'published_at' => $now,
                'updated_by_user_id' => (int) $user['id'],
                'updated_at' => $now,
            ]);

            $this->audit('notice.published', (string) $row['uuid'], [
                'category' => (string) $row['category'],
                'priority' => (string) $row['priority'],
                'audience' => (string) $row['audience'],
                'status' => 'published',
            ]);

            NotificationService::make($this->context)->fanoutNotice([
                'id' => (int) $row['id'],
                'uuid' => (string) $row['uuid'],
                'estate_id' => (int) $row['estate_id'],
                'title' => (string) $row['title'],
                'body' => (string) $row['body'],
                'audience' => (string) $row['audience'],
                'status' => 'published',
                'expires_at' => $row['expires_at'] ?? null,
            ], (int) $user['id']);
        });

        return $this->adminView((string) $row['uuid']);
    }

    /**
     * @return array<string, mixed>
     */
    public function archive(string $uuid): array
    {
        $row = $this->findAdmin($uuid);

        if ((string) $row['status'] === 'archived') {
            // Idempotent archive: already closed, no second audit row.
            return $this->adminView((string) $row['uuid']);
        }

        $user = $this->context->auth->requireUser();
        $this->notices->update((int) $row['id'], [
            'status' => 'archived',
            'updated_by_user_id' => (int) $user['id'],
            'updated_at' => Clock::nowDb(),
        ]);

        $this->audit('notice.archived', (string) $row['uuid'], [
            'category' => (string) $row['category'],
            'priority' => (string) $row['priority'],
            'audience' => (string) $row['audience'],
            'status' => 'archived',
        ]);

        return $this->adminView((string) $row['uuid']);
    }

    /* ------------------------------------------------------------------ */
    /* Validation                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input
     * @return array{path:?string,alt:?string}|null
     */
    private function prepareImage(array $input, string $title, ?string $currentPath): ?array
    {
        if (!isset($input['_image']) || !is_array($input['_image'])) {
            return null;
        }

        try {
            return (new NoticeImageService())->store(
                $input['_image'],
                $title,
                isset($input['image_alt']) && is_string($input['image_alt']) ? $input['image_alt'] : null
            );
        } catch (\RuntimeException $exception) {
            throw new HttpException(422, 'NOTICE_IMAGE_INVALID', $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        return Validator::validate($input, [
            'title' => 'required|string|min:3|max:160',
            'body' => 'required|string|min:10|max:2000',
            'category' => 'required|in:' . implode(',', NoticePresenter::CATEGORIES),
            'priority' => 'required|in:' . implode(',', NoticePresenter::PRIORITIES),
            'audience' => 'required|in:' . implode(',', NoticePresenter::AUDIENCES),
            'expires_at' => 'nullable|string|max:40',
        ], [
            'title' => 'title',
            'body' => 'body',
            'category' => 'category',
            'priority' => 'priority',
            'audience' => 'audience',
            'expires_at' => 'expiry time',
        ]);
    }

    private function assertExpiryInFuture(mixed $raw): void
    {
        if (!is_string($raw) || trim($raw) === '') {
            return;
        }

        $moment = $this->parseMoment($raw);

        if ($moment === null) {
            throw new ValidationException(['expires_at' => ['Enter a valid expiry time.']]);
        }

        if ($moment < Clock::now()->modify('+' . self::EXPIRY_MIN_LEAD_MINUTES . ' minutes')) {
            throw new ValidationException([
                'expires_at' => ['The expiry time must be at least 5 minutes in the future.'],
            ]);
        }
    }

    private function expiresAtOrNull(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $moment = $this->parseMoment($raw);

        return $moment === null ? null : Clock::toDb($moment);
    }

    private function parseMoment(string $raw): ?DateTimeImmutable
    {
        $value = trim($raw);

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $value) === 1) {
                $moment = new DateTimeImmutable($value, new DateTimeZone($this->timezone()));
            } else {
                $moment = Clock::parse($value);
            }
        } catch (Throwable) {
            return null;
        }

        return $moment->setTimezone(Clock::utc());
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function findAdmin(string $uuid): array
    {
        $row = $this->notices->findInEstate($uuid, $this->estateId());

        if ($row === null) {
            throw HttpException::notFound('NOTICE_NOT_FOUND', 'That notice is not available.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertEditable(array $row): void
    {
        $status = (string) $row['status'];

        if ($status === 'archived') {
            throw new HttpException(409, 'NOTICE_CLOSED', 'Archived notices cannot be edited.');
        }

        if ($status === 'published') {
            throw new HttpException(
                409,
                'NOTICE_LOCKED',
                'Published notices are locked. Create a new notice instead.'
            );
        }
    }

    /**
     * @param array<int, string> $allowed
     */
    private function filterValue(?string $requested, array $allowed): ?string
    {
        $value = trim((string) $requested);

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, string $uuid, array $metadata): void
    {
        $this->context->audit->record(
            $action,
            'estate_notice',
            null,
            ['notice_uuid' => $uuid] + $metadata,
            $this->estateId()
        );
    }

    private function estateId(): int
    {
        return (int) $this->context->tenant->requireEstate()['estate_id'];
    }

    private function timezone(): string
    {
        return (string) ($this->context->tenant->requireEstate()['estate_timezone'] ?? 'Africa/Nairobi');
    }
}

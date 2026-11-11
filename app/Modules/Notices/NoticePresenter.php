<?php

declare(strict_types=1);

namespace App\Modules\Notices;

use App\Core\Clock;

/**
 * Canonical notice option lists, labels, tones and frozen view shapes
 * (Increment 7 §3-§4; T53 frozen keys for T54 views).
 *
 * The service never escapes; views receive raw text and must render it with e()
 * (body line breaks via nl2br(e(...))). Excerpts are plain-text shortened copies.
 */
final class NoticePresenter
{
    /** @var array<string, string> */
    private const CATEGORY_LABELS = [
        'general' => 'General',
        'security' => 'Security',
        'maintenance' => 'Maintenance',
        'event' => 'Event',
        'emergency' => 'Emergency',
    ];

    /** @var array<string, string> */
    private const PRIORITY_LABELS = [
        'normal' => 'Normal',
        'important' => 'Important',
        'urgent' => 'Urgent',
    ];

    /** @var array<string, string> */
    private const PRIORITY_TONES = [
        'normal' => 'neutral',
        'important' => 'warn',
        'urgent' => 'danger',
    ];

    /** @var array<string, string> */
    private const AUDIENCE_LABELS = [
        'all' => 'Everyone',
        'residents' => 'Residents',
        'staff' => 'Staff',
    ];

    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ];

    /** @var array<string, string> */
    private const STATUS_TONES = [
        'draft' => 'neutral',
        'published' => 'ok',
        'archived' => 'neutral',
    ];

    /** @var array<int, string> */
    public const CATEGORIES = ['general', 'security', 'maintenance', 'event', 'emergency'];

    /** @var array<int, string> */
    public const PRIORITIES = ['normal', 'important', 'urgent'];

    /** @var array<int, string> */
    public const AUDIENCES = ['all', 'residents', 'staff'];

    /** @var array<int, string> */
    public const STATUSES = ['draft', 'published', 'archived'];

    /* ------------------------------------------------------------------ */
    /* Option lists                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function categories(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::CATEGORY_LABELS[$value],
        ], self::CATEGORIES);
    }

    /**
     * @return array<int, array{value: string, label: string, tone: string}>
     */
    public function priorities(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::PRIORITY_LABELS[$value],
            'tone' => self::PRIORITY_TONES[$value],
        ], self::PRIORITIES);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function audiences(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::AUDIENCE_LABELS[$value],
        ], self::AUDIENCES);
    }

    /**
     * @return array<int, array{value: string, label: string, tone: string}>
     */
    public function statuses(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => self::STATUS_LABELS[$value],
            'tone' => self::STATUS_TONES[$value],
        ], self::STATUSES);
    }

    /* ------------------------------------------------------------------ */
    /* Labels / tones                                                     */
    /* ------------------------------------------------------------------ */

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? self::humanise($category);
    }

    public static function priorityLabel(string $priority): string
    {
        return self::PRIORITY_LABELS[$priority] ?? self::humanise($priority);
    }

    public static function priorityTone(string $priority): string
    {
        return self::PRIORITY_TONES[$priority] ?? 'neutral';
    }

    public static function audienceLabel(string $audience): string
    {
        return self::AUDIENCE_LABELS[$audience] ?? self::humanise($audience);
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? self::humanise($status);
    }

    public static function statusTone(string $status): string
    {
        return self::STATUS_TONES[$status] ?? 'neutral';
    }

    /* ------------------------------------------------------------------ */
    /* Frozen shapes                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Frozen list item: `/app/notices`, `/gate/notices`, `/admin/notices`.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function item(array $row): array
    {
        $category = (string) ($row['category'] ?? 'general');
        $priority = (string) ($row['priority'] ?? 'normal');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'body_excerpt' => self::excerpt((string) ($row['body'] ?? '')),
            'category' => $category,
            'category_label' => self::categoryLabel($category),
            'priority' => $priority,
            'priority_label' => self::priorityLabel($priority),
            'priority_tone' => self::priorityTone($priority),
            'published_at' => self::iso(isset($row['published_at']) ? (string) $row['published_at'] : null),
        ];
    }

    /**
     * Frozen detail shape: `/app/notices/{uuid}` (and admin detail).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function detail(array $row): array
    {
        $item = self::item($row);
        $status = (string) ($row['status'] ?? 'draft');
        $audience = (string) ($row['audience'] ?? 'all');

        return $item + [
            'body' => (string) ($row['body'] ?? ''),
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'status_tone' => self::statusTone($status),
            'audience' => $audience,
            'audience_label' => self::audienceLabel($audience),
            'expires_at' => self::iso(isset($row['expires_at']) ? (string) $row['expires_at'] : null),
            'created_at' => self::iso(isset($row['created_at']) ? (string) $row['created_at'] : null),
            'updated_at' => self::iso(isset($row['updated_at']) ? (string) $row['updated_at'] : null),
        ];
    }

    /**
     * Home announcement feed shape (Increment 7 §3): title + excerpt + chips.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function homeAnnouncement(array $row): array
    {
        $item = self::item($row);

        return [
            'uuid' => $item['uuid'],
            'title' => $item['title'],
            'body' => $item['body_excerpt'],
            'body_excerpt' => $item['body_excerpt'],
            'category' => $item['category'],
            'category_label' => $item['category_label'],
            'priority' => $item['priority'],
            'priority_label' => $item['priority_label'],
            'priority_tone' => $item['priority_tone'],
            'published_at' => $item['published_at'],
        ];
    }

    /**
     * Plain-text excerpt (<= 140 characters by default), no HTML truncation.
     */
    public static function excerpt(string $body, int $limit = 140): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    }

    public static function iso(?string $utc): ?string
    {
        return Clock::fromDb($utc)?->format('Y-m-d\TH:i:s.v\Z');
    }
    /**
     * Humanise an unknown enum value for defensive rendering (title-case each word).
     */
    private static function humanise(string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $value));
    }
}
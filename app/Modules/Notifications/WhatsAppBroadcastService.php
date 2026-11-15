<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Phone;
use App\Core\Uuid;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Integrations\WhatsApp\WhatsAppCloudProvider;

/**
 * WhatsApp broadcast messaging (owner request 2026-11-15).
 *
 * The Estate Admin composes a broadcast for the estate's opted-in people
 * (active estate residents and/or the household staff directory), picks an
 * approved Meta template, previews it and sends now or schedules it. Each
 * recipient attempt is recorded; residents can opt out from the Account screen,
 * and opted-out phones are skipped.
 *
 * Delivery uses the estate's existing WhatsApp Cloud API credentials
 * (WhatsAppSettingsService). Scheduled broadcasts are dispatched by
 * scripts/send-scheduled-broadcasts.php (cron) or the "Send now" action.
 */
final class WhatsAppBroadcastService
{
    public const AUDIENCES = ['residents', 'staff', 'both'];

    public const AUDIENCE_LABELS = [
        'residents' => 'Estate residents',
        'staff' => 'Household staff',
        'both' => 'Residents and household staff',
    ];

    public const STATUS_LABELS = [
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'sending' => 'Sending',
        'sent' => 'Sent',
        'partial' => 'Partly sent',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ];

    /** Cap per broadcast so an inline send stays bounded. */
    public const MAX_RECIPIENTS = 300;

    public function __construct(
        private readonly Context $context,
        private readonly Database $db
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db);
    }

    /**
     * Eligible recipients for an audience (opted-out phones removed).
     *
     * @return array<int, array{kind:string, user_id:?int, staff_id:?int, name:string, phone:string}>
     */
    public function recipients(int $estateId, string $audience): array
    {
        if (!in_array($audience, self::AUDIENCES, true)) {
            throw new ValidationException(['audience' => ['Choose residents, household staff, or both.']]);
        }

        $people = [];

        if ($audience === 'residents' || $audience === 'both') {
            $rows = $this->db->select(
                "SELECT u.id AS user_id, u.full_name, u.phone
                 FROM estate_memberships em
                 INNER JOIN users u ON u.id = em.user_id
                 WHERE em.estate_id = :estate_id
                   AND em.status = 'active'
                   AND u.status = 'active'
                   AND u.phone IS NOT NULL AND u.phone <> ''
                 ORDER BY u.full_name ASC",
                ['estate_id' => $estateId]
            );

            foreach ($rows as $row) {
                $people[] = [
                    'kind' => 'resident',
                    'user_id' => (int) $row['user_id'],
                    'staff_id' => null,
                    'name' => trim((string) $row['full_name']),
                    'phone' => trim((string) $row['phone']),
                ];
            }
        }

        if ($audience === 'staff' || $audience === 'both') {
            $rows = $this->db->select(
                "SELECT s.id AS staff_id, s.full_name, s.phone
                 FROM household_staff s
                 WHERE s.estate_id = :estate_id
                   AND s.status = 'active'
                   AND s.phone <> ''
                 ORDER BY s.full_name ASC",
                ['estate_id' => $estateId]
            );

            foreach ($rows as $row) {
                $people[] = [
                    'kind' => 'staff',
                    'user_id' => null,
                    'staff_id' => (int) $row['staff_id'],
                    'name' => trim((string) $row['full_name']),
                    'phone' => trim((string) $row['phone']),
                ];
            }
        }

        $optedOut = $this->optedOutPhones($estateId);
        $seen = [];
        $eligible = [];

        foreach ($people as $person) {
            $phone = $this->normalisePhone($person['phone']);

            if ($phone === '' || in_array($phone, $optedOut, true) || isset($seen[$phone])) {
                continue;
            }

            $seen[$phone] = true;
            $person['phone'] = $phone;
            $eligible[] = $person;
        }

        return $eligible;
    }

    /**
     * @return array{residents:int, staff:int, both:int}
     */
    public function audienceCounts(int $estateId): array
    {
        return [
            'residents' => count($this->recipients($estateId, 'residents')),
            'staff' => count($this->recipients($estateId, 'staff')),
            'both' => count($this->recipients($estateId, 'both')),
        ];
    }

    /**
     * Create a broadcast; send immediately when no schedule was supplied.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input, ?callable $transport = null): array
    {
        $estateId = $this->estateId();

        $clean = Validator::validate($input, [
            'title' => 'required|string|min:3|max:160',
            'audience' => 'required|in:residents,staff,both',
            'template_name' => 'required|string|max:120',
            'template_language' => 'nullable|string|max:12',
            'parameter_1' => 'nullable|string|max:200',
            'parameter_2' => 'nullable|string|max:200',
            'parameter_3' => 'nullable|string|max:200',
            'preview_text' => 'nullable|string|max:1000',
            'scheduled_at' => 'nullable|date',
            'action' => 'nullable|in:send,schedule',
        ]);

        $parameters = [];
        foreach (['parameter_1', 'parameter_2', 'parameter_3'] as $key) {
            $value = trim((string) ($clean[$key] ?? ''));
            if ($value !== '') {
                $parameters[] = $value;
            }
        }

        $scheduleRaw = trim((string) ($clean['scheduled_at'] ?? ''));
        $scheduledAt = $scheduleRaw !== '' ? substr($scheduleRaw, 0, 19) : null;

        if ($scheduledAt !== null && strtotime($scheduledAt) === false) {
            throw new ValidationException(['scheduled_at' => ['Enter a valid schedule date and time.']]);
        }

        $action = (string) ($clean['action'] ?? 'send');

        if ($action === 'schedule' && ($scheduledAt === null || strtotime($scheduledAt) <= time())) {
            throw new ValidationException(['scheduled_at' => ['Choose a future date and time for the scheduled broadcast.']]);
        }

        $audience = (string) $clean['audience'];
        $eligible = $this->recipients($estateId, $audience);

        if ($eligible === []) {
            throw new ValidationException(['audience' => ['No opted-in recipients for that audience yet.']]);
        }

        if (count($eligible) > self::MAX_RECIPIENTS) {
            throw new ValidationException([
                'audience' => ['That audience is too large for one broadcast (' . count($eligible) . '). Split it into smaller audiences.'],
            ]);
        }

        $now = Clock::nowDb();
        $status = $action === 'schedule' ? 'scheduled' : 'draft';

        $broadcastId = $this->db->insert('whatsapp_broadcasts', [
            'uuid' => Uuid::v4(),
            'estate_id' => $estateId,
            'title' => trim((string) $clean['title']),
            'template_name' => trim((string) $clean['template_name']),
            'template_language' => trim((string) ($clean['template_language'] ?? 'en')) ?: 'en',
            'parameters' => $parameters === [] ? null : json_encode($parameters),
            'preview_text' => trim((string) ($clean['preview_text'] ?? '')) ?: null,
            'audience' => $audience,
            'status' => $status,
            'scheduled_at' => $scheduledAt,
            'recipients_total' => count($eligible),
            'created_by' => $this->context->auth->requireUser()['id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->context->audit->record('whatsapp.broadcast_created', 'whatsapp_broadcast', $broadcastId, [
            'estate_id' => $estateId,
            'audience' => $audience,
            'status' => $status,
            'recipients' => count($eligible),
        ]);

        if ($status === 'draft') {
            $this->dispatch($broadcastId, $transport);
        }

        return $this->findById($broadcastId) ?? [];
    }

    /**
     * Send (or resume) a broadcast to its current eligible recipients.
     *
     * @return array<string, mixed>
     */
    public function dispatch(int $broadcastId, ?callable $transport = null): array
    {
        $broadcast = $this->findById($broadcastId);

        if ($broadcast === null) {
            throw HttpException::notFound('BROADCAST_NOT_FOUND', 'That broadcast is not available.');
        }

        if (in_array((string) $broadcast['status'], ['sent', 'cancelled'], true)) {
            return $broadcast;
        }

        $estateId = (int) $broadcast['estate_id'];
        $settings = WhatsAppSettingsService::make($this->context);
        $credentials = $settings->credentials($estateId);

        if ($credentials === null || !$settings->isConfigured($estateId)) {
            $this->db->update('whatsapp_broadcasts', [
                'status' => 'failed',
                'completed_at' => Clock::nowDb(),
                'updated_at' => Clock::nowDb(),
            ], ['id' => $broadcastId]);

            throw new HttpException(
                422,
                'WHATSAPP_NOT_CONFIGURED',
                'Connect the estate WhatsApp channel before sending broadcasts.'
            );
        }

        $recipients = $this->recipients($estateId, (string) $broadcast['audience']);
        $rawParameters = $broadcast['parameters'] ?? [];
        $parameters = is_array($rawParameters)
            ? array_values(array_map('strval', $rawParameters))
            : (json_decode((string) $rawParameters, true) ?: []);
        $provider = new WhatsAppCloudProvider($transport);
        $now = Clock::nowDb();
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        // Replace the previous attempt rows so a retry starts cleanly.
        $this->db->execute(
            'DELETE FROM whatsapp_broadcast_recipients WHERE broadcast_id = :broadcast_id',
            ['broadcast_id' => $broadcastId]
        );

        $this->db->update('whatsapp_broadcasts', [
            'status' => 'sending',
            'started_at' => $now,
            'recipients_total' => count($recipients),
            'updated_at' => $now,
        ], ['id' => $broadcastId]);

        foreach ($recipients as $recipient) {
            $recipientId = $this->db->insert('whatsapp_broadcast_recipients', [
                'uuid' => Uuid::v4(),
                'broadcast_id' => $broadcastId,
                'estate_id' => $estateId,
                'recipient_kind' => $recipient['kind'],
                'user_id' => $recipient['user_id'],
                'staff_id' => $recipient['staff_id'],
                'recipient_name' => mb_substr($recipient['name'], 0, 180),
                'phone' => $recipient['phone'],
                'status' => 'queued',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            try {
                $result = $provider->sendTemplate($credentials, $recipient['phone'], $parameters);
                $sent++;
                $this->db->update('whatsapp_broadcast_recipients', [
                    'status' => 'sent',
                    'provider_message_id' => $result['message_id'],
                    'sent_at' => Clock::nowDb(),
                    'updated_at' => Clock::nowDb(),
                ], ['id' => $recipientId]);
            } catch (\Throwable $exception) {
                $failed++;
                $code = $exception instanceof \App\Integrations\WhatsApp\WhatsAppSendException
                    ? $exception->getMessage()
                    : 'whatsapp_send_failed';
                $this->db->update('whatsapp_broadcast_recipients', [
                    'status' => 'failed',
                    'error_code' => mb_substr($code, 0, 60),
                    'updated_at' => Clock::nowDb(),
                ], ['id' => $recipientId]);
            }
        }

        $status = $failed === 0 ? 'sent' : ($sent === 0 ? 'failed' : 'partial');
        $completedAt = Clock::nowDb();

        $this->db->update('whatsapp_broadcasts', [
            'status' => $status,
            'recipients_total' => count($recipients),
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'completed_at' => $completedAt,
            'updated_at' => $completedAt,
        ], ['id' => $broadcastId]);

        $this->context->audit->record('whatsapp.broadcast_sent', 'whatsapp_broadcast', $broadcastId, [
            'estate_id' => $estateId,
            'status' => $status,
            'sent' => $sent,
            'failed' => $failed,
        ]);

        return $this->findById($broadcastId) ?? [];
    }

    /**
     * Dispatch a broadcast by its uuid (admin "Send now" action).
     *
     * @return array<string, mixed>
     */
    public function dispatchByUuid(string $uuid, ?callable $transport = null): array
    {
        $row = $this->db->selectOne(
            'SELECT id FROM whatsapp_broadcasts WHERE uuid = :uuid AND estate_id = :estate_id LIMIT 1',
            ['uuid' => $uuid, 'estate_id' => $this->estateId()]
        );

        if ($row === null) {
            throw HttpException::notFound('BROADCAST_NOT_FOUND', 'That broadcast is not available.');
        }

        return $this->dispatch((int) $row['id'], $transport);
    }

    /**
     * Dispatch every scheduled broadcast that is due (cron entry point).
     *
     * @return array<int, string> Broadcast uuids attempted.
     */
    public function dispatchDue(?callable $transport = null): array
    {
        $estateId = $this->estateId();
        $rows = $this->db->select(
            "SELECT id FROM whatsapp_broadcasts
             WHERE estate_id = :estate_id AND status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= :now
             ORDER BY scheduled_at ASC LIMIT 20",
            ['estate_id' => $estateId, 'now' => Clock::nowDb()]
        );

        $done = [];

        foreach ($rows as $row) {
            try {
                $broadcast = $this->dispatch((int) $row['id'], $transport);
                $done[] = (string) ($broadcast['uuid'] ?? '');
            } catch (\Throwable) {
                // Leave the broadcast scheduled so the next cron run retries.
            }
        }

        return $done;
    }

    /**
     * Broadcasts for the estate, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForEstate(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->select(
            "SELECT wb.*, creator.full_name AS created_by_name
             FROM whatsapp_broadcasts wb
             INNER JOIN users creator ON creator.id = wb.created_by
             WHERE wb.estate_id = :estate_id
             ORDER BY wb.created_at DESC, wb.id DESC
             LIMIT " . $limit,
            ['estate_id' => $this->estateId()]
        );

        return array_map([$this, 'present'], $rows);
    }

    /**
     * One broadcast with its per-recipient delivery log.
     *
     * @return array{broadcast: array<string, mixed>, recipients: array<int, array<string, mixed>>}|null
     */
    public function find(string $uuid): ?array
    {
        $row = $this->db->selectOne(
            'SELECT wb.*, creator.full_name AS created_by_name
             FROM whatsapp_broadcasts wb
             INNER JOIN users creator ON creator.id = wb.created_by
             WHERE wb.uuid = :uuid AND wb.estate_id = :estate_id LIMIT 1',
            ['uuid' => $uuid, 'estate_id' => $this->estateId()]
        );

        if ($row === null) {
            return null;
        }

        $recipients = $this->db->select(
            'SELECT * FROM whatsapp_broadcast_recipients WHERE broadcast_id = :id ORDER BY id ASC',
            ['id' => (int) $row['id']]
        );

        return [
            'broadcast' => $this->present($row),
            'recipients' => array_map(
                static fn (array $recipient): array => [
                    'name' => (string) $recipient['recipient_name'],
                    'phone' => (string) $recipient['phone'],
                    'kind' => (string) $recipient['recipient_kind'],
                    'status' => (string) $recipient['status'],
                    'error_code' => $recipient['error_code'] === null ? null : (string) $recipient['error_code'],
                    'sent_at' => $recipient['sent_at'] === null ? null : (string) $recipient['sent_at'],
                ],
                $recipients
            ),
        ];
    }

    public function isUserOptedOut(int $estateId, int $userId): bool
    {
        return $this->db->selectOne(
            'SELECT id FROM whatsapp_opt_outs WHERE estate_id = :estate_id AND user_id = :user_id LIMIT 1',
            ['estate_id' => $estateId, 'user_id' => $userId]
        ) !== null;
    }

    /**
     * Toggle the signed-in resident's WhatsApp opt-out; returns the new opt-out state.
     */
    public function toggleResidentOptOut(): bool
    {
        $user = $this->context->auth->requireUser();
        $estateId = $this->estateId();
        $userId = (int) $user['id'];
        $phone = trim((string) ($user['phone'] ?? ''));

        if ($this->isUserOptedOut($estateId, $userId)) {
            $this->db->execute(
                'DELETE FROM whatsapp_opt_outs WHERE estate_id = :estate_id AND user_id = :user_id',
                ['estate_id' => $estateId, 'user_id' => $userId]
            );
            $this->context->audit->record('whatsapp.opt_in', 'user', $userId, ['estate_id' => $estateId]);

            return false;
        }

        if ($phone === '') {
            throw new HttpException(422, 'WHATSAPP_NO_PHONE', 'Add a phone number to your account first.');
        }

        $this->db->insert('whatsapp_opt_outs', [
            'uuid' => Uuid::v4(),
            'estate_id' => $estateId,
            'user_id' => $userId,
            'phone' => $phone,
            'reason' => 'Resident opt-out from Account',
            'created_at' => Clock::nowDb(),
            'updated_at' => Clock::nowDb(),
        ]);
        $this->context->audit->record('whatsapp.opt_out', 'user', $userId, ['estate_id' => $estateId]);

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function optedOutPhones(int $estateId): array
    {
        $rows = $this->db->select(
            'SELECT phone FROM whatsapp_opt_outs WHERE estate_id = :estate_id',
            ['estate_id' => $estateId]
        );

        return array_values(array_filter(array_map(
            fn (array $row): string => $this->normalisePhone((string) $row['phone']),
            $rows
        )));
    }

    private function findById(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT wb.*, creator.full_name AS created_by_name
             FROM whatsapp_broadcasts wb
             INNER JOIN users creator ON creator.id = wb.created_by
             WHERE wb.id = :id AND wb.estate_id = :estate_id LIMIT 1',
            ['id' => $id, 'estate_id' => $this->estateId()]
        );

        return $row === null ? null : $this->present($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $status = (string) ($row['status'] ?? 'draft');

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'estate_id' => (int) ($row['estate_id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'template_name' => (string) ($row['template_name'] ?? ''),
            'template_language' => (string) ($row['template_language'] ?? 'en'),
            'parameters' => json_decode((string) ($row['parameters'] ?? '[]'), true) ?: [],
            'preview_text' => $row['preview_text'] === null ? null : (string) $row['preview_text'],
            'audience' => (string) ($row['audience'] ?? 'residents'),
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status] ?? ucfirst($status),
            'scheduled_at' => $row['scheduled_at'] === null ? null : (string) $row['scheduled_at'],
            'started_at' => $row['started_at'] === null ? null : (string) $row['started_at'],
            'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
            'recipients_total' => (int) ($row['recipients_total'] ?? 0),
            'sent_count' => (int) ($row['sent_count'] ?? 0),
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'skipped_count' => (int) ($row['skipped_count'] ?? 0),
            'created_by_name' => (string) ($row['created_by_name'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function estateId(): int
    {
        return (int) $this->context->tenant->requireEstate()['estate_id'];
    }

    private function normalisePhone(string $phone): string
    {
        $phone = trim($phone);

        if ($phone === '') {
            return '';
        }

        // Meta expects international digits. Accept local formats (0712...) by
        // adding the platform default country code; keep international numbers as
        // their digits. Unparseable values fall back to the raw digits.
        try {
            $e164 = Phone::normalize($phone, (string) Config::get('app.default_country_code', '254'));

            return preg_replace('/\D+/', '', $e164) ?? '';
        } catch (\Throwable) {
            return preg_replace('/\D+/', '', $phone) ?? '';
        }
    }
}

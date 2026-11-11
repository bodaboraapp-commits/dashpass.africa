<?php

declare(strict_types=1);

namespace App\Modules\Incidents;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Uuid;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Modules\Notifications\EmailAlertService;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Visitor/security incident service (Increment 6 §3).
 *
 * Residents report incidents for their own households/visits and see only their
 * own reports; guards report at their session gate and see their own reports;
 * admins/managers with `visitor.incident.manage` see and triage the estate queue.
 * Status workflow: open -> investigating -> resolved|dismissed (terminal). Every
 * mutation is audited with uuid/status-only metadata (never the description,
 * names or phones). High/critical reports trigger a non-blocking post-commit
 * `incident.reported` email alert through the Increment-5 mail infrastructure.
 */
final class IncidentService
{
    private const FUTURE_TOLERANCE_MINUTES = 5;

    public function __construct(
        private readonly Context $context,
        private readonly Database $db,
        private readonly IncidentRepository $incidents,
        private readonly ?EmailAlertService $emailAlerts = null
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, $context->db, new IncidentRepository($context->db), EmailAlertService::make($context));
    }

    /* ------------------------------------------------------------------ */
    /* Options / labels                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function types(): array
    {
        return (new IncidentPresenter())->types();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function severities(): array
    {
        return (new IncidentPresenter())->severities();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function statusOptions(): array
    {
        return (new IncidentPresenter())->statuses();
    }

    /**
     * Resident report-form options (own active households, own invitations, active gates).
     *
     * @return array<string, mixed>
     */
    public function residentFormOptions(): array
    {
        $user = $this->context->auth->requireUser();
        $estateId = $this->estateId();

        return [
            'incident_types' => $this->types(),
            'severities' => $this->severities(),
            'gates' => array_map(static fn (array $gate): array => [
                'uuid' => (string) $gate['uuid'],
                'name' => (string) $gate['name'],
            ], $this->incidents->gateOptions($estateId)),
            'households' => $this->householdOptions(
                $this->incidents->userHouseholdOptions($estateId, (int) $user['id'])
            ),
            'invitations' => $this->invitationOptions(
                $this->incidents->reporterInvitationOptions($estateId, (int) $user['id'])
            ),
        ];
    }

    /**
     * Guard report-form options (session gate, estate households/invitations).
     *
     * @param array{id: int, uuid: string, name: string} $gate
     * @return array<string, mixed>
     */
    public function guardFormOptions(array $gate): array
    {
        $estateId = $this->estateId();

        return [
            'incident_types' => $this->types(),
            'severities' => $this->severities(),
            'gate' => ['uuid' => (string) $gate['uuid'], 'name' => (string) $gate['name']],
            'households' => $this->householdOptions($this->incidents->estateHouseholdOptions($estateId)),
            'invitations' => $this->invitationOptions($this->incidents->estateInvitationOptions($estateId)),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Lists                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function listResident(?string $status = null, ?string $severity = null): array
    {
        $user = $this->context->auth->requireUser();
        $status = $this->filterValue($status, IncidentPresenter::STATUSES);
        $severity = $this->filterValue($severity, IncidentPresenter::SEVERITIES);

        return [
            'incidents' => array_map(
                static fn (array $row): array => IncidentPresenter::item($row),
                $this->incidents->forReporter($this->estateId(), (int) $user['id'], $status, $severity)
            ),
            'filters' => $this->filters($status, $severity),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listGuard(?string $status = null, ?string $severity = null): array
    {
        $user = $this->context->auth->requireUser();
        $status = $this->filterValue($status, IncidentPresenter::STATUSES);
        $severity = $this->filterValue($severity, IncidentPresenter::SEVERITIES);

        return [
            'incidents' => array_map(
                static fn (array $row): array => IncidentPresenter::item($row),
                $this->incidents->forReporter($this->estateId(), (int) $user['id'], $status, $severity)
            ),
            'filters' => $this->filters($status, $severity),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listAdmin(?string $status = null, ?string $severity = null): array
    {
        $status = $this->filterValue($status, IncidentPresenter::STATUSES);
        $severity = $this->filterValue($severity, IncidentPresenter::SEVERITIES);

        return [
            'incidents' => array_map(
                static fn (array $row): array => IncidentPresenter::item($row),
                $this->incidents->forEstate($this->estateId(), $status, $severity)
            ),
            'filters' => $this->filters($status, $severity),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Detail                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function detailResident(string $uuid): array
    {
        $user = $this->context->auth->requireUser();
        $row = $this->incidents->findForReporter($uuid, $this->estateId(), (int) $user['id']);

        if ($row === null) {
            throw HttpException::notFound('INCIDENT_NOT_FOUND', 'That incident is not available for your account.');
        }

        return IncidentPresenter::detail($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function detailGuard(string $uuid): array
    {
        return $this->detailResident($uuid);
    }

    /**
     * @return array<string, mixed>
     */
    public function detailAdmin(string $uuid): array
    {
        return IncidentPresenter::detail($this->findAdmin($uuid));
    }

    /* ------------------------------------------------------------------ */
    /* Create                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createResident(array $input): array
    {
        $user = $this->context->auth->requireUser();
        $estateId = $this->estateId();
        $clean = $this->validate($input);

        $gate = null;
        if (isset($clean['gate_uuid']) && is_string($clean['gate_uuid']) && $clean['gate_uuid'] !== '') {
            $gate = $this->incidents->activeGate($estateId, $clean['gate_uuid']);

            if ($gate === null) {
                throw HttpException::notFound('GATE_NOT_FOUND', 'That gate is not available for this estate.');
            }
        }

        $household = null;
        if (isset($clean['household_uuid']) && is_string($clean['household_uuid']) && $clean['household_uuid'] !== '') {
            $household = $this->incidents->userHousehold($estateId, (int) $user['id'], $clean['household_uuid']);

            if ($household === null) {
                throw HttpException::notFound('HOUSEHOLD_NOT_FOUND', 'That household is not available for your account.');
            }
        }

        $invitation = null;
        if (isset($clean['invitation_uuid']) && is_string($clean['invitation_uuid']) && $clean['invitation_uuid'] !== '') {
            $invitation = $this->incidents->reporterInvitation($estateId, (int) $user['id'], $clean['invitation_uuid']);

            if ($invitation === null) {
                throw HttpException::notFound('INVITATION_NOT_FOUND', 'That visitor invitation is not available for your account.');
            }

            if ($household === null) {
                $household = $this->incidents->householdById((int) $invitation['household_id'], $estateId);
            }
        }

        return $this->create([
            'gate_id' => $gate === null ? null : (int) $gate['id'],
            'gate_uuid' => $gate === null ? null : (string) $gate['uuid'],
            'household_id' => $household === null ? null : (int) $household['id'],
            'household_uuid' => $household === null ? null : (string) $household['uuid'],
            'invitation_id' => $invitation === null ? null : (int) $invitation['id'],
            'invitation_uuid' => $invitation === null ? null : (string) $invitation['uuid'],
            'clean' => $clean,
            'user_id' => (int) $user['id'],
        ]);
    }

    /**
     * @param array{id: int, uuid: string, name: string} $gate
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createGuard(array $gate, array $input): array
    {
        $user = $this->context->auth->requireUser();
        $estateId = $this->estateId();
        $clean = $this->validate($input, false);

        $household = null;
        if (isset($clean['household_uuid']) && is_string($clean['household_uuid']) && $clean['household_uuid'] !== '') {
            $household = $this->incidents->estateHousehold($estateId, $clean['household_uuid']);

            if ($household === null) {
                throw HttpException::notFound('HOUSEHOLD_NOT_FOUND', 'That household is not available for this estate.');
            }
        }

        $invitation = null;
        if (isset($clean['invitation_uuid']) && is_string($clean['invitation_uuid']) && $clean['invitation_uuid'] !== '') {
            $invitation = $this->incidents->estateInvitation($estateId, $clean['invitation_uuid']);

            if ($invitation === null) {
                throw HttpException::notFound('INVITATION_NOT_FOUND', 'That visitor invitation is not available for this estate.');
            }

            if ($household === null) {
                $household = $this->incidents->householdById((int) $invitation['household_id'], $estateId);
            }
        }

        return $this->create([
            'gate_id' => (int) $gate['id'],
            'gate_uuid' => (string) $gate['uuid'],
            'household_id' => $household === null ? null : (int) $household['id'],
            'household_uuid' => $household === null ? null : (string) $household['uuid'],
            'invitation_id' => $invitation === null ? null : (int) $invitation['id'],
            'invitation_uuid' => $invitation === null ? null : (string) $invitation['uuid'],
            'clean' => $clean,
            'user_id' => (int) $user['id'],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Status workflow                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function transition(string $uuid, array $input): array
    {
        $row = $this->findAdmin($uuid);
        $clean = Validator::validate(
            $input,
            [
                'status' => 'required|in:' . implode(',', IncidentPresenter::STATUSES),
                'resolution_notes' => 'nullable|string|min:10|max:500',
            ],
            ['status' => 'status', 'resolution_notes' => 'resolution notes']
        );

        $current = (string) $row['status'];
        $target = (string) $clean['status'];

        if (IncidentPresenter::isTerminal($current)) {
            throw new HttpException(
                409,
                'INCIDENT_CLOSED',
                'This incident is closed and cannot be changed.',
                ['errors' => ['status' => ['This incident is closed and cannot be changed.']]]
            );
        }

        $allowed = $current === 'open' ? ['investigating', 'resolved', 'dismissed'] : ['resolved', 'dismissed'];

        if (!in_array($target, $allowed, true)) {
            throw new HttpException(
                422,
                'INVALID_STATUS_TRANSITION',
                'That status change is not allowed from the current state.',
                ['errors' => ['status' => ['That status change is not allowed from the current state.']]]
            );
        }

        $notes = isset($clean['resolution_notes']) && $clean['resolution_notes'] !== null
            ? trim((string) $clean['resolution_notes'])
            : null;

        if ($target === 'resolved' && ($notes === null || $notes === '')) {
            throw new ValidationException([
                'resolution_notes' => ['Add resolution notes (10-500 characters) to resolve this incident.'],
            ]);
        }

        $user = $this->context->auth->requireUser();
        $now = Clock::nowDb();
        $fields = [
            'status' => $target,
            'resolution_notes' => $target === 'resolved' ? $notes : null,
            'resolved_at' => $now,
            'resolved_by_user_id' => (int) $user['id'],
            'updated_at' => $now,
        ];

        $this->incidents->update((int) $row['id'], $fields);

        $this->audit('incident.status_changed', (int) $row['id'], [
            'incident_uuid' => (string) $row['uuid'],
            'old_status' => $current,
            'new_status' => $target,
            'severity' => (string) $row['severity'],
        ]);
        $this->audit('incident.updated', (int) $row['id'], [
            'incident_uuid' => (string) $row['uuid'],
            'fields' => ['status', 'resolution_notes', 'resolved_at'],
        ]);

        return $this->detailAdmin((string) $row['uuid']);
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function create(array $context): array
    {
        $clean = $context['clean'];
        $estateId = $this->estateId();
        $uuid = Uuid::v4();
        $occurredAt = $this->resolveOccurredAt(
            isset($clean['occurred_at']) && is_string($clean['occurred_at']) ? $clean['occurred_at'] : null
        );
        $now = Clock::nowDb();

        $id = $this->incidents->insert([
            'uuid' => $uuid,
            'estate_id' => $estateId,
            'gate_id' => $context['gate_id'],
            'household_id' => $context['household_id'],
            'invitation_id' => $context['invitation_id'],
            'reported_by_user_id' => $context['user_id'],
            'incident_type' => (string) $clean['incident_type'],
            'severity' => (string) $clean['severity'],
            'status' => 'open',
            'summary' => (string) $clean['summary'],
            'description' => (string) $clean['description'],
            'occurred_at' => $occurredAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit('incident.reported', $id, [
            'incident_uuid' => $uuid,
            'incident_type' => (string) $clean['incident_type'],
            'severity' => (string) $clean['severity'],
            'status' => 'open',
            'gate_uuid' => $context['gate_uuid'],
            'household_uuid' => $context['household_uuid'],
            'invitation_uuid' => $context['invitation_uuid'],
        ]);

        $row = $this->incidents->findInEstate($uuid, $estateId);

        if ($row === null) {
            throw new HttpException(500, 'INCIDENT_PERSIST_FAILED', 'The incident could not be saved.');
        }

        // Post-commit, non-blocking: only high/critical severities alert.
        $this->maybeSendEmail($row);

        return IncidentPresenter::detail($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function maybeSendEmail(array $row): void
    {
        try {
            $severity = (string) ($row['severity'] ?? 'medium');

            if (!in_array($severity, ['high', 'critical'], true)) {
                return;
            }

            $membership = $this->context->tenant->active() ?? [];
            $estateId = $this->estateId();
            $incidentType = (string) ($row['incident_type'] ?? 'other');

            $this->emailAlerts?->sendSecurityAlert('incident.reported', [
                'incident_type' => $incidentType,
                'incident_type_label' => IncidentPresenter::typeLabel($incidentType),
                'severity' => $severity,
                'severity_label' => IncidentPresenter::severityLabel($severity),
                'summary' => (string) ($row['summary'] ?? ''),
                'estate_name' => (string) ($membership['estate_name'] ?? ''),
                'occurred_at' => (string) ($row['occurred_at'] ?? Clock::nowDb()),
                'timezone' => (string) ($membership['estate_timezone'] ?? 'Africa/Nairobi'),
                'recipient_emails' => $this->incidents->adminEmails($estateId),
            ]);
        } catch (Throwable $exception) {
            // Never let an alert problem affect incident creation.
            Logger::warning('Incident email alert wiring failed.', [
                'incident_uuid' => (string) ($row['uuid'] ?? ''),
                'error' => mb_substr($exception->getMessage(), 0, 200),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, bool $allowGate = true): array
    {
        $rules = [
            'incident_type' => 'required|in:' . implode(',', IncidentPresenter::TYPES),
            'severity' => 'required|in:' . implode(',', IncidentPresenter::SEVERITIES),
            'summary' => 'required|string|min:3|max:180',
            'description' => 'required|string|min:10|max:2000',
            'occurred_at' => 'nullable|string|max:40',
            'household_uuid' => 'nullable|uuid',
            'invitation_uuid' => 'nullable|uuid',
        ];

        if ($allowGate) {
            $rules['gate_uuid'] = 'nullable|uuid';
        }

        return Validator::validate($input, $rules, [
            'incident_type' => 'incident type',
            'severity' => 'severity',
            'summary' => 'summary',
            'description' => 'description',
            'occurred_at' => 'occurrence time',
            'gate_uuid' => 'gate',
            'household_uuid' => 'household',
            'invitation_uuid' => 'visitor invitation',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function findAdmin(string $uuid): array
    {
        $row = $this->incidents->findInEstate($uuid, $this->estateId());

        if ($row === null) {
            throw HttpException::notFound('INCIDENT_NOT_FOUND', 'That incident is not available for this estate.');
        }

        return $row;
    }

    private function resolveOccurredAt(?string $raw): string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return Clock::nowDb();
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $value) === 1) {
                // datetime-local input: interpret in estate-local time.
                $moment = new DateTimeImmutable($value, new DateTimeZone($this->timezone()));
            } else {
                $moment = Clock::parse($value);
            }
        } catch (Throwable) {
            throw new ValidationException(['occurred_at' => ['Enter a valid occurrence time.']]);
        }

        $utc = $moment->setTimezone(Clock::utc());

        if ($utc > Clock::now()->modify('+' . self::FUTURE_TOLERANCE_MINUTES . ' minutes')) {
            throw new ValidationException([
                'occurred_at' => ['The occurrence time cannot be in the future.'],
            ]);
        }

        return Clock::toDb($utc);
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
     * @return array{status: string, severity: string}
     */
    private function filters(?string $status, ?string $severity): array
    {
        return [
            'status' => $status ?? 'all',
            'severity' => $severity ?? 'all',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{uuid: string, label: string}>
     */
    private function householdOptions(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'uuid' => (string) $row['uuid'],
            'label' => self::householdLabel($row),
        ], $rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{uuid: string, label: string}>
     */
    private function invitationOptions(array $rows): array
    {
        return array_map(static function (array $row): array {
            $name = (string) ($row['visitor_name'] ?? 'Visitor');
            $unit = (string) ($row['unit_number'] ?? '');
            $status = (string) ($row['status'] ?? '');

            $label = $unit === '' ? $name : $name . ' - ' . $unit;

            return [
                'uuid' => (string) $row['uuid'],
                'label' => $status === '' ? $label : $label . ' - ' . ucfirst($status),
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function householdLabel(array $row): string
    {
        $unit = (string) ($row['unit_number'] ?? '');
        $building = (string) ($row['building_name'] ?? '');

        return $building === '' ? $unit : $unit . ' - ' . $building;
    }

    private function estateId(): int
    {
        return (int) $this->context->tenant->requireEstate()['estate_id'];
    }

    private function timezone(): string
    {
        return (string) ($this->context->tenant->requireEstate()['estate_timezone'] ?? 'Africa/Nairobi');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, ?int $entityId, array $metadata): void
    {
        $this->context->audit->record($action, 'access_incident', $entityId, $metadata, $this->estateId());
    }
}

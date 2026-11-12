<?php

declare(strict_types=1);

namespace App\Modules\Residents;

use App\Core\Context;
use App\Modules\Visitors\AccessPresenter;
use App\Modules\Visitors\UserHouseholdRepository;

/**
 * Own-household visitor activity timeline for the resident Activity screen.
 *
 * The timeline merges two server-side sources, newest first:
 *  - gate access events (`visitor_access_events`: check_in, check_out, denied,
 *    manual_override) joined to the invitation, visitor, gate, household and
 *    building rows;
 *  - invitation issue events (`visitor_invitations.created_at`).
 *
 * Tenant/user scoping is derived only from App\Core\Context: the estate id comes
 * from the resolved active membership and the household ids from the
 * authenticated user's active household memberships. A user without households
 * gets an empty timeline and no query is ever run against another household.
 */
final class ResidentActivityService
{
    /** Default and maximum number of timeline items (§7.1: limit 50). */
    public const LIMIT = 50;

    private const MAX_LIMIT = 200;

    /** @var array<int, string> */
    private const EVENT_TYPES = ['check_in', 'check_out', 'denied', 'manual_override'];

    private const EVENT_SELECT = 'SELECT e.id AS row_id, e.event_type AS kind, e.occurred_at AS at,
                v.name AS visitor_name,
                h.unit_number, b.name AS building_name,
                g.name AS gate_name,
                i.uuid AS invitation_uuid
         FROM visitor_access_events e
         INNER JOIN visitor_invitations i ON i.id = e.invitation_id AND i.estate_id = e.estate_id
         INNER JOIN visitors v ON v.id = i.visitor_id AND v.estate_id = i.estate_id
         INNER JOIN households h ON h.id = i.household_id AND h.estate_id = i.estate_id
         LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
         INNER JOIN gates g ON g.id = e.gate_id AND g.estate_id = e.estate_id
         WHERE e.estate_id = ?
           AND e.event_type IN (\'check_in\', \'check_out\', \'denied\', \'manual_override\')
           AND i.household_id IN (%s)
         ORDER BY e.occurred_at DESC, e.id DESC
         LIMIT %d';

    private const INVITATION_SELECT = 'SELECT i.id AS row_id, i.created_at AS at,
                v.name AS visitor_name,
                h.unit_number, b.name AS building_name,
                NULL AS gate_name,
                i.uuid AS invitation_uuid
         FROM visitor_invitations i
         INNER JOIN visitors v ON v.id = i.visitor_id AND v.estate_id = i.estate_id
         INNER JOIN households h ON h.id = i.household_id AND h.estate_id = i.estate_id
         LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
         WHERE i.estate_id = ?
           AND i.household_id IN (%s)
         ORDER BY i.created_at DESC, i.id DESC
         LIMIT %d';

    public function __construct(
        private readonly Context $context,
        private readonly UserHouseholdRepository $households
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, new UserHouseholdRepository($context->db));
    }

    /**
     * Normalised timeline items, newest first.
     *
     * @return array<int, array{kind: string, at: string, visitor_name: string, household_label: string, gate_name: ?string, invitation_uuid: string, href: string}>
     */
    public function timeline(int $limit = self::LIMIT): array
    {
        $user = $this->context->auth->requireUser();
        $membership = $this->context->tenant->requireEstate();
        $estateId = (int) $membership['estate_id'];
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $householdIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $this->households->idsForUser((int) $user['id'], $estateId)),
            static fn (int $id): bool => $id > 0
        )));

        if ($householdIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($householdIds), '?'));
        $params = array_merge([$estateId], $householdIds);

        $events = $this->context->db->select(
            sprintf(self::EVENT_SELECT, $placeholders, $limit),
            $params
        );
        $invitations = $this->context->db->select(
            sprintf(self::INVITATION_SELECT, $placeholders, $limit),
            $params
        );

        $items = [];

        foreach ($events as $row) {
            $kind = (string) ($row['kind'] ?? '');

            if (!in_array($kind, self::EVENT_TYPES, true)) {
                continue;
            }

            $items[] = $this->item($kind, $row);
        }

        foreach ($invitations as $row) {
            $items[] = $this->item('invitation', $row);
        }

        usort($items, static function (array $a, array $b): int {
            $byTime = strcmp((string) $b['at'], (string) $a['at']);

            if ($byTime !== 0) {
                return $byTime;
            }

            return strcmp((string) $b['invitation_uuid'], (string) $a['invitation_uuid']);
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{kind: string, at: string, visitor_name: string, household_label: string, gate_name: ?string, invitation_uuid: string, href: string}
     */
    private function item(string $kind, array $row): array
    {
        $invitationUuid = trim((string) ($row['invitation_uuid'] ?? ''));
        $gateName = trim((string) ($row['gate_name'] ?? ''));

        return [
            'kind' => $kind,
            'at' => (string) ($row['at'] ?? ''),
            'visitor_name' => trim((string) ($row['visitor_name'] ?? '')),
            'household_label' => AccessPresenter::householdLabel($row),
            'gate_name' => $gateName === '' ? null : $gateName,
            'invitation_uuid' => $invitationUuid,
            'href' => $invitationUuid === '' ? '' : '/app/passes/' . rawurlencode($invitationUuid),
        ];
    }
}

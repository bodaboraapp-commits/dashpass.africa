<?php

declare(strict_types=1);

namespace App\Modules\Incidents;

use App\Core\Database;

/**
 * Incident persistence (Increment 6 §1/§3).
 *
 * Every read is estate-scoped. Reporter-scoped reads add `reported_by_user_id`
 * so residents/guards only ever see their own reports; the admin queue reads the
 * whole estate. Incidents are never deleted (status transitions only).
 */
final class IncidentRepository
{
    private const SELECT = 'SELECT i.id, i.uuid, i.estate_id, i.gate_id, i.household_id, i.invitation_id,
                i.reported_by_user_id, i.incident_type, i.severity, i.status, i.summary, i.description,
                i.occurred_at, i.resolved_at, i.resolved_by_user_id, i.resolution_notes,
                i.created_at, i.updated_at,
                g.uuid AS gate_uuid, g.name AS gate_name,
                h.uuid AS household_uuid, h.unit_number, h.status AS household_status,
                b.name AS building_name,
                rep.full_name AS reported_by_name, rep.uuid AS reported_by_uuid,
                res.full_name AS resolved_by_name,
                inv.uuid AS invitation_uuid, v.name AS invitation_visitor_name
         FROM access_incidents i
         LEFT JOIN gates g ON g.id = i.gate_id AND g.estate_id = i.estate_id
         LEFT JOIN households h ON h.id = i.household_id AND h.estate_id = i.estate_id
         LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
         LEFT JOIN users rep ON rep.id = i.reported_by_user_id
         LEFT JOIN users res ON res.id = i.resolved_by_user_id
         LEFT JOIN visitor_invitations inv ON inv.id = i.invitation_id AND inv.estate_id = i.estate_id
         LEFT JOIN visitors v ON v.id = inv.visitor_id AND v.estate_id = inv.estate_id';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forReporter(int $estateId, int $userId, ?string $status, ?string $severity): array
    {
        [$filter, $params] = $this->filters($estateId, $status, $severity);
        $params['user_id'] = $userId;

        return $this->db->select(
            self::SELECT . ' WHERE i.estate_id = :estate_id AND i.reported_by_user_id = :user_id'
            . $filter . ' ORDER BY i.created_at DESC, i.id DESC',
            $params
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forEstate(int $estateId, ?string $status, ?string $severity): array
    {
        [$filter, $params] = $this->filters($estateId, $status, $severity);

        return $this->db->select(
            self::SELECT . ' WHERE i.estate_id = :estate_id' . $filter
            . ' ORDER BY i.created_at DESC, i.id DESC',
            $params
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForReporter(string $uuid, int $estateId, int $userId): ?array
    {
        return $this->db->selectOne(
            self::SELECT . ' WHERE i.estate_id = :estate_id AND i.uuid = :uuid AND i.reported_by_user_id = :user_id LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $uuid, 'user_id' => $userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInEstate(string $uuid, int $estateId): ?array
    {
        return $this->db->selectOne(
            self::SELECT . ' WHERE i.estate_id = :estate_id AND i.uuid = :uuid LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insert(array $fields): int
    {
        return $this->db->insert('access_incidents', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, array $fields): void
    {
        $this->db->update('access_incidents', $fields, ['id' => $id]);
    }

    /* ------------------------------------------------------------------ */
    /* Related lookups (all estate-scoped)                                */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>|null Active estate gate.
     */
    public function activeGate(int $estateId, string $uuid): ?array
    {
        return $this->db->selectOne(
            "SELECT id, uuid, name FROM gates
             WHERE estate_id = :estate_id AND uuid = :uuid AND status = 'active' LIMIT 1",
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * @return array<string, mixed>|null Household the user belongs to (active).
     */
    public function userHousehold(int $estateId, int $userId, string $uuid): ?array
    {
        return $this->db->selectOne(
            "SELECT h.id, h.uuid, h.unit_number, h.status, b.name AS building_name
             FROM household_members hm
             INNER JOIN households h ON h.id = hm.household_id AND h.estate_id = hm.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE hm.user_id = :user_id
               AND hm.estate_id = :estate_id
               AND hm.status = 'active'
               AND h.uuid = :uuid
             LIMIT 1",
            ['user_id' => $userId, 'estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * @return array<string, mixed>|null Active estate household (guard/admin scope).
     */
    public function estateHousehold(int $estateId, string $uuid): ?array
    {
        return $this->db->selectOne(
            "SELECT h.id, h.uuid, h.unit_number, h.status, b.name AS building_name
             FROM households h
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE h.estate_id = :estate_id AND h.uuid = :uuid AND h.status = 'active' LIMIT 1",
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * @return array<string, mixed>|null Active estate household by internal id.
     */
    public function householdById(int $id, int $estateId): ?array
    {
        return $this->db->selectOne(
            "SELECT h.id, h.uuid, h.unit_number, h.status, b.name AS building_name
             FROM households h
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE h.estate_id = :estate_id AND h.id = :id AND h.status = 'active' LIMIT 1",
            ['estate_id' => $estateId, 'id' => $id]
        );
    }

    /**
     * @return array<int, array<string, mixed>> The user's active households (resident form).
     */
    public function userHouseholdOptions(int $estateId, int $userId): array
    {
        return $this->db->select(
            "SELECT h.uuid, h.unit_number, h.status, b.name AS building_name
             FROM household_members hm
             INNER JOIN households h ON h.id = hm.household_id AND h.estate_id = hm.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE hm.user_id = :user_id
               AND hm.estate_id = :estate_id
               AND hm.status = 'active'
               AND h.status = 'active'
             ORDER BY hm.is_primary DESC, h.unit_number ASC",
            ['user_id' => $userId, 'estate_id' => $estateId]
        );
    }

    /**
     * @return array<string, mixed>|null Invitation hosted by the resident.
     */
    public function reporterInvitation(int $estateId, int $userId, string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT i.id, i.uuid, i.household_id, i.host_user_id
             FROM visitor_invitations i
             WHERE i.estate_id = :estate_id AND i.uuid = :uuid AND i.host_user_id = :user_id
             LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $uuid, 'user_id' => $userId]
        );
    }

    /**
     * @return array<string, mixed>|null Any estate invitation (guard/admin scope).
     */
    public function estateInvitation(int $estateId, string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT i.id, i.uuid, i.household_id, i.host_user_id
             FROM visitor_invitations i
             WHERE i.estate_id = :estate_id AND i.uuid = :uuid LIMIT 1',
            ['estate_id' => $estateId, 'uuid' => $uuid]
        );
    }

    /**
     * @return array<int, array<string, mixed>> Active estate gates for the form.
     */
    public function gateOptions(int $estateId): array
    {
        return $this->db->select(
            "SELECT uuid, name FROM gates WHERE estate_id = :estate_id AND status = 'active' ORDER BY name ASC",
            ['estate_id' => $estateId]
        );
    }

    /**
     * @return array<int, array<string, mixed>> Households for the guard/admin form.
     */
    public function estateHouseholdOptions(int $estateId): array
    {
        return $this->db->select(
            "SELECT h.uuid, h.unit_number, b.name AS building_name
             FROM households h
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE h.estate_id = :estate_id AND h.status = 'active'
             ORDER BY h.unit_number ASC",
            ['estate_id' => $estateId]
        );
    }

    /**
     * @return array<int, array<string, mixed>> The resident's own invitations (recent, linkable).
     */
    public function reporterInvitationOptions(int $estateId, int $userId): array
    {
        return $this->db->select(
            'SELECT i.uuid, i.valid_from, i.valid_until, i.status,
                    v.name AS visitor_name, h.unit_number
             FROM visitor_invitations i
             INNER JOIN visitors v ON v.id = i.visitor_id AND v.estate_id = i.estate_id
             LEFT JOIN households h ON h.id = i.household_id AND h.estate_id = i.estate_id
             WHERE i.estate_id = :estate_id AND i.host_user_id = :user_id
             ORDER BY i.created_at DESC
             LIMIT 50',
            ['estate_id' => $estateId, 'user_id' => $userId]
        );
    }

    /**
     * @return array<int, array<string, mixed>> Recent estate invitations (guard scope).
     */
    public function estateInvitationOptions(int $estateId): array
    {
        return $this->db->select(
            'SELECT i.uuid, i.valid_from, i.valid_until, i.status,
                    v.name AS visitor_name, h.unit_number
             FROM visitor_invitations i
             INNER JOIN visitors v ON v.id = i.visitor_id AND v.estate_id = i.estate_id
             LEFT JOIN households h ON h.id = i.household_id AND h.estate_id = i.estate_id
             WHERE i.estate_id = :estate_id
             ORDER BY i.created_at DESC
             LIMIT 100',
            ['estate_id' => $estateId]
        );
    }

    /**
     * Valid email addresses of active estate admins/managers (incident alerts).
     *
     * @return array<int, string>
     */
    public function adminEmails(int $estateId): array
    {
        $rows = $this->db->select(
            "SELECT DISTINCT u.email
             FROM estate_memberships em
             INNER JOIN users u ON u.id = em.user_id
             INNER JOIN roles r ON r.id = em.role_id
             WHERE em.estate_id = :estate_id
               AND em.status = 'active'
               AND u.status = 'active'
               AND r.name IN ('estate_admin', 'estate_manager')
               AND u.email IS NOT NULL
               AND u.email <> ''",
            ['estate_id' => $estateId]
        );

        $emails = [];

        foreach ($rows as $row) {
            $email = trim((string) $row['email']);

            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filters(int $estateId, ?string $status, ?string $severity): array
    {
        $filter = '';
        $params = ['estate_id' => $estateId];

        if ($status !== null && in_array($status, IncidentPresenter::STATUSES, true)) {
            $filter .= ' AND i.status = :status';
            $params['status'] = $status;
        }

        if ($severity !== null && in_array($severity, IncidentPresenter::SEVERITIES, true)) {
            $filter .= ' AND i.severity = :severity';
            $params['severity'] = $severity;
        }

        return [$filter, $params];
    }
}


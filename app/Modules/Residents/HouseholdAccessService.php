<?php

declare(strict_types=1);

namespace App\Modules\Residents;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Crypto;
use App\Core\HttpException;
use App\Core\Uuid;
use App\Modules\Visitors\QrCode;

/**
 * Household access passes (owner request 2026-11-13).
 *
 * "My access pass" shows the resident household's people, not visitors: the
 * Tenant (primary resident), family members on the household and domestic
 * workers in the household staff directory. Every person gets a signed,
 * gate-scannable household pass rendered as a QR image.
 *
 * Security model:
 *  - Tokens are self-contained and HMAC-signed (`hpa1.<payload>.<sig>`) with a
 *    dedicated Crypto context; there is no database secret to leak and no
 *    bearer/visitor token is reused.
 *  - The token carries only the pass type, record id, household id and estate id
 *    (no name, phone, PIN or address).
 *  - A resident can only list/download QR images for people in their own
 *    household in the active estate; a guard can only verify a token inside the
 *    estate they are currently signed into.
 *  - Deactivating a household member / staff record revokes the pass because
 *    verification reloads the live record and requires `active` status.
 */
final class HouseholdAccessService
{
    public const TYPE_MEMBER = 'member';

    public const TYPE_STAFF = 'staff';

    private const TOKEN_PREFIX = 'hpa1';

    private const HMAC_CONTEXT = 'household-access-pass';

    private const SHORT_CODE_CONTEXT = 'household-pass-code';

    /** @var array<string, string> Rotatable per-person salts, cached per request. */
    private array $saltCache = [];

    private bool $saltsPreloaded = false;

    /** @var array<string, string> */
    private const RELATIONSHIP_LABELS = [
        'owner' => 'Tenant',
        'tenant' => 'Tenant',
        'family_member' => 'Family member',
        'family' => 'Family member',
        'spouse' => 'Family member',
        'child' => 'Family member',
        'dependent' => 'Family member',
    ];

    /** @var array<string, string> */
    private const STAFF_LABELS = [
        'domestic' => 'Domestic worker',
        'driver' => 'Driver',
        'gardener' => 'Gardener',
        'nanny' => 'Nanny',
        'cook' => 'Cook',
        'security' => 'Security',
        'other' => 'Household staff',
    ];

    public function __construct(private readonly Context $context)
    {
    }

    public static function make(Context $context): self
    {
        return new self($context);
    }

    /**
     * Every active household pass for the signed-in resident's household:
     * Tenant (primary member), family members and domestic workers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function passes(): array
    {
        $user = $this->context->auth->requireUser();
        $membership = $this->context->tenant->requireEstate();
        $estateId = (int) $membership['estate_id'];

        // One salt query for every pass built on this page.
        $this->preloadSalts($estateId);

        $household = $this->context->db->selectOne(
            "SELECT h.id, h.uuid, h.unit_number, b.name AS building_name
             FROM household_members hm
             INNER JOIN households h ON h.id = hm.household_id AND h.estate_id = hm.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE hm.user_id = :user_id
               AND hm.estate_id = :estate_id
               AND hm.status = 'active'
               AND h.status = 'active'
             ORDER BY hm.is_primary DESC, h.id ASC
             LIMIT 1",
            ['user_id' => (int) $user['id'], 'estate_id' => $estateId]
        );

        if ($household === null) {
            return [];
        }

        $householdId = (int) $household['id'];
        $householdLabel = $this->householdLabel($household);
        $currentUserId = (int) $user['id'];
        $passes = [];

        $members = $this->context->db->select(
            "SELECT hm.id, hm.uuid, hm.relationship, hm.is_primary, hm.user_id,
                    u.full_name, u.phone, u.status
             FROM household_members hm
             INNER JOIN users u ON u.id = hm.user_id
             WHERE hm.household_id = :household_id
               AND hm.estate_id = :estate_id
               AND hm.status = 'active'
               AND u.status = 'active'
             ORDER BY hm.is_primary DESC, hm.id ASC",
            ['household_id' => $householdId, 'estate_id' => $estateId]
        );

        foreach ($members as $row) {
            $name = trim((string) ($row['full_name'] ?? ''));
            $passes[] = $this->pass(
                self::TYPE_MEMBER,
                (int) $row['id'],
                (string) ($row['uuid'] ?? ''),
                $name !== '' ? $name : 'Household member',
                $this->memberRoleLabel($row),
                $householdId,
                $householdLabel,
                $estateId,
                (int) ($row['user_id'] ?? 0) === $currentUserId
            );
        }

        $staff = $this->context->db->select(
            "SELECT id, uuid, full_name, phone, staff_type
             FROM household_staff
             WHERE household_id = :household_id
               AND estate_id = :estate_id
               AND status = 'active'
             ORDER BY id ASC",
            ['household_id' => $householdId, 'estate_id' => $estateId]
        );

        foreach ($staff as $row) {
            $name = trim((string) ($row['full_name'] ?? ''));
            $passes[] = $this->pass(
                self::TYPE_STAFF,
                (int) $row['id'],
                (string) ($row['uuid'] ?? ''),
                $name !== '' ? $name : 'Household worker',
                self::STAFF_LABELS[(string) ($row['staff_type'] ?? 'other')] ?? 'Household staff',
                $householdId,
                $householdLabel,
                $estateId,
                false
            );
        }

        return $passes;
    }

    /**
     * The signed-in resident's own pass (QR + pass code live on /app/access).
     *
     * @return array<string, mixed>|null
     */
    public function ownPass(): ?array
    {
        foreach ($this->passes() as $pass) {
            if ((bool) ($pass['is_self'] ?? false)) {
                return $pass;
            }
        }

        return null;
    }

    /**
     * Passes the resident may create/share for other household people (family
     * members and domestic workers), excluding their own pass.
     *
     * @return array<int, array<string, mixed>>
     */
    public function othersPasses(): array
    {
        return array_values(array_filter(
            $this->passes(),
            static fn (array $pass): bool => !(bool) ($pass['is_self'] ?? false)
        ));
    }

    /**
     * One pass from the caller's own household (used by the create-member flow
     * after validating the submitted type/id).
     *
     * @return array<string, mixed>|null
     */
    public function passFor(string $type, int $id): ?array
    {
        return $this->findOwnPass($type, $id);
    }

    /**
     * Render the PNG QR for one of the signed-in resident's household passes.
     * IDOR-safe: the requested pass must be in the caller's own passes() list.
     */
    public function qrPng(string $type, int $id): string
    {
        $pass = $this->findOwnPass($type, $id);

        if ($pass === null) {
            throw HttpException::notFound(
                'HOUSEHOLD_PASS_NOT_FOUND',
                'That household access pass is not available for your account.'
            );
        }

        return QrCode::png((string) $pass['token']);
    }

    public function looksLikeToken(string $code): bool
    {
        return str_starts_with(trim($code), self::TOKEN_PREFIX . '.');
    }

    /**
     * Verify a 4-digit household pass code inside an estate (guard gate).
     *
     * The code is a short human form of the signed pass; it has only 10,000
     * possibilities so the caller must rate-limit attempts. Collisions between
     * two household people in the same estate are reported as ambiguous and the
     * guard is asked to scan the QR instead of guessing.
     *
     * @return array{status: string, person: array<string, mixed>|null}
     */
    public function verifyShortCode(string $code, int $estateId): array
    {
        $code = trim($code);

        if (preg_match('/^\d{4}$/', $code) !== 1) {
            return ['status' => 'invalid', 'person' => null];
        }

        $matches = [];

        // One salt query for the whole estate instead of one per person.
        $this->preloadSalts($estateId);

        $members = $this->context->db->select(
            "SELECT hm.id, hm.relationship, hm.is_primary,
                    u.full_name, h.id AS household_id, h.unit_number, b.name AS building_name
             FROM household_members hm
             INNER JOIN users u ON u.id = hm.user_id
             INNER JOIN households h ON h.id = hm.household_id AND h.estate_id = hm.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE hm.estate_id = :estate_id
               AND hm.status = 'active'
               AND h.status = 'active'
               AND u.status = 'active'",
            ['estate_id' => $estateId]
        );

        foreach ($members as $row) {
            $token = $this->tokenFor(self::TYPE_MEMBER, (int) $row['id'], (int) $row['household_id'], $estateId);

            if (hash_equals($this->shortCode($token), $code)) {
                $matches[] = $this->personFromRow(self::TYPE_MEMBER, $row);
            }
        }

        $staff = $this->context->db->select(
            "SELECT s.id, s.staff_type, s.full_name, s.household_id, h.unit_number, b.name AS building_name
             FROM household_staff s
             INNER JOIN households h ON h.id = s.household_id AND h.estate_id = s.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE s.estate_id = :estate_id
               AND s.status = 'active'
               AND h.status = 'active'",
            ['estate_id' => $estateId]
        );

        foreach ($staff as $row) {
            $token = $this->tokenFor(self::TYPE_STAFF, (int) $row['id'], (int) $row['household_id'], $estateId);

            if (hash_equals($this->shortCode($token), $code)) {
                $matches[] = $this->personFromRow(self::TYPE_STAFF, $row);
            }
        }

        if ($matches === []) {
            return ['status' => 'not_found', 'person' => null];
        }

        if (count($matches) > 1) {
            return ['status' => 'ambiguous', 'person' => null];
        }

        return ['status' => 'ok', 'person' => $matches[0]];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function personFromRow(string $type, array $row): array
    {
        $name = trim((string) ($row['full_name'] ?? ''));

        return [
            'name' => $name !== '' ? $name : ($type === self::TYPE_STAFF ? 'Household worker' : 'Household member'),
            'role_label' => $type === self::TYPE_STAFF
                ? (self::STAFF_LABELS[(string) ($row['staff_type'] ?? 'other')] ?? 'Household staff')
                : $this->memberRoleLabel($row),
            'household_label' => $this->householdLabel($row),
            'type' => $type,
        ];
    }

    /**
     * Verify a household pass token inside an estate (used by the guard gate).
     *
     * @return array{name: string, role_label: string, household_label: string, type: string}|null
     */
    public function verifyToken(string $token, int $estateId): ?array
    {
        $parts = explode('.', trim($token));

        if (count($parts) !== 3 || $parts[0] !== self::TOKEN_PREFIX) {
            return null;
        }

        $encoded = $parts[1];
        $expected = Crypto::hmac(self::HMAC_CONTEXT, $encoded);

        if (!Crypto::equals($expected, $parts[2])) {
            return null;
        }

        $payload = $this->decodePayload($encoded);

        if ($payload === null) {
            return null;
        }

        $type = (string) ($payload['t'] ?? '');
        $id = (int) ($payload['i'] ?? 0);
        $tokenEstate = (int) ($payload['e'] ?? 0);

        if (!in_array($type, [self::TYPE_MEMBER, self::TYPE_STAFF], true) || $id <= 0 || $tokenEstate !== $estateId) {
            return null;
        }

        // Rotated passes carry a new salt in the payload; a previously shared QR
        // (old salt) is rejected even though its signature is valid.
        if (!hash_equals($this->saltFor($type, $id), (string) ($payload['s'] ?? ''))) {
            return null;
        }

        return $this->loadActivePerson($type, $id, $estateId);
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $household
     */
    private function householdLabel(array $household): string
    {
        $unit = trim((string) ($household['unit_number'] ?? ''));
        $building = trim((string) ($household['building_name'] ?? ''));

        if ($unit === '') {
            return $building !== '' ? $building : 'Household';
        }

        return $building !== '' ? $unit . ' - ' . $building : $unit;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function memberRoleLabel(array $row): string
    {
        if ((int) ($row['is_primary'] ?? 0) === 1) {
            return 'Tenant';
        }

        $relationship = strtolower(trim((string) ($row['relationship'] ?? '')));

        if ($relationship !== '') {
            return self::RELATIONSHIP_LABELS[$relationship] ?? ucwords(str_replace('_', ' ', $relationship));
        }

        return 'Family member';
    }

    /**
     * @return array<string, mixed>
     */
    private function pass(
        string $type,
        int $id,
        string $uuid,
        string $name,
        string $roleLabel,
        int $householdId,
        string $householdLabel,
        int $estateId,
        bool $isSelf = false
    ): array {
        $token = $this->tokenFor($type, $id, $householdId, $estateId);

        return [
            'type' => $type,
            'id' => $id,
            'uuid' => $uuid,
            'name' => $name,
            'role_label' => $roleLabel,
            'initials' => $this->initials($name),
            'household_label' => $householdLabel,
            'status_label' => 'Active',
            'is_self' => $isSelf,
            'token' => $token,
            // Owner request: the human pass code is a simple 4-digit number
            // (the long signed token stays embedded only in the QR image).
            'pass_code' => $this->shortCode($token),
            // Version the QR URL with the token so a rotated PIN/QR is fetched
            // fresh instead of being served from the browser cache.
            'qr_url' => '/app/access/qr.png?type=' . rawurlencode($type) . '&id=' . $id
                . '&v=' . rawurlencode(substr(hash('sha256', $token), 0, 10)),
        ];
    }

    /**
     * Derive the 4-digit human pass code from a signed pass token.
     *
     * Owner request (2026-11-13): residents should read a 4-digit code to the
     * guard instead of the long token. The code is deterministic for a pass, so
     * the same person always has the same code; the full signed token remains
     * the authoritative credential (QR and pasted-code paths).
     */
    private function shortCode(string $token): string
    {
        $hex = Crypto::hmac(self::SHORT_CODE_CONTEXT, $token);
        $number = (int) (hexdec(substr($hex, 0, 6)) % 10000);

        return str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    private function tokenFor(string $type, int $id, int $householdId, int $estateId): string
    {
        $payload = $this->encodePayload([
            'v' => 1,
            't' => $type,
            'i' => $id,
            'h' => $householdId,
            'e' => $estateId,
            // Rotatable per-person salt: rotating it changes both the token and
            // the 4-digit code, so an old QR is rejected by verifyToken().
            's' => $this->saltFor($type, $id),
        ]);

        return self::TOKEN_PREFIX . '.' . $payload . '.' . Crypto::hmac(self::HMAC_CONTEXT, $payload);
    }

    /**
     * Current rotation salt for one household person (empty until first rotation).
     */
    private function saltFor(string $type, int $id): string
    {
        $key = $type . ':' . $id;

        if (array_key_exists($key, $this->saltCache)) {
            return $this->saltCache[$key];
        }

        if ($this->saltsPreloaded) {
            // An estate preload has already covered every person in the estate.
            $this->saltCache[$key] = '';

            return '';
        }

        $row = $this->context->db->selectOne(
            'SELECT salt FROM household_access_salts WHERE person_type = :type AND person_id = :person_id LIMIT 1',
            ['type' => $type, 'person_id' => $id]
        );
        $this->saltCache[$key] = $row !== null ? (string) $row['salt'] : '';

        return $this->saltCache[$key];
    }

    /**
     * Load every salt for an estate once so pass rendering and short-code
     * verification do not run one query per household person.
     */
    private function preloadSalts(int $estateId): void
    {
        if ($this->saltsPreloaded) {
            return;
        }

        $rows = $this->context->db->select(
            'SELECT person_type, person_id, salt FROM household_access_salts WHERE estate_id = :estate_id',
            ['estate_id' => $estateId]
        );

        foreach ($rows as $row) {
            $this->saltCache[(string) $row['person_type'] . ':' . (int) $row['person_id']] = (string) $row['salt'];
        }

        $this->saltsPreloaded = true;
    }

    /**
     * Rotate the PIN/QR for one of the caller's own household passes.
     *
     * Writes a fresh random salt for the person; the next pass() call derives a
     * new 4-digit code and a new QR token, and verifyToken()/verifyShortCode()
     * stop accepting the old values. Returns the refreshed pass.
     *
     * @return array<string, mixed>
     */
    public function rotatePass(string $type, int $id): array
    {
        $pass = $this->findOwnPass($type, $id);

        if ($pass === null) {
            throw HttpException::notFound(
                'HOUSEHOLD_PASS_NOT_FOUND',
                'That household access pass is not available for your account.'
            );
        }

        $membership = $this->context->tenant->requireEstate();
        $estateId = (int) $membership['estate_id'];
        $oldCode = (string) ($pass['pass_code'] ?? '');
        $rotated = $pass;

        // A 4-digit code has only 10,000 values, so retry the (random) salt in
        // the unlikely event the new code equals the old one; the PIN must change.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $salt = bin2hex(random_bytes(8));
            $now = Clock::nowDb();

            $this->context->db->transaction(function () use ($type, $id, $estateId, $salt, $now): void {
                $existing = $this->context->db->selectOne(
                    'SELECT id FROM household_access_salts WHERE person_type = :type AND person_id = :person_id LIMIT 1',
                    ['type' => $type, 'person_id' => $id]
                );

                if ($existing !== null) {
                    $this->context->db->update(
                        'household_access_salts',
                        ['salt' => $salt, 'updated_at' => $now],
                        ['id' => (int) $existing['id']]
                    );

                    return;
                }

                $this->context->db->insert('household_access_salts', [
                    'uuid' => Uuid::v4(),
                    'estate_id' => $estateId,
                    'person_type' => $type,
                    'person_id' => $id,
                    'salt' => $salt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

            // Drop the per-request salt cache so the refreshed pass below is
            // derived from the new salt (and later calls in this request too).
            $this->saltCache = [];
            $this->saltsPreloaded = false;
            $candidate = $this->findOwnPass($type, $id);

            if ($candidate === null) {
                break;
            }

            $rotated = $candidate;

            if ((string) $candidate['pass_code'] !== $oldCode) {
                break;
            }
        }

        $this->context->audit->record(
            'household.access_rotated',
            $type === self::TYPE_STAFF ? 'household_staff' : 'household_members',
            $id,
            ['pass_type' => $type, 'estate_id' => $estateId]
        );

        return $rotated;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $encoded): ?array
    {
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) && (int) ($payload['v'] ?? 0) === 1 ? $payload : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findOwnPass(string $type, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        foreach ($this->passes() as $pass) {
            if ((string) $pass['type'] === $type && (int) $pass['id'] === $id) {
                return $pass;
            }
        }

        return null;
    }

    /**
     * @return array{name: string, role_label: string, household_label: string, type: string}|null
     */
    private function loadActivePerson(string $type, int $id, int $estateId): ?array
    {
        if ($type === self::TYPE_MEMBER) {
            $row = $this->context->db->selectOne(
                "SELECT hm.relationship, hm.is_primary, u.full_name,
                        h.unit_number, b.name AS building_name
                 FROM household_members hm
                 INNER JOIN users u ON u.id = hm.user_id
                 INNER JOIN households h ON h.id = hm.household_id AND h.estate_id = hm.estate_id
                 LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
                 WHERE hm.id = :id
                   AND hm.estate_id = :estate_id
                   AND hm.status = 'active'
                   AND u.status = 'active'
                 LIMIT 1",
                ['id' => $id, 'estate_id' => $estateId]
            );

            if ($row === null) {
                return null;
            }

            return [
                'name' => trim((string) ($row['full_name'] ?? '')) !== '' ? (string) $row['full_name'] : 'Household member',
                'role_label' => $this->memberRoleLabel($row),
                'household_label' => $this->householdLabel($row),
                'type' => $type,
            ];
        }

        $row = $this->context->db->selectOne(
            "SELECT s.full_name, s.staff_type, h.unit_number, b.name AS building_name
             FROM household_staff s
             INNER JOIN households h ON h.id = s.household_id AND h.estate_id = s.estate_id
             LEFT JOIN buildings b ON b.id = h.building_id AND b.estate_id = h.estate_id
             WHERE s.id = :id
               AND s.estate_id = :estate_id
               AND s.status = 'active'
             LIMIT 1",
            ['id' => $id, 'estate_id' => $estateId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'name' => trim((string) ($row['full_name'] ?? '')) !== '' ? (string) $row['full_name'] : 'Household worker',
            'role_label' => self::STAFF_LABELS[(string) ($row['staff_type'] ?? 'other')] ?? 'Household staff',
            'household_label' => $this->householdLabel($row),
            'type' => $type,
        ];
    }

    private function initials(string $name): string
    {
        $parts = array_values(array_filter(
            preg_split('/\s+/', trim($name)) ?: [],
            static fn (string $part): bool => $part !== ''
        ));
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : 'HP';
    }
}

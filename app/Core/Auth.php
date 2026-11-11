<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session-backed authentication.
 *
 * Only the user id is stored in the session; authority (roles, permissions,
 * estate memberships) is always re-resolved from the database on every request
 * so revocation takes effect immediately.
 */
final class Auth
{
    /** @var array<string, mixed>|null */
    private ?array $user = null;

    private bool $resolved = false;

    public function __construct(
        private readonly Request $request,
        private readonly Database $db
    ) {
    }

    public function id(): ?int
    {
        $value = Session::get('auth_user_id');

        if ($value === null) {
            return null;
        }

        return is_int($value) ? $value : (int) $value;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $id = $this->id();

        if ($id === null || $id <= 0) {
            return $this->user = null;
        }

        $row = $this->db->selectOne(
            'SELECT id, uuid, full_name, phone, email, status, created_at, last_login_at
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $id]
        );

        if ($row === null || (string) $row['status'] !== 'active') {
            Session::forget('auth_user_id');

            return $this->user = null;
        }

        $storedFingerprint = Session::get('_fingerprint');

        if (is_string($storedFingerprint) && !hash_equals($storedFingerprint, $this->fingerprint())) {
            Logger::security('Session fingerprint mismatch — session destroyed', [
                'user_id' => $id,
            ]);
            Session::destroy();

            return $this->user = null;
        }

        return $this->user = $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function requireUser(): array
    {
        $user = $this->user();

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        return $user;
    }

    /**
     * Establish an authenticated session. The session id and CSRF token rotate
     * so a pre-authentication session cannot be replayed.
     */
    public function login(int $userId): void
    {
        Session::regenerate();
        Session::put('auth_user_id', $userId);
        Session::put('logged_in_at', time());
        Session::put('_fingerprint', $this->fingerprint());

        // Clear any estate context from a previous identity in this session.
        Session::forget('estate_id');

        Csrf::regenerate();

        $now = Clock::nowDb();
        $this->db->execute(
            'UPDATE users SET last_login_at = :now, updated_at = :updated WHERE id = :id',
            ['now' => $now, 'updated' => $now, 'id' => $userId]
        );

        // Force the identity to be re-resolved against the new session.
        $this->resolved = false;
        $this->user = null;
    }

    public function logout(): void
    {
        Session::destroy();
        Csrf::regenerate();
        $this->resolved = true;
        $this->user = null;
    }

    /**
     * Bind the session to a coarse client signature. This is a defence in depth
     * measure; it intentionally ignores IP address because mobile visitors
     * change networks mid-session.
     */
    private function fingerprint(): string
    {
        return hash('sha256', 'ua|' . $this->request->userAgent());
    }
}

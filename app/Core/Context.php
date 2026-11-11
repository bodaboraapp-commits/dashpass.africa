<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Per-request service container.
 *
 * Everything that depends on "who is calling" is built here, once per request,
 * so no authenticated state can leak between requests in long-running workers
 * or in-process tests.
 */
final class Context
{
    /**
     * Current request context for view helpers (request-scoped). The kernel
     * replaces it on every App::handle() call; in-process tests can rely on it.
     */
    private static ?self $current = null;

    public readonly Database $db;

    public readonly Auth $auth;

    public readonly TenantContext $tenant;

    public readonly Permission $permission;

    public readonly RateLimiter $rateLimiter;

    public readonly AuditLogger $audit;

    public function __construct(
        public readonly Request $request,
        public readonly string $correlationId
    ) {
        $this->db = Database::instance();
        $this->auth = new Auth($request, $this->db);
        $this->tenant = new TenantContext($this->db, $this->auth);
        $this->permission = new Permission($this->db, $this->tenant);
        $this->rateLimiter = new RateLimiter($this->db);
        $this->audit = new AuditLogger($this->db, $request, $this->auth, $this->tenant);
    }

    public static function setCurrent(?self $context): void
    {
        self::$current = $context;
    }

    public static function current(): ?self
    {
        return self::$current;
    }
}

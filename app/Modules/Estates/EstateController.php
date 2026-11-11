<?php

declare(strict_types=1);

namespace App\Modules\Estates;

use App\Core\Context;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Shared\BuildsShell;

/**
 * Estate context endpoints: listing memberships, switching the active estate,
 * the estate summary/settings and the estate-scoped audit log.
 *
 * The active estate is always resolved server-side by TenantContext; a browser
 * supplied estate id is never accepted.
 */
final class EstateController
{
    use BuildsShell;

    /* ------------------------------------------------------------------ */
    /* Web                                                                */
    /* ------------------------------------------------------------------ */

    public function webEstateSwitcher(Request $request, array $params, Context $context): Response
    {
        return View::render('app/estates', $this->shell($context, 'Your estates', 'account'), 'app');
    }

    public function webSwitch(Request $request, array $params, Context $context): Response
    {
        $previous = $context->tenant->active();
        $previousEstateId = $previous === null ? null : (int) $previous['estate_id'];

        $membership = $context->tenant->switchTo((string) ($params['uuid'] ?? ''));

        $context->audit->record(
            'estate.context_switched',
            'estate',
            (int) $membership['estate_id'],
            [
                'estate_uuid' => (string) $membership['estate_uuid'],
                'from_estate_id' => $previousEstateId,
            ],
            (int) $membership['estate_id']
        );

        Session::flash('success', 'Estate switched to ' . (string) $membership['estate_name'] . '.');

        return Response::redirect('/app');
    }

    /* ------------------------------------------------------------------ */
    /* API                                                                */
    /* ------------------------------------------------------------------ */

    public function apiIndex(Request $request, array $params, Context $context): Response
    {
        $active = $context->tenant->active();
        $activeEstateId = $active === null ? null : (int) $active['estate_id'];

        $memberships = array_map(
            static fn (array $membership): array => EstateRepository::membershipShape($membership, $activeEstateId),
            $context->tenant->memberships()
        );

        return Response::success('Estate memberships loaded.', ['memberships' => $memberships]);
    }

    public function apiSwitch(Request $request, array $params, Context $context): Response
    {
        $previous = $context->tenant->active();
        $previousEstateId = $previous === null ? null : (int) $previous['estate_id'];

        $membership = $context->tenant->switchTo((string) ($params['uuid'] ?? ''));

        $context->audit->record(
            'estate.context_switched',
            'estate',
            (int) $membership['estate_id'],
            [
                'estate_uuid' => (string) $membership['estate_uuid'],
                'from_estate_id' => $previousEstateId,
            ],
            (int) $membership['estate_id']
        );

        $repository = EstateRepository::make($context->db);

        return Response::success('Estate context switched.', [
            'estate' => $repository->summary($membership),
            'permissions' => $context->permission->slugs(),
            'csrf_token' => Csrf::token(),
        ]);
    }

    public function apiShow(Request $request, array $params, Context $context): Response
    {
        $membership = $context->tenant->requireEstate();
        $repository = EstateRepository::make($context->db);

        return Response::success('Estate context loaded.', $repository->summary($membership));
    }

    public function apiSettings(Request $request, array $params, Context $context): Response
    {
        $membership = $context->tenant->requireEstate();
        $repository = EstateRepository::make($context->db);
        $settings = $repository->settings((int) $membership['estate_id']);

        return Response::success('Estate settings loaded.', [
            'settings' => $settings === [] ? new \stdClass() : $settings,
        ]);
    }

    public function apiAuditLogs(Request $request, array $params, Context $context): Response
    {
        $membership = $context->tenant->requireEstate();
        $limit = $this->resolveLimit($request->queryString('limit'));

        $rows = $context->audit->recentForEstate((int) $membership['estate_id'], $limit);
        $logs = array_map([self::class, 'shapeAuditRow'], $rows);

        return Response::success('Audit logs loaded.', [
            'logs' => $logs,
            'limit' => $limit,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function resolveLimit(?string $raw): int
    {
        if ($raw === null || $raw === '' || !ctype_digit($raw)) {
            return 50;
        }

        return max(1, min(200, (int) $raw));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapeAuditRow(array $row): array
    {
        $metadata = new \stdClass();

        if (isset($row['metadata']) && is_string($row['metadata']) && $row['metadata'] !== '') {
            $decoded = json_decode($row['metadata'], true);
            $metadata = is_array($decoded) ? $decoded : new \stdClass();
        }

        return [
            'uuid' => (string) $row['uuid'],
            'action' => (string) $row['action'],
            'entity_type' => (string) $row['entity_type'],
            'entity_id' => $row['entity_id'] === null ? null : (int) $row['entity_id'],
            'metadata' => $metadata,
            'correlation_id' => $row['correlation_id'] === null ? null : (string) $row['correlation_id'],
            'ip_address' => $row['ip_address'] === null ? null : (string) $row['ip_address'],
            'created_at' => (string) $row['created_at'],
            'actor_name' => $row['actor_name'] === null ? null : (string) $row['actor_name'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Clock;
use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Admin overview dashboard (frozen contract §7): stats, quick links, estate shell.
 *
 * Links are permission-filtered so partial-permission roles (e.g. estate_manager
 * without settings/residents) never see dead or forbidden links and never trigger
 * an error. The "Recent admin activity" panel was removed in T115, so no audit
 * query/payload is computed here anymore.
 */
final class AdminController extends AdminBaseController
{
    public function dashboard(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $access = AdminAccess::make($context);
        $estateId = $access->estateId();
        $db = $context->db;

        $stats = [
            'buildings' => (int) ($db->scalar(
                'SELECT COUNT(*) FROM buildings WHERE estate_id = :estate_id',
                ['estate_id' => $estateId]
            ) ?? 0),
            'households_active' => (int) ($db->scalar(
                "SELECT COUNT(*) FROM households WHERE estate_id = :estate_id AND status = 'active'",
                ['estate_id' => $estateId]
            ) ?? 0),
            'residents_active' => (int) ($db->scalar(
                "SELECT COUNT(*) FROM estate_memberships WHERE estate_id = :estate_id AND status = 'active'",
                ['estate_id' => $estateId]
            ) ?? 0),
            'guards_active' => (int) ($db->scalar(
                "SELECT COUNT(*) FROM estate_memberships em
                 INNER JOIN roles r ON r.id = em.role_id
                 WHERE em.estate_id = :estate_id AND em.status = 'active' AND r.name = 'security_guard'",
                ['estate_id' => $estateId]
            ) ?? 0),
            'gates_active' => (int) ($db->scalar(
                "SELECT COUNT(*) FROM gates WHERE estate_id = :estate_id AND status = 'active'",
                ['estate_id' => $estateId]
            ) ?? 0),
            'visitor_reservations_today' => $this->visitorReservationsToday($context, $estateId, $access->timezone()),
        ];

        $data = $this->shell($context, 'Overview', 'overview') + [
            'stats' => $stats,
            'quick_links' => $this->quickLinks($context),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return $this->render('admin/dashboard', $data);
    }

    private function visitorReservationsToday(Context $context, int $estateId, string $timezone): int
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Throwable) {
            $zone = new DateTimeZone('Africa/Nairobi');
        }

        $nowLocal = new DateTimeImmutable('now', $zone);
        $startLocal = $nowLocal->setTime(0, 0, 0);
        $endLocal = $startLocal->modify('+1 day');

        return (int) ($context->db->scalar(
            "SELECT COUNT(*) FROM parking_reservations
             WHERE estate_id = :estate_id
               AND starts_at >= :day_from
               AND starts_at < :day_to
               AND status IN ('reserved', 'checked_in')",
            [
                'estate_id' => $estateId,
                'day_from' => Clock::toDb($startLocal),
                'day_to' => Clock::toDb($endLocal),
            ]
        ) ?? 0);
    }

    /**
     * @return array<int, array{label: string, href: string}>
     */
    private function quickLinks(Context $context): array
    {
        $links = [
            ['label' => 'Buildings', 'href' => '/admin/buildings', 'permission' => 'household.view'],
            ['label' => 'Households', 'href' => '/admin/households', 'permission' => 'household.view'],
            ['label' => 'Residents', 'href' => '/admin/residents', 'permission' => 'estate.user.manage'],
            ['label' => 'Guards', 'href' => '/admin/guards', 'permission' => 'guard.manage'],
            ['label' => 'Gates', 'href' => '/admin/gates', 'permission' => 'gate.view'],
            ['label' => 'Settings', 'href' => '/admin/settings', 'permission' => 'estate.settings.manage'],
        ];

        $visible = [];

        foreach ($links as $link) {
            if ($context->permission->can($link['permission'])) {
                $visible[] = ['label' => $link['label'], 'href' => $link['href']];
            }
        }

        return $visible;
    }
}

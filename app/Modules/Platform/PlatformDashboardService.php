<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Context;

/**
 * Platform dashboard data (Increment 9 §2): real stats, dashboard-only audit
 * (last platform-scope entries, permission-filtered) and permission-filtered links.
 */
final class PlatformDashboardService
{
    public function __construct(
        private readonly Context $context,
        private readonly PlatformRepository $repo
    ) {
    }

    public static function make(Context $context): self
    {
        return new self($context, new PlatformRepository($context->db));
    }

    /**
     * @return array{stats: array<string, int>, recent_audit: array<int, array<string, mixed>>, quick_links: array<int, array<string, string>>}
     */
    public function dashboard(PlatformAccess $access): array
    {
        $recentAudit = [];

        if ($access->can('platform.audit.view')) {
            $recentAudit = array_map(
                static fn (array $row): array => PlatformPresenter::auditEntry($row),
                $this->repo->recentPlatformAudit(10)
            );
        }

        return [
            'stats' => $this->repo->stats(),
            'recent_audit' => $recentAudit,
            'quick_links' => $this->quickLinks($access),
        ];
    }

    /**
     * @return array<int, array{label: string, href: string}>
     */
    private function quickLinks(PlatformAccess $access): array
    {
        $links = [
            ['label' => 'Estates', 'href' => '/platform/estates', 'permission' => 'platform.estate.manage'],
            ['label' => 'Plans', 'href' => '/platform/plans', 'permission' => 'platform.plan.manage'],
            ['label' => 'Subscriptions', 'href' => '/platform/subscriptions', 'permission' => 'platform.subscription.manage'],
        ];

        return array_values(array_map(
            static fn (array $link): array => ['label' => $link['label'], 'href' => $link['href']],
            array_filter($links, static fn (array $link): bool => $access->can($link['permission']))
        ));
    }
}

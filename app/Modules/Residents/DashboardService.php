<?php

declare(strict_types=1);

namespace App\Modules\Residents;

use App\Core\Database;
use App\Modules\Estates\EstateRepository;
use App\Modules\Households\HouseholdRepository;

/**
 * Builds the resident dashboard payload (contract §8.1).
 *
 * The visitor/parking/notification modules are intentionally not installed in
 * this increment; the dashboard reports that honestly instead of faking data.
 */
final class DashboardService
{
    private const MODULES = [
        'visitors' => 'not_installed',
        'parking' => 'not_installed',
        'notifications' => 'not_installed',
    ];

    public function __construct(
        private readonly EstateRepository $estates,
        private readonly HouseholdRepository $households
    ) {
    }

    public static function make(?Database $db = null): self
    {
        $db ??= Database::instance();

        return new self(EstateRepository::make($db), HouseholdRepository::make($db));
    }

    /**
     * @param array<string, mixed> $membership TenantContext::active() row.
     * @return array<string, mixed>
     */
    public function build(array $membership, int $userId): array
    {
        $estateId = (int) $membership['estate_id'];

        return [
            'estate' => $this->estates->summary($membership),
            'households' => $this->households->forUserInEstate($userId, $estateId),
            'gates' => $this->estates->gates($estateId),
            'counts' => $this->estates->counts($estateId),
            'modules' => self::MODULES,
            'announcements' => [],
        ];
    }
}

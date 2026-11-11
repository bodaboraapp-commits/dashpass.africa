<?php

declare(strict_types=1);

namespace App\Modules\Navigation;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Shared\BuildsShell;

/**
 * Estate wayfinding / navigation page (auth + estate only; no new permission).
 *
 * Read-only, no map tiles or external APIs. Uses existing estate/gate/building/
 * household data and degrades gracefully to office-contact guidance.
 */
final class NavigationController
{
    use BuildsShell;

    public function index(Request $request, array $params, Context $context): Response
    {
        $estate = $context->tenant->requireEstate();
        $estateId = (int) $estate['estate_id'];
        $data = $this->shell($context, 'Navigation', 'home');
        $data['navigation'] = $this->navigation($estateId);
        $data['title'] = 'Navigation · ' . (string) ($data['navigation']['estate']['name'] ?? 'Estate');

        if (View::exists('app/navigation')) {
            return View::render('app/navigation', $data, 'app');
        }

        return Response::html($this->fallbackHtml($data));
    }

    /**
     * @return array{estate:array<string,mixed>,gates:array<int,array<string,mixed>>,buildings:array<int,array<string,mixed>>,notes:array<int,string>}
     */
    private function navigation(int $estateId): array
    {
        $estate = $this->dbRow(
            'SELECT name, estate_type, timezone FROM estates WHERE id = :estate_id LIMIT 1',
            ['estate_id' => $estateId]
        );
        $gates = $this->dbRows(
            "SELECT name, location, status FROM gates
             WHERE estate_id = :estate_id
             ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, name ASC",
            ['estate_id' => $estateId]
        );
        $buildings = $this->dbRows(
            "SELECT b.name, b.building_type,
                    (SELECT COUNT(*) FROM households h
                     WHERE h.building_id = b.id AND h.estate_id = b.estate_id AND h.status = 'active') AS households_count
             FROM buildings b
             WHERE b.estate_id = :estate_id
             ORDER BY b.name ASC",
            ['estate_id' => $estateId]
        );
        $notes = [
            'For detailed directions, contact the estate office.',
            'Directions are indicative; follow on-site signage and gate staff instructions.',
        ];

        $estateType = (string) ($estate['estate_type'] ?? '');
        $estateOut = [
            'name' => (string) ($estate['name'] ?? ''),
            'type' => $estateType,
            'type_label' => $estateType === '' ? '' : ucfirst($estateType),
            'timezone' => (string) ($estate['timezone'] ?? ''),
        ];

        return [
            'estate' => $estateOut,
            'gates' => array_map(static fn (array $row): array => [
                'name' => (string) ($row['name'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'status_label' => ucfirst((string) ($row['status'] ?? '')),
                'location' => trim((string) ($row['location'] ?? '')),
            ], $gates),
            'buildings' => array_map(static fn (array $row): array => [
                'name' => (string) ($row['name'] ?? ''),
                'type' => (string) ($row['building_type'] ?? ''),
                'type_label' => ucfirst(str_replace('_', ' ', (string) ($row['building_type'] ?? ''))),
                'households_count' => (int) ($row['households_count'] ?? 0),
            ], $buildings),
            'notes' => $notes,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function dbRow(string $sql, array $params): ?array
    {
        try {
            return \App\Core\Database::instance()->selectOne($sql, $params);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function dbRows(string $sql, array $params): array
    {
        try {
            return \App\Core\Database::instance()->select($sql, $params);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fallbackHtml(array $data): string
    {
        $navigation = is_array($data['navigation'] ?? null) ? $data['navigation'] : [];
        $estate = is_array($navigation['estate'] ?? null) ? $navigation['estate'] : [];
        $gates = is_array($navigation['gates'] ?? null) ? $navigation['gates'] : [];
        $buildings = is_array($navigation['buildings'] ?? null) ? $navigation['buildings'] : [];
        $notes = is_array($navigation['notes'] ?? null) ? $navigation['notes'] : [];
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e((string) ($data['title'] ?? 'Navigation')) . '</title></head>'
            . '<body><main><h1>Navigation</h1>'
            . '<p>' . e((string) ($estate['name'] ?? ''))
            . (($estate['type_label'] ?? '') !== '' ? ' · ' . e((string) $estate['type_label']) : '')
            . '</p>';

        if ($gates !== []) {
            $html .= '<h2>Gates</h2><ul>';

            foreach ($gates as $gate) {
                $html .= '<li>' . e((string) ($gate['name'] ?? '')) . ' · '
                    . e((string) ($gate['status_label'] ?? ''))
                    . (($gate['location'] ?? '') !== '' ? ' · ' . e((string) $gate['location']) : '') . '</li>';
            }

            $html .= '</ul>';
        }

        if ($buildings !== []) {
            $html .= '<h2>Buildings</h2><ul>';

            foreach ($buildings as $building) {
                $html .= '<li>' . e((string) ($building['name'] ?? ''))
                    . ' · ' . e((string) ($building['type_label'] ?? ''))
                    . ' · ' . (int) ($building['households_count'] ?? 0) . ' active households</li>';
            }

            $html .= '</ul>';
        }

        foreach ($notes as $note) {
            $html .= '<p>' . e((string) $note) . '</p>';
        }

        return $html . '<p><a href="/app">Back to app</a></p></main></body></html>';
    }
}

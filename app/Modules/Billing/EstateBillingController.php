<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Clock;
use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\View;
use App\Modules\Admin\AdminBaseController;

/**
 * Inc-11 estate billing read-only overview, reports and CSV export. Estate-scoped;
 * reuses billing.subscription.view; no writes except the PII-free export audit.
 */
final class EstateBillingController extends AdminBaseController
{
    private const REPORT_KEYS = ['summary', 'outstanding', 'payments', 'utility'];
    private const ROW_CAP = 50000;
    private const REPORT_COLUMNS = [
        'summary' => ['period_key', 'invoices', 'open_count', 'past_due_count', 'paid_count', 'amount_cents', 'currency'],
        'outstanding' => ['invoice_number', 'period_key', 'status', 'due_at', 'amount_cents', 'currency', 'paid_cents'],
        'payments' => ['paid_at', 'invoice_number', 'method', 'reference', 'amount_cents', 'currency', 'status'],
        'utility' => ['invoice_number', 'period_key', 'status', 'amount_cents', 'currency', 'unit_number', 'charge_model'],
    ];

    public function overview(Request $request, array $params, Context $context): Response
    {
        $denied = $this->requireBillingRead($context);
        if ($denied !== null) { return $denied; }
        $estateId = $this->estateId($context);
        $filters = $this->filters($context, $request);
        [$fromSql, $fromParams] = $this->dateSql('i.created_at', $filters);
        $invoiceParams = ['estate_id' => $estateId] + $fromParams;
        $invoices = $context->db->select(
            "SELECT i.uuid, i.invoice_number, i.kind, i.status, i.period_key, i.amount_cents, i.currency,
                    i.due_at, i.paid_at, i.created_at, h.unit_number,
                    COALESCE((SELECT SUM(p.amount_cents) FROM billing_payments p
                              WHERE p.invoice_id=i.id AND p.estate_id=i.estate_id AND p.status='confirmed'),0) AS paid_cents
             FROM billing_invoices i LEFT JOIN households h ON h.id=i.household_id AND h.estate_id=i.estate_id
             WHERE i.estate_id=:estate_id" . $fromSql . ' ORDER BY i.created_at DESC, i.id DESC LIMIT 100',
            $invoiceParams
        );
        [$paySql, $payParams] = $this->dateSql('p.paid_at', $filters);
        $payments = $context->db->select(
            "SELECT p.uuid, p.method, p.reference, p.amount_cents, p.currency, p.status, p.paid_at,
                    i.invoice_number
             FROM billing_payments p INNER JOIN billing_invoices i ON i.id=p.invoice_id AND i.estate_id=p.estate_id
             WHERE p.estate_id=:estate_id" . $paySql . ' ORDER BY p.created_at DESC, p.id DESC LIMIT 100',
            ['estate_id' => $estateId] + $payParams
        );
        $totals = $this->totals($context, $estateId);
        $data = $this->shell($context, 'Billing', 'billing') + [
            'invoices' => $invoices,
            'payments' => $payments,
            'totals' => $totals,
            'filters' => $filters,
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return View::exists('admin/billing')
            ? View::render('admin/billing', $data, 'admin')
            : $this->fallback('Billing', $data);
    }

    public function invoice(Request $request, array $params, Context $context): Response
    {
        $denied = $this->requireBillingRead($context);
        if ($denied !== null) { return $denied; }
        $estateId = $this->estateId($context);
        $invoice = $context->db->selectOne(
            'SELECT * FROM billing_invoices WHERE uuid=:uuid AND estate_id=:estate_id LIMIT 1',
            ['uuid' => (string) ($params['uuid'] ?? ''), 'estate_id' => $estateId]
        );

        if ($invoice === null) {
            throw HttpException::notFound('INVOICE_NOT_FOUND', 'That invoice is not available.');
        }

        $payments = $context->db->select(
            'SELECT uuid, method, reference, amount_cents, currency, status, paid_at, created_at
             FROM billing_payments WHERE invoice_id=:id AND estate_id=:estate_id ORDER BY created_at DESC, id DESC',
            ['id' => (int) $invoice['id'], 'estate_id' => $estateId]
        );
        $data = $this->shell($context, 'Invoice ' . $invoice['invoice_number'], 'billing')
            + ['invoice' => $invoice, 'payments' => $payments];

        return View::exists('admin/billing-invoice')
            ? View::render('admin/billing-invoice', $data, 'admin')
            : $this->fallback('Invoice ' . (string) $invoice['invoice_number'], $data);
    }

    public function reports(Request $request, array $params, Context $context): Response
    {
        $denied = $this->requireBillingRead($context);
        if ($denied !== null) { return $denied; }
        $estateId = $this->estateId($context);
        $report = $this->reportKey($request->queryString('report'));
        $filters = $this->filters($context, $request);
        $data = $this->shell($context, 'Billing reports', 'billing') + [
            'report' => ['key' => $report, 'rows' => $this->reportRows($context, $estateId, $report, $filters), 'filters' => $filters],
            'filters' => $filters,
        ] + ['errors' => $this->pullErrors(), 'old' => $this->pullOld()];

        return View::exists('admin/billing-reports')
            ? View::render('admin/billing-reports', $data, 'admin')
            : $this->fallback('Billing reports', $data);
    }

    public function export(Request $request, array $params, Context $context): Response
    {
        $denied = $this->requireBillingRead($context);
        if ($denied !== null) { return $denied; }
        $estateId = $this->estateId($context);
        $report = $this->reportKey($request->queryString('report'));
        $filters = $this->filters($context, $request);
        $rows = $this->reportRows($context, $estateId, $report, $filters);

        try {
            $csv = $this->csvForRows($rows, $report);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return Response::json([
                    'success' => false,
                    'error' => ['code' => 'EXPORT_TOO_LARGE', 'message' => 'The export exceeds the 50000-row cap. Narrow the date range or report.'],
                ], 422);
            }

            return $this->capResponse();
        }

        $rowCount = count($rows);

        try {
            $context->audit->record('report.exported', 'billing_report', null, [
                'report' => $report,
                'format' => 'csv',
                'row_count' => $rowCount,
                'estate_id' => $estateId,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'from_utc' => $filters['from_utc'] ?? null,
                'to_utc' => $filters['to_utc'] ?? null,
                'timezone' => $filters['timezone'] ?? null,
            ], $estateId);
        } catch (\Throwable) {
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="estate-billing-' . $report . '-' . gmdate('Ymd') . '.csv"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function requireBillingRead(Context $context): ?Response
    {
        if (!$context->permission->can('billing.subscription.view') && !$context->permission->can('billing.invoice.view')) {
            return $this->billingForbidden();
        }

        return null;
    }

    private function billingForbidden(): Response
    {
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Billing access required</title></head><body><main>'
            . '<h1>Billing access required</h1>'
            . '<p>Estate billing is available to estate administrators and managers with billing permission.</p>'
            . '<p>Contact your estate administrator if you need access.</p>'
            . '<p><a href="/app">Back to app</a></p></main></body></html>';

        return Response::html($html, 403);
    }

    private function estateId(Context $context): int
    {
        return (int) $context->tenant->requireEstate()['estate_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Context $context, Request $request): array
    {
        $estate = $context->tenant->requireEstate();
        $timezone = new \DateTimeZone((string) ($estate['estate_timezone'] ?? $estate['timezone'] ?? 'Africa/Nairobi'));
        $from = trim((string) $request->queryString('from'));
        $to = trim((string) $request->queryString('to'));
        $filters = [
            'status' => $request->queryString('status'),
            'kind' => $request->queryString('kind'),
            'from' => $from !== '' ? $from : null,
            'to' => $to !== '' ? $to : null,
            'from_utc' => null,
            'to_utc' => null,
            'timezone' => $timezone->getName(),
        ];

        foreach (['from', 'to'] as $field) {
            $value = $filters[$field];

            if ($value === null) {
                continue;
            }

            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new ValidationException([$field => ['Enter a valid date (YYYY-MM-DD).']]);
            }

            if ($field === 'from') {
                $filters['from_utc'] = $date->setTime(0, 0, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } else {
                $filters['to_utc'] = $date->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        if ($filters['from_utc'] !== null && $filters['to_utc'] !== null && $filters['from_utc'] > $filters['to_utc']) {
            throw new ValidationException(['from' => ['The from date must not be after the to date.']]);
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0:string,1:array<string,string>}
     */
    private function dateSql(string $column, array $filters): array
    {
        $sql = '';
        $params = [];

        if (($filters['from_utc'] ?? null) !== null) {
            $sql .= ' AND ' . $column . ' >= :date_from';
            $params['date_from'] = (string) $filters['from_utc'];
        }

        if (($filters['to_utc'] ?? null) !== null) {
            $sql .= ' AND ' . $column . ' <= :date_to';
            $params['date_to'] = (string) $filters['to_utc'];
        }

        return [$sql, $params];
    }

    /**
     * @return array<string, int>
     */
    private function totals(Context $context, int $estateId): array
    {
        $rows = $context->db->select(
            "SELECT i.status, i.amount_cents,
                    COALESCE((SELECT SUM(p.amount_cents) FROM billing_payments p WHERE p.invoice_id=i.id AND p.status='confirmed'),0) AS paid
             FROM billing_invoices i WHERE i.estate_id=:estate_id",
            ['estate_id' => $estateId]
        );
        $out = ['outstanding_cents' => 0, 'open_cents' => 0, 'past_due_cents' => 0, 'paid_cents' => 0];

        foreach ($rows as $row) {
            $balance = max(0, (int) $row['amount_cents'] - (int) $row['paid']);
            $out['outstanding_cents'] += $balance;
            $out['paid_cents'] += (int) $row['paid'];

            if ((string) $row['status'] === 'open') {
                $out['open_cents'] += $balance;
            } elseif ((string) $row['status'] === 'past_due') {
                $out['past_due_cents'] += $balance;
            }
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reportRows(Context $context, int $estateId, string $report, array $filters = []): array
    {
        if ($report === 'payments') {
            [$sql, $params] = $this->dateSql('p.paid_at', $filters);

            return $context->db->select(
                "SELECT p.paid_at, i.invoice_number, p.method, p.reference, p.amount_cents, p.currency, p.status
                 FROM billing_payments p INNER JOIN billing_invoices i ON i.id=p.invoice_id
                 WHERE p.estate_id=:estate_id AND p.status='confirmed'" . $sql . ' ORDER BY p.paid_at DESC LIMIT 50001',
                ['estate_id' => $estateId] + $params
            );
        }

        [$sql, $params] = $this->dateSql('i.created_at', $filters);

        if ($report === 'outstanding') {
            return $context->db->select(
                "SELECT i.invoice_number, i.period_key, i.status, i.due_at, i.amount_cents, i.currency,
                        COALESCE((SELECT SUM(p.amount_cents) FROM billing_payments p WHERE p.invoice_id=i.id AND p.status='confirmed'),0) AS paid_cents
                 FROM billing_invoices i WHERE i.estate_id=:estate_id AND i.status IN ('open','past_due')" . $sql
                . ' ORDER BY i.due_at ASC LIMIT 50001',
                ['estate_id' => $estateId] + $params
            );
        }

        if ($report === 'utility') {
            return $context->db->select(
                "SELECT i.invoice_number, i.period_key, i.status, i.amount_cents, i.currency, h.unit_number, u.charge_model
                 FROM billing_invoices i LEFT JOIN households h ON h.id=i.household_id
                 LEFT JOIN utility_charges u ON u.invoice_id=i.id
                 WHERE i.estate_id=:estate_id AND i.kind='utility'" . $sql . ' ORDER BY i.period_key DESC LIMIT 50001',
                ['estate_id' => $estateId] + $params
            );
        }

        return $context->db->select(
            "SELECT i.period_key, COUNT(*) AS invoices,
                    SUM(CASE WHEN i.status='open' THEN 1 ELSE 0 END) AS open_count,
                    SUM(CASE WHEN i.status='past_due' THEN 1 ELSE 0 END) AS past_due_count,
                    SUM(CASE WHEN i.status='paid' THEN 1 ELSE 0 END) AS paid_count,
                    SUM(i.amount_cents) AS amount_cents, i.currency
             FROM billing_invoices i WHERE i.estate_id=:estate_id" . $sql
            . ' GROUP BY i.period_key, i.currency ORDER BY i.period_key DESC LIMIT 50001',
            ['estate_id' => $estateId] + $params
        );
    }

    private function reportKey(?string $report): string
    {
        return in_array($report, self::REPORT_KEYS, true) ? (string) $report : 'summary';
    }

    /**
     * Build the RFC4180 CSV with the contract UTF-8 BOM and hard row cap.
     *
     * @param array<int, array<string, mixed>> $rows first row is the header source
     */
    private function csvForRows(array $rows, string $report): string
    {
        $rowCount = count($rows);

        if ($rowCount > self::ROW_CAP) {
            throw new ValidationException([
                'export' => ['The export exceeds the 50000-row cap. Narrow the date range or report.'],
            ]);
        }

        $headers = self::REPORT_COLUMNS[$report] ?? array_keys($rows[0] ?? []);
        $csv = "\xEF\xBB\xBF" . $this->csvLine($headers);

        foreach ($rows as $row) {
            $csv .= $this->csvLine(array_map(static fn (string $key): string => (string) ($row[$key] ?? ''), $headers));
        }

        return $csv;
    }

    private function capResponse(): Response
    {
        return Response::html('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Export too large</title></head>'
            . '<body><main><h1>Export too large</h1>'
            . '<p>The export exceeds the 50000-row cap. Narrow the date range or report.</p>'
            . '<p><a href="/admin/billing/reports">Back to billing reports</a></p></main></body></html>', 422);
    }

    /**
     * @param array<int, string> $fields
     */
    private function csvLine(array $fields): string
    {
        $out = [];

        foreach ($fields as $field) {
            $field = (string) $field;

            if ($field !== '' && in_array($field[0], ['=', '+', '-', '@'], true)) {
                $field = "'" . $field;
            }

            $out[] = '"' . str_replace('"', '""', $field) . '"';
        }

        return implode(',', $out) . "\r\n";
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fallback(string $title, array $data): Response
    {
        $body = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . e($title) . '</title></head>'
            . '<body><main><h1>' . e($title) . '</h1>';

        if (isset($data['totals'])) {
            $body .= '<pre>' . e((string) json_encode($data['totals'])) . '</pre>';
        }

        if (isset($data['filters']) && is_array($data['filters'])) {
            $body .= '<pre class="filters">' . e((string) json_encode($data['filters'])) . '</pre>';
        }

        foreach (['invoices', 'payments'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $body .= '<ul>';

                foreach ($data[$key] as $row) {
                    $body .= '<li>' . e((string) ($row['invoice_number'] ?? $row['uuid'] ?? '')) . '</li>';
                }

                $body .= '</ul>';
            }
        }

        if (isset($data['report']['rows']) && is_array($data['report']['rows'])) {
            $body .= '<pre>' . e((string) json_encode($data['report']['rows'], JSON_PRETTY_PRINT)) . '</pre>';
        }

        return Response::html($body . '<p><a href="/admin/billing">Billing</a> · <a href="/admin/billing/reports">Reports</a></p></main></body></html>');
    }
}

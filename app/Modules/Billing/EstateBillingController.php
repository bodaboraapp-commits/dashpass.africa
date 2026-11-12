<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Clock;
use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Admin\AdminBaseController;

/**
 * Inc-11 estate billing read-only overview, reports and CSV export. Estate-scoped;
 * reuses billing.subscription.view; no writes except the PII-free export audit.
 */
final class EstateBillingController extends AdminBaseController
{
    private const REPORT_KEYS = ['summary', 'outstanding', 'payments', 'utility'];

    public function overview(Request $request, array $params, Context $context): Response
    {
        $this->requireBillingRead($context);
        $estateId = $this->estateId($context);
        $filters = $this->filters($request);
        $invoices = $context->db->select(
            "SELECT i.uuid, i.invoice_number, i.kind, i.status, i.period_key, i.amount_cents, i.currency,
                    i.due_at, i.paid_at, i.created_at, h.unit_number,
                    COALESCE((SELECT SUM(p.amount_cents) FROM billing_payments p
                              WHERE p.invoice_id=i.id AND p.estate_id=i.estate_id AND p.status='confirmed'),0) AS paid_cents
             FROM billing_invoices i LEFT JOIN households h ON h.id=i.household_id AND h.estate_id=i.estate_id
             WHERE i.estate_id=:estate_id ORDER BY i.created_at DESC, i.id DESC LIMIT 100",
            ['estate_id' => $estateId]
        );
        $payments = $context->db->select(
            "SELECT p.uuid, p.method, p.reference, p.amount_cents, p.currency, p.status, p.paid_at,
                    i.invoice_number
             FROM billing_payments p INNER JOIN billing_invoices i ON i.id=p.invoice_id AND i.estate_id=p.estate_id
             WHERE p.estate_id=:estate_id ORDER BY p.created_at DESC, p.id DESC LIMIT 100",
            ['estate_id' => $estateId]
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
        $this->requireBillingRead($context);
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
        $this->requireBillingRead($context);
        $estateId = $this->estateId($context);
        $report = $this->reportKey($request->queryString('report'));
        $data = $this->shell($context, 'Billing reports', 'billing') + [
            'report' => ['key' => $report, 'rows' => $this->reportRows($context, $estateId, $report)],
        ] + ['errors' => $this->pullErrors(), 'old' => $this->pullOld()];

        return View::exists('admin/billing-reports')
            ? View::render('admin/billing-reports', $data, 'admin')
            : $this->fallback('Billing reports', $data);
    }

    public function export(Request $request, array $params, Context $context): Response
    {
        $this->requireBillingRead($context);
        $estateId = $this->estateId($context);
        $report = $this->reportKey($request->queryString('report'));
        $rows = $this->reportRows($context, $estateId, $report);
        $headers = array_keys($rows[0] ?? []);
        $csv = $this->csvLine($headers);

        foreach (array_slice($rows, 0, 10000) as $row) {
            $csv .= $this->csvLine(array_map(static fn (string $key): string => (string) ($row[$key] ?? ''), $headers));
        }

        try {
            $context->audit->record('report.exported', 'billing_report', null, [
                'report' => $report,
                'row_count' => min(count($rows), 10000),
                'estate_id' => $estateId,
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

    private function requireBillingRead(Context $context): void
    {
        $this->requireAdminArea($context);

        if (!$context->permission->can('billing.subscription.view') && !$context->permission->can('billing.invoice.view')) {
            throw HttpException::forbidden('PERMISSION_DENIED', 'Billing is not available for this account.');
        }
    }

    private function estateId(Context $context): int
    {
        return (int) $context->tenant->requireEstate()['estate_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'status' => $request->queryString('status'),
            'kind' => $request->queryString('kind'),
            'from' => $request->queryString('from'),
            'to' => $request->queryString('to'),
        ];
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
    private function reportRows(Context $context, int $estateId, string $report): array
    {
        return match ($report) {
            'outstanding' => $context->db->select(
                "SELECT i.invoice_number, i.period_key, i.status, i.due_at, i.amount_cents, i.currency,
                        COALESCE((SELECT SUM(p.amount_cents) FROM billing_payments p WHERE p.invoice_id=i.id AND p.status='confirmed'),0) AS paid_cents
                 FROM billing_invoices i WHERE i.estate_id=:estate_id AND i.status IN ('open','past_due')
                 ORDER BY i.due_at ASC LIMIT 10000",
                ['estate_id' => $estateId]
            ),
            'payments' => $context->db->select(
                "SELECT p.paid_at, i.invoice_number, p.method, p.reference, p.amount_cents, p.currency, p.status
                 FROM billing_payments p INNER JOIN billing_invoices i ON i.id=p.invoice_id
                 WHERE p.estate_id=:estate_id AND p.status='confirmed' ORDER BY p.paid_at DESC LIMIT 10000",
                ['estate_id' => $estateId]
            ),
            'utility' => $context->db->select(
                "SELECT i.invoice_number, i.period_key, i.status, i.amount_cents, i.currency, h.unit_number, u.charge_model
                 FROM billing_invoices i LEFT JOIN households h ON h.id=i.household_id
                 LEFT JOIN utility_charges u ON u.invoice_id=i.id
                 WHERE i.estate_id=:estate_id AND i.kind='utility' ORDER BY i.period_key DESC LIMIT 10000",
                ['estate_id' => $estateId]
            ),
            default => $context->db->select(
                "SELECT period_key, COUNT(*) AS invoices,
                        SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) AS open_count,
                        SUM(CASE WHEN status='past_due' THEN 1 ELSE 0 END) AS past_due_count,
                        SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_count,
                        SUM(amount_cents) AS amount_cents, currency
                 FROM billing_invoices WHERE estate_id=:estate_id
                 GROUP BY period_key, currency ORDER BY period_key DESC LIMIT 10000",
                ['estate_id' => $estateId]
            ),
        };
    }

    private function reportKey(?string $report): string
    {
        return in_array($report, self::REPORT_KEYS, true) ? (string) $report : 'summary';
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

<?php

declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Modules\Shared\BuildsShell;

/**
 * Resident household-staff screens + API (Increment 6, frozen keys):
 * `/app/staff` => staff, staff_types, households, errors, old;
 * API => `{staff:[...]}` / created staff object / `{status}`.
 */
final class StaffController
{
    use BuildsShell;

    public function webIndex(Request $request, array $params, Context $context): Response
    {
        $service = StaffService::make($context);

        $data = $this->shell($context, 'Household staff', 'home') + [
            'staff' => $service->listOwn(),
            'staff_types' => $service->staffTypes(),
            'households' => $service->householdsForSelect(),
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return \App\Core\View::render('app/staff', $data, 'app');
    }

    public function webStore(Request $request, array $params, Context $context): Response
    {
        return $this->runMutation(
            $context,
            static fn (): array => StaffService::make($context)->create($request->all()),
            'Staff member added.'
        );
    }

    public function webDeactivate(Request $request, array $params, Context $context): Response
    {
        return $this->runMutation(
            $context,
            static fn (): array => StaffService::make($context)->deactivate((string) ($params['uuid'] ?? '')),
            'Staff member deactivated.'
        );
    }

    public function webReactivate(Request $request, array $params, Context $context): Response
    {
        return $this->runMutation(
            $context,
            static fn (): array => StaffService::make($context)->reactivate((string) ($params['uuid'] ?? '')),
            'Staff member reactivated.'
        );
    }

    public function apiIndex(Request $request, array $params, Context $context): Response
    {
        return Response::success('Household staff loaded.', [
            'staff' => StaffService::make($context)->listOwn(),
        ]);
    }

    public function apiStore(Request $request, array $params, Context $context): Response
    {
        $created = StaffService::make($context)->create($request->all());

        return Response::success('Staff member added.', $created, 201);
    }

    public function apiDeactivate(Request $request, array $params, Context $context): Response
    {
        $result = StaffService::make($context)->deactivate((string) ($params['uuid'] ?? ''));

        return Response::success('Staff member deactivated.', $result);
    }

    public function apiReactivate(Request $request, array $params, Context $context): Response
    {
        $result = StaffService::make($context)->reactivate((string) ($params['uuid'] ?? ''));

        return Response::success('Staff member reactivated.', $result);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function pullErrors(): array
    {
        $errors = Session::pullFlash('errors', []);

        return is_array($errors) ? $errors : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function pullOld(): array
    {
        $old = Session::pullFlash('old', []);

        return is_array($old) ? $old : [];
    }

    /**
     * @param callable(): array<string, mixed> $operation
     */
    private function runMutation(Context $context, callable $operation, string $successMessage): Response
    {
        try {
            $operation();
            Session::flash('success', $successMessage);
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', []);
        } catch (HttpException $exception) {
            Session::flash('error', $exception->publicMessage());
        }

        return Response::redirect('/app/staff');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Parking;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Modules\Shared\BuildsShell;

/**
 * My Parking screen + parking JSON APIs (resident + guard).
 *
 * Frozen T21 view data for `/app/parking`: `allocations`, `reservations`.
 * Availability API returns `{bays, from, to}`; guard session APIs start/stop
 * parking sessions explicitly (the automatic side effects live in the visitor
 * check-in/check-out flow).
 */
final class ParkingController
{
    use BuildsShell;

    public function webIndex(Request $request, array $params, Context $context): Response
    {
        $service = ParkingService::make($context);

        $shell = $this->shell($context, 'My parking', 'home');
        $shell['allocations'] = $service->allocationsForResident();
        $shell['reservations'] = $service->reservationsForResident();

        return View::render('app/parking', $shell, 'app');
    }

    public function apiResidentParking(Request $request, array $params, Context $context): Response
    {
        $service = ParkingService::make($context);

        return Response::success('Parking loaded.', [
            'allocations' => $service->allocationsForResident(),
            'reservations' => $service->reservationsForResident(),
        ]);
    }

    public function apiAvailability(Request $request, array $params, Context $context): Response
    {
        $type = $request->queryString('type', 'visitor');
        $type = is_string($type) && in_array($type, ['visitor', 'resident', 'service', 'mixed'], true)
            ? $type
            : 'visitor';

        $data = ParkingService::make($context)->availability(
            $request->queryString('from'),
            $request->queryString('to'),
            $type
        );

        return Response::success('Parking availability loaded.', $data);
    }

    public function apiGuardCheckin(Request $request, array $params, Context $context): Response
    {
        $validated = Validator::validate(
            $request->all(),
            ['invitation_uuid' => 'required|uuid'],
            ['invitation_uuid' => 'invitation']
        );

        $session = ParkingService::make($context)->guardCheckin((string) $validated['invitation_uuid']);

        return Response::success('Parking session started.', $session);
    }

    public function apiGuardCheckout(Request $request, array $params, Context $context): Response
    {
        $validated = Validator::validate(
            $request->all(),
            ['invitation_uuid' => 'nullable|uuid', 'session_uuid' => 'nullable|uuid'],
            ['invitation_uuid' => 'invitation', 'session_uuid' => 'parking session']
        );

        $invitationUuid = isset($validated['invitation_uuid']) && is_string($validated['invitation_uuid'])
            ? $validated['invitation_uuid']
            : null;
        $sessionUuid = isset($validated['session_uuid']) && is_string($validated['session_uuid'])
            ? $validated['session_uuid']
            : null;

        if ($invitationUuid === null && $sessionUuid === null) {
            throw new \App\Core\HttpException(
                422,
                'VALIDATION_FAILED',
                'Provide an invitation or parking session identifier.',
                ['errors' => ['invitation_uuid' => ['Provide an invitation or parking session identifier.']]]
            );
        }

        $session = ParkingService::make($context)->guardCheckout($invitationUuid, $sessionUuid);

        return Response::success('Parking session completed.', $session);
    }

    public function apiGuardActive(Request $request, array $params, Context $context): Response
    {
        $sessions = ParkingService::make($context)->activeSessions();

        return Response::success('Active parking sessions loaded.', [
            'sessions' => $sessions,
            'count' => count($sessions),
        ]);
    }
}

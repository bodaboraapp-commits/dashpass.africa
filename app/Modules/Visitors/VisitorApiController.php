<?php

declare(strict_types=1);

namespace App\Modules\Visitors;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Resident-facing JSON API for visitor invitations and walk-in approvals
 * (contract §5/§6). Every response uses the standard success/error envelope.
 */
final class VisitorApiController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $tab = $this->resolveTab($request->queryString('tab'));
        $invitations = InvitationService::make($context);
        $lists = $invitations->listForResident($tab);
        $pending = WalkInService::make($context)->pendingForResident();

        return Response::success('Visitor invitations loaded.', [
            'tab' => $lists['tab'],
            'upcoming' => $lists['upcoming'],
            'history' => $lists['history'],
            'pending_walkins' => $pending,
        ]);
    }

    public function store(Request $request, array $params, Context $context): Response
    {
        $created = InvitationService::make($context)->create($request->all());

        return Response::success(
            'Visitor invitation created.',
            InvitationService::publicCreateData($created),
            201
        );
    }

    public function show(Request $request, array $params, Context $context): Response
    {
        $invitations = InvitationService::make($context);
        $row = $invitations->findForResident((string) ($params['uuid'] ?? ''));

        return Response::success('Visitor invitation loaded.', $invitations->passData($row));
    }

    public function revoke(Request $request, array $params, Context $context): Response
    {
        $result = InvitationService::make($context)->revoke((string) ($params['uuid'] ?? ''));

        return Response::success('Visitor invitation revoked.', $result);
    }

    public function extend(Request $request, array $params, Context $context): Response
    {
        $validated = Validator::validate(
            $request->all(),
            ['minutes' => 'required|integer|between:15,1440'],
            ['minutes' => 'minutes']
        );

        $result = InvitationService::make($context)->extend(
            (string) ($params['uuid'] ?? ''),
            (int) $validated['minutes']
        );

        return Response::success('Visitor invitation extended.', $result);
    }

    public function rotatePin(Request $request, array $params, Context $context): Response
    {
        $result = InvitationService::make($context)->rotatePin((string) ($params['uuid'] ?? ''));

        return Response::success('A new PIN has been issued.', $result);
    }

    public function approveWalkIn(Request $request, array $params, Context $context): Response
    {
        $result = WalkInService::make($context)->approve((string) ($params['uuid'] ?? ''));

        return Response::success('Walk-in visitor approved.', $result);
    }

    public function rejectWalkIn(Request $request, array $params, Context $context): Response
    {
        $result = WalkInService::make($context)->reject((string) ($params['uuid'] ?? ''));

        return Response::success('Walk-in visitor rejected.', $result);
    }

    private function resolveTab(?string $tab): string
    {
        return $tab === 'history' ? 'history' : 'upcoming';
    }
}

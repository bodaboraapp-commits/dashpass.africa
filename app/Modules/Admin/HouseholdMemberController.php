<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;

/**
 * Add/remove household members.
 *
 * Route middleware stays `permission:household.member.manage` per the frozen
 * contract, with an additional controller-level admin-area guard because the
 * seeded resident role also holds that permission.
 */
final class HouseholdMemberController extends AdminBaseController
{
    public function store(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $this->requireMemberManagement($context);
        $householdUuid = (string) ($params['uuid'] ?? '');

        try {
            HouseholdAdminService::make($context)->addMember($householdUuid, $request->all());
            $this->flashSuccess('Household member added.');
        } catch (ValidationException $exception) {
            Session::flash('errors', $exception->errors());
            Session::flash('old', $request->all());
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
            Session::flash('old', $request->all());
        }

        return $this->backTo('/admin/households/' . $householdUuid);
    }

    public function remove(Request $request, array $params, Context $context): Response
    {
        $this->requireAdminArea($context);
        $this->requireMemberManagement($context);
        $householdUuid = (string) ($params['uuid'] ?? '');
        $memberUuid = (string) ($params['memberUuid'] ?? '');

        try {
            HouseholdAdminService::make($context)->removeMember($householdUuid, $memberUuid);
            $this->flashSuccess('Household member removed.');
        } catch (HttpException $exception) {
            $this->flashError($exception->publicMessage());
        }

        return $this->backTo('/admin/households/' . $householdUuid);
    }
}

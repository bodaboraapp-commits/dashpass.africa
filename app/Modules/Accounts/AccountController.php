<?php

declare(strict_types=1);

namespace App\Modules\Accounts;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Shared\BuildsShell;

/**
 * Account screen: identity, phone and active estate context.
 */
final class AccountController
{
    use BuildsShell;

    public function webAccount(Request $request, array $params, Context $context): Response
    {
        return View::render('app/account', $this->shell($context, 'Account', 'account'), 'app');
    }
}

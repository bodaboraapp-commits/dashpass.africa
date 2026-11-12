<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;

/**
 * Guest About entry point: the route stays registered for stable links but
 * redirects to the landing page's #about section. No rendered About page.
 */
final class AboutController
{
    public function show(Request $request, array $params, Context $context): Response
    {
        return Response::redirect('/#about', 302);
    }
}

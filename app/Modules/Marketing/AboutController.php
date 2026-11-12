<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;

/**
 * Guest About entry point for the single-scroll landing: the route stays
 * registered for stable links and redirects to the landing's #about section.
 */
final class AboutController
{
    public function show(Request $request, array $params, Context $context): Response
    {
        return Response::redirect('/#about', 302);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;

/**
 * Public About page (`GET /about`), a real rendered marketing page.
 *
 * Replaces the old 302 redirect to the landing page's removed #about anchor.
 * Public, guest layout, no auth, no data access.
 */
final class AboutController
{
    use BuildsGuestMarketingPage;

    public function show(Request $request, array $params, Context $context): Response
    {
        return $this->renderMarketingPage(
            'marketing/about',
            'About',
            'DashPASS is the Pass & Pay management system for estates: automated access, '
                . 'frictionless payments and a complete, auditable record of every arrival.'
        );
    }
}

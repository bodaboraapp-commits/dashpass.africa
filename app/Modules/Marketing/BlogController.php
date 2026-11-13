<?php

declare(strict_types=1);

namespace App\Modules\Marketing;

use App\Core\Context;
use App\Core\Request;
use App\Core\Response;

/**
 * Public Blog page (`GET /blog`).
 *
 * Honest empty state: DashPASS has no published posts yet, so the page says so
 * (no fabricated articles) and points readers at the contact form.
 */
final class BlogController
{
    use BuildsGuestMarketingPage;

    public function index(Request $request, array $params, Context $context): Response
    {
        return $this->renderMarketingPage(
            'marketing/blog',
            'Blog',
            'Insights & updates from DashPASS — product notes, estate access and payment '
                . 'best practice. New posts are published here.'
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Notices;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Authenticated, estate-scoped notice-image stream (Inc-12).
 *
 * No direct file path exposure: strict basename validation + realpath containment
 * under storage/uploads/notices; files are served with private caching and
 * nosniff. Archived/missing/foreign images return 404.
 */
final class NoticeImageController
{
    public function show(Request $request, array $params, Context $context): Response
    {
        $estate = $context->tenant->requireEstate();
        $estateId = (int) $estate['estate_id'];
        $uuid = (string) ($params['uuid'] ?? '');
        $notice = $context->db->selectOne(
            "SELECT image_path, status, audience, estate_id, expires_at
             FROM estate_notices WHERE uuid = :uuid AND estate_id = :estate_id LIMIT 1",
            ['uuid' => $uuid, 'estate_id' => $estateId]
        );

        if ($notice === null || (string) $notice['status'] !== 'published'
            || ($notice['expires_at'] !== null && (string) $notice['expires_at'] <= \App\Core\Clock::nowDb())) {
            throw HttpException::notFound('NOTICE_IMAGE_NOT_FOUND', 'That image is not available.');
        }

        $audience = (string) ($notice['audience'] ?? 'all');
        $userId = (int) ($context->auth->id() ?? 0);
        $isManager = $context->permission->can('notice.manage');
        $isGuard = $context->permission->can('visitor.incident.create') && !$isManager;
        $residentOk = in_array($audience, ['all', 'residents'], true)
            && $context->db->selectOne(
                "SELECT hm.id FROM household_members hm
                 INNER JOIN households h ON h.id = hm.household_id AND h.estate_id = hm.estate_id AND h.status = 'active'
                 WHERE hm.user_id = :user_id AND hm.estate_id = :estate_id AND hm.status = 'active' LIMIT 1",
                ['user_id' => $userId, 'estate_id' => $estateId]
            ) !== null;
        $staffOk = in_array($audience, ['all', 'staff'], true);

        if (!$isManager && !($isGuard && $staffOk) && !$residentOk) {
            throw HttpException::notFound('NOTICE_IMAGE_NOT_FOUND', 'That image is not available.');
        }

        $service = new NoticeImageService();
        $path = $service->resolve(isset($notice['image_path']) ? (string) $notice['image_path'] : null);

        if ($path === null) {
            throw HttpException::notFound('NOTICE_IMAGE_NOT_FOUND', 'That image is not available.');
        }

        $body = file_get_contents($path);

        if ($body === false) {
            throw HttpException::notFound('NOTICE_IMAGE_NOT_FOUND', 'That image is not available.');
        }

        return new Response($body, 200, [
            'Content-Type' => $service->mimeFor($path),
            'Content-Length' => (string) strlen($body),
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

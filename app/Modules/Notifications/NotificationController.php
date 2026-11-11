<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Shared\BuildsShell;

/**
 * Notifications inbox + mark-read (Inc-13 P2a).
 *
 * The page is auth-only and owner-scoped: no permission node/estate guard is
 * required, and foreign/unknown notification uuids return a generic 404. The
 * bell/inbox data is degraded safely when the P0 columns or feature flag are
 * unavailable (never a 500 on an authenticated shell).
 */
final class NotificationController
{
    use BuildsShell;

    public function webIndex(Request $request, array $params, Context $context): Response
    {
        $state = (string) ($request->queryString('state') ?? 'all');
        $type = (string) ($request->queryString('type') ?? 'all');
        $service = NotificationService::make($context);
        $data = $this->shell($context, 'Notifications', 'notifications');
        $data += $service->inboxData($state, $type);

        if (View::exists('notifications/index')) {
            return View::render('notifications/index', $data, 'app');
        }

        // Backend fallback until the P2a UI (pwa-shell T172) lands. It keeps the
        // route functional, accessible and testable without depending on the
        // final view, and uses the same frozen payload keys.
        return Response::html($this->fallbackHtml($data, $request));
    }

    public function webRead(Request $request, array $params, Context $context): Response
    {
        $uuid = (string) ($params['uuid'] ?? '');
        $service = NotificationService::make($context);
        $row = $service->markReadOwned($uuid);

        if ($row === null) {
            throw HttpException::notFound('NOTIFICATION_NOT_FOUND', 'That notification is not available.');
        }

        $target = $service->readTarget($row);

        if ($target === '/notifications' && str_starts_with((string) ($row['template'] ?? ''), 'notice')) {
            Session::flash('error', 'That notice is no longer available.');
        }

        return Response::redirect($target);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fallbackHtml(array $data, Request $request): string
    {
        $enabled = (bool) ($data['notifications_enabled'] ?? false);
        $counts = is_array($data['counts'] ?? null) ? $data['counts'] : ['unread' => 0, 'total' => 0];
        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : ['state' => 'all', 'type' => 'all'];
        $notifications = is_array($data['notifications'] ?? null) ? $data['notifications'] : [];
        $rows = '';

        foreach ($notifications as $item) {
            if (!is_array($item)) {
                continue;
            }

            $uuid = (string) ($item['uuid'] ?? '');
            $title = (string) ($item['title'] ?? '');
            $excerpt = (string) ($item['body_excerpt'] ?? '');
            $typeLabel = (string) ($item['type_label'] ?? 'Notification');
            $created = (string) ($item['created_at'] ?? '');
            $unread = (bool) ($item['unread'] ?? false);
            $rows .= '<li class="notification-row">'
                . '<form method="post" action="/notifications/' . e(rawurlencode($uuid)) . '/read">'
                . csrf_field()
                . '<button type="submit" class="btn btn--ghost">'
                . '<strong>' . e($title) . '</strong>'
                . '<span class="muted">' . e($typeLabel) . ' · ' . e($created)
                . ($unread ? ' · Unread' : ' · Read') . '</span>'
                . '<span>' . e($excerpt) . '</span>'
                . '</button></form></li>';
        }

        $state = (string) ($filters['state'] ?? 'all');
        $type = (string) ($filters['type'] ?? 'all');
        $body = $enabled
            ? '<p>' . (int) ($counts['unread'] ?? 0) . ' unread of ' . (int) ($counts['total'] ?? 0) . '</p>'
                . '<p><a href="/notifications?state=unread&type=' . e($type) . '">Unread</a> · '
                . '<a href="/notifications?state=all&type=' . e($type) . '">All</a></p>'
                . ($rows === '' ? '<p>No notifications yet.</p>' : '<ul>' . $rows . '</ul>')
            : '<p>Notifications are not available yet. New notices will appear here when enabled.</p>';

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Notifications · ' . e((string) ($data['title'] ?? 'MtaaPass')) . '</title></head>'
            . '<body><main><h1>Notifications</h1>' . $body
            . '<p><a href="/app">Back to app</a></p></main></body></html>';
    }
}

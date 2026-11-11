<?php

declare(strict_types=1);

namespace App\Modules\Notices;

use App\Core\Config;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Visitors\AccessService;

/**
 * Guard notices screen (Increment 7 frozen keys): `/gate/notices` => notices,
 * filters, errors, old — read-only, rendered in the guard shell.
 */
final class GuardNoticeController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $result = NoticeService::make($context)->guardList();

        $data = $this->shell($context, 'Notices', 'notices') + [
            'notices' => $result['notices'],
            'filters' => $result['filters'],
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return View::render('guard/notices', $data, 'guard');
    }

    /**
     * @return array<string, mixed>
     */
    private function shell(Context $context, string $title, string $activeNav): array
    {
        $access = AccessService::make($context);
        $gate = $access->currentGate();
        $user = $context->auth->user() ?? [];
        $flashError = Session::pullFlash('error');
        $flashSuccess = Session::pullFlash('success');

        return [
            'title' => $title,
            'user' => [
                'full_name' => (string) ($user['full_name'] ?? ''),
                'phone' => (string) ($user['phone'] ?? ''),
            ],
            'gate' => $gate === null ? null : (string) $gate['name'],
            'selected_gate' => $gate === null
                ? null
                : ['uuid' => (string) $gate['uuid'], 'name' => (string) $gate['name']],
            'gates' => $access->gateOptions(),
            'csrfToken' => Csrf::token(),
            'activeNav' => $activeNav,
            'flashError' => is_string($flashError) ? $flashError : null,
            'flashSuccess' => is_string($flashSuccess) ? $flashSuccess : null,
            'appVersion' => (string) Config::get('app.version', '0.1.0-foundation'),
            'isDev' => (string) Config::get('app.env', 'production') !== 'production',
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function pullErrors(): array
    {
        $errors = Session::pullFlash('errors', []);

        return is_array($errors) ? $errors : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function pullOld(): array
    {
        $old = Session::pullFlash('old', []);

        return is_array($old) ? $old : [];
    }
}

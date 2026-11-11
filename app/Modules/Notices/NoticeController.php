<?php

declare(strict_types=1);

namespace App\Modules\Notices;

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Shared\BuildsShell;

/**
 * Resident notices screens (Increment 7 frozen keys):
 * `/app/notices` => notices, filters, errors, old;
 * `/app/notices/{uuid}` => notice detail, errors, old.
 */
final class NoticeController
{
    use BuildsShell;

    public function webIndex(Request $request, array $params, Context $context): Response
    {
        $result = NoticeService::make($context)->residentList();

        $data = $this->shell($context, 'Notices', 'home') + [
            'notices' => $result['notices'],
            'filters' => $result['filters'],
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return View::render('app/notices', $data, 'app');
    }

    public function webShow(Request $request, array $params, Context $context): Response
    {
        $notice = NoticeService::make($context)->residentDetail((string) ($params['uuid'] ?? ''));

        $data = $this->shell($context, $notice['title'], 'home') + [
            'notice' => $notice,
            'errors' => $this->pullErrors(),
            'old' => $this->pullOld(),
        ];

        return View::render('app/notice', $data, 'app');
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

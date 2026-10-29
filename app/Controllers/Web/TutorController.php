<?php

declare(strict_types=1);

namespace App\Controllers\Web;

/**
 * Vista propia del tutor. Con ?page=ID usa ese apunte como contexto.
 */
class TutorController extends BaseWebController
{
    public function index(): string
    {
        $pageId   = (int) ($this->request->getGet('page') ?? 0);
        $chatPage = $pageId > 0 ? $this->repository()->page($pageId, (int) auth()->id()) : null;

        return $this->render('tutor/index', [
            'pageTitle'   => 'Tutor',
            'topbarTitle' => 'Tutor',
            'activeNav'   => 'tutor',
            'chatPage'    => $chatPage,
        ]);
    }
}

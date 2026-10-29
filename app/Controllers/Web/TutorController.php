<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Libraries\RagService;

/**
 * Vista propia del tutor. Con ?page=ID usa ese apunte como contexto y con
 * ?doc=ID responde solo con ese archivo.
 */
class TutorController extends BaseWebController
{
    public function index(): string
    {
        $pageId   = (int) ($this->request->getGet('page') ?? 0);
        $chatPage = $pageId > 0 ? $this->repository()->page($pageId, (int) auth()->id()) : null;
        $docId    = (int) ($this->request->getGet('doc') ?? 0);
        $docTitle = (new RagService())->documentsFor((int) auth()->id())[$docId] ?? null;

        return $this->render('tutor/index', [
            'pageTitle'   => 'Tutor',
            'topbarTitle' => 'Tutor',
            'activeNav'   => 'tutor',
            'chatPage'    => $chatPage,
            'chatDoc'     => $docTitle !== null ? ['id' => $docId, 'title' => $docTitle] : null,
        ]);
    }
}

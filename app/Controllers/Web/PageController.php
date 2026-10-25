<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use CodeIgniter\Exceptions\PageNotFoundException;

class PageController extends BaseWebController
{
    public function show(string $id): string
    {
        $page = $this->repository()->page((int) $id, (int) auth()->id());

        if ($page === null) {
            throw PageNotFoundException::forPageNotFound('No encontramos esa página.');
        }

        return $this->renderPage($page, $page['title']);
    }

    public function new(): string
    {
        $userId    = (int) auth()->id();
        $workspace = $this->repository()->ensurePersonalWorkspace($userId);

        return $this->renderPage($this->repository()->newPage((int) $workspace['id'], $userId), 'Página nueva');
    }

    private function renderPage(array $page, string $title): string
    {
        $this->setWorkspace($page['workspace']);
        $this->setPage($page);

        return $this->render('pages/show', [
            'pageTitle'   => $title,
            'topbarTitle' => $title,
            'activeNav'   => 'dashboard',
            'page'        => $page,
        ]);
    }
}

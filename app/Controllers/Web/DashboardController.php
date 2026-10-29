<?php

declare(strict_types=1);

namespace App\Controllers\Web;

class DashboardController extends BaseWebController
{
    public function index(): string
    {
        return $this->render('dashboard/index', [
            'pageTitle'   => 'Inicio',
            'topbarTitle' => 'Inicio',
            'activeNav'   => 'dashboard',
            'recentPages' => $this->repository()->recentPages((int) auth()->id(), 100),
        ]);
    }
}

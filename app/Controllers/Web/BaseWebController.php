<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\NexoRepository;

/**
 * Base de los controladores web del frontend.
 *
 * Mantiene una sola fuente de verdad para el shell (usuario, espacios,
 * navegación) para todas las vistas.
 */
abstract class BaseWebController extends BaseController
{
    protected ?array $currentWorkspace = null;

    protected ?array $currentPage = null;

    protected function currentUser(): array
    {
        $user = auth()->user();

        if ($user === null) {
            throw new \RuntimeException('No hay un usuario autenticado en una ruta web protegida.');
        }

        $name = trim((string) ($user->name ?? $user->username ?? 'Usuario'));

        return [
            'id'       => (int) $user->id,
            'name'     => $name !== '' ? $name : 'Usuario',
            'email'    => (string) ($user->email ?? ''),
            'initials' => nexo_initials($name),
            'tone'     => 'sage',
        ];
    }

    protected function repository(): NexoRepository
    {
        return new NexoRepository();
    }

    /**
     * @return array<string, mixed>
     */
    protected function commonData(): array
    {
        $repo      = $this->repository();
        $workspace = $this->currentWorkspace ?? $repo->ensurePersonalWorkspace((int) auth()->id());

        return [
            'user'             => $this->currentUser(),
            'currentWorkspace' => $this->currentWorkspace,
            'currentPage'      => $this->currentPage,
            'sidebarWorkspace' => $workspace,
            'sidebarPages'     => $repo->pages((int) $workspace['id']),
            'prefix'           => '',
            'currentPath'      => '/' . trim($this->request->getPath(), '/'),
            'topbarTitle'      => $this->currentPage['title'] ?? 'Inicio',
            'pageTitle'        => 'NEXO UNAC',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data = []): string
    {
        $data = array_merge($this->commonData(), $data);
        $data['content'] = view($view, $data);

        return view('layouts/app', $data);
    }

    /**
     * @param array<string, mixed> $workspace
     */
    protected function setWorkspace(array $workspace): void
    {
        $this->currentWorkspace = $workspace;
    }

    /**
     * @param array<string, mixed> $page
     */
    protected function setPage(array $page): void
    {
        $this->currentPage = $page;
    }
}

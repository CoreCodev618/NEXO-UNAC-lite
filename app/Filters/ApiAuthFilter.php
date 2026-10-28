<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Exige sesión activa en /api/v1 y responde JSON.
 * El filtro `session` de Shield redirige al login, inútil para API.
 */
final class ApiAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        if (auth()->loggedIn() && ! auth()->user()->isBanned()) {
            return null;
        }

        return service('response')
            ->setStatusCode(401)
            ->setJSON([
                'success' => false,
                'data'    => null,
                'message' => 'Se requiere sesión activa.',
                'errors'  => null,
            ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}

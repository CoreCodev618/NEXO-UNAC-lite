<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Expone el token CSRF vigente en cada respuesta que modifica estado.
 *
 * Con `Security::$regenerate = true`, CodeIgniter rota el token después de
 * cada POST/PUT/PATCH/DELETE. Sin este header, `public/js/core/csrf.js`
 * conserva el token anterior y el siguiente envío recibe 403.
 */
class CsrfTokenHeader implements FilterInterface
{
    /**
     * @param array<string, string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): void
    {
        // Sin acción: el token se publica en la respuesta.
    }

    /**
     * @param array<string, string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
        $method = strtoupper($request->getMethod());

        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $response->setHeader('X-CSRF-TOKEN', csrf_hash());
    }
}

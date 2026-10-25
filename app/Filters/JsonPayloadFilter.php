<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Si el body JSON de la API no se puede decodificar responde 400 con el
 * envelope del contrato {success, data, message, errors}; sin este filtro
 * el error 400 sale con el formato estándar de errores del framework.
 */
final class JsonPayloadFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        if (! str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            return null;
        }

        $raw = trim((string) $request->getBody());

        if ($raw === '' || is_array(json_decode($raw, true))) {
            return null;
        }

        return service('response')
            ->setStatusCode(400)
            ->setJSON([
                'success' => false,
                'data'    => null,
                'message' => 'JSON malformado.',
                'errors'  => null,
            ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}

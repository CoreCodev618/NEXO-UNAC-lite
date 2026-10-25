<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Valida el header X-API-KEY contra EVENT_INGEST_API_KEY con comparación
 * constante. Una key vacía en .env rechaza todo (fail-closed).
 */
final class ApiKeyFilter implements FilterInterface
{
    private const HEADER = 'X-API-KEY';

    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $expected = (string) env('EVENT_INGEST_API_KEY', '');
        $given    = (string) ($request->getHeaderLine(self::HEADER));

        if ($expected !== '' && hash_equals($expected, $given)) {
            return null;
        }

        return service('response')
            ->setStatusCode(401)
            ->setJSON([
                'success' => false,
                'data'    => null,
                'message' => 'API key inválida.',
                'errors'  => null,
            ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}

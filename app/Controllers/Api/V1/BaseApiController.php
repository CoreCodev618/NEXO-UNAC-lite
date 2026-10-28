<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base de la API v1: formato de respuesta uniforme
 * {success, data, message, errors} para todos los endpoints.
 */
abstract class BaseApiController extends Controller
{
    use ResponseTrait;

    /**
     * Respuesta exitosa. $meta solo se usa en listados paginados
     * ({"page":1,"perPage":20,"total":57}).
     */
    protected function ok(mixed $data, int $status = 200, ?array $meta = null): ResponseInterface
    {
        $body = [
            'success' => true,
            'data'    => $data,
            'message' => null,
            'errors'  => null,
        ];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return $this->respond($body, $status);
    }

    protected function error(string $message, int $status, ?array $errors = null): ResponseInterface
    {
        return $this->respond([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => $errors,
        ], $status);
    }

    /**
     * Body JSON o form.
     *
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        if (! str_contains($this->request->getHeaderLine('Content-Type'), 'application/json')) {
            return $this->request->getPost();
        }

        $raw = trim((string) $this->request->getBody());

        if ($raw === '') {
            return [];
        }

        // JsonPayloadFilter ya rechazó el JSON malformado.
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Devuelve una respuesta 403 si el usuario no pertenece al workspace
     * (o si $write y su rol es de solo lectura). Devuelve null si tiene acceso.
     */
    protected function guardWorkspace(int $workspaceId, bool $write = false): ?ResponseInterface
    {
        if ($workspaceId <= 0) {
            return $this->error('workspace_id inválido.', 422, ['workspace_id' => 'Requerido.']);
        }

        $member = db_connect()
            ->table('workspace_members')
            ->select('role')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', (int) auth()->id())
            ->get()
            ->getRowArray();

        if ($member === null) {
            return $this->error('No tienes acceso a este espacio.', 403);
        }

        if ($write && $member['role'] === 'viewer') {
            return $this->error('Tu rol en este espacio es de solo lectura.', 403);
        }

        return null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $number = (int) $value;

            return $number > 0 ? $number : null;
        }

        return null;
    }
}

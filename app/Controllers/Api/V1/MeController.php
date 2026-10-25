<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Perfil del usuario autenticado. La fuente de verdad del "quién soy yo"
 * para el frontend (nombre, facultad y grupos/permisos).
 */
final class MeController extends BaseApiController
{
    public function show(): ResponseInterface
    {
        $user = auth()->user();

        return $this->ok([
            'id'         => (int) $user->id,
            'username'   => $user->username,
            'name'       => $user->name,
            'email'      => $user->email,
            'faculty_id' => $user->faculty_id !== null ? (int) $user->faculty_id : null,
            'groups'     => $user->getGroups(),
        ]);
    }
}

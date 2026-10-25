<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Shield\Models\UserModel as ShieldUserModel;

/**
 * Usuario Shield + perfil institucional (name, faculty_id).
 * Email y password viven en auth_identities (los maneja Shield).
 */
class UserModel extends ShieldUserModel
{
    protected function initialize(): void
    {
        parent::initialize();

        $this->allowedFields = [
            ...$this->allowedFields,
            'name',
            'faculty_id',
        ];
    }
}

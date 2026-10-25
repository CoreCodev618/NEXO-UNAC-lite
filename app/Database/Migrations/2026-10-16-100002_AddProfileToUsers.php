<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Perfil institucional sobre la tabla `users` de Shield.
 * Shield crea `users` (migración 2020-12-28, corre antes) y guarda
 * email + password en `auth_identities`. Aquí solo agregamos name y faculty_id.
 * Rol (alumno/docente/admin) = grupos de Shield en Config/AuthGroups.php.
 */
class AddProfileToUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => true,
                'after'      => 'username',
            ],
            'faculty_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'name',
            ],
        ]);

        $this->forge->addForeignKey(
            'faculty_id',
            'faculties',
            'id',
            '',
            'SET NULL',
            'users_faculty_id_foreign',
        );
        $this->forge->processIndexes('users');
    }

    public function down(): void
    {
        $this->forge->dropForeignKey('users', 'users_faculty_id_foreign');
        $this->forge->dropColumn('users', ['name', 'faculty_id']);
    }
}

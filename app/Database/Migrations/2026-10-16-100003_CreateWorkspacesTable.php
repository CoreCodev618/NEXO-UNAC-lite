<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Workspaces por facultad/tipo. Seeds iniciales FIIS:
 * EP Sistemas, EP Industrial, Club IA-FIIS.
 * type: EP-Sistemas | EP-Industrial | club | tesis (libre escalar).
 */
class CreateWorkspacesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'faculty_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'owner_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
            ],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => ['EP-Sistemas', 'EP-Industrial', 'club', 'tesis'],
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('faculty_id');
        $this->forge->addKey('owner_id');
        $this->forge->addForeignKey('faculty_id', 'faculties', 'id', '', 'SET NULL');
        $this->forge->addForeignKey('owner_id', 'users', 'id', '', 'SET NULL');

        $this->forge->createTable('workspaces', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('workspaces', true);
    }
}

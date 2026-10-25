<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tabla raíz de facultades. Seed único inicial: FIIS (piloto).
 * Escalable a otras facultades sin cambiar código (solo datos).
 */
class CreateFacultiesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
            ],
            'short_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('short_code');
        $this->forge->createTable('faculties', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('faculties', true);
    }
}

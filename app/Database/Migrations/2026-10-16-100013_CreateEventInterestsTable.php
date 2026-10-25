<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Interés de usuario en un evento (guardado/inscrito).
 * NO es asistencia académica del SGA; es interés extracurricular tech.
 * UNIQUE(event_id, user_id) evita duplicados.
 */
class CreateEventInterestsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'event_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['guardado', 'inscrito'],
                'default'    => 'guardado',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['event_id', 'user_id']);
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('event_id', 'events', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');

        $this->forge->createTable('event_interests', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('event_interests', true);
    }
}

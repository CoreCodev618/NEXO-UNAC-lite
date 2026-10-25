<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tech Radar de eventos reales. REGLA (L7): link_original OBLIGATORIO
 * y verificable, UNIQUE para dedupe en ingest. source_url documenta fuente.
 * status: draft (ingest/admin) -> published (admin).
 */
class CreateEventsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'created_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'category' => [
                'type'       => 'ENUM',
                'constraint' => ['hackathon', 'workshop', 'competencia-ia', 'feria'],
            ],
            'event_date' => [
                'type' => 'DATE',
            ],
            'end_date' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'mode' => [
                'type'       => 'ENUM',
                'constraint' => ['presencial', 'virtual', 'hibrido'],
                'default'    => 'virtual',
            ],
            'location' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'link_original' => [
                // URL canónica del evento. VARCHAR(190) permite UNIQUE limpio
                // en utf8mb4 (190*4=760 < 3072 bytes) para dedupe en ingest.
                'type'       => 'VARCHAR',
                'constraint' => 190,
            ],
            'source' => [
                'type'       => 'ENUM',
                'constraint' => ['nasa', 'devpost', 'luma', 'kaggle', 'mlh', 'eventbrite', 'manual'],
                'default'    => 'manual',
            ],
            'source_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'external_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['draft', 'published'],
                'default'    => 'draft',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        // Dedupe en ingest: una sola fila por link_original.
        $this->forge->addUniqueKey('link_original');
        $this->forge->addKey(['event_date', 'status']);
        $this->forge->addForeignKey('created_by', 'users', 'id', '', 'SET NULL');

        $this->forge->createTable('events', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('events', true);
    }
}

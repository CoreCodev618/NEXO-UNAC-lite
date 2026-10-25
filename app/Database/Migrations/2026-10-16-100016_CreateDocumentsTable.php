<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * STUB V2 (RAG): documentos indexables. Vacía en V1.
 * indexed_v2 marca si ya fue procesado a embeddings en Qdrant.
 * No se toca en V1 salvo para crearla.
 */
class CreateDocumentsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'page_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'file_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'indexed_v2' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('page_id');
        $this->forge->addForeignKey('page_id', 'pages', 'id', '', 'SET NULL');

        $this->forge->createTable('documents', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('documents', true);
    }
}

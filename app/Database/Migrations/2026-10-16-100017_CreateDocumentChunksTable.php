<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * STUB V2 (RAG): chunks de documento con referencia al embedding en Qdrant
 * (embedding_id). Vacía en V1. Cascada document -> chunks.
 */
class CreateDocumentChunksTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'document_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'chunk_index' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
            ],
            'content' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'token_count' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'embedding_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('document_id');
        $this->forge->addForeignKey('document_id', 'documents', 'id', '', 'CASCADE');

        $this->forge->createTable('document_chunks', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('document_chunks', true);
    }
}

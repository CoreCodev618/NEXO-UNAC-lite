<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Metadatos RAG sobre `documents`.
 *
 * El esquema base solo tenía título/ruta/estado. Para citar material y
 * reindexar sin perder contexto necesitamos curso, fecha, tema, tipo, tamaño,
 * autor y el resultado del ingest.
 */
class AddRagMetadataToDocuments extends Migration
{
    public function up(): void
    {
        // Re-ejecutable si una corrida anterior quedó a medias.
        if ($this->db->fieldExists('curso_id', 'documents')) {
            return;
        }

        $this->forge->addColumn('documents', [
            'curso_id' => [
                'type'       => 'INT',
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'title',
            ],
            'class_date' => [
                'type' => 'DATE',
                'null' => true,
                'after' => 'curso_id',
            ],
            'topic' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'class_date',
            ],
            'source_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 512,
                'null'       => true,
                'after'      => 'file_path',
            ],
            'mime_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
                'after'      => 'source_key',
            ],
            'size_bytes' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'mime_type',
            ],
            'created_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'size_bytes',
            ],
            'indexed_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'indexed_v2',
            ],
            'error_message' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'indexed_at',
            ],
        ]);

        $this->forge->addKey('curso_id');
        $this->forge->addKey('created_by');
        $this->forge->addForeignKey('created_by', 'users', 'id', '', 'SET NULL', 'documents_created_by_foreign');
        $this->forge->processIndexes('documents');
    }

    public function down(): void
    {
        $this->forge->dropForeignKey('documents', 'documents_created_by_foreign');
        $this->forge->dropColumn('documents', [
            'curso_id',
            'class_date',
            'topic',
            'source_key',
            'mime_type',
            'size_bytes',
            'created_by',
            'indexed_at',
            'error_message',
        ]);
    }
}

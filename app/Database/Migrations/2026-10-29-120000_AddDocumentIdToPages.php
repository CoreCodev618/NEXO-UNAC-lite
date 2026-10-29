<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Apuntes del tutor: cada apunte puede llevar el archivo del que salió
 * (etiqueta y filtro en el inicio). Si se quita el archivo, el apunte queda.
 */
class AddDocumentIdToPages extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('document_id', 'pages')) {
            return;
        }

        $this->forge->addColumn('pages', [
            'document_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'parent_id',
            ],
        ]);

        $this->db->query('ALTER TABLE pages ADD CONSTRAINT pages_document_id_foreign
            FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE pages DROP FOREIGN KEY pages_document_id_foreign');
        $this->forge->dropColumn('pages', 'document_id');
    }
}

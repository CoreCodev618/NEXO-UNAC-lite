<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Bloques de contenido por página (estilo Notion).
 * type: parrafo | checklist | codigo | tabla | quote | callout.
 * content: JSON (bloque rico) guardado en TEXT.
 */
class CreateBlocksTable extends Migration
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
            ],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => ['parrafo', 'checklist', 'codigo', 'tabla', 'quote', 'callout'],
                'default'    => 'parrafo',
            ],
            'content' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'order_index' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
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
        $this->forge->addKey('page_id');
        $this->forge->addForeignKey('page_id', 'pages', 'id', '', 'CASCADE');

        $this->forge->createTable('blocks', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('blocks', true);
    }
}

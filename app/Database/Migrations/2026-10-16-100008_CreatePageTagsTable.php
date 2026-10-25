<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Relación N:N página<->tag. PK compuesta (page_id, tag_id).
 */
class CreatePageTagsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'page_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'tag_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
        ]);

        $this->forge->addPrimaryKey(['page_id', 'tag_id']);
        $this->forge->addKey('tag_id');
        $this->forge->addForeignKey('page_id', 'pages', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('tag_id', 'tags', 'id', '', 'CASCADE');

        $this->forge->createTable('page_tags', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('page_tags', true);
    }
}

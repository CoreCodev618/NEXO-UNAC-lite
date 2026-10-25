<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Mensajes de chat por sesión. role: user | assistant.
 * Cascada session -> messages.
 */
class CreateChatMessagesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'session_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'role' => [
                'type'       => 'ENUM',
                'constraint' => ['user', 'assistant'],
            ],
            'content' => [
                'type' => 'TEXT',
            ],
            'tokens' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('session_id');
        $this->forge->addForeignKey('session_id', 'chat_sessions', 'id', '', 'CASCADE');

        $this->forge->createTable('chat_messages', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('chat_messages', true);
    }
}

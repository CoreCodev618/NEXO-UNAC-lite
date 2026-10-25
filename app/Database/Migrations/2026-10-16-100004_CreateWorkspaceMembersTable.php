<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Membresías de usuario a workspace. UNIQUE(workspace_id, user_id)
 * evita duplicados. role: owner | editor | viewer.
 */
class CreateWorkspaceMembersTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'workspace_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'role' => [
                'type'       => 'ENUM',
                'constraint' => ['owner', 'editor', 'viewer'],
                'default'    => 'viewer',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['workspace_id', 'user_id']);
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('workspace_id', 'workspaces', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');

        $this->forge->createTable('workspace_members', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('workspace_members', true);
    }
}

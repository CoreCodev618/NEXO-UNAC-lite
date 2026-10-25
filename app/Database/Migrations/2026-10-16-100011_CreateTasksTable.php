<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tareas Kanban por workspace. status mueve en tablero
 * (todo/doing/done). assigned_to FK usuario.
 */
class CreateTasksTable extends Migration
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
            'page_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'assigned_to' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['todo', 'doing', 'done'],
                'default'    => 'todo',
            ],
            'priority' => [
                'type'       => 'ENUM',
                'constraint' => ['baja', 'media', 'alta'],
                'default'    => 'media',
            ],
            'order_index' => [
                'type'     => 'INT',
                'signed'   => true,
                'default'  => 0,
            ],
            'due_date' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('workspace_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('workspace_id', 'workspaces', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('page_id', 'pages', 'id', '', 'SET NULL');
        $this->forge->addForeignKey('assigned_to', 'users', 'id', '', 'SET NULL');

        $this->forge->createTable('tasks', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('tasks', true);
    }
}

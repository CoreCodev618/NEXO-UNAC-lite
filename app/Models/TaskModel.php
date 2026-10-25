<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class TaskModel extends Model
{
    protected $table            = 'tasks';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';
    protected $allowedFields    = [
        'workspace_id',
        'page_id',
        'assigned_to',
        'title',
        'status',
        'priority',
        'order_index',
        'due_date',
    ];
}

<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class WorkspaceModel extends Model
{
    protected $table            = 'workspaces';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';
    protected $allowedFields    = ['faculty_id', 'owner_id', 'name', 'type'];
}

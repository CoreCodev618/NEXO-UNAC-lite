<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class WorkspaceMemberModel extends Model
{
    protected $table            = 'workspace_members';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;
    protected $allowedFields    = ['workspace_id', 'user_id', 'role', 'created_at'];
}

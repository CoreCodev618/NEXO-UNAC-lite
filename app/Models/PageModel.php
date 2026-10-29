<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class PageModel extends Model
{
    protected $table            = 'pages';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';
    protected $allowedFields    = [
        'workspace_id',
        'parent_id',
        'document_id',
        'created_by',
        'title',
        'icon',
        'cover',
        'order_index',
    ];
}

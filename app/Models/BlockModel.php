<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class BlockModel extends Model
{
    protected $table            = 'blocks';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $allowedFields    = ['page_id', 'type', 'content', 'order_index'];
}

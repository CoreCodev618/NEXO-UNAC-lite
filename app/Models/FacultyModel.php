<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class FacultyModel extends Model
{
    protected $table            = 'faculties';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;
    protected $allowedFields    = ['name', 'short_code', 'created_at'];
}

<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Seed único de facultades. Piloto: solo FIIS.
 * Arquitectura mult-facultad: agregar más filas sin cambiar código.
 * Idempotente (upsert por short_code).
 */
class FacultySeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            [
                'name'       => 'Facultad de Ingeniería de Industria y Sistemas',
                'short_code' => 'FIIS',
            ],
        ];

        foreach ($data as $row) {
            $exists = $this->db->table('faculties')
                ->where('short_code', $row['short_code'])
                ->get()
                ->getRow();

            if ($exists === null) {
                $this->db->table('faculties')->insert($row);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Workspaces iniciales FIIS: EP Sistemas, EP Industrial, Club IA-FIIS.
 * Depende de FacultySeeder (FIIS) corriendo antes.
 * Idempotente (upsert por name+type).
 */
class WorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $faculty = $this->db->table('faculties')
            ->where('short_code', 'FIIS')
            ->get()
            ->getRow();

        if ($faculty === null) {
            // Asegura FIIS aunque se corra suelto.
            $this->call('FacultySeeder');
            $faculty = $this->db->table('faculties')
                ->where('short_code', 'FIIS')
                ->get()
                ->getRow();
        }

        $data = [
            ['name' => 'EP Sistemas', 'type' => 'EP-Sistemas'],
            ['name' => 'EP Industrial', 'type' => 'EP-Industrial'],
            ['name' => 'Club IA-FIIS', 'type' => 'club'],
        ];

        foreach ($data as $row) {
            $exists = $this->db->table('workspaces')
                ->where('name', $row['name'])
                ->where('type', $row['type'])
                ->get()
                ->getRow();

            if ($exists === null) {
                $this->db->table('workspaces')->insert([
                    'faculty_id' => $faculty->id,
                    'name'       => $row['name'],
                    'type'       => $row['type'],
                ]);
            }
        }
    }
}

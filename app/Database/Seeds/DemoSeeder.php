<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;

final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call('FacultySeeder');
        $this->call('WorkspaceSeeder');

        $faculty = $this->db->table('faculties')
            ->where('short_code', 'FIIS')
            ->get()
            ->getRow();

        if ($faculty === null) {
            throw new \RuntimeException('Falta FIIS; corre FacultySeeder.');
        }

        $facultyId = (int) $faculty->id;

        $demoId = $this->ensureUser('demo', 'demo@unac.edu.pe', 'Sebastián Rabanal', 'admin', $facultyId);
        $anaId  = $this->ensureUser('ana.torres', 'ana.torres@unac.edu.pe', 'Ana Torres', 'docente', $facultyId);
        $luisId = $this->ensureUser('luis.quispe', 'luis.quispe@unac.edu.pe', 'Luis Quispe', 'alumno', $facultyId);
        $camiId = $this->ensureUser('camila.rojas', 'camila.rojas@unac.edu.pe', 'Camila Rojas', 'alumno', $facultyId);

        $workspaces = [
            'EP Sistemas'    => ['EP-Sistemas', $demoId],
            'EP Industrial'  => ['EP-Industrial', $demoId],
            'Club IA-FIIS'   => ['club', $demoId],
        ];

        $workspaceIds = [];

        foreach ($workspaces as $name => [$type, $ownerId]) {
            $row = $this->db->table('workspaces')
                ->where('name', $name)
                ->where('type', $type)
                ->get()
                ->getRow();

            if ($row === null) {
                $this->db->table('workspaces')->insert([
                    'faculty_id' => $facultyId,
                    'owner_id'   => $ownerId,
                    'name'       => $name,
                    'type'       => $type,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $workspaceId = (int) $this->db->insertID();
            } else {
                $workspaceId = (int) $row->id;
                $this->db->table('workspaces')->where('id', $workspaceId)->update([
                    'faculty_id' => $facultyId,
                    'owner_id'   => $ownerId,
                ]);
            }

            $workspaceIds[$name] = $workspaceId;
        }

        $this->ensureMembership($workspaceIds['EP Sistemas'], $demoId, 'owner');
        $this->ensureMembership($workspaceIds['EP Sistemas'], $anaId, 'editor');
        $this->ensureMembership($workspaceIds['EP Sistemas'], $luisId, 'editor');
        $this->ensureMembership($workspaceIds['EP Sistemas'], $camiId, 'viewer');

        $this->ensureMembership($workspaceIds['EP Industrial'], $demoId, 'owner');
        $this->ensureMembership($workspaceIds['EP Industrial'], $anaId, 'editor');
        $this->ensureMembership($workspaceIds['EP Industrial'], $luisId, 'viewer');

        $this->ensureMembership($workspaceIds['Club IA-FIIS'], $demoId, 'owner');
        $this->ensureMembership($workspaceIds['Club IA-FIIS'], $anaId, 'editor');
        $this->ensureMembership($workspaceIds['Club IA-FIIS'], $camiId, 'viewer');

        $semana6 = $this->ensurePage($workspaceIds['EP Sistemas'], $demoId, 'Semana 6 · Guía de programación entera', 'book-open', 'wine');
        $pc1     = $this->ensurePage($workspaceIds['EP Sistemas'], $demoId, 'PC1 resuelta', 'file-text', 'gold');
        $io4     = $this->ensurePage($workspaceIds['EP Industrial'], $demoId, 'Investigación de operaciones · Semana 4', 'building', 'gold');
        $ruta    = $this->ensurePage($workspaceIds['Club IA-FIIS'], $demoId, 'Ruta de aprendizaje', 'sparkles', 'sage');

        $this->ensureBlocks($semana6, [
            ['callout', ['variant' => 'info', 'title' => 'Objetivo de la sesión', 'text' => 'Entender por qué la solución relajada no siempre sirve y cómo resolver un modelo entero por ramificación y acotación.']],
            ['parrafo', ['style' => 'h2', 'text' => 'Relajación y acotación']],
            ['parrafo', ['style' => 'p', 'text' => 'La relajación lineal da una cota; la ramificación y acotación la ajusta hasta encontrar la solución entera.']],
            ['codigo', ['language' => 'php', 'code' => "<?php\n\$solution = branchAndBound(\$model);\necho \$solution;"]],
            ['checklist', ['items' => [
                ['text' => 'Leer la guía de la semana', 'checked' => true],
                ['text' => 'Resolver el ejercicio 3', 'checked' => false],
                ['text' => 'Comparar con la PC1', 'checked' => false],
            ]]],
        ]);

        $this->ensureBlocks($pc1, [
            ['parrafo', ['style' => 'p', 'text' => 'Solución paso a paso de la primera práctica calificada.']],
            ['tabla', ['headers' => ['Iteración', 'Cota', 'Solución'], 'rows' => [['1', '12.5', 'x=2.5'], ['2', '13.0', 'x=2'], ['3', '13.0', 'óptimo']]]],
            ['codigo', ['language' => 'python', 'code' => "def branch_and_bound(model):\n    return solve(model)"]],
        ]);

        $this->ensureBlocks($io4, [
            ['parrafo', ['style' => 'p', 'text' => 'Modelado de colas y tiempos de espera en un sistema de atención.']],
            ['callout', ['variant' => 'warning', 'title' => 'Antes de la práctica', 'text' => 'Revisar la fórmula de Little y los supuestos de Poisson.']],
            ['checklist', ['items' => [
                ['text' => 'Leer el caso de la empresa', 'checked' => true],
                ['text' => 'Calcular L, Lq, W y Wq', 'checked' => false],
            ]]],
        ]);

        $this->ensureBlocks($ruta, [
            ['parrafo', ['style' => 'p', 'text' => 'De Python básico a un primer modelo de lenguaje: la ruta del club para este ciclo.']],
            ['checklist', ['items' => [
                ['text' => 'Python y Git', 'checked' => true],
                ['text' => 'Datos y estadística', 'checked' => true],
                ['text' => 'Embeddings y RAG', 'checked' => false],
                ['text' => 'Proyecto final', 'checked' => false],
            ]]],
            ['quote', ['text' => 'La mejor forma de aprender IA es construir algo que te sirva.', 'cite' => 'Club IA-FIIS']],
        ]);

        $this->ensureTasks($workspaceIds['EP Sistemas'], [
            ['Terminar la guía de programación entera', 'doing', 'alta', '+1 day', $luisId, $semana6],
            ['Subir los apuntes de la Semana 6', 'todo', 'media', '+3 days', $demoId, $semana6],
            ['Revisar la PC1 resuelta', 'done', 'baja', '-2 days', $anaId, $pc1],
        ]);

        $this->ensureTasks($workspaceIds['EP Industrial'], [
            ['Preparar el laboratorio de IO', 'todo', 'alta', '+4 days', $demoId, $io4],
        ]);

        $this->ensureTasks($workspaceIds['Club IA-FIIS'], [
            ['Publicar la ruta de aprendizaje', 'done', 'baja', '-5 days', $demoId, $ruta],
            ['Documentar el pipeline de embeddings', 'todo', 'media', '+6 days', $luisId, $ruta],
        ]);
    }

    private function ensureUser(string $username, string $email, string $name, string $group, int $facultyId): int
    {
        $provider = auth()->getProvider();
        $user     = $provider->findByCredentials(['username' => $username]);

        if ($user === null) {
            $user = new User([
                'username' => $username,
                'email'    => $email,
                'password' => 'NexoDemo2026!',
            ]);

            $provider->save($user);
            $user = $provider->findByCredentials(['username' => $username]);
        }

        if ($user === null) {
            throw new \RuntimeException('No se pudo crear el usuario ' . $username);
        }

        $user->name       = $name;
        $user->faculty_id = $facultyId;
        $provider->save($user);

        if (! $user->inGroup($group)) {
            $user->addGroup($group);
        }

        return (int) $user->id;
    }

    private function ensureMembership(int $workspaceId, int $userId, string $role): void
    {
        $exists = $this->db->table('workspace_members')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->countAllResults();

        if ($exists === 0) {
            $this->db->table('workspace_members')->insert([
                'workspace_id' => $workspaceId,
                'user_id'      => $userId,
                'role'         => $role,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function ensurePage(int $workspaceId, int $userId, string $title, string $icon, string $cover): int
    {
        $row = $this->db->table('pages')
            ->where('workspace_id', $workspaceId)
            ->where('title', $title)
            ->get()
            ->getRow();

        if ($row !== null) {
            return (int) $row->id;
        }

        $this->db->table('pages')->insert([
            'workspace_id' => $workspaceId,
            'parent_id'    => null,
            'created_by'   => $userId,
            'title'        => $title,
            'icon'         => $icon,
            'cover'        => $cover,
            'order_index'  => 0,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * @param list<array{0:string,1:array<string,mixed>}> $blocks
     */
    private function ensureBlocks(int $pageId, array $blocks): void
    {
        $exists = $this->db->table('blocks')->where('page_id', $pageId)->countAllResults();

        if ($exists > 0) {
            return;
        }

        foreach ($blocks as $index => [$type, $content]) {
            $this->db->table('blocks')->insert([
                'page_id'     => $pageId,
                'type'        => $type,
                'content'     => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'order_index' => $index,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * @param list<array{0:string,1:string,2:string,3:string,4:int,5:int}> $tasks
     */
    private function ensureTasks(int $workspaceId, array $tasks): void
    {
        $exists = $this->db->table('tasks')->where('workspace_id', $workspaceId)->countAllResults();

        if ($exists > 0) {
            return;
        }

        foreach ($tasks as $index => [$title, $status, $priority, $dueOffset, $assignedTo, $pageId]) {
            $this->db->table('tasks')->insert([
                'workspace_id' => $workspaceId,
                'page_id'      => $pageId,
                'assigned_to'  => $assignedTo,
                'title'        => $title,
                'status'       => $status,
                'priority'     => $priority,
                'order_index'  => $index,
                'due_date'     => date('Y-m-d', strtotime($dueOffset)),
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        }
    }
}

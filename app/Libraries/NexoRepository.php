<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\BlockModel;
use App\Models\PageModel;
use App\Models\WorkspaceMemberModel;
use App\Models\WorkspaceModel;

final class NexoRepository
{
    /**
     * Cada usuario tiene su espacio propio: se crea la primera vez que entra.
     * Si además es miembro de espacios compartidos, se prefiere el suyo.
     */
    public function ensurePersonalWorkspace(int $userId): array
    {
        $own = model(WorkspaceModel::class)
            ->where('owner_id', $userId)
            ->orderBy('id', 'ASC')
            ->first();

        if ($own !== null && $this->isMember((int) $own['id'], $userId)) {
            return $this->hydrateWorkspace($own);
        }

        $db = db_connect();
        $db->transStart();
        $db->table('workspaces')->insert([
            'faculty_id' => $db->table('faculties')->select('id')->orderBy('id')->get()->getRow('id'),
            'name'       => 'Mis apuntes',
            'type'       => 'tesis',
            'owner_id'   => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $workspaceId = (int) $db->insertID();
        $db->table('workspace_members')->insert([
            'workspace_id' => $workspaceId,
            'user_id'      => $userId,
            'role'         => 'owner',
        ]);
        $db->transComplete();

        if (! $db->transStatus()) {
            throw new \RuntimeException('No se pudo crear tu espacio de apuntes.');
        }

        return $this->hydrateWorkspace(model(WorkspaceModel::class)->find($workspaceId));
    }

    public function workspace(int $workspaceId, int $userId): ?array
    {
        if (! $this->isMember($workspaceId, $userId)) {
            return null;
        }

        $row = model(WorkspaceModel::class)->find($workspaceId);

        return $row !== null ? $this->hydrateWorkspace($row) : null;
    }

    public function pages(int $workspaceId): array
    {
        $rows = model(PageModel::class)
            ->where('workspace_id', $workspaceId)
            ->orderBy('order_index', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();

        $index = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $index[$id] = [
                'id'         => $id,
                'parent_id'  => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'title'      => (string) $row['title'],
                'icon'       => (string) ($row['icon'] ?? 'file-text'),
                'updated_at' => $row['updated_at'],
                'children'   => [],
            ];
        }

        $childrenByParent = [];

        foreach ($index as $id => $page) {
            $parentId = $page['parent_id'];

            if ($parentId !== null && isset($index[$parentId])) {
                $childrenByParent[$parentId][] = $id;
            } else {
                $childrenByParent[0][] = $id;
            }
        }

        $build = function (int $parentId) use (&$build, $childrenByParent, $index): array {
            $branch = [];

            foreach ($childrenByParent[$parentId] ?? [] as $id) {
                $page             = $index[$id];
                $page['children'] = $build($id);
                $branch[]         = $page;
            }

            return $branch;
        };

        return $build(0);
    }

    public function page(int $pageId, int $userId): ?array
    {
        $page = model(PageModel::class)->find($pageId);

        if ($page === null) {
            return null;
        }

        $workspace = $this->workspace((int) $page['workspace_id'], $userId);

        if ($workspace === null) {
            return null;
        }

        $blocks = model(BlockModel::class)
            ->where('page_id', $pageId)
            ->orderBy('order_index', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();

        $blocks = array_map(static function (array $block): array {
            $content              = json_decode((string) ($block['content'] ?? ''), true);
            $block['content']     = is_array($content) ? $content : [];
            $block['id']          = (int) $block['id'];
            $block['page_id']     = (int) $block['page_id'];
            $block['order_index'] = (int) $block['order_index'];

            return $block;
        }, $blocks);

        return [
            'id'         => (int) $page['id'],
            'title'      => (string) $page['title'],
            'icon'       => (string) ($page['icon'] ?? 'file-text'),
            'updated_at' => $page['updated_at'],
            'workspace'  => $workspace,
            'blocks'     => $blocks,
        ];
    }

    public function newPage(int $workspaceId, int $userId): array
    {
        $workspace = $this->workspace($workspaceId, $userId);

        if ($workspace === null) {
            throw new \RuntimeException('Sin acceso al workspace.');
        }

        return [
            'id'         => 0,
            'title'      => '',
            'icon'       => 'file-text',
            'updated_at' => null,
            'workspace'  => $workspace,
            'blocks'     => [],
        ];
    }

    public function recentPages(int $userId, int $limit = 6): array
    {
        $limit = max(1, min(100, $limit));

        $sql = 'SELECT p.id, p.title, p.icon, p.updated_at
                FROM pages p
                INNER JOIN workspaces w ON w.id = p.workspace_id
                INNER JOIN workspace_members wm ON wm.workspace_id = p.workspace_id
                WHERE wm.user_id = ?
                  AND p.deleted_at IS NULL
                  AND w.deleted_at IS NULL
                ORDER BY p.updated_at DESC, p.id DESC
                LIMIT ' . $limit;

        return array_map(static function (array $page): array {
            $page['id'] = (int) $page['id'];

            return $page;
        }, db_connect()->query($sql, [$userId])->getResultArray());
    }

    public function isMember(int $workspaceId, int $userId): bool
    {
        return model(WorkspaceMemberModel::class)
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->countAllResults() > 0;
    }

    private function hydrateWorkspace(array $workspace): array
    {
        $workspace['id']       = (int) $workspace['id'];
        $workspace['owner_id'] = ($workspace['owner_id'] ?? null) !== null ? (int) $workspace['owner_id'] : null;

        return $workspace;
    }
}

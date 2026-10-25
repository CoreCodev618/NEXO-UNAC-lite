<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\BlockModel;
use App\Models\FacultyModel;
use App\Models\PageModel;
use App\Models\TaskModel;
use App\Models\UserModel;
use App\Models\WorkspaceMemberModel;
use App\Models\WorkspaceModel;
use DateTimeImmutable;
use DateTimeZone;

final class NexoRepository
{
    private const TZ = 'America/Lima';

    private const WORKSPACE_META = [
        'EP-Sistemas' => [
            'type_label' => 'Escuela de Sistemas',
            'type_full'  => 'Escuela Profesional de Ingeniería de Sistemas',
            'summary'    => 'Apuntes, sílabos y proyectos del salón de Sistemas.',
            'icon'       => 'book-open',
            'tone'       => 'wine',
            'cover'      => 'wine',
        ],
        'EP-Industrial' => [
            'type_label' => 'Escuela Industrial',
            'type_full'  => 'Escuela Profesional de Ingeniería Industrial',
            'summary'    => 'Investigación de operaciones, laboratorios y casos.',
            'icon'       => 'building',
            'tone'       => 'gold',
            'cover'      => 'gold',
        ],
        'club' => [
            'type_label' => 'Club estudiantil',
            'type_full'  => 'Organización estudiantil',
            'summary'    => 'Ruta de aprendizaje, sesiones y proyectos del club.',
            'icon'       => 'sparkles',
            'tone'       => 'sage',
            'cover'      => 'sage',
        ],
        'tesis' => [
            'type_label' => 'Proyecto de tesis',
            'type_full'  => 'Investigación de fin de carrera',
            'summary'    => 'Avances, fuentes y entregas del proyecto de tesis.',
            'icon'       => 'file-text',
            'tone'       => 'gold',
            'cover'      => 'gold',
        ],
    ];

    private const WORKSPACE_META_FALLBACK = [
        'type_label' => 'Espacio de trabajo',
        'type_full'  => 'Espacio colaborativo',
        'summary'    => 'Páginas y tareas del espacio.',
        'icon'       => 'folder',
        'tone'       => 'paper',
        'cover'      => 'rose',
    ];

    public function workspacesForUser(int $userId): array
    {
        $db  = db_connect();
        $sql = 'SELECT w.*
                FROM workspace_members wm
                INNER JOIN workspaces w ON w.id = wm.workspace_id
                WHERE wm.user_id = ?
                  AND w.deleted_at IS NULL
                ORDER BY w.id ASC';

        $rows = $db->query($sql, [$userId])->getResultArray();

        return array_map(fn (array $row): array => $this->hydrateWorkspace($row), $rows);
    }

    /**
     * Cada usuario tiene su espacio propio: se crea la primera vez que entra.
     */
    public function ensurePersonalWorkspace(int $userId): array
    {
        $existing = $this->workspacesForUser($userId)[0] ?? null;

        if ($existing !== null) {
            return $existing;
        }

        $db = db_connect();
        $db->transStart();
        $db->table('workspaces')->insert([
            'faculty_id' => (int) ($db->table('faculties')->select('id')->orderBy('id')->get()->getRow('id') ?? 1),
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

        return $this->workspacesForUser($userId)[0];
    }

    public function workspace(int $workspaceId, int $userId): ?array
    {
        if (! $this->isMember($workspaceId, $userId)) {
            return null;
        }

        $row = model(WorkspaceModel::class)->find($workspaceId);

        if ($row === null) {
            return null;
        }

        return $this->hydrateWorkspace($row);
    }

    public function pages(int $workspaceId): array
    {
        $rows = model(PageModel::class)
            ->where('workspace_id', $workspaceId)
            ->orderBy('order_index', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();

        $index   = [];
        $authors = [];

        foreach ($rows as $row) {
            $id       = (int) $row['id'];
            $authorId = (int) ($row['created_by'] ?? 0);

            if (! isset($authors[$authorId])) {
                $authors[$authorId] = $this->authorName($authorId);
            }

            $index[$id] = [
                'id'           => $id,
                'workspace_id' => (int) $row['workspace_id'],
                'parent_id'    => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'title'        => (string) $row['title'],
                'icon'         => (string) ($row['icon'] ?? 'file-text'),
                'cover'        => (string) ($row['cover'] ?? 'rose'),
                'order_index'  => (int) $row['order_index'],
                'created_at'   => $row['created_at'],
                'updated_at'   => $row['updated_at'],
                'author'       => $authors[$authorId],
                'tags'         => [],
                'children'     => [],
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

        $wordCount = $this->wordCount($blocks);

        return [
            'id'           => (int) $page['id'],
            'workspace_id' => (int) $page['workspace_id'],
            'parent_id'    => $page['parent_id'] !== null ? (int) $page['parent_id'] : null,
            'title'        => (string) $page['title'],
            'icon'         => (string) ($page['icon'] ?? 'file-text'),
            'cover'        => (string) ($page['cover'] ?? 'rose'),
            'order_index'  => (int) $page['order_index'],
            'created_at'   => $page['created_at'],
            'updated_at'   => $page['updated_at'],
            'author'       => $this->authorName((int) ($page['created_by'] ?? 0)),
            'workspace'    => $workspace,
            'tags'         => [],
            'blocks'       => $blocks,
            'comments'     => [],
            'attachments'  => [],
            'members'      => $this->members((int) $page['workspace_id']),
            'word_count'   => $wordCount,
            'read_time'    => max(1, (int) ceil($wordCount / 210)),
        ];
    }

    public function newPage(int $workspaceId, int $userId): array
    {
        $workspace = $this->workspace($workspaceId, $userId);

        if ($workspace === null) {
            throw new \RuntimeException('Sin acceso al workspace.');
        }

        return [
            'id'           => 0,
            'workspace_id' => $workspaceId,
            'parent_id'    => null,
            'title'        => 'Página sin título',
            'icon'         => 'file-text',
            'cover'        => 'rose',
            'order_index'  => 99,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
            'author'       => $this->authorName($userId),
            'workspace'    => $workspace,
            'tags'         => [],
            'blocks'       => [],
            'comments'     => [],
            'attachments'  => [],
            'members'      => $this->members($workspaceId),
            'word_count'   => 0,
            'read_time'    => 1,
            'is_new'       => true,
        ];
    }

    public function tasks(?int $workspaceId = null, ?int $userId = null): array
    {
        if ($userId === null) {
            $userId = (int) auth()->id();
        }

        $db     = db_connect();
        $sql    = 'SELECT t.*
                   FROM tasks t
                   INNER JOIN workspace_members wm ON wm.workspace_id = t.workspace_id
                   WHERE t.deleted_at IS NULL
                     AND wm.user_id = ?';
        $params = [$userId];

        if ($workspaceId !== null) {
            $sql .= ' AND t.workspace_id = ?';
            $params[] = $workspaceId;
        }

        $sql .= ' ORDER BY t.status ASC, t.order_index ASC, t.id ASC';

        $rows = $db->query($sql, $params)->getResultArray();

        $membersByWorkspace = [];
        $tasks              = [];

        foreach ($rows as $row) {
            $taskWorkspaceId = (int) $row['workspace_id'];

            if (! isset($membersByWorkspace[$taskWorkspaceId])) {
                $membersById = [];

                foreach ($this->members($taskWorkspaceId) as $member) {
                    $membersById[(int) $member['id']] = $member;
                }

                $membersByWorkspace[$taskWorkspaceId] = $membersById;
            }

            $tasks[] = $this->shapeTask($row, $membersByWorkspace[$taskWorkspaceId]);
        }

        return $tasks;
    }

    public function task(int $taskId): ?array
    {
        $task = model(TaskModel::class)->find($taskId);

        if ($task === null) {
            return null;
        }

        return $this->shapeTask($task);
    }

    public function dashboard(int $userId): array
    {
        $workspaces = $this->workspacesForUser($userId);
        $recent     = $this->recentPages($userId, 6);
        $tasks      = $this->tasks(null, $userId);
        $open       = array_values(array_filter($tasks, static fn (array $t): bool => $t['status'] !== 'done'));

        usort($open, static function (array $a, array $b): int {
            $weight = ['alta' => 0, 'media' => 1, 'baja' => 2];
            $aDue   = $a['due_date'] ?? '9999-12-31';
            $bDue   = $b['due_date'] ?? '9999-12-31';

            return strcmp($aDue, $bDue)
                ?: (($weight[$a['priority']] ?? 3) <=> ($weight[$b['priority']] ?? 3));
        });

        $pagesCount = 0;

        foreach ($workspaces as $workspace) {
            $pagesCount += (int) $workspace['pages_count'];
        }

        return [
            'user'          => [],
            'workspaces'    => $workspaces,
            'recent_pages'  => $recent,
            'featured_page' => $recent[0] ?? null,
            'tasks'         => array_slice($open, 0, 4),
            'top_tasks'     => $open,
            'counts'        => [
                'workspaces' => count($workspaces),
                'pages'      => $pagesCount,
                'open_tasks' => count($open),
            ],
            'events'        => [],
            'today'         => date('Y-m-d'),
        ];
    }

    public function recentPages(int $userId, int $limit = 6): array
    {
        $limit = max(1, min(100, $limit));

        $db  = db_connect();
        $sql = 'SELECT p.*, w.name AS workspace_name, w.type AS workspace_type
                FROM pages p
                INNER JOIN workspaces w ON w.id = p.workspace_id
                INNER JOIN workspace_members wm ON wm.workspace_id = p.workspace_id
                WHERE wm.user_id = ?
                  AND p.deleted_at IS NULL
                  AND w.deleted_at IS NULL
                ORDER BY p.updated_at DESC, p.id DESC
                LIMIT ' . $limit;

        $rows = $db->query($sql, [$userId])->getResultArray();

        return array_map(function (array $page): array {
            $meta = $this->workspaceMeta((string) ($page['workspace_type'] ?? ''));

            $page['id']           = (int) $page['id'];
            $page['workspace_id'] = (int) $page['workspace_id'];
            $page['parent_id']    = $page['parent_id'] !== null ? (int) $page['parent_id'] : null;
            $page['order_index']  = (int) $page['order_index'];

            $page['workspace_name'] = $page['workspace_name'];
            $page['workspace_icon'] = $meta['icon'];
            $page['workspace_tone'] = $meta['tone'];
            $page['workspace_type'] = $page['workspace_type'];
            $page['tags']           = [];
            $page['cover']          = $page['cover'] ?? 'rose';
            $page['icon']           = $page['icon'] ?? 'file-text';

            return $page;
        }, $rows);
    }

    public function members(int $workspaceId): array
    {
        $db  = db_connect();
        $sql = "SELECT
                    u.id,
                    COALESCE(NULLIF(u.name, ''), u.username) AS name,
                    u.username,
                    wm.role,
                    (
                        SELECT ai.secret
                        FROM auth_identities ai
                        WHERE ai.user_id = u.id
                          AND ai.type = 'email_password'
                        ORDER BY ai.id ASC
                        LIMIT 1
                    ) AS email,
                    (
                        SELECT ag.group
                        FROM auth_groups_users ag
                        WHERE ag.user_id = u.id
                        ORDER BY ag.id ASC
                        LIMIT 1
                    ) AS group_name
                FROM workspace_members wm
                INNER JOIN users u ON u.id = wm.user_id
                WHERE wm.workspace_id = ?
                ORDER BY wm.id ASC";

        $rows = $db->query($sql, [$workspaceId])->getResultArray();

        return array_map(static function (array $row): array {
            $tone = match (true) {
                ($row['group_name'] ?? '') === 'admin'   => 'wine',
                ($row['group_name'] ?? '') === 'docente' => 'gold',
                ($row['role'] ?? '') === 'viewer'        => 'rose',
                default                                  => 'sage',
            };

            return [
                'id'       => (int) $row['id'],
                'name'     => (string) $row['name'],
                'email'    => (string) ($row['email'] ?? ''),
                'initials' => nexo_initials((string) $row['name']),
                'tone'     => $tone,
                'role'     => (string) $row['role'],
                'group'    => (string) ($row['group_name'] ?? 'alumno'),
            ];
        }, $rows);
    }

    public function openTaskCounts(int $userId): array
    {
        $counts = [];

        foreach ($this->workspacesForUser($userId) as $workspace) {
            $counts[(int) $workspace['id']] = model(TaskModel::class)
                ->where('workspace_id', (int) $workspace['id'])
                ->where('status !=', 'done')
                ->countAllResults();
        }

        return $counts;
    }

    public function palette(int $userId, int $workspaceId, string $prefix = ''): array
    {
        $workspaces = [];

        foreach ($this->workspacesForUser($userId) as $workspace) {
            $workspaces[] = [
                'title' => $workspace['name'],
                'meta'  => $workspace['type_label'],
                'icon'  => $workspace['icon'],
                'url'   => nexo_url($prefix, 'workspaces/' . $workspace['id']),
            ];
        }

        $pages = [];

        foreach ($this->recentPages($userId, 50) as $page) {
            $pages[] = [
                'title' => $page['title'],
                'meta'  => $page['workspace_name'] . ' · ' . nexo_datetime($page['updated_at']),
                'icon'  => nexo_page_icon($page['icon']),
                'url'   => nexo_url($prefix, 'pages/' . $page['id']),
            ];
        }

        return [
            'actions' => [
                ['title' => 'Ir a mi mesa', 'meta' => 'Panel principal', 'icon' => 'home', 'url' => nexo_url($prefix, 'dashboard'), 'shortcut' => 'G D'],
                ['title' => 'Ver espacios', 'meta' => 'Todos los espacios', 'icon' => 'folder', 'url' => nexo_url($prefix, 'workspaces'), 'shortcut' => 'G E'],
                ['title' => 'Abrir tablero', 'meta' => 'Tareas del espacio activo', 'icon' => 'kanban', 'url' => nexo_url($prefix, 'workspaces/' . $workspaceId . '/kanban'), 'shortcut' => 'G K'],
                ['title' => 'Abrir calendario', 'meta' => 'Fechas y entregas', 'icon' => 'calendar', 'url' => nexo_url($prefix, 'workspaces/' . $workspaceId . '/calendar'), 'shortcut' => 'G C'],
                ['title' => 'Nueva página', 'meta' => 'Crear un documento', 'icon' => 'plus', 'url' => nexo_url($prefix, 'pages/nueva') . '?workspace=' . $workspaceId, 'shortcut' => 'N'],
                ['title' => 'Abrir tutor', 'meta' => 'Preguntar al material', 'icon' => 'sparkles', 'url' => '#tutor', 'shortcut' => '⌘ ⇧ T'],
                ['title' => 'Ajustes', 'meta' => 'Perfil y preferencias', 'icon' => 'settings', 'url' => nexo_url($prefix, 'settings'), 'shortcut' => 'G S'],
            ],
            'pages'      => $pages,
            'workspaces' => $workspaces,
        ];
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
        $workspaceId = (int) ($workspace['id'] ?? 0);
        $meta        = $this->workspaceMeta((string) ($workspace['type'] ?? ''));

        $workspace['id']         = $workspaceId;
        $workspace['faculty_id'] = ($workspace['faculty_id'] ?? null) !== null ? (int) $workspace['faculty_id'] : null;
        $workspace['owner_id']   = ($workspace['owner_id'] ?? null) !== null ? (int) $workspace['owner_id'] : null;

        $workspace['members']     = $this->members($workspaceId);
        $workspace['member_ids']  = array_map(static fn (array $m): int => (int) $m['id'], $workspace['members']);
        $workspace['pages_count'] = model(PageModel::class)->where('workspace_id', $workspaceId)->countAllResults();
        $workspace['open_tasks']  = model(TaskModel::class)->where('workspace_id', $workspaceId)->where('status !=', 'done')->countAllResults();
        $workspace['faculty']     = $this->facultyCode((int) ($workspace['faculty_id'] ?? 0));
        $workspace['type_label']  = $meta['type_label'];
        $workspace['type_full']   = $meta['type_full'];
        $workspace['summary']     = $meta['summary'];
        $workspace['icon']        = $meta['icon'];
        $workspace['tone']        = $meta['tone'];
        $workspace['cover']       = $meta['cover'];
        $workspace['updated_at']  = $workspace['updated_at'] ?? date('Y-m-d H:i:s');

        return $workspace;
    }

    private function workspaceMeta(string $type): array
    {
        return self::WORKSPACE_META[$type] ?? self::WORKSPACE_META_FALLBACK;
    }

    private function facultyCode(int $facultyId): string
    {
        $faculty = model(FacultyModel::class)->find($facultyId);

        return $faculty['short_code'] ?? 'FIIS';
    }

    /**
     * @param array<string, mixed>|null $membersById
     *
     * @return array<string, mixed>
     */
    private function shapeTask(array $task, ?array $membersById = null): array
    {
        $workspaceId = (int) ($task['workspace_id'] ?? 0);

        if ($membersById === null) {
            $membersById = [];

            foreach ($this->members($workspaceId) as $member) {
                $membersById[(int) $member['id']] = $member;
            }
        }

        $assignedTo = (int) ($task['assigned_to'] ?? 0);
        $assignee   = $assignedTo > 0 ? model(UserModel::class)->find($assignedTo) : null;

        if ($assignee !== null) {
            $assigneeName = trim((string) ($assignee->name ?? ''));

            if ($assigneeName === '') {
                $assigneeName = trim((string) ($assignee->username ?? ''));
            }
        } else {
            $assigneeName = '';
        }

        $pageTitle = null;

        if (($task['page_id'] ?? null) !== null) {
            $page = model(PageModel::class)->find((int) $task['page_id']);

            if ($page !== null) {
                $pageTitle = (string) $page['title'];
            }
        }

        $workspaceName = null;

        if ($workspaceId > 0) {
            $workspace = model(WorkspaceModel::class)->find($workspaceId);

            if ($workspace !== null) {
                $workspaceName = (string) $workspace['name'];
            }
        }

        $dueDate   = $task['due_date'] ?? null;
        $isOverdue = $task['status'] !== 'done'
            && $dueDate !== null
            && $dueDate < date('Y-m-d');

        $task['id']           = (int) $task['id'];
        $task['workspace_id'] = $workspaceId;
        $task['page_id']      = $task['page_id'] !== null ? (int) $task['page_id'] : null;
        $task['assigned_to']  = $task['assigned_to'] !== null ? (int) $task['assigned_to'] : null;
        $task['order_index']  = (int) $task['order_index'];

        unset($task['deleted_at']);

        $task['assignee_name']     = $assigneeName !== '' ? $assigneeName : 'Sin asignar';
        $task['assignee_initials'] = $assigneeName !== '' ? nexo_initials($assigneeName) : '—';
        $task['assignee_tone']     = $assigneeName !== '' ? ($membersById[$assignedTo]['tone'] ?? 'sage') : 'paper';
        $task['page_title']        = $pageTitle;
        $task['workspace_name']    = $workspaceName;
        $task['due_label']         = $dueDate !== null ? nexo_date((string) $dueDate) : 'Sin fecha';
        $task['is_overdue']        = $isOverdue;

        return $task;
    }

    private function authorName(int $userId): string
    {
        if ($userId <= 0) {
            return 'Sin autor';
        }

        $user = model(UserModel::class)->find($userId);

        return $user !== null ? (string) ($user->name ?? $user->username ?? 'Sin autor') : 'Sin autor';
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function wordCount(array $blocks): int
    {
        $parts   = [];
        $ignored = ['style', 'variant', 'language', 'checked', 'code'];

        $collect = static function (mixed $value, string $key = '') use (&$collect, &$parts, $ignored): void {
            if (is_string($value)) {
                if (! in_array($key, $ignored, true)) {
                    $parts[] = $value;
                }

                return;
            }

            if (! is_array($value)) {
                return;
            }

            foreach ($value as $childKey => $child) {
                $collect($child, (string) $childKey);
            }
        };

        foreach ($blocks as $block) {
            $collect($block['content'] ?? []);
        }

        return max(0, str_word_count(implode(' ', $parts), 0, 'áéíóúüñÁÉÍÓÚÜÑ'));
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\NexoRepository;
use App\Models\BlockModel;
use App\Models\PageModel;
use CodeIgniter\HTTP\ResponseInterface;

final class PageController extends BaseApiController
{
    private PageModel $pages;
    private BlockModel $blocks;

    public function __construct()
    {
        $this->pages  = model(PageModel::class);
        $this->blocks = model(BlockModel::class);
    }

    public function create(): ResponseInterface
    {
        $data        = $this->payload();
        $workspaceId = (int) ($data['workspace_id'] ?? 0);

        if ($guard = $this->guardWorkspace($workspaceId)) {
            return $guard;
        }

        $title = trim((string) ($data['title'] ?? ''));
        $title = $title !== '' ? mb_substr($title, 0, 255) : 'Página sin título';

        $parentId = isset($data['parent_id']) && $data['parent_id'] !== null
            ? (int) $data['parent_id']
            : null;

        if ($parentId !== null) {
            $parent = $this->pages->find($parentId);
            if ($parent === null || (int) $parent['workspace_id'] !== $workspaceId) {
                return $this->error('La página padre no pertenece al espacio.', 422);
            }
        }

        $max = $this->pages
            ->selectMax('order_index')
            ->where('workspace_id', $workspaceId)
            ->first();

        $order = (int) ($max['order_index'] ?? 0) + 1;

        $id = $this->pages->insert([
            'workspace_id' => $workspaceId,
            'parent_id'    => $parentId,
            'created_by'   => (int) auth()->id(),
            'title'        => $title,
            'icon'         => 'file-text',
            'cover'        => 'rose',
            'order_index'  => $order,
        ]);

        if ($id === false) {
            return $this->error('No se pudo crear la página.', 500);
        }

        return $this->ok($this->pages->find((int) $id), 201);
    }

    public function show(string $id): ResponseInterface
    {
        $page = (new NexoRepository())->page((int) $id, (int) auth()->id());

        if ($page === null) {
            return $this->error('Página no encontrada.', 404);
        }

        return $this->ok($page);
    }

    public function update(string $id): ResponseInterface
    {
        $pageId = (int) $id;
        $page   = $this->pages->find($pageId);

        if ($page === null) {
            return $this->error('Página no encontrada.', 404);
        }

        if ($guard = $this->guardWorkspace((int) $page['workspace_id'])) {
            return $guard;
        }

        $data  = $this->payload();
        $title = trim((string) ($data['title'] ?? $page['title']));
        $title = $title !== '' ? mb_substr($title, 0, 255) : 'Página sin título';

        $incoming = $data['blocks'] ?? [];
        if (! is_array($incoming)) {
            return $this->error('blocks debe ser un arreglo.', 422);
        }

        $deleted = $data['deleted_ids'] ?? [];
        if (! is_array($deleted)) {
            return $this->error('deleted_ids debe ser un arreglo.', 422);
        }

        $allowed = ['parrafo', 'checklist', 'codigo', 'tabla', 'quote', 'callout'];
        $db      = db_connect();
        $db->transBegin();

        $results = [];

        try {
            $this->pages->update($pageId, ['title' => $title]);

            foreach ($incoming as $index => $block) {
                if (! is_array($block)) {
                    throw new \InvalidArgumentException('Bloque inválido.');
                }

                $type = (string) ($block['type'] ?? 'parrafo');
                if (! in_array($type, $allowed, true)) {
                    throw new \InvalidArgumentException('Tipo de bloque inválido: ' . $type);
                }

                $content = $block['content'] ?? [];
                if (! is_array($content)) {
                    throw new \InvalidArgumentException('content debe ser un objeto.');
                }

                $order    = max(0, (int) ($block['order_index'] ?? $index));
                $clientId = (string) ($block['client_id'] ?? ($block['id'] ?? ''));
                $blockId  = isset($block['id']) && is_numeric($block['id'])
                    ? (int) $block['id']
                    : 0;

                $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($encoded === false) {
                    throw new \InvalidArgumentException('content no se pudo serializar a JSON.');
                }

                $row = [
                    'page_id'     => $pageId,
                    'type'        => $type,
                    'content'     => $encoded,
                    'order_index' => $order,
                ];

                if ($blockId > 0) {
                    $existing = $this->blocks->find($blockId);

                    if ($existing === null || (int) $existing['page_id'] !== $pageId) {
                        throw new \InvalidArgumentException('El bloque ' . $blockId . ' no pertenece a la página.');
                    }

                    $this->blocks->update($blockId, $row);
                    $results[] = ['client_id' => $clientId, 'id' => $blockId];
                } else {
                    $newId = $this->blocks->insert($row);

                    if ($newId === false) {
                        throw new \RuntimeException('No se pudo insertar el bloque.');
                    }

                    $results[] = ['client_id' => $clientId, 'id' => (int) $newId];
                }
            }

            $ids = array_values(array_filter(
                array_map('intval', $deleted),
                static fn (int $value): bool => $value > 0
            ));

            if ($ids !== []) {
                $this->blocks
                    ->where('page_id', $pageId)
                    ->whereIn('id', $ids)
                    ->delete();
            }

            $db->transCommit();
        } catch (\InvalidArgumentException $e) {
            $db->transRollback();
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'PageController::update ' . $e->getMessage());
            return $this->error('No se pudo guardar la página.', 500);
        }

        return $this->ok(['blocks' => $results]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\RagService;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Documentos del RAG.
 *
 * GET  /api/v1/documents       lista el material indexado
 * POST /api/v1/documents       multipart: file, title, topic, class_date, curso_id, page_id
 *
 * El archivo se guarda en writable/uploads/rag y se indexa en el momento con
 * `python/ingest.py --doc-id`. Si el ingest falla, el documento queda en
 * MariaDB con `error_message` para poder reintentar/depurar.
 */
final class DocumentController extends BaseApiController
{
    /** Extensión permitida => tipos MIME que `finfo` puede devolver para ella. */
    private const ALLOWED = [
        'pdf'  => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/x-zip-compressed'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    private const MAX_UPLOADS_PER_MINUTE = 5;

    public function index(): ResponseInterface
    {
        $pageId = $this->positiveInt($this->request->getGet('page_id'));

        if ($pageId !== null) {
            try {
                $this->assertPageAccess($pageId, (int) auth()->id());
            } catch (\RuntimeException $error) {
                return $this->error($error->getMessage(), 403);
            }
        }

        $builder = \Config\Database::connect()
            ->table('documents')
            ->select('id, page_id, title, file_path, source_key, mime_type, size_bytes, curso_id, class_date, topic, indexed_v2, indexed_at, error_message, created_at')
            ->where('created_by', (int) auth()->id())
            ->orderBy('id', 'DESC')
            ->limit(50);

        if ($pageId !== null) {
            $builder->where('page_id', $pageId);
        }

        $rows = $builder->get()->getResultArray();

        return $this->ok(array_map(fn (array $row): array => $this->formatDocument($row), $rows));
    }

    public function create(): ResponseInterface
    {
        $user = auth()->user();
        $file = $this->request->getFile('file');

        if (! service('throttler')->check('upload-' . $user->id, self::MAX_UPLOADS_PER_MINUTE, MINUTE)) {
            return $this->error('Subiste muchos archivos seguidos. Espera un minuto e intenta de nuevo.', 429);
        }

        if ($file === null) {
            return $this->error('Adjunta un archivo para indexar.', 422);
        }

        try {
            if (! $file->isValid()) {
                return $this->error('El archivo no llegó completo. Intenta de nuevo.', 422);
            }
        } catch (Throwable $error) {
            return $this->error('No se pudo leer el archivo.', 422);
        }

        $maxMb = max(1, min(100, (int) env('RAG_MAX_UPLOAD_MB', 20)));
        $size  = (int) $file->getSize();

        if ($size <= 0) {
            return $this->error('El archivo está vacío.', 422);
        }

        if ($size > $maxMb * 1024 * 1024) {
            return $this->error('El archivo supera el límite de ' . $maxMb . ' MB.', 422);
        }

        $original = $file->getClientName();
        $ext      = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

        if ($ext === '') {
            $ext = strtolower($file->getClientExtension());
        }

        if (! isset(self::ALLOWED[$ext])) {
            return $this->error('Formato no permitido. Usa PDF, DOCX, PPTX, JPG, PNG o ZIP.', 422);
        }

        $mime = strtolower((string) $file->getMimeType());

        if (! in_array($mime, self::ALLOWED[$ext], true)) {
            return $this->error('El contenido del archivo no coincide con su extensión .' . $ext . '.', 422);
        }

        $pageId = $this->positiveInt($this->request->getPost('page_id'));

        if ($pageId !== null) {
            try {
                $page = \Config\Database::connect()
                    ->table('pages')
                    ->where('id', $pageId)
                    ->where('deleted_at', null)
                    ->get()
                    ->getRowArray();

                if ($page === null) {
                    // En la demo de A todavía puede no existir la página de B:
                    // se indexa igual, sin asociación, en vez de romper el FK.
                    $pageId = null;
                } else {
                    $this->assertPageAccess($pageId, (int) $user->id, true);
                }
            } catch (\RuntimeException $error) {
                return $this->error($error->getMessage(), 403);
            }
        }

        $cursoId = max(1, min(999999, (int) ($this->request->getPost('curso_id') ?? 1)));
        $topic   = mb_substr(trim((string) ($this->request->getPost('topic') ?? '')), 0, 255);
        $title   = mb_substr(trim((string) ($this->request->getPost('title') ?? '')), 0, 255);
        $date    = trim((string) ($this->request->getPost('class_date') ?? ''));

        if ($title === '') {
            $title = mb_substr((string) pathinfo($original, PATHINFO_FILENAME), 0, 255);
        }

        if ($title === '') {
            $title = 'Documento ' . date('Y-m-d H:i');
        }

        if ($date !== '' && ! $this->validDate($date)) {
            return $this->error('La fecha de clase debe tener el formato YYYY-MM-DD.', 422);
        }

        $directory = WRITEPATH . 'uploads/rag';

        if (! is_dir($directory) && ! mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            return $this->error('No se pudo crear la carpeta de documentos.', 500);
        }

        // La extensión ya validada, no la que adivina finfo: Python despacha por ella.
        $stored = bin2hex(random_bytes(10)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);

        try {
            $file->move($directory, $stored);
        } catch (Throwable $error) {
            log_message('error', 'No se pudo mover el documento RAG: {error}', ['error' => $error->getMessage()]);

            return $this->error('No se pudo guardar el archivo en el servidor.', 500);
        }

        $relative = 'writable/uploads/rag/' . $stored;
        $db       = \Config\Database::connect();

        try {
            $db->table('documents')->insert([
                'page_id'      => $pageId,
                'title'        => $title,
                'file_path'    => $relative,
                'source_key'   => $relative,
                'mime_type'    => mb_substr($mime, 0, 120),
                'size_bytes'   => $size,
                'curso_id'     => $cursoId,
                'class_date'   => $date !== '' ? $date : null,
                'topic'        => $topic !== '' ? $topic : null,
                'created_by'   => (int) $user->id,
                'indexed_v2'   => false,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            $docId = (int) $db->insertID();
        } catch (Throwable $error) {
            log_message('error', 'No se pudo registrar el documento RAG: {error}', ['error' => $error->getMessage()]);
            $docId = 0;
        }

        if ($docId <= 0) {
            @unlink($directory . '/' . $stored);

            return $this->error('No se pudo registrar el documento en la base de datos.', 500);
        }

        // El ingest tarda: soltar el lock de la sesión (FileHandler) para no
        // bloquear el autosave del editor y no abortar a medias si cierran la pestaña.
        session_write_close();
        ignore_user_abort(true);
        @set_time_limit(240);

        try {
            $result = (new RagService())->ingest($docId, $relative, [
                'curso_id'   => $cursoId,
                'class_date' => $date,
                'topic'      => $topic,
            ]);

            $db->table('documents')->where('id', $docId)->update([
                'indexed_v2'    => true,
                'indexed_at'    => date('Y-m-d H:i:s'),
                'error_message' => null,
            ]);

            $row = $this->find($docId);

            return $this->ok(
                ($row !== null ? $this->formatDocument($row) : ['id' => $docId]) + ['chunks' => $result['chunks']],
                201,
            );
        } catch (Throwable $error) {
            // RagService ya devuelve un mensaje sin stderr; otros errores no se exponen.
            $message = $error instanceof \RuntimeException
                ? mb_substr($error->getMessage(), 0, 300)
                : 'No se pudo indexar el documento.';

            $db->table('documents')->where('id', $docId)->update([
                'indexed_v2'    => false,
                'indexed_at'    => null,
                'error_message' => $message,
            ]);

            log_message('warning', 'Ingest RAG falló para el documento {id}: {error}', [
                'id'    => $docId,
                'error' => $message,
            ]);

            // Un timeout puede llegar después del upsert: no dejar fragmentos sueltos.
            try {
                (new RagService())->delete($docId);
            } catch (Throwable) {
                // Qdrant caído: el reintento del usuario o el borrado los limpiará.
            }

            $row = $this->find($docId);

            return $this->error(
                'El archivo se guardó, pero no se pudo indexar: ' . $message,
                422,
                ['document' => $row !== null ? $this->formatDocument($row) : ['id' => $docId]],
            );
        }
    }

    public function delete(string $id): ResponseInterface
    {
        $docId = (int) $id;
        $db    = \Config\Database::connect();
        $row   = $db->table('documents')
            ->where('id', $docId)
            ->where('created_by', (int) auth()->id())
            ->get()
            ->getRowArray();

        if ($row === null) {
            return $this->error('Ese material ya no existe.', 404);
        }

        try {
            (new RagService())->delete($docId);
        } catch (Throwable $error) {
            log_message('error', 'No se pudo quitar el documento {id} de Qdrant: {error}', ['id' => $docId, 'error' => $error->getMessage()]);

            return $this->error('No se pudo quitar el material. Intenta de nuevo.', 502);
        }

        $db->table('documents')->where('id', $docId)->delete();
        @unlink(ROOTPATH . ltrim((string) $row['file_path'], '/'));

        return $this->ok(['id' => $docId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $docId): ?array
    {
        return \Config\Database::connect()
            ->table('documents')
            ->select('id, page_id, title, file_path, source_key, mime_type, size_bytes, curso_id, class_date, topic, indexed_v2, indexed_at, error_message, created_at')
            ->where('id', $docId)
            ->get()
            ->getRowArray();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function formatDocument(array $row): array
    {
        $filePath = (string) ($row['file_path'] ?? '');
        $indexed  = (bool) ($row['indexed_v2'] ?? false);
        $error    = trim((string) ($row['error_message'] ?? ''));

        return [
            'id'          => (int) ($row['id'] ?? 0),
            'page_id'     => isset($row['page_id']) && $row['page_id'] !== null ? (int) $row['page_id'] : null,
            'title'       => (string) ($row['title'] ?? 'Documento'),
            'file_name'   => $filePath !== '' ? basename($filePath) : null,
            'mime_type'   => $row['mime_type'] ?? null,
            'size_bytes'  => isset($row['size_bytes']) ? (int) $row['size_bytes'] : null,
            'size_label'  => nexo_bytes(isset($row['size_bytes']) ? (int) $row['size_bytes'] : null),
            'curso_id'    => isset($row['curso_id']) ? (int) $row['curso_id'] : null,
            'class_date'  => $row['class_date'] ?? null,
            'topic'       => $row['topic'] ?? null,
            'indexed'     => $indexed,
            'status'      => $indexed ? 'indexed' : ($error !== '' ? 'error' : 'pending'),
            'indexed_at'  => $row['indexed_at'] ?? null,
            'error'       => $error !== '' ? $error : null,
            'created_at'  => $row['created_at'] ?? null,
        ];
    }

    private function assertPageAccess(int $pageId, int $userId, bool $write = false): void
    {
        $db   = \Config\Database::connect();
        $page = $db->table('pages')
            ->where('id', $pageId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($page === null) {
            throw new \RuntimeException('La página no existe.');
        }

        $member = $db->table('workspace_members')
            ->select('role')
            ->where('workspace_id', (int) $page['workspace_id'])
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        if ($member === null) {
            throw new \RuntimeException('No tienes acceso a esta página.');
        }

        if ($write && ($member['role'] ?? '') === 'viewer') {
            throw new \RuntimeException('Tu rol en este espacio es de solo lectura.');
        }
    }

    private function validDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }
}

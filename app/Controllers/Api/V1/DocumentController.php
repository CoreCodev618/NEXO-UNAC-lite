<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\RagService;
use App\Libraries\TutorNotes;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Documentos del RAG.
 *
 * GET  /api/v1/documents       lista el material indexado
 * POST /api/v1/documents       multipart: file, title, topic, class_date, curso_id, page_id
 *
 * POST /api/v1/documents/{id}/ingest   lee e indexa (reintentable)
 * POST /api/v1/documents/{id}/summary  el tutor escribe el apunte resumen
 *
 * El archivo se guarda en writable/uploads/rag y se indexa aparte con
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

    /** El resumen del tutor es el apunte «Resumen: …» ligado al documento. */
    private const COLUMNS = 'id, page_id, title, file_path, source_key, mime_type, size_bytes, curso_id, class_date, topic,'
        . ' indexed_v2, indexed_at, error_message, created_at, created_by,'
        . " (SELECT MIN(p.id) FROM pages p WHERE p.document_id = documents.id AND p.deleted_at IS NULL AND p.title LIKE 'Resumen:%') AS summary_page_id";

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
            ->select(self::COLUMNS, false)
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
                    // Apunte borrado o inexistente: se indexa sin asociación en vez de romper el FK.
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

        // Se indexa en otra petición (POST documents/{id}/ingest): así la UI sabe
        // el id para mostrar el progreso y poder cancelar.
        $row = $this->find($docId);

        return $this->ok($row !== null ? $this->formatDocument($row) : ['id' => $docId], 201);
    }

    /**
     * Lee el archivo (texto u OCR), lo trocea y lo sube a Qdrant. Reintentable.
     */
    public function ingest(string $id): ResponseInterface
    {
        $row = $this->owned((int) $id);

        if ($row === null) {
            return $this->error('Ese material ya no existe.', 404);
        }

        $docId = (int) $row['id'];
        $db    = \Config\Database::connect();

        // El ingest tarda: soltar el lock de la sesión para no bloquear el resto
        // de la app. Si cancelan, la UI borra el documento (delete()).
        session_write_close();
        @set_time_limit(240);

        try {
            $result = (new RagService())->ingest($docId, (string) $row['file_path'], [
                'curso_id'   => (int) ($row['curso_id'] ?? 1),
                'class_date' => (string) ($row['class_date'] ?? ''),
                'topic'      => (string) ($row['topic'] ?? ''),
            ]);

            // Cancelado mientras se leía: la UI ya borró el documento; quitar lo subido a Qdrant.
            if ($this->find($docId) === null) {
                (new RagService())->delete($docId);

                return $this->error('La subida se canceló.', 410);
            }

            $db->table('documents')->where('id', $docId)->update([
                'indexed_v2'    => true,
                'indexed_at'    => date('Y-m-d H:i:s'),
                'error_message' => null,
            ]);

            return $this->ok($this->formatDocument($this->find($docId) ?? $row) + ['chunks' => $result['chunks']]);
        } catch (Throwable $error) {
            // RagService ya devuelve un mensaje sin stderr; otros errores no se exponen.
            $message = $error instanceof \RuntimeException
                ? mb_substr($error->getMessage(), 0, 300)
                : 'No se pudo leer el archivo.';

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

            return $this->error($message, 422, ['document' => $this->formatDocument($this->find($docId) ?? $row)]);
        }
    }

    /**
     * El tutor escribe el apunte «Resumen: <archivo>» (o devuelve el que ya existe).
     */
    public function summary(string $id): ResponseInterface
    {
        $row = $this->owned((int) $id);

        if ($row === null || ! (bool) $row['indexed_v2']) {
            return $this->error('Ese material todavía no está listo.', 422);
        }

        $noteId = $row['summary_page_id'] !== null ? (int) $row['summary_page_id'] : null;

        if ($noteId === null) {
            session_write_close();

            try {
                $noteId = (new TutorNotes())->fromDocument((int) auth()->id(), (int) $row['id'], (string) $row['title']);
            } catch (Throwable $error) {
                log_message('error', 'No se pudo crear el resumen del documento {id}: {error}', [
                    'id'    => $row['id'],
                    'error' => $error->getMessage(),
                ]);

                return $this->error('El tutor no pudo escribir el resumen ahora. Intenta de nuevo en unos segundos.', 503);
            }
        }

        return $this->ok(['id' => $noteId, 'url' => base_url('pages/' . $noteId)], 201);
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
            ->select(self::COLUMNS, false)
            ->where('id', $docId)
            ->get()
            ->getRowArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function owned(int $docId): ?array
    {
        $row = $this->find($docId);

        return $row !== null && (int) $row['created_by'] === (int) auth()->id() ? $row : null;
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
            'summary_id'  => isset($row['summary_page_id']) ? (int) $row['summary_page_id'] : null,
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
            throw new \RuntimeException('El apunte no existe.');
        }

        $member = $db->table('workspace_members')
            ->select('role')
            ->where('workspace_id', (int) $page['workspace_id'])
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        if ($member === null) {
            throw new \RuntimeException('No tienes acceso a este apunte.');
        }

        if ($write && ($member['role'] ?? '') === 'viewer') {
            throw new \RuntimeException('No puedes editar este apunte.');
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

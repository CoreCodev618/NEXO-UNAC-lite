<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;

/**
 * Puente PHP -> scripts Python del RAG.
 *
 * - `retrieve()` llama `python/retrieve.py --json --doc-ids` y normaliza los hits.
 * - `ingest()` llama `python/ingest.py --doc-id` de forma síncrona.
 * - `citations()` convierte `doc_id` de Qdrant en títulos/URLs de MariaDB.
 *
 * MySQL es la fuente de verdad: un fragmento cuyo `doc_id` no es del usuario
 * en `documents` nunca llega al tutor.
 */
final class RagService
{
    private PythonRunner $runner;

    private int $topK;

    private float $minScore;

    private int $ingestTimeout;

    public function __construct(?PythonRunner $runner = null)
    {
        $this->runner        = $runner ?? new PythonRunner();
        $this->topK          = max(1, min(10, (int) env('RAG_TOP_K', 5)));
        $this->minScore      = (float) env('RAG_MIN_SCORE', 0);
        $this->ingestTimeout = max(30, min(600, (int) env('RAG_TIMEOUT', 180)));
    }

    /**
     * Fragmentos más parecidos a $query, solo de los documentos de $documents.
     *
     * @param array<int, string> $documents doc_id => título (ver documentsFor())
     * @param float|null          $minScore  null = RAG_MIN_SCORE; 0 = sin umbral
     *
     * @return list<array<string, mixed>>
     */
    public function retrieve(string $query, array $documents, ?int $topK = null, ?float $minScore = null): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new RuntimeException('La consulta del RAG no puede estar vacía.');
        }

        if ($documents === []) {
            return [];
        }

        $args = [
            'python/retrieve.py',
            '--json',
            '--top',
            (string) max(1, min(10, $topK ?? $this->topK)),
            '--doc-ids',
            implode(',', array_keys($documents)),
        ];

        $minScore ??= $this->minScore;
        if ($minScore > 0) {
            $args[] = '--min-score';
            $args[] = (string) $minScore;
        }

        // `--` evita que una consulta que empiece con "-" se lea como opción.
        $args[] = '--';
        $args[] = $query;

        $result = $this->runner->run($args, $this->retrieveTimeout());

        if (! $result['ok']) {
            throw new RuntimeException($this->failureMessage($result, 'No se pudo consultar el material.'));
        }

        $decoded = json_decode($result['stdout'], true);

        if (! is_array($decoded)) {
            throw new RuntimeException('El retrieval devolvió una respuesta que no es JSON.');
        }

        $hits = [];
        foreach ($decoded as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $normalized = $this->normalizeHit($hit);
            // Qdrant ya filtra por doc_id; esto es la segunda barrera (fail-closed).
            if ($normalized !== null && isset($documents[$normalized['doc_id']])) {
                $normalized['title'] = $documents[$normalized['doc_id']];
                $hits[]              = $normalized;
            }
        }

        return $hits;
    }

    /**
     * Ingesta un archivo ya guardado. El `doc_id` debe ser el `documents.id`.
     *
     * @param array{curso_id?: int, class_date?: string, topic?: string} $meta
     *
     * @return array{chunks: int, output: string}
     */
    public function ingest(int $docId, string $path, array $meta = []): array
    {
        if ($docId <= 0) {
            throw new RuntimeException('El documento no tiene un id válido para indexar.');
        }

        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('El documento no tiene una ruta para indexar.');
        }

        $cursoId = max(1, (int) ($meta['curso_id'] ?? 1));
        $args    = [
            'python/ingest.py',
            $path,
            '--doc-id',
            (string) $docId,
            '--curso-id',
            (string) $cursoId,
        ];

        $date = trim((string) ($meta['class_date'] ?? ''));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $args[] = '--class-date';
            $args[] = $date;
        }

        $topic = trim((string) ($meta['topic'] ?? ''));
        if ($topic !== '') {
            $args[] = '--topic=' . $topic;
        }

        $result = $this->runner->run($args, $this->ingestTimeout);

        if (! $result['ok']) {
            throw new RuntimeException($this->failureMessage($result, 'No se pudo leer el archivo.'));
        }

        $chunks = 0;
        if (preg_match('/Total:\s+(\d+)\s+chunks/i', $result['stdout'], $match) === 1) {
            $chunks = (int) $match[1];
        }

        return [
            'chunks' => $chunks,
            'output' => $result['stdout'],
        ];
    }

    /**
     * Quita de Qdrant los fragmentos de un documento.
     */
    public function delete(int $docId): void
    {
        $result = $this->runner->run(['python/delete.py', (string) $docId], 30);

        if (! $result['ok']) {
            throw new RuntimeException($this->failureMessage($result, 'No se pudo quitar el material del tutor.'));
        }
    }

    /**
     * Documentos indexados que $userId subió: el tutor solo consulta estos.
     *
     * @return array<int, string> doc_id => título
     */
    public function documentsFor(int $userId): array
    {
        $rows = \Config\Database::connect()
            ->table('documents')
            ->select('id, title')
            ->where('created_by', $userId)
            ->where('indexed_v2', true)
            ->get()
            ->getResultArray();

        return array_column($rows, 'title', 'id');
    }

    /**
     * Convierte hits de Qdrant en fuentes para el chat.
     *
     * @param list<array<string, mixed>> $hits
     *
     * @return list<array<string, mixed>>
     */
    public function citations(array $hits): array
    {
        if ($hits === []) {
            return [];
        }

        $hits = $this->uniqueHits($hits);
        $rows = $this->documentsById($hits);
        $out  = [];

        foreach ($hits as $hit) {
            $docId = (int) ($hit['doc_id'] ?? 0);
            $row   = $rows[$docId] ?? null;

            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                $title = (string) ($hit['title'] ?? 'Material del curso');
            }

            $pageId = (int) ($row['page_id'] ?? 0);
            $source = (string) ($row['file_path'] ?? $hit['source'] ?? '');

            $out[] = [
                'doc_id'    => $docId,
                'title'     => $title,
                'page_id'   => $pageId > 0 ? $pageId : null,
                'url'       => $pageId > 0 ? $this->pageUrl($pageId) : null,
                'source'    => $source !== '' ? basename($source) : null,
                'location'  => $hit['location'] ?? null,
                'topic'     => $hit['topic'] ?? null,
                'score'     => $hit['score'] ?? null,
                'snippet'   => $hit['snippet'] ?? null,
                'chunk_key' => $hit['chunk_key'] ?? null,
            ];
        }

        return array_slice($out, 0, 8);
    }

    /**
     * Quita citas [n] que no apuntan a una fuente devuelta: el modelo a veces
     * numera el apunte abierto, que no es material citable.
     */
    public function stripUnknownCitations(string $reply, int $sources): string
    {
        $reply = preg_replace_callback(
            '/[ \t]*\[(\d+)\]/',
            static fn (array $m): string => (int) $m[1] >= 1 && (int) $m[1] <= $sources ? $m[0] : '',
            $reply,
        ) ?? $reply;

        return trim(preg_replace('/[ \t]+([.,;:])/', '$1', $reply) ?? $reply);
    }

    /**
     * Contexto acotado para el prompt: página actual + fragmentos RAG.
     *
     * @param list<array<string, mixed>> $hits
     * @param array{title?: string, text?: string}|null $pageContext
     */
    public function buildContext(array $hits, ?array $pageContext = null, int $maxChars = 6000): string
    {
        $parts = [];

        $pageTitle = trim((string) ($pageContext['title'] ?? ''));
        $pageText  = trim((string) ($pageContext['text'] ?? ''));

        if ($pageText !== '') {
            $parts[] = "APUNTE ABIERTO (sin número, no se cita)" . ($pageTitle !== '' ? ': ' . $pageTitle : '') . "\n"
                . mb_substr($pageText, 0, 2200);
        }

        foreach (array_values($this->uniqueHits($hits)) as $index => $hit) {
            $number = $index + 1;
            $title  = trim((string) ($hit['title'] ?? 'Material'));
            $where  = trim((string) ($hit['location'] ?? ''));
            $text   = trim((string) ($hit['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $header = '[' . $number . '] ' . $title . ($where !== '' ? ' · ' . $where : '');
            $parts[] = $header . "\n" . mb_substr($text, 0, 1200);
        }

        $context = implode("\n\n", $parts);

        return mb_substr($context, 0, $maxChars);
    }

    /**
     * Un fragmento por documento/fuente. El primero conserva el mejor score
     * porque `retrieve.py` ya devuelve los hits ordenados.
     *
     * @param list<array<string, mixed>> $hits
     *
     * @return list<array<string, mixed>>
     */
    private function uniqueHits(array $hits): array
    {
        $seen = [];
        $out  = [];

        foreach ($hits as $hit) {
            // Un fragmento por clave: varios fragmentos del mismo archivo sí cuentan.
            $key = (string) ($hit['chunk_key'] ?? '') !== ''
                ? 'chunk:' . $hit['chunk_key']
                : 'text:' . md5((string) ($hit['text'] ?? ''));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[]      = $hit;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $hit
     *
     * @return array<string, mixed>|null
     */
    private function normalizeHit(array $hit): ?array
    {
        $payload = is_array($hit['payload'] ?? null) ? $hit['payload'] : [];
        $text    = trim((string) ($payload['text'] ?? ''));

        if ($text === '') {
            return null;
        }

        $source = trim((string) ($payload['source'] ?? $payload['file_path'] ?? ''));
        $title  = $source !== '' ? pathinfo(basename($source), PATHINFO_FILENAME) : 'Material del curso';

        return [
            'score'     => round((float) ($hit['score'] ?? 0.0), 6),
            'doc_id'    => (int) ($payload['doc_id'] ?? 0),
            'title'     => $title,
            'source'    => $source,
            'location'  => trim((string) ($payload['location'] ?? '')),
            'topic'     => trim((string) ($payload['topic'] ?? '')),
            'chunk_key' => trim((string) ($payload['chunk_key'] ?? '')),
            'text'      => $text,
            'snippet'   => mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 240),
        ];
    }

    /**
     * @param list<array<string, mixed>> $hits
     *
     * @return array<int, array<string, mixed>>
     */
    private function documentsById(array $hits): array
    {
        $ids = [];

        foreach ($hits as $hit) {
            $id = (int) ($hit['doc_id'] ?? 0);
            // La columna documents.id es INT UNSIGNED; los doc_id de material
            // histórico de Qdrant son hashes de 60 bits y no aplican.
            if ($id > 0 && $id <= 4_294_967_295) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        try {
            $rows = \Config\Database::connect()
                ->table('documents')
                ->select('id, title, file_path, page_id, curso_id, class_date, topic, created_by')
                ->whereIn('id', $ids)
                ->get()
                ->getResultArray();
        } catch (\Throwable $error) {
            log_message('warning', 'No se pudieron leer documents para citas: {error}', [
                'error' => $error->getMessage(),
            ]);

            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }

        return $map;
    }

    private function retrieveTimeout(): int
    {
        return max(10, min(120, (int) env('RAG_RETRIEVE_TIMEOUT', 30)));
    }

    /**
     * @param array{ok: bool, exit: int, stdout: string, stderr: string, timed_out: bool} $result
     */
    private function failureMessage(array $result, string $prefix): string
    {
        if ($result['timed_out']) {
            return $prefix . ' Tardó demasiado: intenta de nuevo o con un archivo más pequeño.';
        }

        // El stderr de Python puede traer URLs de Qdrant, rutas locales o errores
        // de Gemini: va al log, nunca al cliente.
        log_message('error', '{prefix} stderr: {stderr}', [
            'prefix' => $prefix,
            'stderr' => mb_substr(trim($result['stderr']), -1000),
        ]);

        return $prefix . ' Intenta de nuevo en unos segundos.';
    }

    private function pageUrl(int $pageId): string
    {
        $base = rtrim((string) config('App')->baseURL, '/');

        return $base . '/pages/' . $pageId;
    }
}

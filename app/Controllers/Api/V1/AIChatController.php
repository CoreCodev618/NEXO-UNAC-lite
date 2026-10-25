<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\GroqService;
use App\Libraries\RagService;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/**
 * Tutor NEXO: RAG sobre Qdrant + Groq, con memoria por sesión.
 *
 * Respuesta:
 * {session_id, reply, sources[], model, page_id, degraded}
 * donde cada source es {doc_id, title, page_id, url, source, location, topic,
 * score, snippet, chunk_key}.
 */
final class AIChatController extends BaseApiController
{
    private const MAX_MESSAGES_PER_MINUTE = 10;

    public function send(): ResponseInterface
    {
        $payload = $this->payload();
        $message = is_string($payload['message'] ?? null) ? trim($payload['message']) : '';

        if ($message === '') {
            return $this->error('Escribe una pregunta para el tutor.', 422);
        }

        if (mb_strlen($message) > 1200) {
            return $this->error('La pregunta es demasiado larga (máximo 1200 caracteres).', 422);
        }

        $user      = auth()->user();
        $userId    = (int) $user->id;
        $pageId    = $this->positiveInt($payload['page_id'] ?? null);
        $sessionId = $this->positiveInt($payload['session_id'] ?? null);

        try {
            $pageContext = $pageId !== null ? $this->pageContext($pageId, $userId) : null;
        } catch (RuntimeException $error) {
            return $this->error($error->getMessage(), 403);
        } catch (\Throwable $error) {
            log_message('warning', 'No se pudo leer el contexto de la página {page}: {error}', [
                'page'  => $pageId,
                'error' => $error->getMessage(),
            ]);
            $pageContext = null;
        }

        if ($this->recentMessages($userId) >= self::MAX_MESSAGES_PER_MINUTE) {
            return $this->error('Estás preguntando muy rápido. Espera unos segundos e intenta de nuevo.', 429);
        }

        try {
            $session = $this->resolveSession($sessionId, $userId, $pageId, $message);
        } catch (RuntimeException $error) {
            // 422 y no 404: el front trata 404 como "tutor no disponible".
            return $this->error($error->getMessage(), 422);
        } catch (\Throwable $error) {
            log_message('error', 'No se pudo crear la sesión de chat: {error}', ['error' => $error->getMessage()]);

            return $this->error('No se pudo iniciar la conversación. Revisa la base de datos.', 500);
        }

        // Retrieval + Groq pueden tardar decenas de segundos: soltar el lock de la
        // sesión para no bloquear el resto de llamadas del usuario.
        session_write_close();

        $rag       = new RagService();
        $hits      = [];
        $ragError  = null;

        try {
            $hits = $rag->filterAccessible($rag->retrieve($message), $userId);
        } catch (\Throwable $error) {
            $ragError = $error->getMessage();
            log_message('warning', 'RAG retrieval falló: {error}', ['error' => $ragError]);
        }

        $citations = $rag->citations($hits);
        $context   = $rag->buildContext($hits, $pageContext);
        $model     = null;

        if ($context === '') {
            $reply = $ragError !== null
                ? 'No pude consultar el material en este momento. Revisa que Qdrant y las claves del RAG estén activas, o vuelve a intentar en unos segundos.'
                : 'No encontré material indexado para responder eso. Sube el PDF, DOCX o PPTX de la clase en «Material para el tutor» y vuelve a preguntar.';
        } else {
            try {
                $answer = (new GroqService())->chat(
                    $this->prompt($message, $context, $ragError !== null, (int) $session['id']),
                );
                $reply  = $answer['content'];
                $model  = $answer['model'];
            } catch (\Throwable $error) {
                log_message('error', 'Groq no respondió: {error}', ['error' => $error->getMessage()]);

                return $this->error(
                    'El tutor no está disponible en este momento. Intenta de nuevo en unos segundos.',
                    503,
                );
            }
        }

        $this->saveExchange((int) $session['id'], $message, $reply);

        return $this->ok([
            'session_id' => (int) $session['id'],
            'reply'      => $reply,
            'sources'    => $citations,
            'model'      => $model,
            'page_id'    => $pageId,
            'degraded'   => $ragError !== null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSession(?int $sessionId, int $userId, ?int $pageId, string $message): array
    {
        $db = \Config\Database::connect();

        if ($sessionId !== null) {
            $session = $db->table('chat_sessions')
                ->where('id', $sessionId)
                ->where('user_id', $userId)
                ->get()
                ->getRowArray();

            if ($session === null) {
                throw new RuntimeException('La conversación no existe o no te pertenece.');
            }

            return $session;
        }

        // La FK de chat_sessions.page_id exige una página real: si no existe, sin página.
        if ($pageId !== null && ! $this->pageExists($pageId)) {
            $pageId = null;
        }

        $db->table('chat_sessions')->insert([
            'user_id'    => $userId,
            'page_id'    => $pageId,
            'title'      => mb_substr($message, 0, 120),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $id = (int) $db->insertID();

        if ($id <= 0) {
            throw new RuntimeException('No se pudo crear la conversación.');
        }

        return [
            'id'      => $id,
            'user_id' => $userId,
            'page_id' => $pageId,
        ];
    }

    /**
     * Contexto de la página que el usuario tiene abierta.
     *
     * @return array{title: string, text: string}|null
     */
    private function pageContext(int $pageId, int $userId): ?array
    {
        try {
            $db   = \Config\Database::connect();
            $page = $db->table('pages')
                ->where('id', $pageId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();

            if ($page !== null) {
                $member = $db->table('workspace_members')
                    ->where('workspace_id', (int) $page['workspace_id'])
                    ->where('user_id', $userId)
                    ->countAllResults();

                if ($member === 0) {
                    throw new RuntimeException('No tienes acceso al contexto de esta página.');
                }

                $blocks = $db->table('blocks')
                    ->where('page_id', $pageId)
                    ->orderBy('order_index', 'ASC')
                    ->get()
                    ->getResultArray();

                return [
                    'title' => (string) ($page['title'] ?? 'Página'),
                    'text'  => $this->blocksText($blocks),
                ];
            }
        } catch (RuntimeException $error) {
            throw $error;
        } catch (\Throwable $error) {
            log_message('warning', 'Contexto de página desde MariaDB falló: {error}', ['error' => $error->getMessage()]);
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function blocksText(array $blocks): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $content = $block['content'] ?? [];
            if (is_string($content)) {
                $decoded = json_decode($content, true);
                $content = is_array($decoded) ? $decoded : [];
            }

            if (! is_array($content)) {
                continue;
            }

            $chunk = [];

            foreach (['title', 'text'] as $field) {
                if (isset($content[$field]) && is_scalar($content[$field])) {
                    $chunk[] = trim((string) $content[$field]);
                }
            }

            if (isset($content['code']) && is_scalar($content['code'])) {
                $chunk[] = 'Código: ' . trim((string) $content['code']);
            }

            if (isset($content['items']) && is_array($content['items'])) {
                foreach ($content['items'] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $text = trim((string) ($item['text'] ?? ''));
                    if ($text !== '') {
                        $chunk[] = '- ' . $text . (! empty($item['checked']) ? ' (hecho)' : '');
                    }
                }
            }

            if (isset($content['headers']) && is_array($content['headers'])) {
                $chunk[] = implode(' | ', array_map('strval', $content['headers']));
            }

            if (isset($content['rows']) && is_array($content['rows'])) {
                foreach ($content['rows'] as $row) {
                    if (is_array($row)) {
                        $chunk[] = implode(' | ', array_map('strval', $row));
                    }
                }
            }

            if (isset($content['cite']) && is_scalar($content['cite'])) {
                $chunk[] = '— ' . trim((string) $content['cite']);
            }

            $chunk = array_values(array_filter(array_map('trim', $chunk), static fn (string $line): bool => $line !== ''));
            if ($chunk !== []) {
                $parts[] = implode("\n", $chunk);
            }
        }

        return mb_substr(trim(implode("\n\n", $parts)), 0, 4000);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function prompt(string $message, string $context, bool $degraded, int $sessionId): array
    {
        $system = <<<'PROMPT'
Eres el Tutor NEXO de la Universidad Nacional del Callao. Ayudas a estudiantes de FIIS a estudiar con el material de sus cursos.

Reglas obligatorias:
1. Responde en español, claro y breve (máximo 180 palabras).
2. Usa únicamente el CONTEXTO. No uses conocimiento externo para afirmar hechos del curso.
3. Cuando uses un fragmento del material, cita su número con corchetes ASCII: [1], [2], etc. Nunca uses 【】.
4. Escribe texto plano: no uses Markdown (nada de **, ##, tablas ni listas con asteriscos). Separa las ideas con saltos de línea.
5. Si el contexto responde solo una parte, responde con lo que sí dice y aclara qué falta. Solo si no hay nada relacionado, responde exactamente: "Con la información disponible no puedo confirmar eso." y sugiere subir el material que falta.
6. No inventes fuentes, fechas, eventos, enlaces ni nombres de archivos.
7. La PÁGINA ACTUAL es el apunte que el estudiante está editando; puedes resumirla o explicarla sin citarla como fuente externa.
PROMPT;

        if ($degraded) {
            $system .= "\n8. El retrieval de material falló en esta petición: no cites fragmentos externos.";
        }

        $messages = [
            ['role' => 'system', 'content' => $system],
        ];

        foreach ($this->history($sessionId) as $row) {
            $messages[] = $row;
        }

        $messages[] = [
            'role'    => 'user',
            'content' => "CONTEXTO:\n" . $context . "\n\nPREGUNTA:\n" . $message,
        ];

        return $messages;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function history(int $sessionId): array
    {
        if ($sessionId <= 0) {
            return [];
        }

        $rows = \Config\Database::connect()
            ->table('chat_messages')
            ->select('role, content')
            ->where('session_id', $sessionId)
            ->orderBy('id', 'DESC')
            ->limit(6)
            ->get()
            ->getResultArray();

        $rows = array_reverse($rows);
        $out  = [];

        foreach ($rows as $row) {
            $role = (string) ($row['role'] ?? '');
            if (! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $content = trim((string) ($row['content'] ?? ''));
            if ($content !== '') {
                $out[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
            }
        }

        return $out;
    }

    private function saveExchange(int $sessionId, string $userMessage, string $reply): void
    {
        try {
            $db  = \Config\Database::connect();
            $now = date('Y-m-d H:i:s');

            $db->transStart();
            $db->table('chat_messages')->insert([
                'session_id' => $sessionId,
                'role'       => 'user',
                'content'    => $userMessage,
                'created_at' => $now,
            ]);
            $db->table('chat_messages')->insert([
                'session_id' => $sessionId,
                'role'       => 'assistant',
                'content'    => $reply,
                'created_at' => $now,
            ]);
            $db->transComplete();
        } catch (\Throwable $error) {
            log_message('error', 'No se guardó el intercambio de chat: {error}', ['error' => $error->getMessage()]);
        }
    }

    private function recentMessages(int $userId): int
    {
        return \Config\Database::connect()
            ->table('chat_messages m')
            ->join('chat_sessions s', 's.id = m.session_id')
            ->where('s.user_id', $userId)
            ->where('m.role', 'user')
            ->where('m.created_at >=', date('Y-m-d H:i:s', time() - 60))
            ->countAllResults();
    }

    private function pageExists(int $pageId): bool
    {
        return \Config\Database::connect()
            ->table('pages')
            ->where('id', $pageId)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $number = (int) $value;

            return $number > 0 ? $number : null;
        }

        return null;
    }
}

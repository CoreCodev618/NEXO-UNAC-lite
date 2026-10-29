<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * El tutor escribe apuntes: un resumen al subir cada archivo y, desde el chat,
 * una respuesta guardada. Todos quedan etiquetados con su archivo de origen.
 */
final class TutorNotes
{
    private const SUMMARY_PROMPT = <<<'PROMPT'
Eres el Tutor NEXO. Escribe un apunte de estudio en español a partir del MATERIAL.

Formato obligatorio (texto plano, sin Markdown, sin asteriscos ni #):
- Primera línea: de qué trata el material, en una o dos frases.
- Luego, una línea por cada concepto, fórmula o paso clave, empezando con "- ".
- Máximo 250 palabras. Usa solo el MATERIAL; no inventes datos ni cites números entre corchetes.
PROMPT;

    public function __construct(
        private readonly NexoRepository $repository = new NexoRepository(),
        private readonly RagService $rag = new RagService(),
    ) {
    }

    /**
     * Resume un archivo recién indexado. Devuelve el id del apunte creado.
     */
    public function fromDocument(int $userId, int $documentId, string $title): int
    {
        $hits    = $this->rag->retrieve('resumen general, temas y conceptos clave de ' . $title, [$documentId => $title], 8, 0.0);
        $context = $this->rag->buildContext($hits);

        if ($context === '') {
            throw new \RuntimeException('El archivo no tiene texto para resumir.');
        }

        $answer = (new GroqService())->chat([
            ['role' => 'system', 'content' => self::SUMMARY_PROMPT],
            ['role' => 'user', 'content' => "MATERIAL: {$title}\n\n{$context}"],
        ]);

        $text = $this->rag->stripUnknownCitations($answer['content'], 0);

        return $this->repository->createNote($userId, 'Resumen: ' . $title, self::lines($text), $documentId);
    }

    /**
     * Guarda una respuesta del chat. Solo etiqueta con archivos del propio usuario.
     */
    public function fromReply(int $userId, string $title, string $text, ?int $documentId): int
    {
        if ($documentId !== null && ! isset($this->rag->documentsFor($userId)[$documentId])) {
            $documentId = null;
        }

        return $this->repository->createNote($userId, $title, self::lines($text), $documentId);
    }

    /**
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R+/', $text) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;

/**
 * Cliente mínimo de Groq (API compatible con OpenAI).
 *
 * Intenta el modelo principal y, si falla por red, cuota o modelo retirado,
 * repite con el fallback. La API key nunca se incluye en logs ni mensajes.
 */
final class GroqService
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    private string $apiKey;

    private string $model;

    private string $fallbackModel;

    private int $timeout;

    public function __construct()
    {
        $this->apiKey        = trim((string) env('GROQ_API_KEY', ''));
        $this->model         = trim((string) env('GROQ_MODEL', 'openai/gpt-oss-120b')) ?: 'openai/gpt-oss-120b';
        $this->fallbackModel = trim((string) env('GROQ_FALLBACK_MODEL', 'openai/gpt-oss-20b')) ?: 'openai/gpt-oss-20b';
        $this->timeout       = max(10, min(120, (int) env('GROQ_TIMEOUT', 45)));
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array{temperature?: float, max_tokens?: int} $options
     *
     * @return array{content: string, model: string, usage: array<string, mixed>}
     */
    public function chat(array $messages, array $options = []): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Falta GROQ_API_KEY en el .env del repo.');
        }

        if ($messages === []) {
            throw new RuntimeException('No hay mensajes para enviar al tutor.');
        }

        $models = array_values(array_unique(array_filter([$this->model, $this->fallbackModel])));
        $lastError = '';

        foreach ($models as $model) {
            try {
                return $this->request($model, $messages, $options);
            } catch (RuntimeException $error) {
                $lastError = $error->getMessage();
                log_message('warning', 'Groq falló con {model}: {error}', [
                    'model' => $model,
                    'error' => $lastError,
                ]);

                // 400/401/403 fallan igual con el otro modelo: no duplicar la espera.
                if (in_array($error->getCode(), [400, 401, 403], true)) {
                    break;
                }
            }
        }

        throw new RuntimeException('No se pudo obtener respuesta del tutor. ' . $lastError);
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array{temperature?: float, max_tokens?: int} $options
     *
     * @return array{content: string, model: string, usage: array<string, mixed>}
     */
    private function request(string $model, array $messages, array $options): array
    {
        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => (float) ($options['temperature'] ?? 0.2),
            'max_tokens'  => (int) ($options['max_tokens'] ?? 800),
            'top_p'       => 0.9,
            'stream'      => false,
        ];

        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('No se pudo preparar la petición al tutor.');
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        [$raw, $status, $curlError] = $this->post($encoded, $headers);

        if ($raw === false) {
            throw new RuntimeException('No se pudo conectar con Groq (' . $curlError . ').');
        }

        $data = json_decode($raw, true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            throw new RuntimeException('Groq HTTP ' . $status . ($message !== '' ? ': ' . $this->clean($message) : '.'), $status);
        }

        if (! is_array($data)) {
            throw new RuntimeException('Groq devolvió una respuesta que no es JSON.');
        }

        $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

        if ($content === '') {
            throw new RuntimeException('Groq devolvió una respuesta vacía.');
        }

        return [
            'content' => $content,
            'model'   => (string) ($data['model'] ?? $model),
            'usage'   => is_array($data['usage'] ?? null) ? $data['usage'] : [],
        ];
    }

    /**
     * @param list<string> $headers
     *
     * @return array{0: string|false, 1: int, 2: string}
     */
    private function post(string $body, array $headers): array
    {
        if (function_exists('curl_init')) {
            $handle = curl_init(self::ENDPOINT);

            curl_setopt_array($handle, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $raw    = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error  = (string) curl_error($handle);

            return [$raw, $status, $error !== '' ? $error : 'error de red'];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $raw    = @file_get_contents(self::ENDPOINT, false, $context);
        $status = 0;
        $headers = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($http_response_header ?? []);

        if (is_array($headers)) {
            foreach ($headers as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match) === 1) {
                    $status = (int) $match[1];
                }
            }
        }

        return [$raw, $status, $raw === false ? 'error de red' : ''];
    }

    private function clean(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? '';

        return mb_substr($message, 0, 300);
    }
}

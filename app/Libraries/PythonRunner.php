<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;

/**
 * Ejecuta los scripts Python del RAG sin shell intermedio.
 *
 * `proc_open` recibe el comando como array, así que rutas y consultas con
 * espacios o comillas no se interpretan como shell. El proceso corre desde la
 * raíz del repo para que `python/nexo/env.py` encuentre el `.env` y para que
 * las rutas relativas de `writable/uploads/rag` funcionen.
 */
final class PythonRunner
{
    private string $root;

    private string $binary;

    public function __construct(?string $binary = null, ?string $root = null)
    {
        $this->root = rtrim($root ?? (defined('ROOTPATH') ? ROOTPATH : dirname(__DIR__, 2) . '/'), '/');

        $configured = trim((string) ($binary ?? env('RAG_PYTHON_BIN', 'python/.venv/bin/python')));
        $configured = $configured !== '' ? $configured : 'python/.venv/bin/python';

        if (str_starts_with($configured, '/')) {
            $this->binary = $configured;
        } elseif (str_contains($configured, '/')) {
            $this->binary = $this->root . '/' . ltrim($configured, '/');
        } else {
            // Permite usar un Python del PATH (ej. "python3") en pruebas.
            $this->binary = $configured;
        }
    }

    /**
     * @param list<string> $args argumentos del script, empezando por la ruta .py
     *
     * @return array{ok: bool, exit: int, stdout: string, stderr: string, timed_out: bool, duration: float}
     */
    public function run(array $args, ?int $timeout = null): array
    {
        if ($this->binary === '' || (! str_contains($this->binary, '/') && ! $this->existsInPath($this->binary))) {
            throw new RuntimeException('No encuentro el intérprete de Python del RAG. Revisa RAG_PYTHON_BIN.');
        }

        if (str_contains($this->binary, '/') && (! is_file($this->binary) || ! is_executable($this->binary))) {
            throw new RuntimeException(
                'Falta el entorno Python del RAG. Crea python/.venv e instala python/requirements.txt.',
            );
        }

        $command = array_merge([$this->binary], array_map(static fn ($arg): string => (string) $arg, $args));
        $timeout = $timeout ?? max(10, (int) env('RAG_TIMEOUT', 180));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = getenv();
        $env = is_array($env) ? $env : [];
        $env['PYTHONUNBUFFERED'] = '1';
        $env['PYTHONIOENCODING'] = 'utf-8';

        $process = proc_open($command, $descriptors, $pipes, $this->root, $env);

        if (! is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el proceso Python del RAG.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout    = '';
        $stderr    = '';
        $started   = microtime(true);
        $timedOut  = false;
        $status    = proc_get_status($process);

        while ($status['running']) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if ((microtime(true) - $started) >= $timeout) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }

            usleep(50_000);
            $status = proc_get_status($process);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = $timedOut ? 137 : (int) ($status['exitcode'] ?? -1);
        $closed   = proc_close($process);

        if (! $timedOut && $exitCode < 0 && $closed >= 0) {
            $exitCode = $closed;
        }

        return [
            'ok'         => ! $timedOut && $exitCode === 0,
            'exit'       => $exitCode,
            'stdout'     => trim($stdout),
            'stderr'     => trim($stderr),
            'timed_out'  => $timedOut,
            'duration'   => round(microtime(true) - $started, 3),
        ];
    }

    private function existsInPath(string $binary): bool
    {
        $path = getenv('PATH');
        if (! is_string($path) || $path === '') {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory !== '' && is_executable($directory . DIRECTORY_SEPARATOR . $binary)) {
                return true;
            }
        }

        return false;
    }
}

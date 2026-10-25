<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Regla de validación: solo correos de ALLOWED_EMAIL_DOMAINS (.env, separados por coma).
 * Se aplica en registro y login vía Config\Auth::$emailValidationRules.
 */
class InstitucionalValidator
{
    public function institutional_email(?string $str, ?string &$error = null): bool
    {
        $domains = array_filter(array_map(
            static fn (string $d): string => strtolower(trim($d)),
            explode(',', (string) env('ALLOWED_EMAIL_DOMAINS', 'unac.edu.pe')),
        ));

        $domain = strtolower(substr((string) strrchr((string) $str, '@'), 1));

        if (in_array($domain, $domains, true)) {
            return true;
        }

        $error = 'Solo se permiten correos institucionales (@' . implode(', @', $domains) . ').';

        return false;
    }
}

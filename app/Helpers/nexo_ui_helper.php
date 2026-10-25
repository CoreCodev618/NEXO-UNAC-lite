<?php

declare(strict_types=1);

if (! function_exists('nexo_icon')) {
    /**
     * Devuelve un icono del sprite local.
     *
     * @param array<string, string> $attrs
     */
    function nexo_icon(string $name, string $class = 'nx-icon', array $attrs = []): string
    {
        $href  = base_url('icons/sprite.svg') . '#i-' . $name;
        $class = trim('nx-icon ' . $class);
        $out   = '<svg class="' . esc($class, 'attr') . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"';

        foreach ($attrs as $key => $value) {
            $out .= ' ' . esc($key, 'attr') . '="' . esc($value, 'attr') . '"';
        }

        return $out . '><use href="' . esc($href, 'attr') . '"></use></svg>';
    }
}

if (! function_exists('nexo_initials')) {
    function nexo_initials(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/u', $name) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
    }
}

if (! function_exists('nexo_workspace_type_label')) {
    function nexo_workspace_type_label(?string $type): string
    {
        return match ($type) {
            'EP-Sistemas'  => 'EP Sistemas',
            'EP-Industrial' => 'EP Industrial',
            'club'         => 'Club estudiantil',
            'tesis'        => 'Tesis',
            default        => 'Espacio',
        };
    }
}

if (! function_exists('nexo_workspace_type_full')) {
    function nexo_workspace_type_full(?string $type): string
    {
        return match ($type) {
            'EP-Sistemas'  => 'Escuela Profesional de Ingeniería de Sistemas',
            'EP-Industrial' => 'Escuela Profesional de Ingeniería Industrial',
            'club'         => 'Organización estudiantil',
            'tesis'        => 'Proyecto de tesis',
            default        => 'Espacio de trabajo',
        };
    }
}

if (! function_exists('nexo_workspace_icon')) {
    function nexo_workspace_icon(?string $type): string
    {
        return match ($type) {
            'EP-Sistemas'  => 'book-open',
            'EP-Industrial' => 'building',
            'club'         => 'sparkles',
            'tesis'        => 'compass',
            default        => 'folder',
        };
    }
}

if (! function_exists('nexo_workspace_tone')) {
    function nexo_workspace_tone(?string $type): string
    {
        return match ($type) {
            'EP-Sistemas'  => 'wine',
            'EP-Industrial' => 'gold',
            'club'         => 'sage',
            'tesis'        => 'rose',
            default        => 'paper',
        };
    }
}

if (! function_exists('nexo_role_label')) {
    function nexo_role_label(?string $role): string
    {
        return match ($role) {
            'owner'  => 'Propietario',
            'editor' => 'Editor',
            'viewer' => 'Lector',
            'admin'  => 'Administrador',
            default  => 'Miembro',
        };
    }
}

if (! function_exists('nexo_status_label')) {
    function nexo_status_label(?string $status): string
    {
        return match ($status) {
            'todo'  => 'Por hacer',
            'doing' => 'En curso',
            'done'  => 'Hecho',
            default => 'Sin estado',
        };
    }
}

if (! function_exists('nexo_priority_label')) {
    function nexo_priority_label(?string $priority): string
    {
        return match ($priority) {
            'alta'  => 'Alta',
            'media' => 'Media',
            'baja'  => 'Baja',
            default => 'Media',
        };
    }
}

if (! function_exists('nexo_date')) {
    /**
     * Formatea fechas de la base de datos sin fingir zonas horarias.
     */
    function nexo_date(?string $date, string $style = 'short'): string
    {
        if ($date === null || trim($date) === '') {
            return 'Sin fecha';
        }

        try {
            $value = new DateTimeImmutable($date, new DateTimeZone('America/Lima'));
        } catch (Throwable) {
            return 'Sin fecha';
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('America/Lima'));
        $day   = $value->setTime(0, 0);
        $diff  = (int) $today->diff($day)->format('%r%a');

        if ($style === 'long') {
            $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

            return $value->format('j') . ' de ' . $months[(int) $value->format('n') - 1] . ' de ' . $value->format('Y');
        }

        if ($diff === 0) {
            return 'Hoy';
        }

        if ($diff === 1) {
            return 'Mañana';
        }

        if ($diff === -1) {
            return 'Ayer';
        }

        if ($diff > 1 && $diff < 7) {
            $days = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

            return $days[(int) $value->format('w')] . ' ' . $value->format('j M');
        }

        if ($value->format('Y') === $today->format('Y')) {
            return $value->format('j M');
        }

        return $value->format('j M Y');
    }
}

if (! function_exists('nexo_datetime')) {
    function nexo_datetime(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'Sin actividad';
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('America/Lima'));
        } catch (Throwable) {
            return 'Sin actividad';
        }

        $now  = new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
        $diff = $now->getTimestamp() - $date->getTimestamp();

        if ($diff < 60) {
            return 'Hace un momento';
        }

        if ($diff < 3600) {
            $minutes = max(1, (int) floor($diff / 60));

            return 'Hace ' . $minutes . ' min';
        }

        if ($diff < 86400 && $date->format('Y-m-d') === $now->format('Y-m-d')) {
            return 'Hoy, ' . $date->format('H:i');
        }

        if ($diff < 172800) {
            return 'Ayer, ' . $date->format('H:i');
        }

        return $date->format('j M, H:i');
    }
}

if (! function_exists('nexo_bytes')) {
    function nexo_bytes(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return ($index === 0 ? (string) (int) $value : number_format($value, 1, ',', '.')) . ' ' . $units[$index];
    }
}

if (! function_exists('nexo_cover_class')) {
    function nexo_cover_class(?string $cover): string
    {
        $allowed = ['wine', 'gold', 'sage', 'ink', 'paper', 'rose', 'dark'];

        return in_array($cover, $allowed, true) ? 'nx-cover--' . $cover : 'nx-cover--rose';
    }
}

if (! function_exists('nexo_page_icon')) {
    function nexo_page_icon(?string $icon): string
    {
        $allowed = [
            'file-text', 'book-open', 'graduation-cap', 'code', 'table', 'check-square',
            'sparkles', 'compass', 'flask', 'lightbulb', 'folder', 'layers',
        ];

        return in_array($icon, $allowed, true) ? $icon : 'file-text';
    }
}

if (! function_exists('nexo_page_tree')) {
    /**
     * Renderiza el árbol de páginas del sidebar con profundidad visual.
     *
     * @param list<array<string, mixed>> $pages
     */
    function nexo_page_tree(array $pages, ?int $currentId, string $base, int $depth = 0): string
    {
        $html = '';

        foreach ($pages as $page) {
            $id       = (int) ($page['id'] ?? 0);
            $active   = $id === $currentId;
            $children = is_array($page['children'] ?? null) ? $page['children'] : [];
            $title    = (string) ($page['title'] ?? 'Sin título');
            $icon     = nexo_page_icon($page['icon'] ?? 'file-text');

            $html .= '<a class="nx-tree__link' . ($active ? ' is-active' : '') . '"'
                . ' style="--depth:' . $depth . '"'
                . ' href="' . esc($base . '/pages/' . $id, 'attr') . '"'
                . ($active ? ' aria-current="page"' : '') . '>'
                . nexo_icon($icon)
                . '<span>' . esc($title) . '</span>'
                . '</a>';

            if ($children !== []) {
                $html .= nexo_page_tree($children, $currentId, $base, $depth + 1);
            }
        }

        return $html;
    }
}

if (! function_exists('nexo_attachment_tone')) {
    function nexo_attachment_tone(?string $type): string
    {
        $type = strtoupper((string) $type);

        return match ($type) {
            'PDF'                   => 'gold',
            'DOC', 'DOCX'           => 'sage',
            'XLS', 'XLSX', 'CSV'    => 'sage',
            default                 => 'wine',
        };
    }
}

if (! function_exists('nexo_attachment_ext')) {
    function nexo_attachment_ext(string $name, ?string $type = null): string
    {
        if ($type !== null && trim($type) !== '') {
            return mb_strtoupper(mb_substr(trim($type), 0, 4));
        }

        $ext = pathinfo($name, PATHINFO_EXTENSION);

        return $ext !== '' ? mb_strtoupper($ext) : 'FILE';
    }
}

if (! function_exists('nexo_file_size')) {
    function nexo_file_size(?int $bytes): string
    {
        return nexo_bytes($bytes);
    }
}

if (! function_exists('nexo_url')) {
    function nexo_url(string $prefix, string $path = ''): string
    {
        $path = '/' . ltrim($path, '/');

        return base_url(trim($prefix, '/') . ($prefix !== '' ? $path : ltrim($path, '/')));
    }
}

if (! function_exists('nexo_month_name')) {
    function nexo_month_name(int $month, bool $short = false): string
    {
        $months = [
            1  => ['enero', 'ene'],
            2  => ['febrero', 'feb'],
            3  => ['marzo', 'mar'],
            4  => ['abril', 'abr'],
            5  => ['mayo', 'may'],
            6  => ['junio', 'jun'],
            7  => ['julio', 'jul'],
            8  => ['agosto', 'ago'],
            9  => ['septiembre', 'sep'],
            10 => ['octubre', 'oct'],
            11 => ['noviembre', 'nov'],
            12 => ['diciembre', 'dic'],
        ];

        $value = $months[$month] ?? $months[1];

        return $short ? $value[1] : $value[0];
    }
}

if (! function_exists('nexo_weekday_short')) {
    function nexo_weekday_short(int $weekday): string
    {
        return ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'][$weekday] ?? '';
    }
}

if (! function_exists('nexo_illustration')) {
    /**
     * Ilustraciones de línea para estados vacíos. Sin texto, sin emojis.
     */
    function nexo_illustration(string $name, string $class = 'nx-empty__art'): string
    {
        $open  = '<svg class="' . esc($class, 'attr') . '" viewBox="0 0 160 110" fill="none" aria-hidden="true" focusable="false">';
        $close = '</svg>';

        $art = match ($name) {
            'book' => '<path d="M20 30c14-6 28-6 42 0v56c-14-6-28-6-42 0V30Z" stroke="currentColor" stroke-width="1.5"/><path d="M98 30c-14-6-28-6-42 0v56c14-6 28-6 42 0V30Z" stroke="currentColor" stroke-width="1.5"/><path d="M62 30v56" stroke="currentColor" stroke-width="1.5"/><path d="M106 20v34l-8-6-8 6V20" stroke="var(--gold-2)" stroke-width="1.5"/><path d="M32 44h20M32 54h20M32 64h12M86 44h20M86 54h20M86 64h12" stroke="currentColor" stroke-width="1.3" opacity=".55"/>',
            'board' => '<rect x="18" y="22" width="34" height="66" rx="3" stroke="currentColor" stroke-width="1.5"/><rect x="63" y="22" width="34" height="46" rx="3" stroke="currentColor" stroke-width="1.5"/><rect x="108" y="22" width="34" height="56" rx="3" stroke="currentColor" stroke-width="1.5"/><path d="M26 34h18M26 44h13M71 34h18M71 44h14M116 34h18M116 44h12" stroke="currentColor" stroke-width="1.3" opacity=".55"/><path d="M71 56h18M116 56h18" stroke="var(--gold-2)" stroke-width="1.3"/>',
            'calendar' => '<rect x="20" y="26" width="120" height="66" rx="4" stroke="currentColor" stroke-width="1.5"/><path d="M20 44h120M46 18v16M114 18v16" stroke="currentColor" stroke-width="1.5"/><path d="M38 58h12M58 58h12M78 58h12M98 58h12M118 58h4M38 72h12M58 72h12" stroke="currentColor" stroke-width="1.3" opacity=".58"/><circle cx="124" cy="72" r="8" stroke="var(--gold-2)" stroke-width="1.5"/>',
            'folder' => '<path d="M20 34c0-5 3-8 8-8h32l10 12h62c5 0 8 3 8 8v42c0 5-3 8-8 8H28c-5 0-8-3-8-8V34Z" stroke="currentColor" stroke-width="1.5"/><path d="M20 50h120" stroke="currentColor" stroke-width="1.3" opacity=".55"/><path d="M44 70h36M44 82h52" stroke="var(--gold-2)" stroke-width="1.3"/><path d="M124 70v14M117 77h14" stroke="currentColor" stroke-width="1.3" opacity=".55"/>',
            'chat' => '<path d="M24 26h112v54H62l-22 16v-16H24V26Z" stroke="currentColor" stroke-width="1.5"/><path d="M42 44h76M42 56h58M42 68h34" stroke="currentColor" stroke-width="1.3" opacity=".58"/><path d="M112 18v20M102 28h20" stroke="var(--gold-2)" stroke-width="1.5"/>',
            'search' => '<circle cx="70" cy="52" r="30" stroke="currentColor" stroke-width="1.5"/><path d="M92 74l22 22" stroke="currentColor" stroke-width="1.5"/><path d="M58 52h24M70 40v24" stroke="var(--gold-2)" stroke-width="1.5"/>',
            'compass' => '<circle cx="80" cy="55" r="34" stroke="currentColor" stroke-width="1.5"/><path d="m92 43-7 19-17 7 7-19 17-7Z" stroke="currentColor" stroke-width="1.5"/><path d="M80 15v8M80 87v8M40 55h8M112 55h8" stroke="currentColor" stroke-width="1.3" opacity=".55"/>',
            'file' => '<path d="M44 18h48l24 24v52H44V18Z" stroke="currentColor" stroke-width="1.5"/><path d="M92 18v24h24" stroke="currentColor" stroke-width="1.5"/><path d="M58 56h44M58 68h44M58 80h28" stroke="currentColor" stroke-width="1.3" opacity=".58"/><path d="M110 12v18" stroke="var(--gold-2)" stroke-width="1.5"/>',
            'lock' => '<rect x="40" y="48" width="80" height="44" rx="5" stroke="currentColor" stroke-width="1.5"/><path d="M58 48V36a22 22 0 0 1 44 0v12" stroke="currentColor" stroke-width="1.5"/><circle cx="80" cy="68" r="4" stroke="var(--gold-2)" stroke-width="1.5"/><path d="M80 72v8" stroke="var(--gold-2)" stroke-width="1.5"/>',
            'spark' => '<path d="M80 18l7 23 23 7-23 7-7 23-7-23-23-7 23-7 7-23Z" stroke="currentColor" stroke-width="1.5"/><path d="M116 20l3 9 9 3-9 3-3 9-3-9-9-3 9-3 3-9ZM38 70l2 6 6 2-6 2-2 6-2-6-6-2 6-2 2-6Z" stroke="var(--gold-2)" stroke-width="1.4"/>',
            default => '<circle cx="80" cy="55" r="34" stroke="currentColor" stroke-width="1.5"/><path d="M62 55h36M80 37v36" stroke="currentColor" stroke-width="1.3" opacity=".55"/>',
        };

        return $open . $art . $close;
    }
}

if (! function_exists('nexo_is_active')) {
    function nexo_is_active(string $current, string $candidate): bool
    {
        $current   = '/' . trim($current, '/');
        $candidate = '/' . trim($candidate, '/');

        return $current === $candidate || ($candidate !== '/' && str_starts_with($current, $candidate . '/'));
    }
}

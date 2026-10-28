<?php
/**
 * Estructura compartida de las páginas de error de NEXO.
 *
 * @var string      $errorCode
 * @var string      $errorTitle
 * @var string      $errorText
 * @var string      $errorArt
 * @var string|null $errorSecondaryMode  back|reload
 * @var string      $errorSecondaryLabel
 */
$home       = base_url('/');
$secondary  = $errorSecondaryMode ?? null;
?>
<!doctype html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="oklch(38% 0.12 15)">
    <meta name="robots" content="noindex">
    <title><?= esc($errorTitle) ?> · NEXO UNAC</title>
    <link rel="icon" href="<?= base_url('favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= base_url('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= base_url('css/base.css') ?>">
    <link rel="stylesheet" href="<?= base_url('css/components.css') ?>">
    <link rel="stylesheet" href="<?= base_url('css/layout.css') ?>">
    <link rel="stylesheet" href="<?= base_url('css/pages.css') ?>">
</head>
<body class="nx-error-page">
    <main class="nx-error-card">
        <a class="nx-brand" href="<?= esc($home, 'attr') ?>" aria-label="NEXO UNAC, inicio" style="justify-content:center; margin-bottom: var(--space-6);">
            <span class="nx-brand__mark" aria-hidden="true">N</span>
            <span class="nx-brand__name">NEXO</span>
            <span class="nx-brand__sub">UNAC</span>
        </a>

        <p class="nx-error-code"><?= esc($errorCode) ?></p>
        <?= nexo_illustration($errorArt, 'nx-error-art') ?>
        <h1 class="nx-error-title"><?= esc($errorTitle) ?></h1>
        <p class="nx-error-text"><?= esc($errorText) ?></p>

        <div class="nx-error-actions">
            <a class="nx-btn nx-btn--primary" href="<?= esc($home, 'attr') ?>">
                <?= nexo_icon('arrow-left') ?>
                Volver a NEXO
            </a>

            <?php if ($secondary === 'back'): ?>
                <button class="nx-btn nx-btn--secondary" type="button" data-history-back>
                    <?= esc($errorSecondaryLabel ?? 'Volver a la página anterior') ?>
                </button>
            <?php elseif ($secondary === 'reload'): ?>
                <button class="nx-btn nx-btn--secondary" type="button" data-reload>
                    <?= esc($errorSecondaryLabel ?? 'Intentar de nuevo') ?>
                </button>
            <?php endif ?>
        </div>

        <p class="nx-hint" style="margin-top: var(--space-8);">
            Si el problema continúa, escribe a <a href="mailto:soporte@unac.edu.pe">soporte@unac.edu.pe</a>.
        </p>
    </main>

    <script>
        document.querySelector('[data-history-back]')?.addEventListener('click', function () {
            if (history.length > 1) {
                history.back();
            } else {
                window.location.href = '<?= esc($home, 'js') ?>';
            }
        });

        document.querySelector('[data-reload]')?.addEventListener('click', function () {
            window.location.reload();
        });
    </script>
</body>
</html>

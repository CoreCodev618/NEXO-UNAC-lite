<?php
/**
 * Layout de autenticación.
 */
$authHome = base_url();
?>
<!doctype html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#5c1f2b">
    <meta name="description" content="Entra a NEXO UNAC: tus apuntes y tu material de clase con un tutor.">
    <meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
    <meta name="csrf-header" content="<?= esc(csrf_header(), 'attr') ?>">

    <title><?= $this->renderSection('title') ?: 'NEXO UNAC' ?> · NEXO UNAC</title>

    <link rel="icon" href="<?= base_url('favicon.svg') ?>" type="image/svg+xml">
    <link rel="preload" href="<?= base_url('fonts/newsreader-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= base_url('fonts/public-sans-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= base_url('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= base_url('css/base.css') ?>">
    <link rel="stylesheet" href="<?= base_url('css/components.css') ?>">
    <link rel="stylesheet" href="<?= base_url('css/layout.css') ?>">
    <link rel="stylesheet" href="<?= base_url('css/auth.css') ?>">
</head>
<body
    class="nx-auth-body"
    data-base="<?= esc(base_url(), 'attr') ?>"
    data-api="<?= esc(rtrim(base_url('api/v1'), '/'), 'attr') ?>"
>
    <a class="nx-skip" href="#nx-auth-main">Saltar al formulario</a>

    <main class="nx-auth" id="nx-auth-main">
        <aside class="nx-auth__brand-panel" aria-label="NEXO UNAC">
            <a class="nx-auth__brand" href="<?= esc($authHome, 'attr') ?>" aria-label="NEXO UNAC, inicio">
                <span class="nx-brand__mark" aria-hidden="true">N</span>
                <span class="nx-brand__name">NEXO</span>
                <span class="nx-brand__sub">UNAC</span>
            </a>

            <h1 class="nx-auth__statement">
                Tus apuntes y tu material, <span class="nx-auth__highlight">en un solo lugar.</span>
            </h1>
            <p class="nx-auth__sub">
                Escribe tus apuntes, sube lo que te dan en clase y pregúntale al tutor. Él responde con tus propios archivos.
            </p>

            <div class="nx-auth__brand-foot">
                <span><?= nexo_icon('graduation-cap') ?> Universidad Nacional del Callao</span>
            </div>
        </aside>

        <section class="nx-auth__panel" aria-label="Acceso a NEXO">
            <a class="nx-brand nx-auth__mobile-brand" href="<?= esc($authHome, 'attr') ?>" aria-label="NEXO UNAC, inicio">
                <span class="nx-brand__mark" aria-hidden="true">N</span>
                <span class="nx-brand__name">NEXO</span>
                <span class="nx-brand__sub">UNAC</span>
            </a>

            <?= $this->renderSection('main') ?>
        </section>
    </main>

    <div class="nx-toasts" id="nx-toasts" aria-live="polite" aria-atomic="false" data-toast-region></div>

    <script type="module" src="<?= base_url('js/auth.js') ?>"></script>
    <?= $this->renderSection('scripts') ?>
</body>
</html>

<?php
/**
 * Estructura principal: menú lateral, barra superior y contenido.
 *
 * @var string      $pageTitle
 * @var array|null  $currentPage
 * @var array       $user
 */
$isDoc    = ! empty($currentPage);
$pageId   = $isDoc ? (int) $currentPage['id'] : null;
?>
<!doctype html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#5c1f2b">
    <meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
    <meta name="csrf-header" content="<?= esc(csrf_header(), 'attr') ?>">

    <title><?= esc($pageTitle ?? 'NEXO') ?> · NEXO UNAC</title>

    <link rel="icon" href="<?= base_url('favicon.svg') ?>" type="image/svg+xml">
    <link rel="preload" href="<?= base_url('fonts/newsreader-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= base_url('fonts/public-sans-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>

    <link rel="stylesheet" href="<?= base_url('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= base_url('css/base.css') ?>">
    <link rel="stylesheet" href="<?= base_url('css/components.css') ?>">
    <link rel="stylesheet" href="<?= base_url('css/layout.css') ?>">
    <link rel="stylesheet" href="<?= base_url('css/pages.css') ?>">
    <link rel="stylesheet" href="<?= base_url('css/chat.css') ?>">
    <?php if ($isDoc): ?>
        <link rel="stylesheet" href="<?= base_url('css/editor.css') ?>">
    <?php endif ?>
    <link rel="stylesheet" href="<?= base_url('css/lite.css') ?>">
</head>
<body
    class="nx-app"
    data-base="<?= esc(base_url(), 'attr') ?>"
    data-api="<?= esc(rtrim(base_url('api/v1'), '/'), 'attr') ?>"
    data-page-id="<?= $pageId !== null ? (string) $pageId : '' ?>"
    data-workspace-id="<?= (int) ($sidebarWorkspace['id'] ?? 0) ?>"
    data-page-title="<?= esc($currentPage['title'] ?? '', 'attr') ?>"
    data-user-name="<?= esc($user['name'] ?? 'Usuario', 'attr') ?>"
>
    <a class="nx-skip" href="#nx-main">Saltar al contenido</a>

    <?= $this->include('partials/sidebar') ?>

    <div class="nx-mobile-backdrop" data-sidebar-backdrop></div>

    <div class="nx-shell">
        <?= $this->include('partials/topbar') ?>

        <main class="nx-main nx-page-enter" id="nx-main" tabindex="-1">
            <?= $content ?? $this->renderSection('content') ?>
        </main>
    </div>

    <?= $this->include('partials/toast-region') ?>

    <?php if ($isDoc): ?>
        <script src="<?= base_url('vendor/sortable/Sortable.min.js') ?>" defer></script>
    <?php endif ?>
    <script type="module" src="<?= base_url('js/app.js') ?>"></script>
</body>
</html>

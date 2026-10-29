<?php
/**
 * Barra superior: dónde estoy, estado de guardado y acceso al tutor.
 *
 * @var array|null $currentPage
 * @var string     $topbarTitle
 */
$page = $currentPage ?? null;
?>
<header class="nx-topbar" role="banner">
    <div class="nx-topbar__left">
        <button class="nx-icon-btn nx-mobile-menu" type="button" data-sidebar-open aria-label="Abrir menú" aria-controls="nx-sidebar" aria-expanded="false">
            <?= nexo_icon('menu') ?>
        </button>

        <nav class="nx-breadcrumb" aria-label="Dónde estás">
            <?php if ($page !== null): ?>
                <a href="<?= base_url('dashboard') ?>">Inicio</a>
                <?= nexo_icon('chevron-right') ?>
                <span class="nx-breadcrumb__current" aria-current="page"><?= esc($page['title'] ?: 'Apunte sin título') ?></span>
            <?php else: ?>
                <span class="nx-breadcrumb__current" aria-current="page"><?= esc($topbarTitle) ?></span>
            <?php endif ?>
        </nav>

        <?php if ($page !== null): ?>
            <?php $isNew = (int) ($page['id'] ?? 0) <= 0; ?>
            <span class="nx-save-state" id="nx-save-state" data-state="<?= $isNew ? 'new' : 'saved' ?>" aria-live="polite">
                <?= nexo_icon($isNew ? 'clock' : 'check-circle') ?>
                <span class="nx-save-state__text"><?= $isNew ? 'Sin guardar todavía' : 'Guardado' ?></span>
            </span>
        <?php endif ?>
    </div>

    <div class="nx-topbar__right">
        <?php if (($activeNav ?? '') !== 'tutor'): ?>
            <a class="nx-btn nx-btn--tutor nx-btn--sm" href="<?= base_url('tutor' . (! empty($page['id']) ? '?page=' . (int) $page['id'] : '')) ?>" aria-keyshortcuts="Control+/" title="Preguntar al tutor (Ctrl+/)">
                <?= nexo_icon('sparkles') ?>
                <span class="nx-topbar__tutor-label"><?= ! empty($page['id']) ? 'Preguntar sobre este apunte' : 'Preguntar al tutor' ?></span>
            </a>
        <?php endif ?>
    </div>
</header>

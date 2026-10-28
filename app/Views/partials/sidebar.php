<?php
/**
 * Menú lateral: inicio, mis apuntes y cuenta.
 *
 * @var array|null $currentPage
 * @var array      $sidebarPages
 * @var array      $user
 * @var string     $prefix
 * @var string     $activeNav
 */
$activeNav = $activeNav ?? '';
$onHome    = $activeNav === 'dashboard' && empty($currentPage);
?>
<aside class="nx-sidebar" id="nx-sidebar" aria-label="Menú">
    <div class="nx-sidebar__head">
        <a class="nx-brand" href="<?= base_url('dashboard') ?>" aria-label="NEXO UNAC, ir al inicio">
            <span class="nx-brand__mark" aria-hidden="true">N</span>
            <span class="nx-brand__name">NEXO</span>
            <span class="nx-brand__sub">UNAC</span>
        </a>
        <button class="nx-icon-btn nx-sidebar__close" type="button" data-sidebar-close aria-label="Cerrar menú">
            <?= nexo_icon('x') ?>
        </button>
    </div>

    <div class="nx-sidebar__scroll nx-scroll">
        <nav class="nx-nav" aria-label="Inicio">
            <a class="nx-nav__item<?= $onHome ? ' is-active' : '' ?>" href="<?= base_url('dashboard') ?>" <?= $onHome ? 'aria-current="page"' : '' ?>>
                <?= nexo_icon('layout-dashboard') ?>
                <span>Inicio</span>
            </a>
        </nav>

        <div class="nx-sidebar__section">
            <div class="nx-sidebar__label"><span>Mis apuntes</span></div>

            <?php if ($sidebarPages !== []): ?>
                <input
                    class="nx-input nx-input--sm nx-sidebar__search"
                    type="search"
                    placeholder="Buscar apuntes"
                    aria-label="Buscar apuntes"
                    autocomplete="off"
                    data-sidebar-search
                >
                <div class="nx-tree">
                    <?= nexo_page_tree($sidebarPages, isset($currentPage['id']) ? (int) $currentPage['id'] : null, $prefix) ?>
                </div>
            <?php else: ?>
                <p class="nx-tree__more">Todavía no tienes apuntes.</p>
            <?php endif ?>

            <a class="nx-nav__new" href="<?= base_url('pages/nueva') ?>">
                <?= nexo_icon('plus') ?>
                <span>Nuevo apunte</span>
            </a>
        </div>
    </div>

    <div class="nx-sidebar__foot">
        <div class="nx-user-card">
            <span class="nx-avatar nx-avatar--<?= esc($user['tone'], 'attr') ?>" aria-hidden="true"><?= esc($user['initials']) ?></span>
            <span class="nx-user-card__text">
                <span class="nx-user-card__name"><?= esc($user['name']) ?></span>
                <span class="nx-user-card__role"><?= esc($user['email']) ?></span>
            </span>
        </div>
        <form action="<?= url_to('logout') ?>" method="post">
            <?= csrf_field() ?>
            <button class="nx-icon-btn" type="submit" aria-label="Cerrar sesión" data-tip="Cerrar sesión">
                <?= nexo_icon('log-out') ?>
            </button>
        </form>
    </div>
</aside>

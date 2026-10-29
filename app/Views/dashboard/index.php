<?php
/**
 * Inicio: 1) subir material, 2) preguntar al tutor, 3) apuntes.
 *
 * @var array $user
 * @var array $recentPages
 */
$firstName = explode(' ', trim((string) $user['name']))[0] ?: 'estudiante';
?>
<div class="nx-page nx-home">
    <header class="nx-home__head">
        <h1 class="nx-dash-greeting">Hola, <?= esc($firstName) ?>.</h1>
        <p class="nx-dash-sub">Sube lo que te dan en clase y pregúntale al tutor. Te responde solo con tus propios archivos y te dice de dónde sale cada dato.</p>
    </header>

    <section class="nx-home__section" aria-labelledby="h-material">
        <p class="nx-home__step"><span>1</span> Sube tu material</p>
        <?= $this->include('partials/rag-upload') ?>
    </section>

    <section class="nx-home__section" aria-labelledby="h-tutor">
        <p class="nx-home__step"><span>2</span> Pregúntale al tutor</p>
        <div class="nx-home__ask">
            <p class="nx-home__ask-text" id="h-tutor">Pídele un resumen, que te explique un tema o que te haga preguntas para practicar.</p>
            <button class="nx-btn nx-btn--primary" type="button" data-chat-toggle aria-controls="nx-chat" aria-expanded="false">
                <?= nexo_icon('sparkles') ?> Abrir el tutor
            </button>
        </div>
    </section>

    <section class="nx-home__section" aria-labelledby="h-apuntes">
        <p class="nx-home__step"><span>3</span> Tus apuntes <small>(opcional)</small></p>
        <div class="nx-section__head">
            <h2 class="nx-section__title" id="h-apuntes">Escribe lo que quieras recordar</h2>
            <?php if ($recentPages !== []): ?>
                <a class="nx-btn nx-btn--secondary nx-btn--sm" href="<?= base_url('pages/nueva') ?>">
                    <?= nexo_icon('plus') ?> Nuevo apunte
                </a>
            <?php endif ?>
        </div>

        <?php if ($recentPages !== []): ?>
            <div class="nx-page-list">
                <?php foreach ($recentPages as $page): ?>
                    <a class="nx-page-list__row" href="<?= base_url('pages/' . $page['id']) ?>">
                        <span class="nx-icon-tile nx-icon-tile--sm" aria-hidden="true">
                            <?= nexo_icon(nexo_page_icon($page['icon'] ?? 'file-text')) ?>
                        </span>
                        <span class="nx-page-list__main">
                            <span class="nx-page-list__title"><?= esc($page['title']) ?></span>
                            <span class="nx-page-list__meta">
                                <span><?= nexo_icon('clock') ?><?= esc(nexo_datetime($page['updated_at'] ?? null)) ?></span>
                            </span>
                        </span>
                        <span class="nx-page-list__arrow" aria-hidden="true"><?= nexo_icon('arrow-right') ?></span>
                    </a>
                <?php endforeach ?>
            </div>
        <?php else: ?>
            <div class="nx-empty">
                <p class="nx-empty__text">Todavía no tienes apuntes. Mientras escribes uno, el tutor puede resumirlo o explicártelo.</p>
                <a class="nx-btn nx-btn--secondary" href="<?= base_url('pages/nueva') ?>">
                    <?= nexo_icon('plus') ?> Crear mi primer apunte
                </a>
            </div>
        <?php endif ?>
    </section>
</div>

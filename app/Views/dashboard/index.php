<?php
/**
 * Inicio: 1) subir material, 2) preguntar al tutor, 3) apuntes.
 *
 * @var array $user
 * @var array $recentPages
 */
$firstName = explode(' ', trim((string) $user['name']))[0] ?: 'estudiante';
$materials = array_filter(array_column($recentPages, 'document_title', 'document_id'));
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
            <p class="nx-home__ask-text" id="h-tutor">Pídele un resumen, que te explique un tema o que te haga preguntas para practicar. Lo que te sirva, guárdalo como apunte.</p>
            <a class="nx-btn nx-btn--primary" href="<?= base_url('tutor') ?>">
                <?= nexo_icon('sparkles') ?> Abrir el tutor
            </a>
        </div>
    </section>

    <section class="nx-home__section" aria-labelledby="h-apuntes">
        <p class="nx-home__step"><span>3</span> Tus apuntes</p>
        <div class="nx-section__head">
            <h2 class="nx-section__title" id="h-apuntes">El tutor resume cada archivo que subes</h2>
            <?php if ($recentPages !== []): ?>
                <a class="nx-btn nx-btn--secondary nx-btn--sm" href="<?= base_url('pages/nueva') ?>">
                    <?= nexo_icon('plus') ?> Nuevo apunte
                </a>
            <?php endif ?>
        </div>

        <?php if ($materials !== []): ?>
            <label class="nx-notes-filter">
                <span>Material</span>
                <select class="nx-input nx-input--sm" data-notes-filter>
                    <option value="">Todos</option>
                    <?php foreach ($materials as $docId => $docTitle): ?>
                        <option value="<?= (int) $docId ?>"><?= esc($docTitle) ?></option>
                    <?php endforeach ?>
                    <option value="0">Sin archivo</option>
                </select>
            </label>
        <?php endif ?>

        <?php if ($recentPages !== []): ?>
            <div class="nx-page-list">
                <?php foreach ($recentPages as $page): ?>
                    <a class="nx-page-list__row" href="<?= base_url('pages/' . $page['id']) ?>" data-note-doc="<?= (int) $page['document_id'] ?>">
                        <span class="nx-icon-tile nx-icon-tile--sm" aria-hidden="true">
                            <?= nexo_icon(nexo_page_icon($page['icon'] ?? 'file-text')) ?>
                        </span>
                        <span class="nx-page-list__main">
                            <span class="nx-page-list__title"><?= esc($page['title']) ?></span>
                            <span class="nx-page-list__meta">
                                <?php if (! empty($page['document_title'])): ?>
                                    <span class="nx-note-tag"><?= nexo_icon('file-text') ?><?= esc($page['document_title']) ?></span>
                                <?php endif ?>
                                <span><?= nexo_icon('clock') ?><?= esc(nexo_datetime($page['updated_at'] ?? null)) ?></span>
                            </span>
                        </span>
                        <span class="nx-page-list__arrow" aria-hidden="true"><?= nexo_icon('arrow-right') ?></span>
                    </a>
                <?php endforeach ?>
            </div>
        <?php else: ?>
            <div class="nx-empty">
                <p class="nx-empty__text">Todavía no tienes apuntes. Sube un archivo y el tutor escribe el primero, o escríbelo tú.</p>
                <a class="nx-btn nx-btn--secondary" href="<?= base_url('pages/nueva') ?>">
                    <?= nexo_icon('plus') ?> Crear mi primer apunte
                </a>
            </div>
        <?php endif ?>
    </section>
</div>

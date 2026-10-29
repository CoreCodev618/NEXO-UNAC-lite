<?php
/**
 * Tutor (vista /tutor). Con $chatPage responde usando ese apunte como contexto.
 *
 * @var array|null $chatPage
 */
$page      = $chatPage ?? null;
$pageTitle = $page !== null ? ($page['title'] ?: 'Apunte sin título') : null;
$suggestions = $pageTitle !== null
    ? [
        ['icon' => 'file-text', 'text' => 'Resume este apunte en cinco puntos'],
        ['icon' => 'check-square', 'text' => 'Hazme tres preguntas para repasar'],
    ]
    : [
        ['icon' => 'file-text', 'text' => 'Hazme un resumen de mi material'],
        ['icon' => 'check-square', 'text' => 'Hazme cinco preguntas de práctica'],
    ];
?>
<section
    class="nx-chat"
    id="nx-chat"
    aria-labelledby="nx-chat-title"
    data-page-id="<?= $page !== null ? (int) $page['id'] : '' ?>"
>
    <div class="nx-chat__head">
        <span class="nx-chat__head-icon" aria-hidden="true"><?= nexo_icon('sparkles') ?></span>
        <span class="nx-chat__head-text">
            <span class="nx-chat__title" id="nx-chat-title">Tutor NEXO</span>
            <span class="nx-chat__status">
                <span class="nx-dot" aria-hidden="true"></span>
                Responde con tu material
            </span>
        </span>
        <span class="nx-chat__head-actions">
            <button class="nx-icon-btn" type="button" data-chat-new aria-label="Nueva conversación" data-tip="Nueva conversación">
                <?= nexo_icon('refresh') ?>
            </button>
        </span>
    </div>

    <div class="nx-chat__body nx-scroll" data-chat-body>
        <div class="nx-chat__intro" data-chat-intro>
            <?= nexo_illustration('spark', 'nx-chat__intro-art') ?>
            <h2 class="nx-chat__intro-title">¿Qué quieres repasar?</h2>
            <p class="nx-chat__intro-text">Pregunta lo que quieras. Te respondo con tu material y te digo de dónde sale. Cada respuesta la puedes guardar como apunte.</p>

            <div class="nx-chat__suggestions">
                <?php foreach ($suggestions as $suggestion): ?>
                    <button class="nx-chat__suggestion" type="button" data-chat-suggestion="<?= esc($suggestion['text'], 'attr') ?>">
                        <?= nexo_icon($suggestion['icon']) ?>
                        <span><?= esc($suggestion['text']) ?></span>
                    </button>
                <?php endforeach ?>
            </div>
        </div>

        <div class="nx-chat__messages" data-chat-messages role="log" aria-live="polite" aria-relevant="additions"></div>
    </div>

    <div class="nx-chat__composer">
        <?php if ($pageTitle !== null): ?>
            <span class="nx-chat__context">
                <?= nexo_icon('file-text') ?>
                <span>Contexto:</span>
                <strong><?= esc($pageTitle) ?></strong>
            </span>
        <?php endif ?>

        <form class="nx-chat__form" data-chat-form>
            <label class="nx-sr-only" for="nx-chat-input">Escribe tu pregunta</label>
            <textarea
                class="nx-chat__input"
                id="nx-chat-input"
                rows="1"
                maxlength="1200"
                placeholder="Escribe una pregunta…"
                data-chat-input
            ></textarea>
            <button class="nx-chat__send" type="submit" data-chat-send aria-label="Enviar pregunta">
                <?= nexo_icon('send') ?>
            </button>
        </form>

    </div>
</section>

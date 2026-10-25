<?php
/**
 * Página y editor de bloques.
 *
 * @var array  $page
 * @var array  $user
 */
$blocks      = $page['blocks'] ?? [];
$pageId      = (int) ($page['id'] ?? 0);

$languageOptions = ['python', 'javascript', 'php', 'sql', 'bash', 'json', 'html', 'css', 'texto'];

$renderBlockMenu = static function (int|string $id): string {
    return '<div class="nx-dropdown">'
        . '<button class="nx-icon-btn" type="button" data-menu-trigger="block-menu-' . esc((string) $id, 'attr') . '" aria-expanded="false" aria-label="Opciones del bloque">' . nexo_icon('more-horizontal') . '</button>'
        . '<div class="nx-menu nx-block__menu" id="block-menu-' . esc((string) $id, 'attr') . '" hidden>'
        . '<div class="nx-menu__label">Bloque</div>'
        . '<button class="nx-menu__item" type="button" data-block-action="duplicate">' . nexo_icon('copy') . '<span>Duplicar</span><span class="nx-menu__shortcut">⌘ D</span></button>'
        . '<button class="nx-menu__item" type="button" data-block-action="up">' . nexo_icon('arrow-up') . '<span>Mover arriba</span></button>'
        . '<button class="nx-menu__item" type="button" data-block-action="down">' . nexo_icon('arrow-down') . '<span>Mover abajo</span></button>'
        . '<div class="nx-menu__separator"></div>'
        . '<button class="nx-menu__item nx-menu__item--danger" type="button" data-block-action="delete">' . nexo_icon('trash') . '<span>Eliminar bloque</span></button>'
        . '</div></div>';
};
?>
<div class="nx-doc" data-editor data-page-id="<?= $pageId ?>" data-page-workspace="<?= (int) ($page['workspace']['id'] ?? 0) ?>">
    <header class="nx-doc-head">
        <div class="nx-doc-head__main">
            <h1
                class="nx-doc-title"
                contenteditable="true"
                spellcheck="true"
                role="textbox"
                aria-label="Título del apunte"
                data-page-title-input
            ><?= esc($page['title']) ?></h1>
            <p class="nx-doc-head__sub">Escribe aquí. Se guarda solo.</p>
        </div>
    </header>

    <div class="nx-doc-body">
        <div class="nx-editor-toolbar" role="toolbar" aria-label="Formato de texto">
            <button class="nx-icon-btn" type="button" data-format="bold" aria-label="Negrita" data-tip="Negrita"><strong>B</strong></button>
            <button class="nx-icon-btn" type="button" data-format="italic" aria-label="Cursiva" data-tip="Cursiva"><em>I</em></button>
            <button class="nx-icon-btn" type="button" data-format="code" aria-label="Código en línea" data-tip="Código en línea"><?= nexo_icon('code') ?></button>
            <button class="nx-icon-btn" type="button" data-format="link" aria-label="Insertar enlace" data-tip="Enlace"><?= nexo_icon('link') ?></button>
        </div>

        <div class="nx-editor" data-blocks>
            <?php if ($blocks === []): ?>
                <div class="nx-block" data-block-id="new-1" data-block-type="parrafo">
                    <div class="nx-block__gutter">
                        <button type="button" data-block-add aria-label="Insertar bloque"><?= nexo_icon('plus') ?></button>
                        <button type="button" data-block-drag aria-label="Mover bloque"><?= nexo_icon('grip-vertical') ?></button>
                    </div>
                    <div class="nx-block__main">
                        <div class="nx-block__editor" contenteditable="true" data-field="text" data-placeholder="Escribe algo, o usa / para insertar un bloque"></div>
                    </div>
                    <div class="nx-block__actions"><?= $renderBlockMenu('new-1') ?></div>
                </div>
            <?php endif ?>

            <?php foreach ($blocks as $block): ?>
                <?php
                $blockId   = (int) ($block['id'] ?? 0);
                $type      = (string) ($block['type'] ?? 'parrafo');
                $content   = is_array($block['content'] ?? null) ? $block['content'] : [];
                $style     = (string) ($content['style'] ?? 'p');
                $style     = in_array($style, ['p', 'h2', 'h3', 'lead'], true) ? $style : 'p';
                $variant   = (string) ($content['variant'] ?? 'info');
                $variant   = in_array($variant, ['info', 'success', 'warning', 'danger'], true) ? $variant : 'info';
                $variantIcon = match ($variant) {
                    'success' => 'check-circle',
                    'warning' => 'alert-triangle',
                    'danger'  => 'alert-circle',
                    default   => 'info',
                };
                ?>
                <div class="nx-block" data-block-id="<?= $blockId ?>" data-block-type="<?= esc($type, 'attr') ?>">
                    <div class="nx-block__gutter">
                        <button type="button" data-block-add aria-label="Insertar bloque"><?= nexo_icon('plus') ?></button>
                        <button type="button" data-block-drag aria-label="Mover bloque"><?= nexo_icon('grip-vertical') ?></button>
                    </div>

                    <div class="nx-block__main">
                        <?php if ($type === 'parrafo'): ?>
                            <div
                                class="nx-block__editor<?= $style !== 'p' ? ' nx-block__editor--' . esc($style, 'attr') : '' ?>"
                                contenteditable="true"
                                spellcheck="true"
                                role="textbox"
                                aria-multiline="true"
                                data-field="text"
                                data-style="<?= esc($style, 'attr') ?>"
                                data-placeholder="Escribe algo, o usa / para insertar un bloque"
                            ><?= esc($content['text'] ?? '') ?></div>

                        <?php elseif ($type === 'checklist'): ?>
                            <div class="nx-checklist" data-checklist>
                                <?php foreach (($content['items'] ?? []) as $item): ?>
                                    <?php $checked = ! empty($item['checked']); ?>
                                    <div class="nx-checklist__item<?= $checked ? ' is-checked' : '' ?>">
                                        <button class="nx-checklist__box" type="button" data-check aria-pressed="<?= $checked ? 'true' : 'false' ?>" aria-label="Marcar elemento">
                                            <?= nexo_icon('check') ?>
                                        </button>
                                        <div class="nx-checklist__text" contenteditable="true" data-field="text" data-placeholder="Elemento de la lista"><?= esc($item['text'] ?? '') ?></div>
                                        <button class="nx-checklist__remove" type="button" data-check-remove aria-label="Eliminar elemento"><?= nexo_icon('x') ?></button>
                                    </div>
                                <?php endforeach ?>
                            </div>
                            <button class="nx-checklist__add" type="button" data-checklist-add>
                                <?= nexo_icon('plus') ?> Añadir elemento
                            </button>

                        <?php elseif ($type === 'codigo'): ?>
                            <div class="nx-codeblock">
                                <div class="nx-codeblock__head">
                                    <label class="nx-sr-only" for="code-lang-<?= $blockId ?>">Lenguaje del bloque de código</label>
                                    <select class="nx-codeblock__lang" id="code-lang-<?= $blockId ?>" data-field="language">
                                        <?php foreach ($languageOptions as $language): ?>
                                            <option value="<?= esc($language, 'attr') ?>" <?= ($content['language'] ?? 'texto') === $language ? 'selected' : '' ?>><?= esc($language) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                    <button class="nx-codeblock__copy" type="button" data-copy-code>
                                        <?= nexo_icon('copy') ?> Copiar
                                    </button>
                                </div>
                                <label class="nx-sr-only" for="code-area-<?= $blockId ?>">Código</label>
                                <textarea class="nx-codeblock__area" id="code-area-<?= $blockId ?>" rows="5" spellcheck="false" data-field="code"><?= esc($content['code'] ?? '') ?></textarea>
                            </div>

                        <?php elseif ($type === 'tabla'): ?>
                            <?php
                            $headers = is_array($content['headers'] ?? null) ? $content['headers'] : [];
                            $rows    = is_array($content['rows'] ?? null) ? $content['rows'] : [];
                            $cols    = max(1, count($headers));
                            ?>
                            <div class="nx-tableblock" data-table>
                                <table>
                                    <thead>
                                        <tr>
                                            <?php for ($col = 0; $col < $cols; $col++): ?>
                                                <th scope="col"><div class="nx-tableblock__cell" contenteditable="true" data-field="header" data-placeholder="Encabezado"><?= esc($headers[$col] ?? '') ?></div></th>
                                            <?php endfor ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($rows === []): ?>
                                            <tr>
                                                <?php for ($col = 0; $col < $cols; $col++): ?>
                                                    <td><div class="nx-tableblock__cell" contenteditable="true" data-field="cell" data-placeholder="Celda"></div></td>
                                                <?php endfor ?>
                                            </tr>
                                        <?php endif ?>
                                        <?php foreach ($rows as $row): ?>
                                            <tr>
                                                <?php for ($col = 0; $col < $cols; $col++): ?>
                                                    <td><div class="nx-tableblock__cell" contenteditable="true" data-field="cell" data-placeholder="Celda"><?= esc($row[$col] ?? '') ?></div></td>
                                                <?php endfor ?>
                                            </tr>
                                        <?php endforeach ?>
                                    </tbody>
                                </table>
                                <div class="nx-tableblock__tools">
                                    <button type="button" data-table-row><?= nexo_icon('plus') ?> Fila</button>
                                    <button type="button" data-table-col><?= nexo_icon('plus') ?> Columna</button>
                                    <button type="button" data-table-del-row><?= nexo_icon('x') ?> Quitar fila</button>
                                    <button type="button" data-table-del-col><?= nexo_icon('x') ?> Quitar columna</button>
                                </div>
                            </div>

                        <?php elseif ($type === 'quote'): ?>
                            <div class="nx-quoteblock">
                                <div class="nx-quoteblock__text" contenteditable="true" data-field="text" data-placeholder="Una idea que merece quedarse"><?= esc($content['text'] ?? '') ?></div>
                                <input class="nx-quoteblock__cite" type="text" data-field="cite" value="<?= esc($content['cite'] ?? '') ?>" placeholder="Fuente (opcional)">
                            </div>

                        <?php elseif ($type === 'callout'): ?>
                            <div class="nx-callout nx-callout--<?= esc($variant, 'attr') ?>" data-variant="<?= esc($variant, 'attr') ?>">
                                <span class="nx-callout__icon" aria-hidden="true"><?= nexo_icon($variantIcon) ?></span>
                                <div class="nx-callout__body">
                                    <div class="nx-callout__title" contenteditable="true" data-field="title" data-placeholder="Título del aviso"><?= esc($content['title'] ?? '') ?></div>
                                    <div class="nx-callout__text" contenteditable="true" data-field="text" data-placeholder="Escribe el detalle…"><?= esc($content['text'] ?? '') ?></div>
                                </div>
                            </div>
                        <?php endif ?>
                    </div>

                    <div class="nx-block__actions">
                        <?= $renderBlockMenu($blockId) ?>
                    </div>
                </div>
            <?php endforeach ?>
        </div>

        <button class="nx-editor__add" type="button" data-editor-add>
            <?= nexo_icon('plus') ?>
            Añadir bloque
        </button>

        <div class="nx-editor__foot">
            <span>Escribe <span class="nx-kbd">/</span> para insertar un bloque</span>
        </div>
    </div>

</div>


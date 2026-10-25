<?php
/**
 * Material para el tutor: subir un archivo y ver lo que ya subiste.
 */
$maxMb = max(1, min(100, (int) env('RAG_MAX_UPLOAD_MB', 20)));
?>
<section class="nx-rag" data-rag-upload data-rag-max-mb="<?= $maxMb ?>">
    <header class="nx-rag__head">
        <span class="nx-rag__icon" aria-hidden="true"><?= nexo_icon('sparkles') ?></span>
        <div class="nx-rag__heading">
            <h2 class="nx-rag__title" id="h-material">Tus archivos de clase</h2>
            <p class="nx-rag__subtitle">Sílabo, diapositivas, guías o fotos de la pizarra. Puedes quitarlos cuando quieras.</p>
        </div>
    </header>

    <label class="nx-rag__dropzone" data-rag-dropzone>
        <span class="nx-rag__dropicon" aria-hidden="true"><?= nexo_icon('upload') ?></span>
        <strong>Elige un archivo o arrástralo aquí</strong>
        <span>PDF, Word, PowerPoint, foto o ZIP · hasta <?= $maxMb ?> MB</span>
        <input class="nx-sr-only" type="file" accept=".pdf,.docx,.pptx,.jpg,.jpeg,.png,.zip" data-rag-input>
    </label>

    <p class="nx-rag__status" data-rag-status role="status" aria-live="polite"></p>

    <div class="nx-rag__list" data-rag-list aria-live="polite"></div>
</section>

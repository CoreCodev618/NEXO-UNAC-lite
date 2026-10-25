<?php
/**
 * Error de producción con la identidad de NEXO. No expone detalles internos.
 */
$errorCode = 'Error del servidor';
$errorTitle = 'Algo se atascó en la biblioteca.';
$errorText = 'No pudimos completar la solicitud. Tus datos siguen a salvo; inténtalo de nuevo en unos instantes.';
$errorArt = 'book';
$errorSecondaryMode = 'reload';
$errorSecondaryLabel = 'Intentar de nuevo';

require __DIR__ . '/_nexo_error.php';

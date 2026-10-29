<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\TutorNotes;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * «Guardar como apunte» desde el tutor.
 */
class NoteController extends BaseApiController
{
    public function create(): ResponseInterface
    {
        $data  = $this->payload();
        $text  = trim((string) ($data['text'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));
        $docId = (int) ($data['document_id'] ?? 0);

        if ($text === '' || mb_strlen($text) > 20000) {
            return $this->error('No hay texto para guardar.', 422);
        }

        try {
            $id = (new TutorNotes())->fromReply((int) auth()->id(), $title, $text, $docId > 0 ? $docId : null);
        } catch (Throwable $error) {
            log_message('error', 'No se pudo guardar el apunte del tutor: {error}', ['error' => $error->getMessage()]);

            return $this->error('No se pudo guardar el apunte.', 500);
        }

        return $this->ok(['id' => $id, 'url' => base_url('pages/' . $id)], 201);
    }
}

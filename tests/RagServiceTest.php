<?php

declare(strict_types=1);

use App\Libraries\PythonRunner;
use App\Libraries\RagService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * El tutor solo debe ver fragmentos de los documentos del usuario, aunque
 * Qdrant devuelva otros (fail-closed).
 */
final class RagServiceTest extends CIUnitTestCase
{
    public function testRetrieveKeepsOnlyTheUsersDocuments(): void
    {
        $rag  = new RagService(new PythonRunner('tests/_support/fake_retrieve.sh'));
        $hits = $rag->retrieve('pregunta', [1 => 'Mi PDF']);

        $this->assertCount(1, $hits);
        $this->assertSame(1, $hits[0]['doc_id']);
        $this->assertSame('Mi PDF', $hits[0]['title']);
    }

    public function testStripUnknownCitationsKeepsOnlyRealSources(): void
    {
        $rag = new RagService(new PythonRunner('/nonexistent/python'));

        $this->assertSame('Guarda datos en tablas.', $rag->stripUnknownCitations('Guarda datos en tablas [1].', 0));
        $this->assertSame('Ver [1] y [2].', $rag->stripUnknownCitations('Ver [1] y [2] [3].', 2));
    }

    public function testRetrieveWithZeroMinScoreSkipsThreshold(): void
    {
        putenv('RAG_MIN_SCORE=0.67');
        $rag = new RagService(new PythonRunner('tests/_support/fake_retrieve_args.sh'));
        putenv('RAG_MIN_SCORE');

        $this->assertStringContainsString('--min-score 0.67', $rag->retrieve('q', [1 => 'PDF'])[0]['text']);
        $this->assertStringNotContainsString('--min-score', $rag->retrieve('q', [1 => 'PDF'], null, 0.0)[0]['text']);
    }

    public function testTutorNoteLinesDropBlankLines(): void
    {
        $this->assertSame(['Resumen.', '- Costo fijo'], \App\Libraries\TutorNotes::lines("Resumen.\n\n  - Costo fijo  \r\n"));
    }

    public function testRetrieveWithoutDocumentsSkipsQdrant(): void
    {
        $rag = new RagService(new PythonRunner('/nonexistent/python'));

        $this->assertSame([], $rag->retrieve('pregunta', []));
    }
}

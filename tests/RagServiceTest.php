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

    public function testRetrieveWithoutDocumentsSkipsQdrant(): void
    {
        $rag = new RagService(new PythonRunner('/nonexistent/python'));

        $this->assertSame([], $rag->retrieve('pregunta', []));
    }
}

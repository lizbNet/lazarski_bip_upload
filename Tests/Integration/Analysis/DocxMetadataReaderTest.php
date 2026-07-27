<?php

declare(strict_types=1);

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PrimeServices\LazarskiBipUpload\Analysis\DocxMetadataReader;

require_once dirname(__DIR__, 6) . '/vendor/autoload.php';

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

// Generate a minimal real .docx fixture on the fly (no committed binary needed) using the
// same phpoffice/phpword library the reader depends on, with a title property and a heading.
$phpWord = new PhpWord();
$phpWord->getDocInfo()->setTitle('Uchwała testowa Senatu');
$phpWord->getDocInfo()->setSubject('Testowy temat dokumentu');
// A style must be registered before addTitle() emits a w:pStyle reference - this mirrors
// what a real Word document looks like when authored via Word's built-in heading styles.
$phpWord->addTitleStyle(1, ['bold' => true]);

$section = $phpWord->addSection();
$section->addTitle('Pierwszy nagłówek dokumentu', 1);
$section->addText('Zwykły akapit tekstu w dokumencie.');

$fixturePath = sys_get_temp_dir() . '/lbu_docx_fixture_' . bin2hex(random_bytes(6)) . '.docx';
IOFactory::createWriter($phpWord, 'Word2007')->save($fixturePath);

try {
    $reader = new DocxMetadataReader();
    $result = $reader->read($fixturePath);

    assertTrue($result['title'] === 'Uchwała testowa Senatu', 'Must read the DOCX core Title property');
    assertTrue($result['subject'] === 'Testowy temat dokumentu', 'Must read the DOCX core Subject property');
    assertTrue(count($result['headings']) === 1, 'Must find exactly the one heading in the document');
    assertTrue($result['headings'][0] === 'Pierwszy nagłówek dokumentu', 'Must read the heading text correctly');
} finally {
    @unlink($fixturePath);
}

echo sprintf("%d DocxMetadataReader assertions passed.\n", $assertions);

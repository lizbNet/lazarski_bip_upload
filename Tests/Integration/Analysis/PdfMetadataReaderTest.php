<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Analysis\PdfMetadataReader;

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

/**
 * Builds a minimal, structurally valid single-page PDF with a real Info/Title dictionary
 * entry and a text content stream, computing an accurate xref table - no committed binary
 * fixture needed, and no PDF-writing library dependency.
 *
 * @param array<int, string> $objectBodies object number (1-based) => object body (without "N 0 obj"/"endobj")
 */
function buildMinimalPdf(array $objectBodies, int $rootObjectNumber, int $infoObjectNumber): string
{
    $output = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objectBodies as $number => $body) {
        $offsets[$number] = strlen($output);
        $output .= sprintf("%d 0 obj\n%s\nendobj\n", $number, $body);
    }

    $xrefOffset = strlen($output);
    $count = count($objectBodies) + 1;
    $output .= sprintf("xref\n0 %d\n", $count);
    $output .= "0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) {
        $output .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $output .= "trailer\n";
    $output .= sprintf("<< /Size %d /Root %d 0 R /Info %d 0 R >>\n", $count, $rootObjectNumber, $infoObjectNumber);
    $output .= "startxref\n{$xrefOffset}\n%%EOF";

    return $output;
}

$streamContent = 'BT /F1 12 Tf 72 720 Td (Uchwala Senatu w sprawie testu.) Tj ET';

$pdfContent = buildMinimalPdf(
    [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        5 => sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($streamContent), $streamContent),
        6 => '<< /Title (Uchwala testowa Senatu) >>',
    ],
    1,
    6
);

$fixturePath = sys_get_temp_dir() . '/lbu_pdf_fixture_' . bin2hex(random_bytes(6)) . '.pdf';
file_put_contents($fixturePath, $pdfContent);

try {
    $reader = new PdfMetadataReader();
    $result = $reader->read($fixturePath);

    assertTrue($result['title'] === 'Uchwala testowa Senatu', 'Must read the PDF Info dictionary Title');
    assertTrue(str_contains($result['text'], 'Uchwala Senatu w sprawie testu'), 'Must extract the page text content');
} finally {
    @unlink($fixturePath);
}

echo sprintf("%d PdfMetadataReader assertions passed.\n", $assertions);

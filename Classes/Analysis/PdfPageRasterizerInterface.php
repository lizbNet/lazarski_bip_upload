<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Analysis;

/**
 * Rasterizes the first page of a PDF to an image, for feeding image-only (unOCR'd scan) PDFs
 * to a vision-capable model instead of the empty text smalot/pdfparser would otherwise return.
 */
interface PdfPageRasterizerInterface
{
    /**
     * @return string raw PNG bytes of the first page
     * @throws RasterizationException
     */
    public function rasterizeFirstPage(string $pdfAbsolutePath): string;
}

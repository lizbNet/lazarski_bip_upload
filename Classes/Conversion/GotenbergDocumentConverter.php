<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Conversion;

use GuzzleHttp\Exception\RequestException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Converts DOCX/XLSX to PDF via Gotenberg (LibreOffice-in-a-container HTTP API), requesting
 * PDF/UA output (Gotenberg's `pdfua` flag) as the tagging baseline - same accessibility
 * caveat as the CLI-based converter this replaces: only as good as the source document's own
 * heading/structure semantics, not a conformance guarantee.
 *
 * Exists because production (home.pl shared hosting, CageFS jail, no root/package manager)
 * cannot run `soffice` directly - see LibreOfficeDocumentConverter, which stays in the repo
 * as the reference implementation for DDEV, where the real binary is installed.
 *
 * XLSX gets the same landscape treatment as the CLI converter, but scaling can't reuse
 * LibreOffice's `SinglePageSheets` export filter - Gotenberg's HTTP API has no equivalent
 * knob. Verified empirically instead: PhpSpreadsheet's own fit-to-page (setFitToWidth(1)-
 * >setFitToHeight(1)) on the source file, sent as a plain conversion request, produces the
 * same one-page-per-sheet result (a 30-col/40-row test sheet: 3 pages with neither landscape
 * nor fit-to-page set, 6 with Gotenberg's own `landscape=true` flag - which made things worse,
 * not better - and exactly 1 with fit-to-page pre-applied here, tagging included).
 */
class GotenbergDocumentConverter implements DocumentConverterInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const EXTENSION_KEY = 'lazarski_bip_upload';
    private const OUTPUT_VERIFICATION_MAGIC_BYTES = '%PDF-';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly int $timeoutSeconds = 90,
    ) {
    }

    public function convertToPdf(string $sourceAbsolutePath, string $outputDirectoryAbsolutePath): string
    {
        $baseUrl = $this->getConfig('gotenbergBaseUrl');
        if ($baseUrl === '') {
            throw new ConversionException('Gotenberg is not configured (gotenbergBaseUrl is empty).');
        }

        $temporaryDirectory = rtrim(sys_get_temp_dir(), '/') . '/lbu_gotenberg_' . bin2hex(random_bytes(8));

        try {
            if (!@mkdir($temporaryDirectory, 0775, true) && !is_dir($temporaryDirectory)) {
                throw new ConversionException('Could not create a temporary working directory.');
            }

            $conversionSourcePath = $sourceAbsolutePath;
            if (strtolower(pathinfo($sourceAbsolutePath, PATHINFO_EXTENSION)) === 'xlsx') {
                $fitToPageCopyPath = $this->createLandscapeFitToPageCopy($sourceAbsolutePath, $temporaryDirectory);
                if ($fitToPageCopyPath !== null) {
                    $conversionSourcePath = $fitToPageCopyPath;
                }
            }

            $expectedOutputPath = rtrim($outputDirectoryAbsolutePath, '/') . '/'
                . pathinfo($sourceAbsolutePath, PATHINFO_FILENAME) . '.pdf';

            $this->requestConversion($baseUrl, $conversionSourcePath, $expectedOutputPath);
            $this->assertValidPdf($expectedOutputPath);

            return $expectedOutputPath;
        } finally {
            if (is_dir($temporaryDirectory)) {
                $this->removeDirectoryRecursively($temporaryDirectory);
            }
        }
    }

    private function requestConversion(string $baseUrl, string $sourceAbsolutePath, string $outputAbsolutePath): void
    {
        $authUser = $this->getConfig('gotenbergAuthUser');
        $authPassword = $this->getConfig('gotenbergAuthPassword');

        $options = [
            'timeout' => $this->timeoutSeconds,
            'multipart' => [
                [
                    'name' => 'files',
                    'contents' => fopen($sourceAbsolutePath, 'rb'),
                    'filename' => basename($sourceAbsolutePath),
                ],
                [
                    'name' => 'pdfua',
                    'contents' => 'true',
                ],
            ],
        ];
        if ($authUser !== '') {
            $options['auth'] = [$authUser, $authPassword];
        }

        try {
            $response = $this->requestFactory->request(
                rtrim($baseUrl, '/') . '/forms/libreoffice/convert',
                'POST',
                $options
            );
        } catch (RequestException $exception) {
            $statusCode = $exception->getResponse()?->getStatusCode();
            $this->logger?->warning('Gotenberg request failed', [
                'exception' => $exception::class,
                'statusCode' => $statusCode,
            ]);

            throw new ConversionException(
                $statusCode !== null
                    ? sprintf('Gotenberg request failed with status %d.', $statusCode)
                    : 'Gotenberg request failed: ' . $exception->getMessage()
            );
        } catch (\Throwable $exception) {
            $this->logger?->warning('Gotenberg request failed with an exception', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new ConversionException('Gotenberg request failed: ' . $exception->getMessage());
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new ConversionException(sprintf('Gotenberg request returned status %d.', $statusCode));
        }

        $written = @file_put_contents($outputAbsolutePath, (string)$response->getBody());
        if ($written === false) {
            throw new ConversionException('Could not write the converted PDF to the output directory.');
        }
    }

    /**
     * Forces landscape orientation and fits the whole sheet onto a single page, saving the
     * result under its original basename inside $temporaryDirectory. Best-effort: returns null
     * on any failure, so the caller falls back to converting the untouched original.
     */
    private function createLandscapeFitToPageCopy(string $sourceAbsolutePath, string $temporaryDirectory): ?string
    {
        try {
            $spreadsheet = IOFactory::load($sourceAbsolutePath);
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $sheet->getPageSetup()
                    ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
                    ->setFitToPage(true)
                    ->setFitToWidth(1)
                    ->setFitToHeight(1);
            }
            $copyPath = rtrim($temporaryDirectory, '/') . '/' . basename($sourceAbsolutePath);
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($copyPath);
            return $copyPath;
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertValidPdf(string $path): void
    {
        if (!is_file($path)) {
            throw new ConversionException('Conversion did not produce an output file.');
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new ConversionException('Conversion produced an empty output file.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ConversionException('Conversion output file could not be read for verification.');
        }
        $header = fread($handle, strlen(self::OUTPUT_VERIFICATION_MAGIC_BYTES));
        fclose($handle);

        if ($header !== self::OUTPUT_VERIFICATION_MAGIC_BYTES) {
            @unlink($path);
            throw new ConversionException('Conversion output is not a valid PDF.');
        }
    }

    private function removeDirectoryRecursively(string $directory): void
    {
        $entries = scandir($directory) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectoryRecursively($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private function getConfig(string $key): string
    {
        try {
            return trim((string)$this->extensionConfiguration->get(self::EXTENSION_KEY, $key));
        } catch (\Exception) {
            return '';
        }
    }
}

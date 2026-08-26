<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Metadata;

use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Writes the editor-approved PDF title via Gotenberg's PDF engines routes instead of shelling
 * to exiftool - production (home.pl, CageFS jail, no root/package manager) has no exiftool
 * binary, same reason Conversion\GotenbergDocumentConverter exists instead of
 * Conversion\LibreOfficeDocumentConverter there. Reuses the same gotenbergBaseUrl/
 * gotenbergAuthUser/gotenbergAuthPassword extension configuration and Gotenberg instance -
 * Gotenberg bundles exiftool internally for these routes, so no new infrastructure is needed.
 *
 * Verified empirically (not guessed) against the real Gotenberg instance: posting a bare
 * {"Title": "..."} to /forms/pdfengines/metadata/write writes both the classic /Info
 * dictionary Title AND the XMP dc:title in one call - exiftool's own default PDF write
 * behaviour synchronizes them - so unlike the exiftool-CLI version this only needs to send
 * one field, not a separate -XMP-dc:Title argument.
 */
class GotenbergPdfMetadataService implements PdfMetadataWriterInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const EXTENSION_KEY = 'lazarski_bip_upload';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function writeApprovedTitle(string $absolutePdfPath, string $title): void
    {
        $baseUrl = $this->getConfig('gotenbergBaseUrl');
        if ($baseUrl === '') {
            throw new PdfMetadataException('Gotenberg is not configured (gotenbergBaseUrl is empty).');
        }

        $writtenBody = $this->request($baseUrl, '/forms/pdfengines/metadata/write', $absolutePdfPath, [
            [
                'name' => 'metadata',
                'contents' => json_encode(['Title' => $title], JSON_THROW_ON_ERROR),
            ],
        ]);

        $written = @file_put_contents($absolutePdfPath, $writtenBody);
        if ($written === false) {
            throw new PdfMetadataException('Could not write the updated PDF back to disk.');
        }

        $actualTitle = $this->readTitle($baseUrl, $absolutePdfPath);
        if ($actualTitle !== $title) {
            throw new PdfMetadataException('Could not verify the PDF title metadata was written correctly.');
        }
    }

    private function readTitle(string $baseUrl, string $absolutePdfPath): string
    {
        $body = $this->request($baseUrl, '/forms/pdfengines/metadata/read', $absolutePdfPath, []);

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PdfMetadataException('Could not read back the PDF title metadata for verification.');
        }

        $filename = basename($absolutePdfPath);
        $title = $decoded[$filename]['Title'] ?? null;
        if (!is_string($title)) {
            throw new PdfMetadataException('Could not read back the PDF title metadata for verification.');
        }

        return $title;
    }

    private function request(string $baseUrl, string $route, string $absolutePdfPath, array $extraFields): string
    {
        $authUser = $this->getConfig('gotenbergAuthUser');
        $authPassword = $this->getConfig('gotenbergAuthPassword');

        $options = [
            'timeout' => $this->timeoutSeconds,
            'multipart' => array_merge(
                [
                    [
                        'name' => 'files',
                        'contents' => fopen($absolutePdfPath, 'rb'),
                        'filename' => basename($absolutePdfPath),
                    ],
                ],
                $extraFields
            ),
        ];
        if ($authUser !== '') {
            $options['auth'] = [$authUser, $authPassword];
        }

        try {
            $response = $this->requestFactory->request(rtrim($baseUrl, '/') . $route, 'POST', $options);
        } catch (RequestException $exception) {
            $statusCode = $exception->getResponse()?->getStatusCode();
            $this->logger?->warning('Gotenberg metadata request failed', [
                'route' => $route,
                'exception' => $exception::class,
                'statusCode' => $statusCode,
            ]);

            throw new PdfMetadataException(
                $statusCode !== null
                    ? sprintf('Gotenberg metadata request failed with status %d.', $statusCode)
                    : 'Gotenberg metadata request failed: ' . $exception->getMessage()
            );
        } catch (\Throwable $exception) {
            $this->logger?->warning('Gotenberg metadata request failed with an exception', [
                'route' => $route,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new PdfMetadataException('Gotenberg metadata request failed: ' . $exception->getMessage());
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new PdfMetadataException(sprintf('Gotenberg metadata request returned status %d.', $statusCode));
        }

        return (string)$response->getBody();
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

<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

use Psr\Http\Message\UploadedFileInterface;
use PrimeServices\LazarskiBipUpload\Exception\UploadValidationException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Type\File\FileInfo;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stores uploaded set files outside public/, validates them, and enforces the
 * staging root as a hard boundary so no path can escape it (traversal protection).
 */
class TemporaryUploadService
{
    private const ALLOWED_MIME_TYPES = [
        'pdf' => ['application/pdf'],
        // Some libmagic databases don't recognise OOXML and fall back to the
        // generic zip signature a .docx is built on; accept both for this extension.
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
    ];

    private const MAX_FILE_SIZE = 50 * 1024 * 1024;
    private const MAX_FILES_PER_SET = 30;

    private const SET_TOKEN_PATTERN = '/^[a-f0-9]{32}$/';

    private string $stagingRootPath;

    public function __construct(string $stagingRootPath = '')
    {
        $this->stagingRootPath = $stagingRootPath !== ''
            ? rtrim($stagingRootPath, '/')
            : rtrim(Environment::getVarPath(), '/') . '/lazarski_bip_upload/staging';
    }

    public function getStagingRootPath(): string
    {
        return $this->stagingRootPath;
    }

    public function generateSetToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Validates and moves one uploaded file into the set's staging directory.
     *
     * @throws UploadValidationException for expected, editor-facing validation failures
     * @return array{path: string, filename: string, extension: string, mimeType: string, size: int}
     */
    public function validateAndStore(string $setToken, UploadedFileInterface $uploadedFile, int $existingItemCount): array
    {
        $this->assertSafeToken($setToken);

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw new UploadValidationException(
                sprintf('Upload failed with error code %d.', $uploadedFile->getError())
            );
        }

        if ($existingItemCount >= self::MAX_FILES_PER_SET) {
            throw new UploadValidationException(
                sprintf('A document set cannot contain more than %d files.', self::MAX_FILES_PER_SET)
            );
        }

        $originalFilename = $uploadedFile->getClientFilename() ?? '';
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_MIME_TYPES[$extension])) {
            throw new UploadValidationException(
                sprintf('File type ".%s" is not allowed. Only PDF, DOCX, and XLSX files are accepted.', $extension ?: '?')
            );
        }

        $size = $uploadedFile->getSize() ?? 0;
        if ($size <= 0) {
            throw new UploadValidationException(sprintf('File "%s" is empty.', $originalFilename));
        }
        if ($size > self::MAX_FILE_SIZE) {
            throw new UploadValidationException(
                sprintf('File "%s" exceeds the maximum allowed size of %d MB.', $originalFilename, self::MAX_FILE_SIZE / 1024 / 1024)
            );
        }

        $directory = $this->createSetDirectory($setToken);
        $storedBasename = bin2hex(random_bytes(8)) . '.' . $extension;
        $destination = $directory . '/' . $storedBasename;

        $uploadedFile->moveTo($destination);

        $mimeType = (string)(new FileInfo($destination))->getMimeType($originalFilename);
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES[$extension], true)) {
            @unlink($destination);
            throw new UploadValidationException(
                sprintf('File "%s" has an unexpected content type (%s) for a .%s file.', $originalFilename, $mimeType, $extension)
            );
        }

        return [
            'path' => $setToken . '/' . $storedBasename,
            'filename' => self::sanitizeDisplayFilename($originalFilename),
            'extension' => $extension,
            'mimeType' => $mimeType,
            'size' => $size,
        ];
    }

    public function deleteSet(string $setToken): void
    {
        $this->assertSafeToken($setToken);
        $directory = $this->stagingRootPath . '/' . $setToken;
        if (is_dir($directory)) {
            GeneralUtility::rmdir($directory, true);
        }
    }

    /**
     * Deletes a single stored file (a DocumentItem's stored_path or converted_path), refusing
     * to touch anything outside the staging root even if the given path has been tampered with.
     */
    public function deleteFile(string $relativePath): void
    {
        if ($relativePath === '') {
            return;
        }

        $absolute = $this->stagingRootPath . '/' . ltrim($relativePath, '/');
        $realFile = realpath($absolute);
        $realRoot = realpath($this->stagingRootPath);
        if ($realFile === false || $realRoot === false || !str_starts_with($realFile, $realRoot . '/')) {
            return;
        }

        @unlink($realFile);
    }

    private function createSetDirectory(string $setToken): string
    {
        $this->assertSafeToken($setToken);
        $directory = $this->stagingRootPath . '/' . $setToken;
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create staging directory for set "%s".', $setToken), 1721550200);
        }

        return $directory;
    }

    private function assertSafeToken(string $setToken): void
    {
        if (preg_match(self::SET_TOKEN_PATTERN, $setToken) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid staging set token "%s".', $setToken), 1721550000);
        }
    }

    public static function sanitizeDisplayFilename(string $filename): string
    {
        $normalized = str_replace('\\', '/', $filename);
        $basename = basename($normalized);
        $basename = preg_replace('/[\x00-\x1F\x7F]/', '', $basename) ?? '';
        $basename = trim($basename);

        return $basename !== '' ? mb_substr($basename, 0, 255) : 'unnamed';
    }
}

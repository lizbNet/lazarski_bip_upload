<?php

declare(strict_types=1);

use PrimeServices\LazarskiBipUpload\Exception\UploadValidationException;
use PrimeServices\LazarskiBipUpload\Service\TemporaryUploadService;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

$vendorAutoload = null;
for ($dir = __DIR__; $dir !== ($parent = dirname($dir)); $dir = $parent) {
    if (is_file($dir . '/vendor/autoload.php')) {
        $vendorAutoload = $dir . '/vendor/autoload.php';
        break;
    }
}
require_once $vendorAutoload;

// Avoid undefined-array-key warnings inside TYPO3\CMS\Core\Type\File\FileInfo::getMimeType()
// when running outside a full TYPO3 bootstrap.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['FileInfo']['fileExtensionToMimeType'] = [];

/**
 * Minimal PSR-7 UploadedFileInterface stub so the service can be exercised
 * without a real HTTP request or TYPO3's SAPI-upload-only UploadedFile class.
 */
final class FakeUploadedFile implements UploadedFileInterface
{
    public bool $wasMoved = false;

    public function __construct(
        private string $sourcePath,
        private ?int $size,
        private int $error,
        private ?string $clientFilename
    ) {
    }

    public function getStream(): StreamInterface
    {
        throw new \RuntimeException('Not implemented in test stub.');
    }

    public function moveTo(string $targetPath): void
    {
        if (!@rename($this->sourcePath, $targetPath)) {
            throw new \RuntimeException('Fake move failed.');
        }
        $this->wasMoved = true;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return null;
    }
}

function makeTempFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'lbu_test_');
    file_put_contents($path, $content);
    return $path;
}

// A minimal but structurally valid PDF signature, so libmagic detects application/pdf.
$validPdfContent = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";

$scratchRoot = sys_get_temp_dir() . '/lbu_staging_test_' . bin2hex(random_bytes(4));
$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $message);
    }
    $assertions++;
}

// --- valid PDF is stored, returns correct metadata, ends up under the staging root ---
$service = new TemporaryUploadService($scratchRoot);
$token = $service->generateSetToken();
assertTrue((bool)preg_match('/^[a-f0-9]{32}$/', $token), 'generateSetToken() must return a 32 char hex token');

$upload = new FakeUploadedFile(makeTempFile($validPdfContent), strlen($validPdfContent), UPLOAD_ERR_OK, 'Uchwała nr 1.pdf');
$result = $service->validateAndStore($token, $upload, 0);

assertTrue($upload->wasMoved, 'A valid upload must be moved into the staging directory');
assertTrue($result['extension'] === 'pdf', 'Extension must be detected as pdf');
assertTrue($result['mimeType'] === 'application/pdf', 'MIME type must be detected as application/pdf');
assertTrue($result['filename'] === 'Uchwała nr 1.pdf', 'Display filename must be preserved (sanitized)');
assertTrue(str_starts_with($result['path'], $token . '/'), 'Stored path must live under the set token directory');
assertTrue(is_file($scratchRoot . '/' . $result['path']), 'Stored file must exist on disk under the staging root');
assertTrue(!str_contains($scratchRoot, 'public'), 'Sanity: staging root used in this test is outside any "public" path');

// --- disallowed extension is rejected before any file is moved ---
$rejectedExtensionUpload = new FakeUploadedFile(makeTempFile('irrelevant'), 9, UPLOAD_ERR_OK, 'notes.txt');
try {
    $service->validateAndStore($token, $rejectedExtensionUpload, 1);
    throw new RuntimeException('Expected UploadValidationException for a .txt file was not thrown.');
} catch (UploadValidationException $e) {
    assertTrue(!$rejectedExtensionUpload->wasMoved, 'A rejected extension must never be moved into staging');
}

// --- oversized file is rejected before any file is moved ---
$oversizedUpload = new FakeUploadedFile(makeTempFile($validPdfContent), 60 * 1024 * 1024, UPLOAD_ERR_OK, 'big.pdf');
try {
    $service->validateAndStore($token, $oversizedUpload, 1);
    throw new RuntimeException('Expected UploadValidationException for an oversized file was not thrown.');
} catch (UploadValidationException $e) {
    assertTrue(!$oversizedUpload->wasMoved, 'An oversized file must never be moved into staging');
}

// --- content that doesn't match its .pdf extension is rejected and cleaned up after the move ---
$filesBefore = scandir($scratchRoot . '/' . $token) ?: [];
$mismatchedContentUpload = new FakeUploadedFile(makeTempFile('this is not a pdf'), 17, UPLOAD_ERR_OK, 'fake.pdf');
try {
    $service->validateAndStore($token, $mismatchedContentUpload, 1);
    throw new RuntimeException('Expected UploadValidationException for mismatched content was not thrown.');
} catch (UploadValidationException $e) {
    assertTrue($mismatchedContentUpload->wasMoved, 'Content is only verifiable after the move, so the move itself happens');
    $filesAfter = scandir($scratchRoot . '/' . $token) ?: [];
    assertTrue(
        $filesAfter === $filesBefore,
        'The mismatched-content file must be deleted again, leaving no orphan behind'
    );
}

// --- per-set file count limit ---
$limitUpload = new FakeUploadedFile(makeTempFile($validPdfContent), strlen($validPdfContent), UPLOAD_ERR_OK, 'another.pdf');
try {
    $service->validateAndStore($token, $limitUpload, 30);
    throw new RuntimeException('Expected UploadValidationException for exceeding the per-set file limit.');
} catch (UploadValidationException $e) {
    assertTrue(!$limitUpload->wasMoved, 'A file rejected for exceeding the count limit must never be moved');
}

// --- path traversal protection: malformed tokens are rejected outright ---
foreach (['../../etc', 'abc', '', str_repeat('a', 31), str_repeat('g', 32)] as $badToken) {
    try {
        $service->validateAndStore(
            $badToken,
            new FakeUploadedFile(makeTempFile($validPdfContent), strlen($validPdfContent), UPLOAD_ERR_OK, 'x.pdf'),
            0
        );
        throw new RuntimeException(sprintf('Expected InvalidArgumentException for malformed token "%s".', $badToken));
    } catch (InvalidArgumentException $e) {
        $assertions++;
    }
}

// --- sanitizeDisplayFilename strips directory components and control characters ---
assertTrue(
    TemporaryUploadService::sanitizeDisplayFilename('../../etc/passwd') === 'passwd',
    'sanitizeDisplayFilename must strip directory traversal components'
);
assertTrue(
    TemporaryUploadService::sanitizeDisplayFilename("evil\x00.pdf") === 'evil.pdf',
    'sanitizeDisplayFilename must strip control characters'
);
assertTrue(
    TemporaryUploadService::sanitizeDisplayFilename('') === 'unnamed',
    'sanitizeDisplayFilename must fall back to a placeholder for an empty name'
);

// --- deleteFile removes a single stored file, leaving the rest of the set directory intact ---
$secondUpload = new FakeUploadedFile(makeTempFile($validPdfContent), strlen($validPdfContent), UPLOAD_ERR_OK, 'second.pdf');
$secondResult = $service->validateAndStore($token, $secondUpload, 1);
$firstAbsolutePath = $scratchRoot . '/' . $result['path'];
$secondAbsolutePath = $scratchRoot . '/' . $secondResult['path'];
assertTrue(is_file($firstAbsolutePath) && is_file($secondAbsolutePath), 'Sanity: both files exist before deletion');

$service->deleteFile($result['path']);
assertTrue(!is_file($firstAbsolutePath), 'deleteFile() must remove the specified file');
assertTrue(is_file($secondAbsolutePath), 'deleteFile() must not touch other files in the same set directory');

// --- deleteFile is a safe no-op for a non-existent path (e.g. already removed) ---
$service->deleteFile($token . '/does-not-exist.pdf');
$assertions++;

// --- deleteFile is a safe no-op for an empty path ---
$service->deleteFile('');
$assertions++;

// --- deleteFile path-traversal protection: a decoy file outside the staging root survives ---
$decoyDir = sys_get_temp_dir() . '/lbu_decoy_' . bin2hex(random_bytes(4));
mkdir($decoyDir, 0775, true);
$decoyFile = $decoyDir . '/secret.txt';
file_put_contents($decoyFile, 'do not delete me');

$service->deleteFile('../' . basename($decoyDir) . '/secret.txt');
assertTrue(is_file($decoyFile), 'deleteFile() must never delete anything outside the staging root, even via a crafted relative path');
exec('rm -rf ' . escapeshellarg($decoyDir));

// --- deleteSet removes the whole set directory ---
$service->deleteSet($token);
assertTrue(!is_dir($scratchRoot . '/' . $token), 'deleteSet() must remove the entire set directory');

// Cleanup the scratch root used by this test run.
if (is_dir($scratchRoot)) {
    exec('rm -rf ' . escapeshellarg($scratchRoot));
}

echo sprintf("%d TemporaryUploadService assertions passed.\n", $assertions);

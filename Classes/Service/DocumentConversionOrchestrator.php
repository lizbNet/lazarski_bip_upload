<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

use PrimeServices\LazarskiBipUpload\Conversion\ConversionException;
use PrimeServices\LazarskiBipUpload\Conversion\DocumentConverterInterface;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItem;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItemStatus;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSet;
use PrimeServices\LazarskiBipUpload\Domain\Repository\DocumentItemRepository;

/**
 * Converts every still-UPLOADED item of a set to a final PDF: existing PDFs pass through
 * unchanged (converted_path = stored_path), DOCX items are converted via
 * DocumentConverterInterface. Conversion failures mark the item FAILED with an error message
 * rather than aborting the rest of the batch.
 */
class DocumentConversionOrchestrator
{
    public function __construct(
        private readonly DocumentConverterInterface $documentConverter,
        private readonly TemporaryUploadService $temporaryUploadService,
        private readonly DocumentItemRepository $documentItemRepository,
    ) {
    }

    /**
     * @param DocumentItem[] $items
     */
    public function convertPendingItems(DocumentSet $documentSet, array $items): void
    {
        foreach ($items as $item) {
            if ($item->getStatusEnum() !== DocumentItemStatus::UPLOADED) {
                continue;
            }

            if ($item->getFileExtension() === 'pdf') {
                $item->setConvertedPath($item->getStoredPath());
                $item->setStatusEnum(DocumentItemStatus::CONVERTED);
                $this->documentItemRepository->update($item);
                continue;
            }

            $absoluteSourcePath = $this->temporaryUploadService->getStagingRootPath() . '/' . $item->getStoredPath();
            $outputDirectory = dirname($absoluteSourcePath);

            try {
                $absoluteOutputPath = $this->documentConverter->convertToPdf($absoluteSourcePath, $outputDirectory);
                $item->setConvertedPath($documentSet->getStagingToken() . '/' . basename($absoluteOutputPath));
                $item->setStatusEnum(DocumentItemStatus::CONVERTED);
            } catch (ConversionException $exception) {
                $item->setStatusEnum(DocumentItemStatus::FAILED);
                $item->setErrorMessage($exception->getMessage());
            }

            $this->documentItemRepository->update($item);
        }
    }
}

<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class DocumentItem extends AbstractEntity
{
    protected int $documentSet = 0;
    protected string $originalFilename = '';
    protected string $fileExtension = '';
    protected string $mimeType = '';
    protected int $size = 0;
    protected string $storedPath = '';
    protected string $convertedPath = '';
    protected int $status = 0;
    protected string $errorMessage = '';
    protected string $suggestedTitle = '';
    protected int $titleConfidence = 0;
    protected string $titleSource = '';
    protected string $approvedTitle = '';
    protected string $approvedDescription = '';
    protected int $finalFile = 0;

    public function getDocumentSet(): int
    {
        return $this->documentSet;
    }

    public function setDocumentSet(int $documentSet): void
    {
        $this->documentSet = $documentSet;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): void
    {
        $this->originalFilename = $originalFilename;
    }

    public function getFileExtension(): string
    {
        return $this->fileExtension;
    }

    public function setFileExtension(string $fileExtension): void
    {
        $this->fileExtension = $fileExtension;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): void
    {
        $this->mimeType = $mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): void
    {
        $this->size = $size;
    }

    public function getStoredPath(): string
    {
        return $this->storedPath;
    }

    public function setStoredPath(string $storedPath): void
    {
        $this->storedPath = $storedPath;
    }

    public function getConvertedPath(): string
    {
        return $this->convertedPath;
    }

    public function setConvertedPath(string $convertedPath): void
    {
        $this->convertedPath = $convertedPath;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function getStatusEnum(): DocumentItemStatus
    {
        return DocumentItemStatus::from($this->status);
    }

    public function setStatusEnum(DocumentItemStatus $status): void
    {
        $this->status = $status->value;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getSuggestedTitle(): string
    {
        return $this->suggestedTitle;
    }

    public function setSuggestedTitle(string $suggestedTitle): void
    {
        $this->suggestedTitle = $suggestedTitle;
    }

    public function getTitleConfidence(): int
    {
        return $this->titleConfidence;
    }

    public function setTitleConfidence(int $titleConfidence): void
    {
        $this->titleConfidence = $titleConfidence;
    }

    public function getTitleSource(): string
    {
        return $this->titleSource;
    }

    public function setTitleSource(string $titleSource): void
    {
        $this->titleSource = $titleSource;
    }

    public function getApprovedTitle(): string
    {
        return $this->approvedTitle;
    }

    public function setApprovedTitle(string $approvedTitle): void
    {
        $this->approvedTitle = $approvedTitle;
    }

    public function getApprovedDescription(): string
    {
        return $this->approvedDescription;
    }

    public function setApprovedDescription(string $approvedDescription): void
    {
        $this->approvedDescription = $approvedDescription;
    }

    public function getFinalFile(): int
    {
        return $this->finalFile;
    }

    public function setFinalFile(int $finalFile): void
    {
        $this->finalFile = $finalFile;
    }
}

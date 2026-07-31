<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class DocumentSet extends AbstractEntity
{
    protected string $stagingToken = '';
    protected int $status = 0;
    protected int $expiresAt = 0;
    protected int $confirmedPage = 0;
    protected int $cruserId = 0;
    protected string $suggestedType = '';
    protected int $typeConfidence = 0;
    protected string $suggestedPageTitle = '';
    protected string $suggestedSubtitle = '';
    protected string $suggestedSlug = '';
    protected string $analysisPayload = '';
    protected string $approvedType = '';
    protected string $approvedPageTitle = '';
    protected string $approvedSubtitle = '';
    protected string $approvedSlug = '';
    protected int $approvedParentPage = 0;
    protected string $approvedFalFolder = '';
    protected string $suggestedAutoFolder = '';
    protected bool $includeAutoFolder = true;
    protected string $approvedFilePrefix = '';
    protected string $approvedAuthor = '';
    /** Unix timestamp of the document's issue date; 0 means "unset - use the publication time". */
    protected int $approvedStartDate = 0;

    public function getStagingToken(): string
    {
        return $this->stagingToken;
    }

    public function setStagingToken(string $stagingToken): void
    {
        $this->stagingToken = $stagingToken;
    }

    public function getCruserId(): int
    {
        return $this->cruserId;
    }

    public function setCruserId(int $cruserId): void
    {
        $this->cruserId = $cruserId;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function getStatusEnum(): DocumentSetStatus
    {
        return DocumentSetStatus::from($this->status);
    }

    public function setStatusEnum(DocumentSetStatus $status): void
    {
        $this->status = $status->value;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(int $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function getConfirmedPage(): int
    {
        return $this->confirmedPage;
    }

    public function setConfirmedPage(int $confirmedPage): void
    {
        $this->confirmedPage = $confirmedPage;
    }

    public function getSuggestedType(): string
    {
        return $this->suggestedType;
    }

    public function setSuggestedType(string $suggestedType): void
    {
        $this->suggestedType = $suggestedType;
    }

    public function getTypeConfidence(): int
    {
        return $this->typeConfidence;
    }

    public function setTypeConfidence(int $typeConfidence): void
    {
        $this->typeConfidence = $typeConfidence;
    }

    public function getSuggestedPageTitle(): string
    {
        return $this->suggestedPageTitle;
    }

    public function setSuggestedPageTitle(string $suggestedPageTitle): void
    {
        $this->suggestedPageTitle = $suggestedPageTitle;
    }

    public function getSuggestedSubtitle(): string
    {
        return $this->suggestedSubtitle;
    }

    public function setSuggestedSubtitle(string $suggestedSubtitle): void
    {
        $this->suggestedSubtitle = $suggestedSubtitle;
    }

    public function getSuggestedSlug(): string
    {
        return $this->suggestedSlug;
    }

    public function setSuggestedSlug(string $suggestedSlug): void
    {
        $this->suggestedSlug = $suggestedSlug;
    }

    public function getAnalysisPayload(): string
    {
        return $this->analysisPayload;
    }

    public function setAnalysisPayload(string $analysisPayload): void
    {
        $this->analysisPayload = $analysisPayload;
    }

    public function getApprovedType(): string
    {
        return $this->approvedType;
    }

    public function setApprovedType(string $approvedType): void
    {
        $this->approvedType = $approvedType;
    }

    public function getApprovedPageTitle(): string
    {
        return $this->approvedPageTitle;
    }

    public function setApprovedPageTitle(string $approvedPageTitle): void
    {
        $this->approvedPageTitle = $approvedPageTitle;
    }

    public function getApprovedSubtitle(): string
    {
        return $this->approvedSubtitle;
    }

    public function setApprovedSubtitle(string $approvedSubtitle): void
    {
        $this->approvedSubtitle = $approvedSubtitle;
    }

    public function getApprovedSlug(): string
    {
        return $this->approvedSlug;
    }

    public function setApprovedSlug(string $approvedSlug): void
    {
        $this->approvedSlug = $approvedSlug;
    }

    public function getApprovedParentPage(): int
    {
        return $this->approvedParentPage;
    }

    public function setApprovedParentPage(int $approvedParentPage): void
    {
        $this->approvedParentPage = $approvedParentPage;
    }

    public function getApprovedFalFolder(): string
    {
        return $this->approvedFalFolder;
    }

    public function setApprovedFalFolder(string $approvedFalFolder): void
    {
        $this->approvedFalFolder = $approvedFalFolder;
    }

    public function getSuggestedAutoFolder(): string
    {
        return $this->suggestedAutoFolder;
    }

    public function setSuggestedAutoFolder(string $suggestedAutoFolder): void
    {
        $this->suggestedAutoFolder = $suggestedAutoFolder;
    }

    public function isIncludeAutoFolder(): bool
    {
        return $this->includeAutoFolder;
    }

    public function setIncludeAutoFolder(bool $includeAutoFolder): void
    {
        $this->includeAutoFolder = $includeAutoFolder;
    }

    public function getApprovedFilePrefix(): string
    {
        return $this->approvedFilePrefix;
    }

    public function setApprovedFilePrefix(string $approvedFilePrefix): void
    {
        $this->approvedFilePrefix = $approvedFilePrefix;
    }

    public function getApprovedAuthor(): string
    {
        return $this->approvedAuthor;
    }

    public function setApprovedAuthor(string $approvedAuthor): void
    {
        $this->approvedAuthor = $approvedAuthor;
    }

    public function getApprovedStartDate(): int
    {
        return $this->approvedStartDate;
    }

    public function setApprovedStartDate(int $approvedStartDate): void
    {
        $this->approvedStartDate = $approvedStartDate;
    }
}

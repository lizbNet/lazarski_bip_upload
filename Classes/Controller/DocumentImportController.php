<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Controller;

use PrimeServices\LazarskiBipUpload\Analysis\Candidate;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItem;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSet;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSetStatus;
use PrimeServices\LazarskiBipUpload\Domain\Repository\DocumentItemRepository;
use PrimeServices\LazarskiBipUpload\Domain\Repository\DocumentSetRepository;
use PrimeServices\LazarskiBipUpload\Exception\UploadValidationException;
use PrimeServices\LazarskiBipUpload\Metadata\PdfMetadataException;
use PrimeServices\LazarskiBipUpload\Metadata\PdfMetadataService;
use PrimeServices\LazarskiBipUpload\Service\AnalysisResult;
use PrimeServices\LazarskiBipUpload\Service\ConfirmationValidator;
use PrimeServices\LazarskiBipUpload\Service\DestinationResolver;
use PrimeServices\LazarskiBipUpload\Service\DocumentAnalysisService;
use PrimeServices\LazarskiBipUpload\Service\DocumentConversionOrchestrator;
use PrimeServices\LazarskiBipUpload\Service\DocumentSetAccessGuard;
use PrimeServices\LazarskiBipUpload\Service\DocumentSetLifecycle;
use PrimeServices\LazarskiBipUpload\Service\DocumentSetPublisher;
use PrimeServices\LazarskiBipUpload\Service\PageBreadcrumbResolver;
use PrimeServices\LazarskiBipUpload\Service\PageSlugChecker;
use PrimeServices\LazarskiBipUpload\Service\PublishException;
use PrimeServices\LazarskiBipUpload\Service\TemporaryUploadService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\FormProtection\BackendFormProtection;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

#[AsController]
class DocumentImportController extends ActionController
{
    private const FORM_NAME = 'tx_lazarskibipupload_documentimport';
    private const DRAFT_LIFETIME_SECONDS = 60 * 60 * 24 * 2;

    public function __construct(
        protected readonly DocumentSetRepository $documentSetRepository,
        protected readonly DocumentItemRepository $documentItemRepository,
        protected readonly TemporaryUploadService $temporaryUploadService,
        protected readonly DocumentConversionOrchestrator $documentConversionOrchestrator,
        protected readonly DocumentAnalysisService $documentAnalysisService,
        protected readonly DestinationResolver $destinationResolver,
        protected readonly PageSlugChecker $pageSlugChecker,
        protected readonly PageBreadcrumbResolver $pageBreadcrumbResolver,
        protected readonly PdfMetadataService $pdfMetadataService,
        protected readonly DocumentSetPublisher $documentSetPublisher,
        protected readonly PersistenceManagerInterface $persistenceManager,
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly FormProtectionFactory $formProtectionFactory,
        protected readonly PageRenderer $pageRenderer,
        protected readonly ComponentFactory $componentFactory,
        protected readonly IconFactory $iconFactory,
    ) {
    }

    public function newAction(): ResponseInterface
    {
        $backendUserUid = (int)$this->getBackendUser()->getUserId();
        $openSets = $this->documentSetRepository->findOpenByBackendUser($backendUserUid);

        $itemsBySet = [];
        foreach ($openSets as $openSet) {
            $itemsBySet[$openSet->getUid()] = $this->documentItemRepository->findByDocumentSet((int)$openSet->getUid());
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle($this->getLanguageService()->sL(
            'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'
        ));
        $moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
        $moduleTemplate->assignMultiple([
            'openSets' => $openSets,
            'itemsBySet' => $itemsBySet,
            'formToken' => $this->getFormProtection()->generateToken(self::FORM_NAME, 'create'),
            'cancelFormToken' => $this->getFormProtection()->generateToken(self::FORM_NAME, 'cancel'),
        ]);

        return $moduleTemplate->renderResponse('DocumentImport/New');
    }

    public function createAction(): ResponseInterface
    {
        $parsedBody = (array)$this->request->getParsedBody();
        $submittedToken = (string)($parsedBody['formToken'] ?? '');
        if (!$this->getFormProtection()->validateToken($submittedToken, self::FORM_NAME, 'create')) {
            $this->throwStatus(403, 'Forbidden', 'The form could not be validated. Please reload and try again.');
        }

        $backendUser = $this->getBackendUser();
        $backendUserUid = (int)$backendUser->getUserId();

        $documentSet = $this->resolveOrCreateDocumentSet((int)($parsedBody['documentSet'] ?? 0), $backendUser);

        // Nested under 'upload' deliberately: groups all uploaded files under one key,
        // independent of how Extbase normalizes/merges the raw PSR-7 uploaded-files array.
        $uploadedFilesParameter = $this->request->getUploadedFiles()['upload']['files'] ?? [];
        if (!is_array($uploadedFilesParameter) || $uploadedFilesParameter === []) {
            $this->addFlashMessage(
                $this->translate('upload.noFilesChosen'),
                '',
                ContextualFeedbackSeverity::WARNING
            );
            return $this->redirect('new');
        }

        $existingItemCount = $this->documentItemRepository->countByDocumentSet((int)$documentSet->getUid());
        $storedCount = 0;
        foreach ($uploadedFilesParameter as $uploadedFile) {
            $clientFilename = $uploadedFile->getClientFilename() ?? $this->translate('upload.unnamedFile');
            try {
                $stored = $this->temporaryUploadService->validateAndStore(
                    $documentSet->getStagingToken(),
                    $uploadedFile,
                    $existingItemCount + $storedCount
                );
            } catch (UploadValidationException $exception) {
                $this->addFlashMessage(
                    sprintf('"%s": %s', $clientFilename, $exception->getMessage()),
                    $this->translate('upload.rejected'),
                    ContextualFeedbackSeverity::ERROR
                );
                continue;
            }

            $documentItem = GeneralUtility::makeInstance(DocumentItem::class);
            $documentItem->setDocumentSet((int)$documentSet->getUid());
            $documentItem->setOriginalFilename($stored['filename']);
            $documentItem->setFileExtension($stored['extension']);
            $documentItem->setMimeType($stored['mimeType']);
            $documentItem->setSize($stored['size']);
            $documentItem->setStoredPath($stored['path']);
            $this->documentItemRepository->add($documentItem);
            $storedCount++;
        }

        if ($storedCount > 0 && $documentSet->getStatus() === DocumentSetStatus::DRAFT->value) {
            DocumentSetLifecycle::assertCanTransition(DocumentSetStatus::DRAFT, DocumentSetStatus::STAGED);
            $documentSet->setStatusEnum(DocumentSetStatus::STAGED);
            $this->documentSetRepository->update($documentSet);
        }

        $this->persistenceManager->persistAll();

        if ($storedCount > 0) {
            $allItems = $this->documentItemRepository->findByDocumentSet((int)$documentSet->getUid());

            $analysisResult = $this->documentAnalysisService->analyze($documentSet, $allItems);
            $this->applyAnalysisResult($documentSet, $allItems, $analysisResult);

            $this->documentConversionOrchestrator->convertPendingItems($documentSet, $allItems);

            $this->refreshExpiry($documentSet);
            $this->documentSetRepository->update($documentSet);
            $this->persistenceManager->persistAll();

            $this->addFlashMessage(
                $this->translate('upload.stored', [$storedCount]),
                '',
                ContextualFeedbackSeverity::OK
            );
        }

        return $this->redirect('new');
    }

    /**
     * @param DocumentItem[] $items
     */
    private function applyAnalysisResult(DocumentSet $documentSet, array $items, AnalysisResult $analysisResult): void
    {
        $documentSet->setSuggestedType($analysisResult->type->type);
        $documentSet->setTypeConfidence($analysisResult->type->confidence);
        $documentSet->setSuggestedPageTitle(($analysisResult->pageTitleCandidates[0] ?? null)?->value ?? '');
        $documentSet->setSuggestedSubtitle(($analysisResult->subtitleCandidates[0] ?? null)?->value ?? '');
        $documentSet->setSuggestedSlug(($analysisResult->slugCandidates[0] ?? null)?->value ?? '');
        $documentSet->setSuggestedAutoFolder($analysisResult->suggestedAutoFolder);
        $documentSet->setAnalysisPayload((string)json_encode([
            'type' => [
                'type' => $analysisResult->type->type,
                'confidence' => $analysisResult->type->confidence,
                'reason' => $analysisResult->type->reason,
            ],
            'pageTitleCandidates' => self::candidatesToArray($analysisResult->pageTitleCandidates),
            'subtitleCandidates' => self::candidatesToArray($analysisResult->subtitleCandidates),
            'slugCandidates' => self::candidatesToArray($analysisResult->slugCandidates),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $itemsByUid = [];
        foreach ($items as $item) {
            $itemsByUid[$item->getUid()] = $item;
        }

        foreach ($analysisResult->itemTitleCandidates as $itemUid => $candidates) {
            $item = $itemsByUid[$itemUid] ?? null;
            $topCandidate = $candidates[0] ?? null;
            if ($item === null || $topCandidate === null) {
                continue;
            }
            $item->setSuggestedTitle($topCandidate->value);
            $item->setTitleConfidence($topCandidate->confidence);
            $item->setTitleSource($topCandidate->source);
            $this->documentItemRepository->update($item);
        }
    }

    /**
     * @param Candidate[] $candidates
     * @return array<int, array{value: string, source: string, confidence: int, reason: string}>
     */
    private static function candidatesToArray(array $candidates): array
    {
        return array_map(
            static fn (Candidate $candidate): array => [
                'value' => $candidate->value,
                'source' => $candidate->source,
                'confidence' => $candidate->confidence,
                'reason' => $candidate->reason,
            ],
            $candidates
        );
    }

    public function cancelAction(): ResponseInterface
    {
        $parsedBody = (array)$this->request->getParsedBody();
        $submittedToken = (string)($parsedBody['formToken'] ?? '');
        if (!$this->getFormProtection()->validateToken($submittedToken, self::FORM_NAME, 'cancel')) {
            $this->throwStatus(403, 'Forbidden', 'The form could not be validated. Please reload and try again.');
        }

        $documentSetUid = (int)($parsedBody['documentSet'] ?? 0);
        $documentSet = $documentSetUid > 0 ? $this->documentSetRepository->findByUid($documentSetUid) : null;
        if (!$documentSet instanceof DocumentSet) {
            $this->addFlashMessage($this->translate('set.notFound'), '', ContextualFeedbackSeverity::WARNING);
            return $this->redirect('new');
        }

        $backendUser = $this->getBackendUser();
        if (!DocumentSetAccessGuard::isEditableBy($documentSet->getCruserId(), (int)$backendUser->getUserId(), (bool)$backendUser->isAdmin())) {
            $this->throwStatus(403, 'Forbidden', 'You are not allowed to modify this document set.');
        }

        if (!DocumentSetLifecycle::canTransition($documentSet->getStatusEnum(), DocumentSetStatus::CANCELLED)) {
            $this->addFlashMessage($this->translate('set.cannotCancel'), '', ContextualFeedbackSeverity::WARNING);
            return $this->redirect('new');
        }

        $documentSet->setStatusEnum(DocumentSetStatus::CANCELLED);
        $this->documentSetRepository->update($documentSet);
        $this->persistenceManager->persistAll();

        $this->temporaryUploadService->deleteSet($documentSet->getStagingToken());

        $this->addFlashMessage($this->translate('set.cancelled'), '', ContextualFeedbackSeverity::OK);

        return $this->redirect('new');
    }

    public function reviewAction(): ResponseInterface
    {
        $isPost = $this->request->getMethod() === 'POST';
        $parsedBody = $isPost ? (array)$this->request->getParsedBody() : [];
        $documentSetUid = $isPost
            ? (int)($parsedBody['documentSet'] ?? 0)
            : (int)($this->request->getQueryParams()['documentSet'] ?? 0);

        $documentSet = $this->fetchOwnedDocumentSetOrFail($documentSetUid);
        $items = $this->documentItemRepository->findByDocumentSet((int)$documentSet->getUid());

        if ($isPost) {
            $submittedToken = (string)($parsedBody['formToken'] ?? '');
            if (!$this->getFormProtection()->validateToken($submittedToken, self::FORM_NAME, 'review')) {
                $this->throwStatus(403, 'Forbidden', 'The form could not be validated. Please reload and try again.');
            }
            if ($documentSet->getStatusEnum() !== DocumentSetStatus::STAGED) {
                $this->addFlashMessage($this->translate('review.cannotEdit'), '', ContextualFeedbackSeverity::WARNING);
                return $this->redirectToReview($documentSetUid);
            }

            $this->applyReviewSubmission($documentSet, $items, $parsedBody);
            $this->refreshExpiry($documentSet);
            $this->documentSetRepository->update($documentSet);
            $this->persistenceManager->persistAll();

            $this->addFlashMessage($this->translate('review.saved'), '', ContextualFeedbackSeverity::OK);
            return $this->redirectToReview($documentSetUid);
        }

        // Sets classified before the zarzadzenie rektor/prezydent split still carry the plain
        // legacy "zarzadzenie" type value - the review form's type <select> no longer has an
        // option for it, so normalize to the new default (zarzadzenie_rektora) here rather than
        // leaving it unselectable (which would silently drop to "" - unknown - on next save).
        if ($documentSet->getApprovedType() === 'zarzadzenie') {
            $documentSet->setApprovedType('zarzadzenie_rektora');
        }
        if ($documentSet->getSuggestedType() === 'zarzadzenie') {
            $documentSet->setSuggestedType('zarzadzenie_rektora');
        }

        $suggestedParentPageUid = $this->destinationResolver->suggestParentPageUid($documentSet->getSuggestedType());
        $suggestedFalFolderIdentifier = $this->destinationResolver->suggestFalFolderIdentifier($documentSet->getSuggestedType());
        $effectiveParentPage = $documentSet->getApprovedParentPage() > 0 ? $documentSet->getApprovedParentPage() : $suggestedParentPageUid;
        $effectiveFalFolder = $documentSet->getApprovedFalFolder() !== '' ? $documentSet->getApprovedFalFolder() : $suggestedFalFolderIdentifier;
        $effectiveSlug = $documentSet->getApprovedSlug() !== '' ? $documentSet->getApprovedSlug() : $documentSet->getSuggestedSlug();

        // Defaults to the effective slug plus a trailing underscore (e.g. "uchwala-31-2026_"),
        // matching every other suggested-vs-approved field on this form: an editor who never
        // touches the field gets this as the file prefix, but can clear it (empty = no prefix)
        // or type/browse something else entirely - the separator is just part of the value, not
        // auto-inserted at publish time, so the editor has full control over it.
        $effectiveFilePrefix = $documentSet->getApprovedFilePrefix() !== '' ? $documentSet->getApprovedFilePrefix() : $effectiveSlug . '_';

        // The base folder above stays editable/browsable as-is; the auto-folder checkbox only
        // affects this preview of the FINAL path actually used at publish time (see
        // DocumentSetPublisher), so toggling it never silently rewrites what the editor typed
        // or browsed to.
        $finalFalFolderPreview = $effectiveFalFolder;
        if ($documentSet->isIncludeAutoFolder() && $documentSet->getSuggestedAutoFolder() !== '') {
            $finalFalFolderPreview = rtrim($effectiveFalFolder, '/') . '/' . $documentSet->getSuggestedAutoFolder() . '/';
        }

        // Wires the native page-tree/folder "Browse" popups to the plain approvedParentPage/
        // approvedFalFolder inputs below - see element-browser-fields.js for why a custom
        // module is needed (this is a plain Fluid form, not a FormEngine record-edit screen).
        $this->pageRenderer->loadJavaScriptModule('@typo3/lazarski-bip-upload/element-browser-fields.js');

        $formToken = $this->getFormProtection()->generateToken(self::FORM_NAME, 'review');
        $confirmFormToken = $this->getFormProtection()->generateToken(self::FORM_NAME, 'confirm');
        $cancelFormToken = $this->getFormProtection()->generateToken(self::FORM_NAME, 'cancel');
        $deleteSetConfirmMessage = $this->translate('review.deleteSetConfirm');

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle($this->getLanguageService()->sL(
            'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'
        ));
        $moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
        $moduleTemplate->assignMultiple([
            'documentSet' => $documentSet,
            'items' => $items,
            'effectiveType' => $documentSet->getApprovedType() !== '' ? $documentSet->getApprovedType() : $documentSet->getSuggestedType(),
            'effectivePageTitle' => $documentSet->getApprovedPageTitle() !== '' ? $documentSet->getApprovedPageTitle() : $documentSet->getSuggestedPageTitle(),
            'effectiveSubtitle' => $documentSet->getApprovedSubtitle() !== '' ? $documentSet->getApprovedSubtitle() : $documentSet->getSuggestedSubtitle(),
            'effectiveSlug' => $effectiveSlug,
            'effectiveParentPage' => $effectiveParentPage,
            'effectiveFalFolder' => $effectiveFalFolder,
            'finalFalFolderPreview' => $finalFalFolderPreview,
            'effectiveFilePrefix' => $effectiveFilePrefix,
            'destinationBreadcrumb' => $this->pageBreadcrumbResolver->resolve($effectiveParentPage),
            'regenerateFormToken' => $this->getFormProtection()->generateToken(self::FORM_NAME, 'regenerateSuggestions'),
            'removeItemFormToken' => $this->getFormProtection()->generateToken(self::FORM_NAME, 'removeItem'),
            'removeItemConfirmMessage' => $this->translate('review.removeItemConfirm'),
            'generateItemFormToken' => $this->getFormProtection()->generateToken(self::FORM_NAME, 'generateItem'),
            'cancelFormToken' => $cancelFormToken,
            'isAiConfigured' => $this->documentAnalysisService->isAiConfigured(),
        ]);

        $this->registerReviewDocHeaderButtons($moduleTemplate, $formToken, $confirmFormToken, $deleteSetConfirmMessage);

        return $moduleTemplate->renderResponse('DocumentImport/Review');
    }

    /**
     * Builds the Review screen's doc header: back-to-list, save, confirm, and delete-set
     * buttons. Save/confirm submit the external "reviewForm" (defined in the template body)
     * via the HTML5 form="" attribute; delete submits the hidden "cancelSetForm".
     */
    private function registerReviewDocHeaderButtons(
        ModuleTemplate $moduleTemplate,
        string $formToken,
        string $confirmFormToken,
        string $deleteSetConfirmMessage
    ): void {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $closeButton = $this->componentFactory->createGenericButton()
            ->setTag('a')
            ->setHref((string)$this->uriBuilder->reset()->uriFor('new'))
            ->setTitle($this->translate('review.backToList'))
            ->setIcon($this->iconFactory->getIcon('actions-close', IconSize::SMALL));
        $buttonBar->addButton($closeButton, ButtonBar::BUTTON_POSITION_LEFT);

        $saveButton = $this->componentFactory->createGenericButton()
            ->setLabel($this->translate('review.save'))
            ->setShowLabelText(true)
            ->setTitle($this->translate('review.save'))
            ->setIcon($this->iconFactory->getIcon('actions-document-save', IconSize::SMALL))
            ->setAttributes([
                'type' => 'submit',
                'form' => 'reviewForm',
                'name' => 'formToken',
                'value' => $formToken,
                'formmethod' => 'post',
                'formaction' => (string)$this->uriBuilder->reset()->uriFor('review'),
            ]);
        $buttonBar->addButton($saveButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);

        $confirmButton = $this->componentFactory->createGenericButton()
            ->setLabel($this->translate('confirm.submit'))
            ->setShowLabelText(true)
            ->setTitle($this->translate('confirm.submit'))
            ->setIcon($this->iconFactory->getIcon('actions-document-save', IconSize::SMALL))
            ->setAttributes([
                'type' => 'submit',
                'form' => 'reviewForm',
                'name' => 'formToken',
                'value' => $confirmFormToken,
                'formmethod' => 'post',
                'formaction' => (string)$this->uriBuilder->reset()->uriFor('confirm'),
            ]);
        $buttonBar->addButton($confirmButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);

        $deleteButton = $this->componentFactory->createGenericButton()
            ->setTitle($this->translate('review.deleteSet'))
            ->setIcon($this->iconFactory->getIcon('actions-edit-delete', IconSize::SMALL))
            ->setAttributes([
                'type' => 'submit',
                'form' => 'cancelSetForm',
                'onclick' => 'return confirm(' . json_encode($deleteSetConfirmMessage) . ');',
            ]);
        $buttonBar->addButton($deleteButton, ButtonBar::BUTTON_POSITION_RIGHT, 2);
    }

    /**
     * Streams a staged item's file (the converted PDF once available, otherwise the original
     * upload) inline, so an editor can preview it directly from the review screen without it
     * ever being exposed outside this access-controlled module - the same private staging
     * root every other action reads from, never made public.
     *
     * A pure read (no state change), so - unlike every other action here - this intentionally
     * does not require a form-protection token: CSRF protection only matters for requests that
     * change something.
     */
    public function previewItemAction(): ResponseInterface
    {
        $queryParams = $this->request->getQueryParams();
        $documentSetUid = (int)($queryParams['documentSet'] ?? 0);
        $documentSet = $this->fetchOwnedDocumentSetOrFail($documentSetUid);

        $itemUid = (int)($queryParams['item'] ?? 0);
        $item = $itemUid > 0 ? $this->documentItemRepository->findByUid($itemUid) : null;
        if (!$item instanceof DocumentItem || $item->getDocumentSet() !== (int)$documentSet->getUid()) {
            $this->throwStatus(404, 'Not Found', 'Document item not found.');
        }

        $relativePath = $item->getConvertedPath() !== '' ? $item->getConvertedPath() : $item->getStoredPath();
        $absolutePath = $relativePath !== '' ? $this->temporaryUploadService->getStagingRootPath() . '/' . $relativePath : '';
        if ($absolutePath === '' || !is_file($absolutePath)) {
            $this->throwStatus(404, 'Not Found', 'The file could not be found.');
        }

        $mimeType = $item->getConvertedPath() !== '' ? 'application/pdf' : ($item->getMimeType() !== '' ? $item->getMimeType() : 'application/octet-stream');

        return new Response(
            new Stream($absolutePath, 'r'),
            200,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . addslashes($item->getOriginalFilename()) . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function removeItemAction(): ResponseInterface
    {
        // Read from the query string, not the POST body: this action is triggered by a
        // per-item button living INSIDE the main review <form> (HTML forbids nested <form>
        // elements), so its documentSet/item/formToken travel via the button's own
        // formaction URL instead of colliding with the surrounding form's own POST fields
        // (which include a differently-scoped formToken for the Save/Confirm buttons).
        $queryParams = $this->request->getQueryParams();
        $submittedToken = (string)($queryParams['formToken'] ?? '');
        if (!$this->getFormProtection()->validateToken($submittedToken, self::FORM_NAME, 'removeItem')) {
            $this->throwStatus(403, 'Forbidden', 'The form could not be validated. Please reload and try again.');
        }

        $documentSetUid = (int)($queryParams['documentSet'] ?? 0);
        $documentSet = $this->fetchOwnedDocumentSetOrFail($documentSetUid);

        if ($documentSet->getStatusEnum() !== DocumentSetStatus::STAGED) {
            $this->addFlashMessage($this->translate('review.cannotEdit'), '', ContextualFeedbackSeverity::WARNING);
            return $this->redirectToReview($documentSetUid);
        }

        $itemUid = (int)($queryParams['item'] ?? 0);
        $item = $itemUid > 0 ? $this->documentItemRepository->findByUid($itemUid) : null;
        if (!$item instanceof DocumentItem || $item->getDocumentSet() !== (int)$documentSet->getUid()) {
            $this->addFlashMessage($this->translate('review.itemNotFound'), '', ContextualFeedbackSeverity::WARNING);
            return $this->redirectToReview($documentSetUid);
        }

        $this->temporaryUploadService->deleteFile($item->getStoredPath());
        if ($item->getConvertedPath() !== '' && $item->getConvertedPath() !== $item->getStoredPath()) {
            $this->temporaryUploadService->deleteFile($item->getConvertedPath());
        }

        $this->documentItemRepository->remove($item);
        $this->persistenceManager->persistAll();

        $remainingItems = $this->documentItemRepository->findByDocumentSet((int)$documentSet->getUid());

        if ($remainingItems === []) {
            // No files left: this is a draft again, not a reviewable set - matches the meaning
            // DRAFT already has elsewhere (an in-progress set with zero items).
            DocumentSetLifecycle::assertCanTransition($documentSet->getStatusEnum(), DocumentSetStatus::DRAFT);
            $documentSet->setStatusEnum(DocumentSetStatus::DRAFT);
            $documentSet->setSuggestedType('');
            $documentSet->setTypeConfidence(0);
            $documentSet->setSuggestedPageTitle('');
            $documentSet->setSuggestedSubtitle('');
            $documentSet->setSuggestedSlug('');
            $documentSet->setAnalysisPayload('');
            $this->refreshExpiry($documentSet);
            $this->documentSetRepository->update($documentSet);
            $this->persistenceManager->persistAll();

            $this->addFlashMessage($this->translate('review.itemRemoved'), '', ContextualFeedbackSeverity::OK);
            return $this->redirect('new');
        }

        $analysisResult = $this->documentAnalysisService->analyze($documentSet, $remainingItems);
        $this->applyAnalysisResult($documentSet, $remainingItems, $analysisResult);
        $this->refreshExpiry($documentSet);
        $this->documentSetRepository->update($documentSet);
        $this->persistenceManager->persistAll();

        $this->addFlashMessage($this->translate('review.itemRemoved'), '', ContextualFeedbackSeverity::OK);

        return $this->redirectToReview($documentSetUid);
    }

    public function generateItemAction(): ResponseInterface
    {
        // Same query-string-token pattern as removeItemAction(), for the same reason: this is
        // a per-item button living inside the main review <form>, so it can't be its own
        // nested <form>.
        $queryParams = $this->request->getQueryParams();
        $submittedToken = (string)($queryParams['formToken'] ?? '');
        if (!$this->getFormProtection()->validateToken($submittedToken, self::FORM_NAME, 'generateItem')) {
            $this->throwStatus(403, 'Forbidden', 'The form could not be validated. Please reload and try again.');
        }

        $documentSetUid = (int)($queryParams['documentSet'] ?? 0);
        $documentSet = $this->fetchOwnedDocumentSetOrFail($documentSetUid);

        if ($documentSet->getStatusEnum() !== DocumentSetStatus::STAGED) {
            $this->addFlashMessage($this->translate('review.cannotEdit'), '', ContextualFeedbackSeverity::WARNING);
            return $this->redirectToReview($documentSetUid);
        }

        $itemUid = (int)($queryParams['item'] ?? 0);
        $item = $itemUid > 0 ? $this->documentItemRepository->findByUid($itemUid) : null;
        if (!$item instanceof DocumentItem || $item->getDocumentSet() !== (int)$documentSet->getUid()) {
            $this->addFlashMessage($this->translate('review.itemNotFound'), '', ContextualFeedbackSeverity::WARNING);
            return $this->redirectToReview($documentSetUid);
        }

        if (!$this->documentAnalysisService->isAiConfigured()) {
            $this->addFlashMessage($this->translate('review.aiNotConfigured'), '', ContextualFeedbackSeverity::WARNING);
            return $this->redirectToReview($documentSetUid);
        }

        $result = $this->documentAnalysisService->generateAiSuggestionForItem($item);
        if ($result === null) {
            $this->addFlashMessage($this->translate('review.itemGenerationFailed'), '', ContextualFeedbackSeverity::ERROR);
            return $this->redirectToReview($documentSetUid);
        }

        // An explicit, per-file trigger: overwrite the approved values directly (unlike the
        // whole-set regenerate, there's no risk of surprising other files, so there is no need
        // for the suggested/approved indirection here - the editor asked for this file's title/
        // description right now and expects to see it immediately).
        $item->setApprovedTitle($result['title']);
        $item->setSuggestedTitle($result['title']);
        $item->setTitleConfidence(95);
        $item->setTitleSource('openai');
        if ($result['description'] !== '') {
            $item->setApprovedDescription($result['description']);
        }
        $this->documentItemRepository->update($item);
        $this->refreshExpiry($documentSet);
        $this->documentSetRepository->update($documentSet);
        $this->persistenceManager->persistAll();

        $this->addFlashMessage($this->translate('review.itemGenerated'), '', ContextualFeedbackSeverity::OK);

        return $this->redirectToReview($documentSetUid);
    }

    public function regenerateSuggestionsAction(): ResponseInterface
    {
        $parsedBody = (array)$this->request->getParsedBody();
        $submittedToken = (string)($parsedBody['formToken'] ?? '');
        if (!$this->getFormProtection()->validateToken($submittedToken, self::FORM_NAME, 'regenerateSuggestions')) {
            $this->throwStatus(403, 'Forbidden', 'The form could not be validated. Please reload and try again.');
        }

        $documentSetUid = (int)($parsedBody['documentSet'] ?? 0);
        $documentSet = $this->fetchOwnedDocumentSetOrFail($documentSetUid);

        if ($documentSet->getStatusEnum() !== DocumentSetStatus::STAGED) {
            $this->addFlashMessage($this->translate('review.cannotEdit'), '', ContextualFeedbackSeverity::WARNING);
            return $this->redirectToReview($documentSetUid);
        }

        $items = $this->documentItemRepository->findByDocumentSet((int)$documentSet->getUid());

        $analysisResult = $this->documentAnalysisService->analyze($documentSet, $items);
        $this->applyAnalysisResult($documentSet, $items, $analysisResult);

        // A regenerate request should actually be visible on the review form - clear any
        // previously-saved approved values, which would otherwise keep masking the fresh
        // suggestion behind the old one (approved values always win over suggested ones).
        $documentSet->setApprovedType('');
        $documentSet->setApprovedPageTitle('');
        $documentSet->setApprovedSubtitle('');
        $documentSet->setApprovedSlug('');
        foreach ($items as $item) {
            $item->setApprovedTitle('');
            $this->documentItemRepository->update($item);
        }

        $this->refreshExpiry($documentSet);
        $this->documentSetRepository->update($documentSet);
        $this->persistenceManager->persistAll();

        $this->addFlashMessage(
            $this->translate($this->documentAnalysisService->isAiConfigured() ? 'review.regeneratedWithAi' : 'review.regeneratedHeuristicOnly'),
            '',
            ContextualFeedbackSeverity::OK
        );

        return $this->redirectToReview($documentSetUid);
    }

    public function confirmAction(): ResponseInterface
    {
        $parsedBody = (array)$this->request->getParsedBody();
        $submittedToken = (string)($parsedBody['formToken'] ?? '');
        if (!$this->getFormProtection()->validateToken($submittedToken, self::FORM_NAME, 'confirm')) {
            $this->throwStatus(403, 'Forbidden', 'The form could not be validated. Please reload and try again.');
        }

        $documentSetUid = (int)($parsedBody['documentSet'] ?? 0);
        $documentSet = $this->fetchOwnedDocumentSetOrFail($documentSetUid);
        $items = $this->documentItemRepository->findByDocumentSet((int)$documentSet->getUid());

        if ($documentSet->getStatusEnum() === DocumentSetStatus::STAGED) {
            // Always save whatever the editor last submitted before validating, so
            // confirmation never validates against stale/never-saved review values.
            $this->applyReviewSubmission($documentSet, $items, $parsedBody);

            $isExpired = $documentSet->getExpiresAt() > 0 && $documentSet->getExpiresAt() < time();
            $isDestinationAllowed = $this->destinationResolver->isAllowedDestination($documentSet->getApprovedParentPage());
            $isSlugAvailable = $documentSet->getApprovedSlug() !== ''
                && $this->pageSlugChecker->isSlugAvailable($documentSet->getApprovedParentPage(), $documentSet->getApprovedSlug());

            $itemValidationInput = array_map(
                static fn (DocumentItem $item): array => [
                    'status' => $item->getStatusEnum(),
                    'title' => $item->getApprovedTitle() !== '' ? $item->getApprovedTitle() : $item->getSuggestedTitle(),
                ],
                $items
            );

            $validationResult = ConfirmationValidator::validate(
                $documentSet->getStatusEnum(),
                $isExpired,
                $documentSet->getApprovedPageTitle(),
                $documentSet->getApprovedSlug(),
                $documentSet->getApprovedParentPage(),
                $isDestinationAllowed,
                $isSlugAvailable,
                $itemValidationInput
            );

            if (!$validationResult->isValid) {
                foreach ($validationResult->errors as $errorCode) {
                    $this->addFlashMessage($this->translate('confirm.error.' . $errorCode), '', ContextualFeedbackSeverity::ERROR);
                }
                $this->documentSetRepository->update($documentSet);
                $this->persistenceManager->persistAll();
                return $this->redirectToReview($documentSetUid);
            }

            foreach ($items as $item) {
                $title = $item->getApprovedTitle() !== '' ? $item->getApprovedTitle() : $item->getSuggestedTitle();
                $absolutePath = $this->temporaryUploadService->getStagingRootPath() . '/' . $item->getConvertedPath();
                try {
                    $this->pdfMetadataService->writeApprovedTitle($absolutePath, $title);
                } catch (PdfMetadataException $exception) {
                    $this->addFlashMessage(
                        sprintf('"%s": %s', $item->getOriginalFilename(), $exception->getMessage()),
                        $this->translate('confirm.metadataFailed'),
                        ContextualFeedbackSeverity::ERROR
                    );
                    $this->documentSetRepository->update($documentSet);
                    $this->persistenceManager->persistAll();
                    return $this->redirectToReview($documentSetUid);
                }
                if ($item->getApprovedTitle() === '') {
                    $item->setApprovedTitle($title);
                    $this->documentItemRepository->update($item);
                }
            }

            DocumentSetLifecycle::assertCanTransition($documentSet->getStatusEnum(), DocumentSetStatus::CONFIRMED);
            $documentSet->setStatusEnum(DocumentSetStatus::CONFIRMED);
            $this->documentSetRepository->update($documentSet);
            $this->persistenceManager->persistAll();
        } elseif ($documentSet->getStatusEnum() === DocumentSetStatus::PUBLISHED) {
            $this->addFlashMessage($this->translate('confirm.alreadyPublished'), '', ContextualFeedbackSeverity::OK);
            return $this->redirect('new');
        } elseif ($documentSet->getStatusEnum() !== DocumentSetStatus::CONFIRMED) {
            // DRAFT/CANCELLED/EXPIRED: nothing to (re)confirm or (re)publish here.
            $this->addFlashMessage($this->translate('confirm.error.set.notStaged'), '', ContextualFeedbackSeverity::ERROR);
            return $this->redirectToReview($documentSetUid);
        }

        // Reached with status CONFIRMED, either just transitioned above (the normal case) or
        // already CONFIRMED from a previous request whose publish step failed (a retry) - the
        // publisher's own idempotency guard (confirmedPage > 0) makes calling it again safe.
        try {
            $pageUid = $this->documentSetPublisher->publish($documentSet, $items);
        } catch (PublishException $exception) {
            $this->addFlashMessage($exception->getMessage(), $this->translate('confirm.publishFailed'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToReview($documentSetUid);
        }

        $documentSet->setConfirmedPage($pageUid);
        DocumentSetLifecycle::assertCanTransition($documentSet->getStatusEnum(), DocumentSetStatus::PUBLISHED);
        $documentSet->setStatusEnum(DocumentSetStatus::PUBLISHED);
        $this->documentSetRepository->update($documentSet);
        foreach ($items as $item) {
            $this->documentItemRepository->update($item);
        }
        $this->persistenceManager->persistAll();

        $this->temporaryUploadService->deleteSet($documentSet->getStagingToken());

        $this->addFlashMessage($this->translate('confirm.success'), '', ContextualFeedbackSeverity::OK);

        return $this->redirect('new');
    }

    /**
     * @param DocumentItem[] $items
     */
    private function applyReviewSubmission(DocumentSet $documentSet, array $items, array $parsedBody): void
    {
        $documentSet->setApprovedType(trim((string)($parsedBody['approvedType'] ?? '')));
        $documentSet->setApprovedPageTitle(trim((string)($parsedBody['approvedPageTitle'] ?? '')));
        $documentSet->setApprovedSubtitle(trim((string)($parsedBody['approvedSubtitle'] ?? '')));
        $documentSet->setApprovedSlug(trim((string)($parsedBody['approvedSlug'] ?? '')));
        $documentSet->setApprovedParentPage((int)($parsedBody['approvedParentPage'] ?? 0));
        $documentSet->setApprovedFalFolder(trim((string)($parsedBody['approvedFalFolder'] ?? '')));
        // Checkboxes only submit a value when checked - presence, not truthiness, is the signal.
        $documentSet->setIncludeAutoFolder(isset($parsedBody['includeAutoFolder']));
        $documentSet->setApprovedFilePrefix(trim((string)($parsedBody['approvedFilePrefix'] ?? '')));

        $itemTitles = is_array($parsedBody['itemTitles'] ?? null) ? $parsedBody['itemTitles'] : [];
        $itemDescriptions = is_array($parsedBody['itemDescriptions'] ?? null) ? $parsedBody['itemDescriptions'] : [];
        foreach ($items as $item) {
            $submittedTitle = $itemTitles[(string)$item->getUid()] ?? null;
            $submittedDescription = $itemDescriptions[(string)$item->getUid()] ?? null;
            if ($submittedTitle === null && $submittedDescription === null) {
                continue;
            }
            if ($submittedTitle !== null) {
                $item->setApprovedTitle(trim((string)$submittedTitle));
            }
            if ($submittedDescription !== null) {
                $item->setApprovedDescription(trim((string)$submittedDescription));
            }
            $this->documentItemRepository->update($item);
        }
    }

    private function fetchOwnedDocumentSetOrFail(int $documentSetUid): DocumentSet
    {
        $documentSet = $documentSetUid > 0 ? $this->documentSetRepository->findByUid($documentSetUid) : null;
        if (!$documentSet instanceof DocumentSet) {
            $this->throwStatus(404, 'Not Found', 'Document set not found.');
        }

        $backendUser = $this->getBackendUser();
        if (!DocumentSetAccessGuard::isEditableBy($documentSet->getCruserId(), (int)$backendUser->getUserId(), (bool)$backendUser->isAdmin())) {
            $this->throwStatus(403, 'Forbidden', 'You are not allowed to modify this document set.');
        }

        return $documentSet;
    }

    /**
     * expiresAt is otherwise only ever set once, at creation (resolveOrCreateDocumentSet()) -
     * without this, a set an editor keeps actively working on (uploading more files, saving
     * review edits, regenerating suggestions, ...) for longer than DRAFT_LIFETIME_SECONDS would
     * expire out from under them mid-session, even though it is clearly not abandoned. Callers
     * are responsible for persisting the document set afterward as usual.
     */
    private function refreshExpiry(DocumentSet $documentSet): void
    {
        $documentSet->setExpiresAt((int)($GLOBALS['EXEC_TIME'] ?? time()) + self::DRAFT_LIFETIME_SECONDS);
    }

    private function redirectToReview(int $documentSetUid): ResponseInterface
    {
        return $this->redirect('review', null, null, ['documentSet' => $documentSetUid]);
    }

    private function resolveOrCreateDocumentSet(int $documentSetUid, BackendUserAuthentication $backendUser): DocumentSet
    {
        $backendUserUid = (int)$backendUser->getUserId();

        if ($documentSetUid > 0) {
            $documentSet = $this->documentSetRepository->findByUid($documentSetUid);
            if (!$documentSet instanceof DocumentSet) {
                $this->throwStatus(404, 'Not Found', 'Document set not found.');
            }
            if (!DocumentSetAccessGuard::isEditableBy($documentSet->getCruserId(), $backendUserUid, (bool)$backendUser->isAdmin())) {
                $this->throwStatus(403, 'Forbidden', 'You are not allowed to modify this document set.');
            }
            if (!in_array($documentSet->getStatusEnum(), [DocumentSetStatus::DRAFT, DocumentSetStatus::STAGED], true)) {
                $this->throwStatus(409, 'Conflict', 'This document set can no longer accept files.');
            }
            return $documentSet;
        }

        $documentSet = GeneralUtility::makeInstance(DocumentSet::class);
        $documentSet->setCruserId($backendUserUid);
        $documentSet->setStagingToken($this->temporaryUploadService->generateSetToken());
        $documentSet->setStatusEnum(DocumentSetStatus::DRAFT);
        $documentSet->setExpiresAt((int)($GLOBALS['EXEC_TIME'] ?? time()) + self::DRAFT_LIFETIME_SECONDS);
        $this->documentSetRepository->add($documentSet);
        $this->persistenceManager->persistAll();

        return $documentSet;
    }

    private function getFormProtection(): BackendFormProtection
    {
        /** @var BackendFormProtection $formProtection */
        $formProtection = $this->formProtectionFactory->createFromRequest($this->request);

        return $formProtection;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * @param array<int, int|string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        return LocalizationUtility::translate(
            'LLL:EXT:lazarski_bip_upload/Resources/Private/Language/locallang.xlf:' . $key,
            null,
            $arguments
        ) ?? $key;
    }
}

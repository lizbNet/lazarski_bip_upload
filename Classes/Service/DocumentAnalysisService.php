<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use PrimeServices\LazarskiBipUpload\Analysis\Candidate;
use PrimeServices\LazarskiBipUpload\Analysis\CandidateRanker;
use PrimeServices\LazarskiBipUpload\Analysis\DocxMetadataReader;
use PrimeServices\LazarskiBipUpload\Analysis\FilenameNormalizer;
use PrimeServices\LazarskiBipUpload\Analysis\IdentifierSlugBuilder;
use PrimeServices\LazarskiBipUpload\Analysis\OpenAiTitleGenerator;
use PrimeServices\LazarskiBipUpload\Analysis\PdfMetadataReader;
use PrimeServices\LazarskiBipUpload\Analysis\PdfPageRasterizerInterface;
use PrimeServices\LazarskiBipUpload\Analysis\RasterizationException;
use PrimeServices\LazarskiBipUpload\Analysis\SlugCandidateBuilder;
use PrimeServices\LazarskiBipUpload\Analysis\SubtitleDeriver;
use PrimeServices\LazarskiBipUpload\Analysis\TypeClassifier;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentItem;
use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSet;

/**
 * Extracts title/type/subtitle/slug candidates from a set's staged originals. Runs on the
 * ORIGINAL files (not converted PDFs): DOCX core properties/headings only exist in the .docx,
 * and LibreOffice's export doesn't reliably preserve them, so it is independent of - and can
 * run before, after, or alongside - conversion.
 *
 * All ranking/classification decisions are delegated to pure Analysis\* classes; this service
 * only wires file reading to those rules. When an OpenAI API key is configured, its output is
 * additionally injected as a high-confidence candidate (catching cases the deterministic
 * heuristic can't, e.g. scanner-generated metadata that merely looks like a real title) -
 * the heuristic candidates are always computed regardless, so an unconfigured/failed AI call
 * never changes behavior.
 */
class DocumentAnalysisService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const AI_CANDIDATE_CONFIDENCE = 95;

    // Below this many characters of real PDF body text, treat the file as an unOCR'd scan
    // (no text layer at all) and fall back to sending the rasterized page image to the vision
    // model instead - well above the ~15 chars an empty PDF yields, well below a genuinely
    // short-but-real extracted clause.
    private const OCR_FALLBACK_MIN_TEXT_LENGTH = 50;

    public function __construct(
        private readonly TemporaryUploadService $temporaryUploadService,
        private readonly DocxMetadataReader $docxMetadataReader,
        private readonly PdfMetadataReader $pdfMetadataReader,
        private readonly OpenAiTitleGenerator $openAiTitleGenerator,
        private readonly PdfPageRasterizerInterface $pdfPageRasterizer,
    ) {
    }

    public function isAiConfigured(): bool
    {
        return $this->openAiTitleGenerator->isConfigured();
    }

    /**
     * Manually-triggered, per-file AI generation: produces a title and short description for
     * just this one item, independent of the rest of its set. Returns null if AI isn't
     * configured or the call fails - callers must not treat this as a hard error.
     *
     * @return array{title: string, description: string}|null
     */
    public function generateAiSuggestionForItem(DocumentItem $item): ?array
    {
        if (!$this->isAiConfigured()) {
            return null;
        }

        [, , $bodyText, $extractedText] = $this->analyzeItem($item);

        if ($item->getFileExtension() === 'pdf' && mb_strlen(trim($bodyText)) < self::OCR_FALLBACK_MIN_TEXT_LENGTH) {
            $result = $this->generateAiSuggestionFromImage($item);
            if ($result !== null) {
                return $result;
            }
        }

        $result = $this->openAiTitleGenerator->generateForItem($item->getOriginalFilename(), $extractedText);
        $this->logger?->debug('generateAiSuggestionForItem()', [
            'itemUid' => $item->getUid(),
            'filename' => $item->getOriginalFilename(),
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * OCR-via-vision fallback for PDFs with no usable text layer (unOCR'd office scans): sends
     * the rasterized first page to the vision model instead of empty text. Never a hard error -
     * any rasterization failure just falls through to the caller's ordinary text-based attempt.
     *
     * @return array{title: string, description: string}|null
     */
    private function generateAiSuggestionFromImage(DocumentItem $item): ?array
    {
        $absolutePath = $this->temporaryUploadService->getStagingRootPath() . '/' . $item->getStoredPath();

        try {
            $imageBase64 = base64_encode($this->pdfPageRasterizer->rasterizeFirstPage($absolutePath));
        } catch (RasterizationException $exception) {
            $this->logger?->warning('OCR fallback rasterization failed', [
                'itemUid' => $item->getUid(),
                'filename' => $item->getOriginalFilename(),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $result = $this->openAiTitleGenerator->generateForItemFromImage($item->getOriginalFilename(), $imageBase64);
        $this->logger?->debug('generateAiSuggestionFromImage()', [
            'itemUid' => $item->getUid(),
            'filename' => $item->getOriginalFilename(),
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * @param DocumentItem[] $items
     */
    public function analyze(DocumentSet $documentSet, array $items): AnalysisResult
    {
        $itemAnalyses = [];
        $classificationTexts = [];
        $bodyTextForSubtitle = '';

        foreach ($items as $item) {
            [$candidates, $classificationText, $bodyText, $extractedText] = $this->analyzeItem($item);
            $itemAnalyses[] = [
                'item' => $item,
                'candidates' => $candidates,
                'extractedText' => $extractedText,
            ];
            $classificationTexts[] = $classificationText;
            if ($bodyTextForSubtitle === '' && $bodyText !== '') {
                $bodyTextForSubtitle = $bodyText;
            }
        }

        $typeSuggestion = TypeClassifier::classify(...$classificationTexts);

        $aiResult = $this->openAiTitleGenerator->generate(
            $typeSuggestion->type,
            array_map(
                static fn (array $analysis): array => [
                    'filename' => $analysis['item']->getOriginalFilename(),
                    'extractedText' => $analysis['extractedText'],
                ],
                $itemAnalyses
            )
        );

        $itemTitleCandidates = [];
        $allTitleCandidates = [];
        foreach ($itemAnalyses as $index => $analysis) {
            $candidates = $analysis['candidates'];
            $aiItemTitle = $aiResult['itemTitles'][$index] ?? '';
            if ($aiItemTitle !== '') {
                $candidates[] = new Candidate($aiItemTitle, 'openai', self::AI_CANDIDATE_CONFIDENCE, 'Wygenerowane przez AI.');
            }

            $itemTitleCandidates[$analysis['item']->getUid()] = CandidateRanker::rank($candidates);
            $allTitleCandidates = array_merge($allTitleCandidates, $candidates);
        }

        if (($aiResult['pageTitle'] ?? '') !== '') {
            $allTitleCandidates[] = new Candidate($aiResult['pageTitle'], 'openai', self::AI_CANDIDATE_CONFIDENCE, 'Wygenerowane przez AI.');
        }
        $rankedPageTitleCandidates = CandidateRanker::rank($allTitleCandidates);

        $subtitleCandidates = [];
        if (($aiResult['subtitle'] ?? '') !== '') {
            $subtitleCandidates[] = new Candidate($aiResult['subtitle'], 'openai', self::AI_CANDIDATE_CONFIDENCE, 'Wygenerowane przez AI.');
        }
        $heuristicSubtitle = SubtitleDeriver::derive($bodyTextForSubtitle, $typeSuggestion->type);
        if ($heuristicSubtitle !== null) {
            $subtitleCandidates[] = new Candidate($heuristicSubtitle, 'derived', 60, 'Extracted from the "w sprawie" clause.');
        }
        $subtitleCandidates = CandidateRanker::rank($subtitleCandidates);

        // Prefer a short "type-number-year" slug over transliterating the (often long)
        // descriptive title: AI-generated first, then the same pattern extracted by regex from
        // the identifier line, falling back to the full-title transliteration only when neither
        // is available (e.g. program_studiow sets, which have no numbered identifier).
        $slugCandidates = [];
        if (($aiResult['slug'] ?? '') !== '') {
            $slugCandidates[] = new Candidate($aiResult['slug'], 'openai', self::AI_CANDIDATE_CONFIDENCE, 'Wygenerowane przez AI.');
        }
        $identifierSlug = IdentifierSlugBuilder::build($typeSuggestion->type, implode(' ', $classificationTexts));
        if ($identifierSlug !== null) {
            $slugCandidates[] = new Candidate($identifierSlug, 'identifier', 70, 'Built from the document\'s number and year.');
        }

        // Same number+year extraction as the slug above, but concatenated with no separator
        // for use as an auto-generated FAL destination subfolder (e.g. "312026") - only ever
        // produced for uchwała/zarządzenie, matching IdentifierSlugBuilder's own type scoping.
        $suggestedAutoFolder = IdentifierSlugBuilder::buildNumberYear($typeSuggestion->type, implode(' ', $classificationTexts)) ?? '';
        $topTitle = $rankedPageTitleCandidates[0]->value ?? '';
        if ($topTitle !== '') {
            $slugCandidates[] = new Candidate(SlugCandidateBuilder::build($topTitle), 'derived', 50, 'Generated from the top title candidate.');
        }
        $slugCandidates = CandidateRanker::rank($slugCandidates);

        $this->logger?->debug('analyze() result', [
            'type' => [$typeSuggestion->type, $typeSuggestion->confidence, $typeSuggestion->reason],
            'aiResult' => $aiResult,
            'pageTitleCandidates' => self::candidatesForLog($rankedPageTitleCandidates),
            'subtitleCandidates' => self::candidatesForLog($subtitleCandidates),
            'slugCandidates' => self::candidatesForLog($slugCandidates),
            'itemTitleCandidates' => array_map(
                static fn (array $candidates): array => self::candidatesForLog($candidates),
                $itemTitleCandidates
            ),
        ]);

        return new AnalysisResult(
            $typeSuggestion,
            $rankedPageTitleCandidates,
            $subtitleCandidates,
            $slugCandidates,
            $itemTitleCandidates,
            $suggestedAutoFolder
        );
    }

    /**
     * @return array{0: Candidate[], 1: string, 2: string, 3: string} candidates, classification
     *         text, body text (for subtitle derivation), and full extracted text (for the AI)
     */
    private function analyzeItem(DocumentItem $item): array
    {
        $absolutePath = $this->temporaryUploadService->getStagingRootPath() . '/' . $item->getStoredPath();

        $candidates = [
            new Candidate(
                FilenameNormalizer::normalize($item->getOriginalFilename()),
                'filename',
                40,
                'Derived from the uploaded filename.'
            ),
        ];
        $classificationText = $item->getOriginalFilename();
        $bodyText = '';
        $extractedText = '';

        try {
            if ($item->getFileExtension() === 'docx') {
                $docx = $this->docxMetadataReader->read($absolutePath);
                if ($docx['title'] !== '') {
                    $candidates[] = new Candidate($docx['title'], 'docx_property', 90, 'DOCX document title property.');
                }
                $firstHeading = $docx['headings'][0] ?? '';
                if ($firstHeading !== '') {
                    $candidates[] = new Candidate($firstHeading, 'docx_heading', 75, 'First heading in the document.');
                }
                $classificationText .= ' ' . $docx['title'] . ' ' . $docx['subject'] . ' ' . implode(' ', $docx['headings']);
                $bodyText = implode(' ', $docx['headings']) !== '' ? implode(' ', $docx['headings']) : $docx['subject'];
                $extractedText = trim(implode("\n", [$docx['title'], $docx['subject'], implode("\n", $docx['headings'])]));
            } elseif ($item->getFileExtension() === 'pdf') {
                $pdf = $this->pdfMetadataReader->read($absolutePath);
                if ($pdf['title'] !== '') {
                    $candidates[] = new Candidate($pdf['title'], 'pdf_title', 90, 'PDF document Title metadata.');
                }
                $clause = SubtitleDeriver::extractClause($pdf['text']);
                if ($clause !== null && $clause !== '') {
                    $candidates[] = new Candidate($clause, 'pdf_text', 65, 'Extracted from the PDF text.');
                }
                $classificationText .= ' ' . $pdf['title'] . ' ' . $pdf['text'];
                $bodyText = $pdf['text'];
                $extractedText = trim(sprintf("Metadata Title: %s\n%s", $pdf['title'], $pdf['text']));
            }
        } catch (\Throwable $exception) {
            // Metadata extraction is best-effort: fall back to the filename candidate alone.
        }

        if ($extractedText === '') {
            $extractedText = $item->getOriginalFilename();
        }

        $this->logger?->debug('analyzeItem()', [
            'itemUid' => $item->getUid(),
            'filename' => $item->getOriginalFilename(),
            'extension' => $item->getFileExtension(),
            'candidates' => self::candidatesForLog($candidates),
            'extractedTextLength' => mb_strlen($extractedText),
            'extractedText' => $extractedText,
        ]);

        return [$candidates, $classificationText, $bodyText, $extractedText];
    }

    /**
     * @param Candidate[] $candidates
     * @return array<int, array{value: string, source: string, confidence: int, reason: string}>
     */
    private static function candidatesForLog(array $candidates): array
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
}

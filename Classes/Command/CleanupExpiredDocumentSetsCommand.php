<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Command;

use PrimeServices\LazarskiBipUpload\Domain\Model\DocumentSetStatus;
use PrimeServices\LazarskiBipUpload\Domain\Repository\DocumentSetRepository;
use PrimeServices\LazarskiBipUpload\Service\DocumentSetLifecycle;
use PrimeServices\LazarskiBipUpload\Service\TemporaryUploadService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Expires abandoned draft/staged sets (never confirmed) and removes their staging files,
 * so a set an editor started and never finished doesn't linger indefinitely.
 */
class CleanupExpiredDocumentSetsCommand extends Command
{
    public function __construct(
        private readonly DocumentSetRepository $documentSetRepository,
        private readonly TemporaryUploadService $temporaryUploadService,
        private readonly PersistenceManagerInterface $persistenceManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Expire abandoned document upload sets whose expiry timestamp has passed and remove their staged files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expiredSets = $this->documentSetRepository->findExpired(time());

        foreach ($expiredSets as $expiredSet) {
            if (!DocumentSetLifecycle::canTransition($expiredSet->getStatusEnum(), DocumentSetStatus::EXPIRED)) {
                continue;
            }
            $expiredSet->setStatusEnum(DocumentSetStatus::EXPIRED);
            $this->documentSetRepository->update($expiredSet);
            $this->temporaryUploadService->deleteSet($expiredSet->getStagingToken());
        }

        $this->persistenceManager->persistAll();

        $output->writeln(sprintf('Expired %d document set(s).', count($expiredSets)));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\Service\ExtractorService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;
use TYPO3Canto\CantoFal\Resource\Repository\FileRepository;

final class UpdateMetadataAssetsCommand extends Command
{
    private int $apiRateLimit = 500;

    public function __construct(
        private ExtractorService $extractorService,
        private FileRepository $fileRepository,
        private FileIndexRepository $fileIndexRepository,
        private FrontendInterface $cantoFileCache
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Update Metadata for all integrated canto assets.');
        $this->setHelp(
            <<<'EOF'
This command will pull down all metadata and override it analog to the definition in the backend.
It will also delete all processed files to these files
EOF
        );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $counter = 0;
        $indexerArray = [];
        $starttime = time();

        $files = $this->fileRepository->findAll();
        $this->cantoFileCache->flush();
        foreach ($files as $file) {
            assert($file instanceof File);
            if ($file->getStorage()->getDriverType() !== CantoDriver::DRIVER_NAME) {
                continue;
            }

            $output->writeln('Working on File: ' . $file->getIdentifier() . ' - ' . $file->getName());

            try {
                if (!$file->exists()) {
                    throw new \InvalidArgumentException('File ' . $file->getIdentifier() . ' does not exist.', 1752151775);
                }

                $storage = $file->getStorage();
                $storageUid = $storage->getUid();
                $currentEvaluatePermissions = $storage->getEvaluatePermissions();
                $storage->setEvaluatePermissions(false);

                $indexer = $indexerArray[$storageUid] = $indexerArray[$storageUid] ?? GeneralUtility::makeInstance(Indexer::class, $storage);

                $file = $indexer->updateIndexEntry($file);

                if (!$storage->autoExtractMetadataEnabled()) {
                    $file->getMetaData()->add($this->extractorService->extractMetaData($file))->save();
                }

                $storage->setEvaluatePermissions($currentEvaluatePermissions);
            } catch (\Exception $e) {
                $this->fileIndexRepository->markFileAsMissing($file->getUid());
                $output->writeln(sprintf(
                    '     Error on File: %s - %s -> %s (%s)',
                    $file->getIdentifier(),
                    $file->getName(),
                    $e->getMessage(),
                    $e->getCode()
                ));
                continue;
            }
            if (++$counter > $this->apiRateLimit) {
                $counter = time() - $starttime;
                if ($counter < 60) {
                    // to circumvent API limits we need to pause for 60s after processing maximum requests
                    $output->writeln('Waiting for API');
                    sleep(61 - $counter);
                    $counter = 0;
                }
                $starttime = time();
            }
        }

        return self::SUCCESS;
    }
}

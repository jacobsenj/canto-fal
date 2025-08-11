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
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Canto\CantoFal\Domain\Repository\FileReferenceRepository;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;
use TYPO3Canto\CantoFal\Resource\Metadata\Extractor;
use TYPO3Canto\CantoFal\Resource\Repository\FileRepository;
use TYPO3Canto\CantoFal\Utility\CantoUtility;

final class UpdateImageAssetsUsedInFrontendCommand extends Command
{
    private Extractor $metadataExtractor;

    private FileRepository $fileRepository;

    protected FrontendInterface $cantoFileCache;

    private int $apiRateLimit = 500;

    public function __construct(?string $name = null)
    {
        parent::__construct($name);
        $this->metadataExtractor = GeneralUtility::makeInstance(Extractor::class);
        $this->fileRepository = GeneralUtility::makeInstance(FileRepository::class);
    }

    public function injectCantoFileCache(FrontendInterface $cantoFileCache): void
    {
        $this->cantoFileCache = $cantoFileCache;
    }

    protected function configure(): void
    {
        $this->setDescription('Update Referenced Metadata Files for all used canto assets.');
        $this->setHelp(
            <<<'EOF'
This command will pull down all metadata and override it analog to the definition in the backend.
It will also delete all processed files to these files
EOF
        );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $fileReferenceRepositry = GeneralUtility::makeInstance(FileReferenceRepository::class);
        $cacheManager = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(CacheManager::class);

        $files = $this->fileRepository->findAll();
        $counter = 0;
        $starttime = time();

        foreach ($files as $file) {
            assert($file instanceof File);
            $output->writeln('Working on File: ' . $file->getIdentifier() . ' - ' . $file->getName());

            //Check file references
            $fileReference = $fileReferenceRepositry->getFileReferenzesByFileUid($file->getUid());

            if ($file->getStorage()->getDriverType() !== CantoDriver::DRIVER_NAME || count($fileReference) == 0) {
                continue;
            }
            //We delete only referenced files
            try {
                //First delete cache
                $scheme = CantoUtility::getSchemeFromCombinedIdentifier($file->getIdentifier());
                $identifier = CantoUtility::getIdFromCombinedIdentifier($file->getIdentifier());
                $combinedIdentifier = CantoUtility::buildCombinedIdentifier($scheme, $identifier);
                $cacheIdentifier = sha1($combinedIdentifier);
                if ($this->cantoFileCache->has($cacheIdentifier)) {
                    //Clear old cache
                    $this->cantoFileCache->remove($cacheIdentifier);
                }

                $metaData = $this->metadataExtractor->extractMetaData($file);
                $fetchedDataForFile = $this->metadataExtractor->fetchDataForFile($file);
                if (isset($fetchedDataForFile['default'])) {
                    $newmtime = CantoUtility::buildTimestampFromCantoDate($fetchedDataForFile['default']['Date modified']);
                    if ($fetchedDataForFile && $newmtime > $file->getModificationTime()) {
                        $this->fileRepository->updateModificationDate($file->getUid(), $newmtime);
                        $file->getMetaData()->add($metaData)->save();
                        $file->getForLocalProcessing(false);
                        $processedFileRepository = GeneralUtility::makeInstance(ProcessedFileRepository::class);
                        foreach ($processedFileRepository->findAllByOriginalFile($file) as $processedFile) {
                            $processedFile->delete(true);
                        }
                    }
                } elseif ($fetchedDataForFile == null) {
                    //Set deleted images in Canto on deleted in typo3
                }
            } catch (\Exception $e) {
                $output->writeln('File ' . $file->getIdentifier() . ' failed: ' . $e->getMessage());
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
        //Clear frontend cache
        $cache = $cacheManager->getCache('pages');
        // Cache leeren
        if ($cache !== null) {
            $cache->flush();
        }

        return self::SUCCESS;
    }
}

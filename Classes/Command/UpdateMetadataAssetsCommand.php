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
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;
use TYPO3Canto\CantoFal\Resource\Metadata\Extractor;
use TYPO3Canto\CantoFal\Resource\Repository\FileRepository;
use TYPO3Canto\CantoFal\Utility\CantoUtility;

final class UpdateMetadataAssetsCommand extends Command
{
    private Extractor $metadataExtractor;

    private FileRepository $fileRepository;

    protected FrontendInterface $cantoFileCache;

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
        $this->initTSFE();
        $files = $this->fileRepository->findAll();
        $counter = 0;
        foreach ($files as $file) {
            assert($file instanceof File);
            $output->writeln('Working on File: ' . $file->getIdentifier() . ' - ' . $file->getName());
            if ($file->getStorage()->getDriverType() !== CantoDriver::DRIVER_NAME) {
                continue;
            }
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

                if ($metaData) {
                    var_dump($metaData);
                    $file->getMetaData()->add($metaData)->save();
                    $file->getForLocalProcessing(true);
                    $processedFileRepository = GeneralUtility::makeInstance(ProcessedFileRepository::class);
                    foreach ($processedFileRepository->findAllByOriginalFile($file) as $processedFile) {
                        $processedFile->delete(true);
                    }
                }
            } catch (\Exception $e) {
                $output->writeln('File ' . $file->getIdentifier() . ' failed: ' . $e->getMessage());
                continue;
            }
            if (++$counter > 1000) {
                $counter = 0;
                // to circumvent API limits we need to pause for 60s after processing a thousand requests
                sleep(60);
            }
        }

        return self::SUCCESS;
    }

    protected function initTSFE($id = 1, $typeNum = 0)
    {
        $this->site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($id);
        $request = (new ServerRequest())
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('site', $this->site);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $_SERVER['HTTP_HOST'] = $this->site->getBase()->getHost();
    }
}

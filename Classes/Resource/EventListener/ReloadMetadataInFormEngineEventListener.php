<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\EventListener;

use TYPO3\CMS\Backend\Controller\Event\AfterFormEnginePageInitializedEvent;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\Service\ExtractorService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ReloadMetadataInFormEngineEventListener
{
    protected FrontendInterface $cantoFileCache;

    protected ExtractorService $extractorService;

    protected ProcessedFileRepository $processedFileRepository;

    protected ResourceFactory $resourceFactory;

    public function __construct(
        CacheManager $cacheManager,
        ExtractorService $extractorService,
        ProcessedFileRepository $processedFileRepository,
        ResourceFactory $resourceFactory
    ) {
        $this->cantoFileCache = $cacheManager->getCache('canto_fal_file');
        $this->extractorService = $extractorService;
        $this->processedFileRepository = $processedFileRepository;
        $this->resourceFactory = $resourceFactory;
    }

    public function __invoke(AfterFormEnginePageInitializedEvent $event): void
    {
        $request = $event->getRequest();
        $parsedBody = $request->getParsedBody();
        if (!is_array($parsedBody) || !isset($parsedBody['cantoFileId'])) {
            return;
        }

        $file = $this->resourceFactory->getFileObject($parsedBody['cantoFileId']);
        if (!$file instanceof FileInterface) {
            return;
        }

        $cacheIdentifier = sha1($file->getIdentifier());
        $this->cantoFileCache->remove($cacheIdentifier);

        $storage = $file->getStorage();
        $currentEvaluatePermissions = $storage->getEvaluatePermissions();
        $storage->setEvaluatePermissions(false);

        $indexer = GeneralUtility::makeInstance(Indexer::class, $storage);

        $file = $indexer->updateIndexEntry($file);

        if (!$storage->autoExtractMetadataEnabled()) {
            $file->getMetaData()->add($this->extractorService->extractMetaData($file))->save();
        }

        foreach ($this->processedFileRepository->findAllByOriginalFile($file) as $processedFile) {
            if ($processedFile->exists()) {
                $processedFile->delete(true);
            }
        }

        $storage->setEvaluatePermissions($currentEvaluatePermissions);
    }
}

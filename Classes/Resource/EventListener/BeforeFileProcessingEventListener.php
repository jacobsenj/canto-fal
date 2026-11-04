<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\EventListener;

use TYPO3\CMS\Core\Resource\Event\BeforeFileProcessingEvent;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\Processing\TaskTypeRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;
use TYPO3Canto\CantoFal\Resource\MdcUrlGenerator;
use TYPO3Canto\CantoFal\Resource\Repository\CantoRepository;
use TYPO3Canto\CantoFal\Utility\CantoUtility;

final class BeforeFileProcessingEventListener
{
    private CantoRepository $cantoRepository;
    private ProcessedFileRepository $processedFileRepository;
    private MdcUrlGenerator $mdcUrlGenerator;

    public function __construct(CantoRepository $cantoRepository, ProcessedFileRepository $processedFileRepository, MdcUrlGenerator $mdcUrlGenerator)
    {
        $this->cantoRepository = $cantoRepository;
        $this->processedFileRepository = $processedFileRepository;
        $this->mdcUrlGenerator = $mdcUrlGenerator;
    }

    public function __invoke(BeforeFileProcessingEvent $event)
    {
        if (!($event->getDriver() instanceof CantoDriver)) {
            return;
        }
        $processedFile = $event->getProcessedFile();
        $identifier = $processedFile->getOriginalFile()->getIdentifier();
        if ($processedFile->isProcessed()) {
            return;
        }
        if (!CantoUtility::isMdcActivated($event->getFile()->getStorage()->getConfiguration())) {
            return;
        }
        $configuration = $event->getConfiguration();
        if ($event->getTaskType() === ProcessedFile::CONTEXT_IMAGEPREVIEW) {
            $configuration['fileExtension'] = 'jpg';
        }
        // ProcessedFile::getTask() has been removed in TYPO3 v14
        $task = GeneralUtility::makeInstance(TaskTypeRegistry::class)->getTaskForType(
            $processedFile->getTaskIdentifier(),
            $processedFile,
            $processedFile->getProcessingConfiguration()
        );
        $url = $this->mdcUrlGenerator->generateMdcUrl($task);
        $processedFile->updateProcessingUrl($url);
        $properties = $processedFile->getProperties() ?? [];
        $properties = array_merge($properties, $this->mdcUrlGenerator->resolveImageWidthHeight($task));
        $properties['processing_url'] = $url;
        $processedFile->updateProperties($properties);
        $processedFile->setIdentifier(CantoUtility::identifierToProcessedIdentifier($identifier));
        if (!$processedFile->isPersisted()) {
            $processedFile->setName($processedFile->getOriginalFile()->getName());
        }
        $event->setProcessedFile($processedFile);
        // The second argument is required since TYPO3 v14 and ignored by TYPO3 v13
        $this->processedFileRepository->add($processedFile, $task);
    }
}

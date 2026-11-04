<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\EventListener;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;
use TYPO3Canto\CantoFal\Resource\Event\IncomingWebhookEvent;
use TYPO3Canto\CantoFal\Resource\Metadata\Extractor;
use TYPO3Canto\CantoFal\Utility\CantoUtility;

final class MetadataWebhookEventListener
{
    private Extractor $metadataExtractor;
    private StorageRepository $storageRepository;

    public function __construct(Extractor $metadataExtractor, StorageRepository $storageRepository)
    {
        $this->metadataExtractor = $metadataExtractor;
        $this->storageRepository = $storageRepository;
    }

    public function __invoke(IncomingWebhookEvent $event)
    {
        if ($event->getType() !== IncomingWebhookEvent::METADATA_UPDATE) {
            return;
        }
        $cantoStorages = $this->storageRepository->findByStorageType(CantoDriver::DRIVER_NAME);
        $identifier = CantoUtility::buildCombinedIdentifier($event->getScheme(), $event->getId());
        $file = null;
        foreach ($cantoStorages as $storage) {
            $file = $storage->getFileByIdentifier($identifier);
            if ($file instanceof File) {
                break;
            }
        }

        $metaData = $this->metadataExtractor->extractMetaData($file);
        $file->getMetaData()->add($metaData)->save();
    }
}

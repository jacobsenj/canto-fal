<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\EventListener;

use TYPO3\CMS\Core\Resource\Event\GeneratePublicUrlForResourceEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;

final class GeneratePublicUrlForResourceEventListener
{
    public function __invoke(GeneratePublicUrlForResourceEvent $event): void
    {
        $file = $event->getResource();
        if (!$file instanceof File
            || $file->getStorage()->getDriverType() !== CantoDriver::DRIVER_NAME
        ) {
            return;
        }
        try {
            // This applies a public url for the given asset.
            // If the file has been registered as a mdc-asset, then this returns the url for it
            // Otherwise we get the url to the downloaded resource instead
            assert($file->getIdentifier() !== '');
            $url = $event->getDriver()->getPublicUrl($file->getIdentifier());
            $event->setPublicUrl($url);
        } catch (\InvalidArgumentException $e) {
            // todo: we should add logging in the future
        }
    }
}

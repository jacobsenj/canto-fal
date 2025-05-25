<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Xclass;

use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;
use TYPO3Canto\CantoFal\Resource\Repository\CantoFileIndexRepository;

class Indexer extends \TYPO3\CMS\Core\Resource\Index\Indexer
{
    protected function getFileIndexRepository(): FileIndexRepository
    {
        if ($this->storage->getDriverType() === CantoDriver::DRIVER_NAME) {
            return GeneralUtility::makeInstance(CantoFileIndexRepository::class);
        }
        return parent::getFileIndexRepository();
    }
}

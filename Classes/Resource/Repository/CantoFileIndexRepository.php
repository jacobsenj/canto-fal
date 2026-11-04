<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\Repository;

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;

/*
 * The core FileIndexRepository is a readonly class since TYPO3 v14. PHP requires
 * subclasses of readonly classes to be readonly as well, while non-readonly classes
 * must not have readonly subclasses. Therefore, the class is declared conditionally.
 */
if ((new Typo3Version())->getMajorVersion() >= 14) {
    readonly class CantoFileIndexRepository extends FileIndexRepository
    {
        use CantoFileIndexRepositoryTrait;
    }
} else {
    class CantoFileIndexRepository extends FileIndexRepository
    {
        use CantoFileIndexRepositoryTrait;
    }
}

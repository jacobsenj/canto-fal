<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\EventListener;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;

final class AddDomainsToContentSecurityPolicyEventListener
{
    protected ConnectionPool $connectionPool;

    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    public function __invoke(PolicyMutatedEvent $event): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_storage');
        $storageRows = $queryBuilder->select('*')
            ->from('sys_file_storage')
            ->where(
                $queryBuilder->expr()->eq(
                    'driver',
                    $queryBuilder->createNamedParameter(CantoDriver::DRIVER_NAME)
                )
            )
            ->executeQuery();

        $uriValues = [];
        while ($row = $storageRows->fetchAssociative()) {
            $configuration = GeneralUtility::xml2array($row['configuration']);
            if (empty($configuration['data']['sDEF']['lDEF']['cantoName']['vDEF'])) {
                continue;
            }
            $uriValues[] = implode(
                '.',
                [
                    $configuration['data']['sDEF']['lDEF']['cantoName']['vDEF'],
                    $configuration['data']['sDEF']['lDEF']['cantoDomain']['vDEF'],
                ]
            );
        }

        if (count($uriValues) === 0) {
            return;
        }

        $uriValues[] = '*.cloudfront.net';
        $uriValues = array_unique($uriValues);

        $currentPolicy = $event->getCurrentPolicy();
        $currentPolicy->mutate(
            new Mutation(
                MutationMode::Extend,
                Directive::ImgSrc,
                ...array_map(static fn($uri) => new UriValue($uri), $uriValues)
            )
        );
    }
}

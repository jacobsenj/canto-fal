<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\Metadata;

use Doctrine\DBAL\ParameterType;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\RootLevelRestriction;
use TYPO3\CMS\Core\Resource\Event\EnrichFileMetaDataEvent;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Read-only access to sys_file_metadata records.
 *
 * Does not extend the core MetaDataRepository, as it is declared readonly in TYPO3 v14
 * and therefore cannot be extended in a way that is compatible with TYPO3 v13 and v14.
 */
class MetadataRepository
{
    private const TABLE_NAME = 'sys_file_metadata';

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function findByFileUidAndLanguageUid(int $uid, int $languageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $queryBuilder
            ->getRestrictions()
            ->add(GeneralUtility::makeInstance(RootLevelRestriction::class))
        ;

        $record = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'file',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        if (empty($record)) {
            return [];
        }

        return $this->eventDispatcher->dispatch(new EnrichFileMetaDataEvent($uid, (int)$record['uid'], $record))->getRecord();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllByFileUid(int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $queryBuilder
            ->getRestrictions()
            ->add(GeneralUtility::makeInstance(RootLevelRestriction::class));

        $statement = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'file',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)
                ),
            )
            ->orderBy('sys_language_uid')
            ->executeQuery();

        $records = [];

        while ($row = $statement->fetchAssociative()) {
            $records[(int)$row['uid']] = $this->eventDispatcher->dispatch(new EnrichFileMetaDataEvent($uid, (int)$row['uid'], $row))->getRecord();
        }

        return $records;
    }
}

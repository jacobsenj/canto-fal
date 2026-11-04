<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\Repository;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Canto specific folder lookups for the file index, which resolve folder
 * membership via sys_file_canto_album (files can be assigned to multiple albums).
 *
 * @internal Only to be used by CantoFileIndexRepository
 */
trait CantoFileIndexRepositoryTrait
{
    private const CANTO_TABLE = 'sys_file';

    private const CANTO_FIELDS = [
        'uid', 'pid', 'missing', 'type', 'storage', 'identifier', 'identifier_hash', 'extension',
        'mime_type', 'name', 'sha1', 'size', 'creation_date', 'modification_date', 'folder_hash',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function findByFolder(Folder $folder): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::CANTO_TABLE);

        $result = $queryBuilder
            ->select(...$this->getFieldsWithTableNamePrefix())
            ->from(self::CANTO_TABLE)
            ->leftJoin(
                self::CANTO_TABLE,
                'sys_file_canto_album',
                'sys_file_canto_album',
                $queryBuilder->expr()->eq(
                    self::CANTO_TABLE . '.uid',
                    $queryBuilder->quoteIdentifier('sys_file_canto_album.file')
                )
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'sys_file_canto_album.album',
                    $queryBuilder->createNamedParameter($folder->getIdentifier())
                ),
                $queryBuilder->expr()->eq(
                    self::CANTO_TABLE . '.storage',
                    $queryBuilder->createNamedParameter($folder->getStorage()->getUid(), ParameterType::INTEGER)
                )
            )
            ->groupBy(self::CANTO_TABLE . '.uid')
            ->executeQuery();

        $resultRows = [];
        while ($row = $result->fetchAssociative()) {
            $resultRows[$row['identifier']] = $row;
        }

        return $resultRows;
    }

    /**
     * @param Folder[] $folders
     * @param bool $includeMissing
     * @param string|null $fileName
     * @return array<string, array<string, mixed>>
     */
    public function findByFolders(array $folders, $includeMissing = true, $fileName = null): array
    {
        $storageUids = [];
        $folderIdentifiers = [];

        foreach ($folders as $folder) {
            if (!$folder instanceof Folder) {
                continue;
            }

            $storageUids[] = (int)$folder->getStorage()->getUid();
            $folderIdentifiers[] = $folder->getIdentifier();
        }

        $storageUids = array_unique($storageUids);
        $folderIdentifiers = array_unique($folderIdentifiers);

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::CANTO_TABLE);

        $queryBuilder
            ->select(...$this->getFieldsWithTableNamePrefix())
            ->from(self::CANTO_TABLE)
            ->leftJoin(
                self::CANTO_TABLE,
                'sys_file_canto_album',
                'sys_file_canto_album',
                $queryBuilder->expr()->eq(
                    self::CANTO_TABLE . '.uid',
                    $queryBuilder->quoteIdentifier('sys_file_canto_album.file')
                )
            )
            ->where(
                $queryBuilder->expr()->in(
                    'sys_file_canto_album.album',
                    $queryBuilder->createNamedParameter($folderIdentifiers, Connection::PARAM_STR_ARRAY)
                ),
                $queryBuilder->expr()->in(
                    'storage',
                    $queryBuilder->createNamedParameter($storageUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->groupBy(self::CANTO_TABLE . '.uid');

        if (isset($fileName)) {
            $nameParts = str_getcsv($fileName, ' ', '"', '');
            foreach ($nameParts as $part) {
                $part = trim((string)$part);
                if ($part !== '') {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->like(
                            'name',
                            $queryBuilder->createNamedParameter(
                                '%' . $queryBuilder->escapeLikeWildcards($part) . '%',
                                ParameterType::STRING
                            )
                        )
                    );
                }
            }
        }

        if (!$includeMissing) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'missing',
                    $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
                )
            );
        }

        $result = $queryBuilder->executeQuery();

        $fileRecords = [];
        while ($fileRecord = $result->fetchAssociative()) {
            $fileRecords[$fileRecord['identifier']] = $fileRecord;
        }

        return $fileRecords;
    }

    /**
     * @return list<string>
     */
    private function getFieldsWithTableNamePrefix(): array
    {
        return array_map(
            static fn(string $fieldName): string => self::CANTO_TABLE . '.' . $fieldName,
            self::CANTO_FIELDS
        );
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;

readonly class FileRepository extends \TYPO3\CMS\Core\Resource\FileRepository
{
    private const TABLE_NAME = 'sys_file';

    public function __construct(
        protected ConnectionPool $connectionPool,
        protected ResourceFactory $factory,
        protected TcaSchemaFactory $tcaSchemaFactory
    ) {}

    /**
     * @return array<File>
     */
    public function findAll(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $result = $queryBuilder->select('file.*')
            ->from(self::TABLE_NAME, 'file')
            ->leftJoin(
                'file',
                'sys_file_storage',
                'storage',
                $queryBuilder->expr()->eq(
                    'storage.uid',
                    $queryBuilder->quoteIdentifier('file.storage')
                )
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'storage.driver',
                    $queryBuilder->createNamedParameter(CantoDriver::DRIVER_NAME)
                )
            )
            ->executeQuery();

        $files = [];
        while ($row = $result->fetchAssociative()) {
            $files[] = $this->factory->getFileObject((int)$row['uid'], $row);
        }

        return $files;
    }

    public function updateModificationDate(int $uid, int $modification_date = 0): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);

        $result = $queryBuilder
            ->update(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq('uid', $uid)
            )->set('modification_date', $modification_date)
            ->executeStatement();

        return $result;
    }
}

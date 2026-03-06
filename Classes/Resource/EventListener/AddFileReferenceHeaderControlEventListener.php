<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\EventListener;

use TYPO3\CMS\Backend\Form\Event\ModifyFileReferenceControlsEvent;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;

final class AddFileReferenceHeaderControlEventListener
{
    protected ConnectionPool $connectionPool;

    protected IconFactory $iconFactory;

    protected PageRenderer $pageRenderer;

    protected ResourceFactory $resourceFactory;

    public function __construct(
        ConnectionPool $connectionPool,
        IconFactory $iconFactory,
        PageRenderer $pageRenderer,
        ResourceFactory $resourceFactory
    ) {
        $this->connectionPool = $connectionPool;
        $this->iconFactory = $iconFactory;
        $this->pageRenderer = $pageRenderer;
        $this->resourceFactory = $resourceFactory;
    }

    public function __invoke(ModifyFileReferenceControlsEvent $event): void
    {
        $data = $event->getElementData();
        if (!$this->isFileReference($data)) {
            return;
        }

        $record = $event->getRecord();
        $file = $this->resourceFactory->getFileObject($record['uid_local'][0]['uid']);
        if (!$file instanceof FileInterface
            || !$this->isCantoFile($file)
            || !$this->fileIsUsedOnce($file, $data['command'])
        ) {
            return;
        }

        $this->pageRenderer->loadJavaScriptModule('@typo3-canto/canto-fal/form-engine-refresh.js');
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:canto_fal/Resources/Private/Language/locallang_be.xlf', 'file_reload');

        $event->setControl(
            'canto',
            '<button type="button" class="btn btn-default"' .
            ' data-cantofal-id="' . $file->getUid() . '">' .
            $this->iconFactory->getIcon('action-refresh-canto', Icon::SIZE_SMALL)->render() .
            '</button>'
        );
    }

    /**
     * @param array<string> $data
     * @return bool
     */
    protected function isFileReference(array $data): bool
    {
        return ($data['tableName'] ?? '') === 'sys_file_reference';
    }

    protected function isCantoFile(FileInterface $file): bool
    {
        return $file->getStorage()->getDriverType() === CantoDriver::DRIVER_NAME;
    }

    protected function fileIsUsedOnce(FileInterface $file, string $command): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $count = $queryBuilder->selectLiteral('COUNT(DISTINCT pid)')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid_local',
                    $queryBuilder->createNamedParameter($file->getProperty('uid'), Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchOne();

        return $count + ($command === 'new' ? 1 : 0) <= 1;
    }
}

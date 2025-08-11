<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Form\Container;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Form\Container\FilesControlContainer as FilesControlContainerCore;
use TYPO3\CMS\Backend\Form\InlineStackProcessor;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Filter\FileExtensionFilter;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FileControlContainer extends FilesControlContainerCore
{
    public function __construct(
        protected readonly IconFactory $iconFactory,
        protected readonly InlineStackProcessor $inlineStackProcessor,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly OnlineMediaHelperRegistry $onlineMediaHelperRegistry,
        protected readonly DefaultUploadFolderResolver $defaultUploadFolderResolver,
        protected readonly HashService $hashService,
    ) {
        // Set private parent properties
        parent::__construct($iconFactory, $inlineStackProcessor, $eventDispatcher, $onlineMediaHelperRegistry, $defaultUploadFolderResolver, $hashService);
    }

    /**
     * Generate buttons to select, reference and upload files.
     */
    protected function getFileSelectors(array $inlineConfiguration, FileExtensionFilter $fileExtensionFilter): array
    {
        $rval = parent::getFileSelectors($inlineConfiguration, $fileExtensionFilter);
        /** @var  StorageRepository $service */
        $storageReporitory = GeneralUtility::makeInstance(StorageRepository::class);
        $storages = $storageReporitory->findAll();

        foreach ($storages as $storage) {
            if ($storage->getDriverType() === 'Canto' && $storage->getUid() > 0) {
                $newbuttonData = $this->renderAssetPickerButton($inlineConfiguration, $storage->getUid(), $storage->getName());
                $rval[count($rval)] = $newbuttonData;
            }
        }

        return $rval;
    }

    /**
     * @param array<string, mixed> $inlineConfiguration
     * @param int $storageId
     * @return string
     */
    private function renderAssetPickerButton(array $inlineConfiguration, int $storageId, string $storageName): string
    {
        $foreign_table = $inlineConfiguration['foreign_table'];
        $allowed = '';
        if (isset($inlineConfiguration['allowed'])) {
            $allowed = $inlineConfiguration['allowed'];
        }
        $currentStructureDomObjectIdPrefix = $this->inlineStackProcessor->getCurrentStructureDomObjectIdPrefix(
            $this->data['inlineFirstPid']
        );
        $objectPrefix = $currentStructureDomObjectIdPrefix . '-' . $foreign_table;

        $hideButton = isset($inlineConfiguration['maxitems']) && count($this->data['parameterArray']['fieldConf']['children']) >= $inlineConfiguration['maxitems'];

        $title = 'Add file [' . $storageName . ']';

        $attributes = [
            'type' => 'button',
            'class' => 'btn btn-default t3js-element-browser',
            'data-mode' => 'cantosaas',
            'data-params' => '|||' . $allowed . '|' . $objectPrefix . '|' . $storageId,
            'style' => $hideButton ? 'display: none;' : ''
                . ($inlineConfiguration['inline']['inlineNewRelationButtonStyle'] ?? ''),
            'title' => $title,
        ];

        return '
<button ' . GeneralUtility::implodeAttributes($attributes, true) . '>' . htmlspecialchars($title) . '</button > ';
    }
}

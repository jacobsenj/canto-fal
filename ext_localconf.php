<?php

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use TYPO3\CMS\Core\Resource\Index\ExtractorRegistry;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// Register new fal driver
$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers'][\TYPO3Canto\CantoFal\Resource\Driver\CantoDriver::DRIVER_NAME] = [
    'class' => \TYPO3Canto\CantoFal\Resource\Driver\CantoDriver::class,
    'shortName' => \TYPO3Canto\CantoFal\Resource\Driver\CantoDriver::DRIVER_NAME,
    'flexFormDS' => 'FILE:EXT:canto_fal/Configuration/FlexForm/CantoDriver.xml',
    'label' => 'Canto DAM',
];

// Register canto specific file processors.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['processors']['CantoPreviewProcessor'] = [
    'className' => \TYPO3Canto\CantoFal\Resource\Processing\CantoPreviewProcessor::class,
    'before' => [
        'SvgImageProcessor',
    ],
];

ExtensionManagementUtility::addTypoScript(
    'canto_fal',
    'setup',
    "@import 'EXT:canto_fal/Configuration/TypoScript/setup.typoscript'",
);

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['processors']['CantoMdcProcessor'] = [
    'className' => \TYPO3Canto\CantoFal\Resource\Processing\CantoMdcProcessor::class,
    'before' => ['LocalImageProcessor'],
];

// Register XClasses to handle multi folder assignments.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\TYPO3\CMS\Core\Resource\ResourceStorage::class] = [
    'className' => \TYPO3Canto\CantoFal\Xclass\ResourceStorage::class,
];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\TYPO3\CMS\Core\Resource\Index\Indexer::class] = [
    'className' => \TYPO3Canto\CantoFal\Xclass\Indexer::class,
];

// Hooks
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][1627626213]
    = \TYPO3Canto\CantoFal\Hooks\DataHandlerHooks::class;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1628070217] = [
    'nodeName' => 'file',
    'priority' => 100,
    'class' => \TYPO3Canto\CantoFal\Form\Container\FileControlContainer::class,
];

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ElementBrowsers']['canto']
    = \TYPO3Canto\CantoFal\Browser\CantoAssetBrowser::class;

$extractorRegistry = GeneralUtility::makeInstance(ExtractorRegistry::class);
$extractorRegistry->registerExtractionService(\TYPO3Canto\CantoFal\Resource\Metadata\Extractor::class);
unset($extractorRegistry);

// Register files and folder information cache
if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['canto_fal_folder'] ?? null)) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['canto_fal_folder'] = [
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'groups' => [
            'system',
            'canto',
        ],
        'options' => [
            'defaultLifetime' => 3600,
        ],
    ];
}
if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['canto_fal_file'] ?? null)) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['canto_fal_file'] = [
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'groups' => [
            'system',
            'canto',
        ],
        'options' => [
            'defaultLifetime' => 3600,
        ],
    ];
}

$mediaFileExtensions = $GLOBALS['TYPO3_CONF_VARS']['SYS']['mediafile_ext'];
$GLOBALS['CANTO_FAL']['IMAGE_TYPES'] = explode(',', $mediaFileExtensions);

if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['mediafile_ext']) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['mediafile_ext'] .= ',eps';
}

if ($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']) {
    $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] .= ',eps';
}

\TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
    'canto_fal',
    'metadataWebhook',
    [
        \TYPO3Canto\CantoFal\Controller\MetadataWebhookController::class => 'index',
    ],
    [
        \TYPO3Canto\CantoFal\Controller\MetadataWebhookController::class => 'index',
    ],
);
/*
$signalSlotDispatcher = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\SignalSlot\Dispatcher::class);
$signalSlotDispatcher->connect(
    TYPO3\CMS\Backend\Controller\EditDocumentController::class,
    'initAfter',
    TYPO3Canto\CantoFal\Resource\EventListener\AfterFormEnginePageInitializedEventListener::class,
    'updateMetadataInCantoSlot'
);*/

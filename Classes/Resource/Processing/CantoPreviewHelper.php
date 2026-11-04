<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\Processing;

use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Processing\TaskInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Imaging\GifBuilder;
use TYPO3Canto\CantoFal\Resource\Repository\CantoRepository;

/**
 * Generates preview images for Canto assets.
 *
 * Formerly extended the core LocalPreviewHelper, which has been removed in TYPO3 v14.
 * The relevant logic has been adopted from TYPO3 core (GPL-2.0-or-later).
 */
class CantoPreviewHelper
{
    protected CantoRepository $cantoRepository;

    public function setCantoRepository(CantoRepository $cantoRepository): void
    {
        $this->cantoRepository = $cantoRepository;
    }

    /**
     * @return array{filePath?: string}|null
     */
    public function process(TaskInterface $task): ?array
    {
        $sourceFile = $task->getSourceFile();
        $task->sanitizeConfiguration();
        $configuration = $task->getConfiguration();

        // Do not scale up, if the source file has dimensions and any target dimension (width/height) is larger
        if ($sourceFile->getProperty('width') > 0 && $sourceFile->getProperty('height') > 0
            && (
                $configuration['width'] > $sourceFile->getProperty('width')
                || $configuration['height'] > $sourceFile->getProperty('height')
            )
        ) {
            return null;
        }

        return $this->generatePreviewFromFile(
            $sourceFile,
            $configuration,
            GeneralUtility::tempnam('preview_', '.' . $task->getTargetFileExtension())
        );
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array{filePath: string}
     */
    protected function generatePreviewFromFile(File $file, array $configuration, string $targetFilePath): array
    {
        try {
            $tempFile = $this->cantoRepository->getFileForLocalProcessing($file->getIdentifier(), true);
        } catch (\Exception $e) {
            // Create a default image
            $graphicalFunctions = GeneralUtility::makeInstance(GifBuilder::class);
            $graphicalFunctions->getTemporaryImageWithText(
                $targetFilePath,
                'Not imagefile!',
                'No ext!',
                $file->getName()
            );

            return [
                'filePath' => $targetFilePath,
            ];
        }

        $processedImagePath = $this->generatePreviewFromLocalFile($tempFile, $configuration, $targetFilePath);
        GeneralUtility::unlink_tempfile($tempFile);

        return $processedImagePath;
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array{filePath: string}
     */
    protected function generatePreviewFromLocalFile(string $originalFileName, array $configuration, string $targetFilePath): array
    {
        $imageService = GeneralUtility::makeInstance(GraphicalFunctions::class);
        $result = $imageService->resize($originalFileName, 'WEB', $configuration['width'] . 'm', $configuration['height'] . 'm', '', ['sample' => true]);
        if ($result) {
            $targetFilePath = $result->getRealPath();
        }
        if (!file_exists($targetFilePath)) {
            // Create an error gif
            $graphicalFunctions = GeneralUtility::makeInstance(GifBuilder::class);
            $graphicalFunctions->getTemporaryImageWithText(
                $targetFilePath,
                'No thumb',
                'generated!'
            );
        }

        return [
            'filePath' => $targetFilePath,
        ];
    }
}

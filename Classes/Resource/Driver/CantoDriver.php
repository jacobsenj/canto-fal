<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\Driver;

use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\FalDumpFileContentsDecoratorStream;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\AbstractDriver;
use TYPO3\CMS\Core\Resource\Driver\StreamableDriverInterface;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\Exception\MissingArrayPathException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Canto\CantoApi\DTO\Status;
use TYPO3Canto\CantoApi\Endpoint\Authorization\AuthorizationFailedException;
use TYPO3Canto\CantoApi\Endpoint\Authorization\NotAuthorizedException;
use TYPO3Canto\CantoApi\Http\Asset\BatchDeleteContentRequest;
use TYPO3Canto\CantoApi\Http\Asset\RenameContentRequest;
use TYPO3Canto\CantoApi\Http\Asset\SearchRequest;
use TYPO3Canto\CantoApi\Http\InvalidResponseException;
use TYPO3Canto\CantoApi\Http\LibraryTree\CreateAlbumFolderRequest;
use TYPO3Canto\CantoApi\Http\LibraryTree\DeleteFolderOrAlbumRequest;
use TYPO3Canto\CantoApi\Http\LibraryTree\GetTreeRequest;
use TYPO3Canto\CantoApi\Http\LibraryTree\ListAlbumContentRequest;
use TYPO3Canto\CantoApi\Http\LibraryTree\SearchFolderRequest;
use TYPO3Canto\CantoApi\Http\Upload\GetUploadSettingRequest;
use TYPO3Canto\CantoApi\Http\Upload\QueryUploadStatusRequest;
use TYPO3Canto\CantoApi\Http\Upload\UploadFileRequest;
use TYPO3Canto\CantoFal\Resource\MdcUrlGenerator;
use TYPO3Canto\CantoFal\Resource\Repository\CantoRepository;
use TYPO3Canto\CantoFal\Utility\CantoUtility;

class CantoDriver extends AbstractDriver implements StreamableDriverInterface
{
    public const DRIVER_NAME = 'Canto';

    public const ROOT_FOLDER = 'ROOT';

    protected CantoRepository $cantoRepository;

    /** @var non-empty-string */
    protected string $rootFolderIdentifier = self::ROOT_FOLDER;

    protected bool $validCantoConfiguration;

    /** @var string[] */
    public static array $transientCachedFiles = [];

    private ?MdcUrlGenerator $mdcUrlGenerator = null;

    public function __construct(array $configuration = [])
    {
        parent::__construct($configuration);
        $this->capabilities = new Capabilities(
            Capabilities::CAPABILITY_BROWSABLE
            | Capabilities::CAPABILITY_WRITABLE
        );
        $this->rootFolderIdentifier = $this->buildRootFolderIdentifier();
    }

    public function processConfiguration(): void
    {
        $this->validCantoConfiguration = is_int($this->storageUid)
            && $this->storageUid > 0
            && ($this->configuration['cantoName'] ?? '') !== ''
            && ($this->configuration['cantoDomain'] ?? '') !== ''
            && ($this->configuration['appId'] ?? '') !== ''
            && ($this->configuration['appSecret'] ?? '') !== '';
    }

    public function initialize(): void
    {
        // The check is necessary to prevent an error thrown in Maintenance Admin Tool -> Remove Temporary Assets
        if ($this->validCantoConfiguration && GeneralUtility::getContainer()->has(CantoRepository::class)) {
            $this->cantoRepository = GeneralUtility::makeInstance(CantoRepository::class);
            try {
                $this->cantoRepository->initialize((int)$this->storageUid, $this->configuration);
            } catch (AuthorizationFailedException $e) {
                echo 'Append Canto Fal Driver configuration.';
            }
        }
        $this->mdcUrlGenerator = GeneralUtility::makeInstance(MdcUrlGenerator::class);
    }

    public function mergeConfigurationCapabilities(Capabilities $capabilities): Capabilities
    {
        $this->capabilities->and($capabilities);

        return $this->capabilities;
    }

    /**
     * @phpstan-return non-empty-string
     */
    public function getRootLevelFolder(): string
    {
        return $this->rootFolderIdentifier;
    }

    public function getDefaultFolder(): string
    {
        return $this->rootFolderIdentifier;
    }

    /**
     * @param string $fileIdentifier
     * @throws FolderDoesNotExistException
     */
    public function getParentFolderIdentifierOfIdentifier($fileIdentifier): string
    {
        if (!$fileIdentifier) {
            return self::ROOT_FOLDER;
        }
        if ($fileIdentifier === $this->rootFolderIdentifier) {
            return $fileIdentifier;
        }

        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($fileIdentifier);
        $explicitFileIdentifier = CantoUtility::getIdFromCombinedIdentifier($fileIdentifier);
        if (CantoUtility::isFolder($scheme)) {
            $result = $this->cantoRepository->getFolderDetails($scheme, $explicitFileIdentifier);
            $pathIds = explode('/', $result['idPath']);
            if (count($pathIds) === 1) {
                return $this->rootFolderIdentifier;
            }
            // Remove current folder/album id.
            array_pop($pathIds);

            // The parent folder is always of scheme folder because albums can only contain files.
            return CantoUtility::buildCombinedIdentifier(CantoUtility::SCHEME_FOLDER, array_pop($pathIds));
        }

        // TODO Check if this method is used for files.
        return $this->rootFolderIdentifier;
    }

    /**
     * @return non-empty-string|null
     */
    public function getPublicUrl(string $identifier): ?string
    {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($identifier);
        $fileIdentifier = CantoUtility::getIdFromCombinedIdentifier($identifier);
        $useMdc = CantoUtility::isMdcActivated($this->configuration);
        $fileData = $this->cantoRepository->getFileDetails($scheme, $fileIdentifier);
        if (!is_array($fileData)) {
            return null;
        }
        if ($useMdc && $this->mdcUrlGenerator) {
            if ($scheme === SearchRequest::SCHEME_IMAGE) {
                $url = $this->cantoRepository->generateMdcUrl($identifier);
                $url .= $this->mdcUrlGenerator->addOperationToMdcUrl([
                    'width' => (int)$fileData['width'],
                    'height' => (int)$fileData['height'],
                ]);
            } else {
                $url = $this->cantoRepository->generateAssetMdcUrl($identifier, $fileData['name']);
            }

            return rawurldecode($url);
        }
        // todo: add FAIRCANTO-72 here
        if (!empty($fileData['url']['directUrlOriginal'])) {
            return rawurldecode($fileData['url']['directUrlOriginal']);
        }

        return null;
    }

    public function fileExists(string $fileIdentifier): bool
    {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($fileIdentifier);
        if (CantoUtility::isFolder($scheme)) {
            return false;
        }
        $explicitFileIdentifier = CantoUtility::getIdFromCombinedIdentifier($fileIdentifier);
        $result = $this->cantoRepository->getFileDetails(
            $scheme,
            $explicitFileIdentifier
        );

        return !empty($result);
    }

    public function folderExists(string $folderIdentifier): bool
    {
        if ($folderIdentifier === $this->rootFolderIdentifier) {
            return true;
        }

        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($folderIdentifier);
        $explicitFolderIdentifier = CantoUtility::getIdFromCombinedIdentifier($folderIdentifier);
        try {
            $result = $this->cantoRepository->getFolderDetails($scheme, $explicitFolderIdentifier);
        } catch (FolderDoesNotExistException $e) {
            return false;
        }

        return !empty($result);
    }

    public function isFolderEmpty(string $folderIdentifier): bool
    {
        return ($this->countFilesInFolder($folderIdentifier) + $this->countFoldersInFolder($folderIdentifier)) === 0;
    }

    /**
     * @param non-empty-string $fileIdentifier
     * @param non-empty-string $hashAlgorithm
     * @return non-empty-string
     */
    public function hash(string $fileIdentifier, string $hashAlgorithm): string
    {
        return hash($hashAlgorithm, $fileIdentifier);
    }

    public function getFileContents(string $fileIdentifier): string
    {
        $publicUrl = $this->getPublicUrl($fileIdentifier);
        if ((string)$publicUrl === '') {
            return '';
        }

        return GeneralUtility::getUrl($publicUrl);
    }

    public function fileExistsInFolder(string $fileName, string $folderIdentifier): bool
    {
        $fileInfo = $this->getFileInfoByIdentifier($fileName);
        foreach ($fileInfo['folder_identifiers'] as $parentFolderIdentifier) {
            if ($this->isWithin($folderIdentifier, $parentFolderIdentifier)) {
                return true;
            }
        }

        return false;
    }

    public function folderExistsInFolder(string $parentFolderIdentifier, string $folderIdentifier): bool
    {
        if ($parentFolderIdentifier === $folderIdentifier) {
            return true;
        }
        try {
            $parentFolderId = CantoUtility::getIdFromCombinedIdentifier($parentFolderIdentifier);
            $folderInfo = $this->getFolderInfoByIdentifier($folderIdentifier);
        } catch (NotAuthorizedException|InvalidResponseException $e) {
            return false;
        }

        return in_array($parentFolderId, GeneralUtility::trimExplode('/', $folderInfo['idPath']), true);
    }

    public function getFileForLocalProcessing(string $fileIdentifier, bool $writable = true): string
    {
        return $this->cantoRepository->getFileForLocalProcessing($fileIdentifier);
    }

    public function getPermissions(string $identifier): array
    {
        return [
            'r' => true,
            'w' => true,
        ];
    }

    public function dumpFileContents(string $identifier): void
    {
        echo $this->getFileContents($identifier);
    }

    public function isWithin(string $folderIdentifier, string $identifier): bool
    {
        /*
         * Ensure that the given identifiers are valid. Do not throw an exception,
         * because the processing folder is currently handed to this method, even
         * if it is configured for another driver.
         * See https://forge.typo3.org/issues/94645
         */
        if (
            !CantoUtility::isValidCombinedIdentifier($folderIdentifier)
            || !CantoUtility::isValidCombinedIdentifier($identifier)
        ) {
            return false;
        }

        $schemeToCheck = CantoUtility::getSchemeFromCombinedIdentifier($identifier);
        if (CantoUtility::isFolder($schemeToCheck)) {
            return $this->folderExistsInFolder($folderIdentifier, $identifier);
        }

        return $this->fileExistsInFolder($identifier, $folderIdentifier);
    }

    public function getFileInfoByIdentifier(string $fileIdentifier, array $propertiesToExtract = []): array
    {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($fileIdentifier);
        if (CantoUtility::isFolder($scheme)) {
            return $this->getFolderInfoByIdentifier($fileIdentifier);
        }

        $folders = [];
        $explicitFileIdentifier = CantoUtility::getIdFromCombinedIdentifier($fileIdentifier);
        $result = $this->cantoRepository->getFileDetails(
            $scheme,
            $explicitFileIdentifier
        );
        if ($result !== null) {
            foreach ($result['relatedAlbums'] ?? [] as $album) {
                $folders[] = CantoUtility::buildCombinedIdentifier($album['scheme'], $album['id']);
            }
            $data = [
                'size' => $result['default']['Size'],
                'atime' => time(),
                'mtime' => CantoUtility::buildTimestampFromCantoDate($result['default']['Date modified']),
                'ctime' => CantoUtility::buildTimestampFromCantoDate($result['default']['Date uploaded']),
                'mimetype' => $result['default']['Content Type'] ?? '',
                'name' => $result['name'],
                'extension' => PathUtility::pathinfo($result['name'], PATHINFO_EXTENSION),
                'identifier' => $fileIdentifier,
                'identifier_hash' => $this->hashIdentifier($fileIdentifier),
                'storage' => $this->storageUid,
                'folder_hash' => '',
                'folder_identifiers' => $folders,
            ];
        } else {
            // Use fallbackimage.jpg as fallback information
            // TODO: throw an exception and handle within code context
            $data = [
                'size' => 1000,
                'atime' => time(),
                'mtime' => 0,
                'ctime' => 0,
                'mimetype' => '',
                'name' => 'fallbackimage.jpg',
                'extension' => 'jpg',
                'identifier' => $fileIdentifier,
                'identifier_hash' => $this->hashIdentifier($fileIdentifier),
                'storage' => $this->storageUid,
                'folder_hash' => '',
                'folder_identifiers' => [],
            ];
        }
        if (!$propertiesToExtract) {
            return $data;
        }
        $properties = [];
        foreach ($propertiesToExtract as $item) {
            $properties[$item] = $data[$item];
        }

        return $properties;
    }

    /**
     * @return array{'identifier': string, 'name': string, 'mtime': int, 'ctime': int, 'storage': ?int, 'idPath': string}
     */
    public function getFolderInfoByIdentifier(string $folderIdentifier): array
    {
        $now = time();
        $rootFolder = [
            'identifier' => $this->rootFolderIdentifier,
            'name' => 'Canto',
            'mtime' => $now,
            'ctime' => $now,
            'storage' => $this->storageUid,
            'idPath' => CantoUtility::getIdFromCombinedIdentifier($this->rootFolderIdentifier),
        ];
        if (!$folderIdentifier || $folderIdentifier === $this->rootFolderIdentifier) {
            return $rootFolder;
        }
        $explicitFolderIdentifier = CantoUtility::getIdFromCombinedIdentifier($folderIdentifier);
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($folderIdentifier);
        $result = $this->cantoRepository->getFolderDetails($scheme, $explicitFolderIdentifier);
        // TODO Find solution how to handle equal folder and album names.
        $folderName = sprintf('F: %s', $result['name']);
        if ($scheme === CantoUtility::SCHEME_ALBUM) {
            $folderName = sprintf('A: %s', $result['name']);
        }

        return [
            'identifier' => $folderIdentifier,
            'name' => $folderName,
            'mtime' => CantoUtility::buildTimestampFromCantoDate($result['time']),
            'ctime' => CantoUtility::buildTimestampFromCantoDate($result['created']),
            'storage' => $this->storageUid,
            'idPath' => $result['idPath'],
        ];
    }

    public function getFileInFolder(string $fileName, string $folderIdentifier): string
    {
        $filesWithName = $this->resolveFilesInFolder(
            $folderIdentifier,
            0,
            0,
            false,
            [],
        );
        foreach ($filesWithName as $file) {
            if ($file['name'] === $fileName) {
                return $file['id'];
            }
        }

        return '';
    }

    public function getFilesInFolder(
        string $folderIdentifier,
        int $start = 0,
        int $numberOfItems = 0,
        bool $recursive = false,
        array $filenameFilterCallbacks = [],
        string $sort = '',
        bool $sortRev = false
    ): array {
        $files = [];
        $results = $this->resolveFilesInFolder($folderIdentifier, $start, $numberOfItems, $recursive, $filenameFilterCallbacks, $sort, $sortRev);
        foreach ($results as $result) {
            $fileIdentifier = CantoUtility::buildCombinedIdentifier($result['scheme'], $result['id']);
            $this->cantoRepository->setFileCache($fileIdentifier, $result);
            $files[] = $fileIdentifier;
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveFilesInFolder(
        string $folderIdentifier,
        int $start = 0,
        int $numberOfItems = 0,
        bool $recursive = false,
        array $filenameFilterCallbacks = [],
        string $sort = '',
        bool $sortRev = false
    ): array {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($folderIdentifier);
        if ($scheme === CantoUtility::SCHEME_FOLDER || $folderIdentifier === $this->rootFolderIdentifier) {
            // There are no files in folders, just other files and albums.
            return [];
        }

        $explicitFolderIdentifier = CantoUtility::getIdFromCombinedIdentifier($folderIdentifier);
        $sortBy = $this->mapSortBy($sort);
        $sortDirection = $sortRev ? ListAlbumContentRequest::SORT_DIRECTION_DESC
            : ListAlbumContentRequest::SORT_DIRECTION_ASC;
        $limit = $numberOfItems > 0 ? min($numberOfItems, 1000) : 1000;

        // TODO Check if there are more that 1000 files and make multiple requests if needed.
        return $this->cantoRepository->getFilesInFolder(
            $explicitFolderIdentifier,
            $start,
            $limit,
            $sortBy,
            $sortDirection
        );
    }

    public function getFolderInFolder(string $folderName, string $folderIdentifier): string
    {
        $foldersWithName = $this->getFoldersInFolder(
            $folderIdentifier,
            0,
            0,
            false,
            [],
        ) ?? [];
        if (count($foldersWithName) !== 1) {
            return '';
        }

        return $foldersWithName[0];
    }

    public function getFoldersInFolder(
        string $folderIdentifier,
        int $start = 0,
        int $numberOfItems = 0,
        bool $recursive = false,
        array $folderNameFilterCallbacks = [],
        string $sort = '',
        bool $sortRev = false
    ): array {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($folderIdentifier);
        if ($scheme === CantoUtility::SCHEME_ALBUM) {
            // Albums contain only files, not folders.
            return [];
        }
        $folders = [];
        $explicitFolderIdentifier = CantoUtility::getIdFromCombinedIdentifier($folderIdentifier);
        $sortBy = GetTreeRequest::SORT_BY_NAME;
        $sortDirection = $sortRev ? GetTreeRequest::SORT_DIRECTION_DESC : GetTreeRequest::SORT_DIRECTION_ASC;
        $folderTree = $this->cantoRepository->getFolderIdentifierTree($sortBy, $sortDirection);
        if ($folderIdentifier === $this->rootFolderIdentifier) {
            $folderTree = $folderTree[$this->rootFolderIdentifier] ?? $folderTree;
        } else {
            $folderInformation = $this->cantoRepository->getFolderDetails($scheme, $explicitFolderIdentifier);
            $idPathSegments = str_getcsv($folderInformation['idPath'], '/', '"', '');
            $lastSegmentIndex = count($idPathSegments) - 1;
            array_walk(
                $idPathSegments,
                static function (string &$folderIdentifier, int $key, $scheme) use ($lastSegmentIndex, $folderInformation) {
                    if ($key === $lastSegmentIndex) {
                        $scheme = $folderInformation['scheme'];
                    }
                    $folderIdentifier = CantoUtility::buildCombinedIdentifier($scheme, $folderIdentifier);
                },
                CantoUtility::SCHEME_FOLDER
            );
            if (in_array($this->rootFolderIdentifier, $idPathSegments)) {
                $idPathSegments = array_slice($idPathSegments, array_search($this->rootFolderIdentifier, $idPathSegments) + 1);
            }
            $idPath = implode('/', $idPathSegments);
            try {
                $folderTree = ArrayUtility::getValueByPath($folderTree, $idPath);
            } catch (MissingArrayPathException $e) {
            }
        }
        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($folderTree), \RecursiveIteratorIterator::SELF_FIRST);
            $folderTree = iterator_to_array($iterator, true);
        }

        // $c is the counter for how many items we still have to fetch (-1 is unlimited)
        $c = $numberOfItems > 0 ? $numberOfItems : -1;
        foreach (array_keys($folderTree) as $identifier) {
            if ($c === 0) {
                break;
            }
            if ($start > 0) {
                $start--;
            } else {
                $folders[$identifier] = (string)$identifier;
                --$c;
            }
        }

        return $folders;
    }

    /**
     * @return int<0, max>
     */
    public function countFilesInFolder(string $folderIdentifier, bool $recursive = false, array $filenameFilterCallbacks = []): int
    {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($folderIdentifier);
        $explicitFolderIdentifier = CantoUtility::getIdFromCombinedIdentifier($folderIdentifier);
        if ($scheme === CantoUtility::SCHEME_FOLDER || $folderIdentifier === $this->rootFolderIdentifier) {
            // Folders can not have files, just other folders and albums.
            return 0;
        }

        return $this->cantoRepository->countFilesInFolder($explicitFolderIdentifier);
    }

    public function countFoldersInFolder(
        string $folderIdentifier,
        bool $recursive = false,
        array $folderNameFilterCallbacks = []
    ): int {
        return count($this->getFoldersInFolder(
            $folderIdentifier,
            0,
            0,
            $recursive,
            $folderNameFilterCallbacks
        ));
    }

    public function hashIdentifier(string $identifier): string
    {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($identifier);
        if (CantoUtility::isFolder($scheme)) {
            $identifier = $this->canonicalizeAndCheckFolderIdentifier($identifier);
        }
        $identifier = $this->canonicalizeAndCheckFileIdentifier($identifier);

        return $this->hash($identifier, 'sha1');
    }

    protected function mapSortBy(string $sortBy): string
    {
        switch ($sortBy) {
            case 'name':
                return SearchFolderRequest::SORT_BY_NAME;
            case 'fileext':
                return SearchFolderRequest::SORT_BY_SCHEME;
            case 'size':
                return SearchFolderRequest::SORT_BY_SIZE;
        }

        return SearchFolderRequest::SORT_BY_TIME;
    }

    /**
     * @phpstan-return non-empty-string
     */
    protected function buildRootFolderIdentifier(): string
    {
        $rootFolderScheme = CantoUtility::SCHEME_FOLDER;
        if (!empty($this->configuration['rootFolderScheme'])
            && $this->configuration['rootFolderScheme'] === CantoUtility::SCHEME_ALBUM
        ) {
            $rootFolderScheme = CantoUtility::SCHEME_ALBUM;
        }
        $rootFolder = self::ROOT_FOLDER;
        if (!empty($this->configuration['rootFolder'])) {
            $rootFolder = $this->configuration['rootFolder'];
        }

        return CantoUtility::buildCombinedIdentifier(
            $rootFolderScheme,
            $rootFolder
        );
    }

    public function sanitizeFileName(string $fileName, string $charset = ''): string
    {
        return $fileName;
    }

    protected function canonicalizeAndCheckFilePath(string $filePath): string
    {
        return $filePath;
    }

    protected function canonicalizeAndCheckFileIdentifier(string $fileIdentifier): string
    {
        return $fileIdentifier;
    }

    protected function canonicalizeAndCheckFolderIdentifier(string $folderIdentifier): string
    {
        return $folderIdentifier;
    }

    /**
     * Transient File-Cache cleanup
     *
     * @see https://review.typo3.org/#/c/36446/
     */
    public function __destruct()
    {
        foreach (self::$transientCachedFiles as $cachedFile) {
            if (file_exists($cachedFile)) {
                unlink($cachedFile);
            }
        }
    }

    /**
     * @param non-empty-string $identifier
     * @param array $properties
     * @return ResponseInterface
     */
    public function streamFile(string $identifier, array $properties): ResponseInterface
    {
        $fileInfo = $this->getFileInfoByIdentifier($identifier, ['name', 'mimetype', 'mtime', 'size']);
        $downloadName = $properties['filename_overwrite'] ?? $fileInfo['name'] ?? '';
        $mimeType = $properties['mimetype_overwrite'] ?? $fileInfo['mimetype'] ?? '';
        $contentDisposition = ($properties['as_download'] ?? false) ? 'attachment' : 'inline';

        return new Response(
            new FalDumpFileContentsDecoratorStream($identifier, $this, (int)$fileInfo['size']),
            200,
            [
                'Content-Disposition' => $contentDisposition . '; filename="' . $downloadName . '"',
                'Content-Type' => $mimeType,
                'Content-Length' => (string)$fileInfo['size'],
                'Last-Modified' => gmdate('D, d M Y H:i:s', $fileInfo['mtime']) . ' GMT',
                // Cache-Control header is needed here to solve an issue with browser IE8 and lower
                // See for more information: http://support.microsoft.com/kb/323308
                'Cache-Control' => '',
            ]
        );
    }

    public function createFolder(string $newFolderName, string $parentFolderIdentifier = '', bool $recursive = false): string
    {
        $createAlbum = str_starts_with($newFolderName, 'A:');
        $newFolderName = str_replace(['A:', 'F:'], '', $newFolderName);
        $request = new CreateAlbumFolderRequest($newFolderName);
        $request->setParentFolder(CantoUtility::getIdFromCombinedIdentifier($parentFolderIdentifier));
        try {
            if ($createAlbum) {
                return 'album<>' . $this->cantoRepository->getClient()->libraryTree()->createAlbum($request)->getId();
            }
            $folder = $this->cantoRepository->getClient()->libraryTree()->createFolder($request);
            $id = 'folder<>' . $folder->getId();
            $this->cantoRepository->setFolderCache($id, $folder->getResponseData());

            return $id;
        } catch (NotAuthorizedException|InvalidResponseException $e) {
            throw new \RuntimeException('Creating the folder did not work - ' . $e->getMessage());
        }
    }

    public function renameFolder(string $folderIdentifier, string $newName): array
    {
        throw new NotSupportedException('Renaming a folder is currently not supported.', 1626963089);
    }

    public function deleteFolder(string $folderIdentifier, bool $deleteRecursively = false): bool
    {
        $request = new DeleteFolderOrAlbumRequest();
        ['scheme' => $scheme, 'identifier' => $identifier] = CantoUtility::splitCombinedIdentifier($folderIdentifier);
        $request->addFolder($identifier, $scheme);
        try {
            return $this->cantoRepository->getClient()->libraryTree()->deleteFolderOrAlbum($request)->isSuccessful();
        } catch (\Exception $e) {
            if ($e->getPrevious() instanceof GuzzleException) {
                // replace with logger
                debug([$request, $e->getPrevious()->getMessage()]);
            }
        }
        CantoUtility::flushCache($this->cantoRepository);

        return false;
    }

    public function addFile(string $localFilePath, string $targetFolderIdentifier, string $newFileName = '', bool $removeOriginal = true): string
    {
        $uploadSettingsRequest = new GetUploadSettingRequest(false);
        $response = $this->cantoRepository->getClient()->upload()->getUploadSetting($uploadSettingsRequest);
        $request = new UploadFileRequest(
            $localFilePath,
            $response,
        );
        $request->setScheme(SearchRequest::SCHEME_IMAGE);
        if (CantoUtility::getSchemeFromCombinedIdentifier($targetFolderIdentifier) === CantoUtility::SCHEME_FOLDER) {
            throw new \Exception('Files need to be within an album, not a folder');
        }
        $request->setAlbumId(CantoUtility::getIdFromCombinedIdentifier($targetFolderIdentifier));
        $request->setFileName($newFileName);
        try {
            $this->cantoRepository->getClient()->upload()->uploadFile($request);
        } catch (NotAuthorizedException $e) {
            $this->sendFlashMessageToUser('Not Authorized', $e->getMessage(), ContextualFeedbackSeverity::ERROR);
            throw $e;
        } catch (InvalidResponseException $e) {
            $this->sendFlashMessageToUser('Invalid Response', $e->getMessage(), ContextualFeedbackSeverity::ERROR);
            throw $e;
        } catch (\JsonException $e) {
            $this->sendFlashMessageToUser('JSON Exception', $e->getMessage(), ContextualFeedbackSeverity::ERROR);
            throw $e;
        }
        $id = '';
        $count = 0;
        while ($id === '') {
            $status = $this->cantoRepository->getClient()->upload()->queryUploadStatus(new QueryUploadStatusRequest());
            // We need to wait for AWS to process the image, only then will we be able to show, that the file has been uploaded successfully and display it in the list.
            // The file though has already been uploaded at this point, it just is not yet present in canto
            sleep(2);
            foreach ($status->getStatusItems() as $item) {
                if ($item->name === $newFileName && $item->status === Status::STATUS_DONE) {
                    $id = CantoUtility::buildCombinedIdentifier($item->scheme, $item->id);
                }
            }
            if (++$count > 15) {
                $this->sendFlashMessageToUser('Timeout', 'File not fully processed. Please reload', ContextualFeedbackSeverity::WARNING);

                return '';
            }
        }
        if ($id && $removeOriginal) {
            unlink($localFilePath);
        }
        CantoUtility::flushCache($this->cantoRepository);

        return $id;
    }

    public function createFile(string $fileName, string $parentFolderIdentifier): string
    {
        $path = '/tmp/' . $fileName;
        touch($path);
        $identifier = $this->addFile($path, $parentFolderIdentifier, $fileName);
        CantoUtility::flushCache($this->cantoRepository);

        return $identifier;
    }

    public function copyFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $fileName): string
    {
        throw new NotSupportedException('This driver does not support this operation yet.', 1626963232);
    }

    public function renameFile(string $fileIdentifier, string $newName): string
    {
        ['scheme' => $scheme, 'identifier' => $identifier] = CantoUtility::splitCombinedIdentifier($fileIdentifier);
        $request = new RenameContentRequest($scheme, $identifier, $newName);
        try {
            $this->cantoRepository->getClient()->asset()->renameContent($request);
            CantoUtility::flushCache($this->cantoRepository);
        } catch (InvalidResponseException $e) {
            // replace with logger
            debug([$request, $e->getPrevious()->getMessage()]);
        }

        return $fileIdentifier;
    }

    public function replaceFile(string $fileIdentifier, string $localFilePath): bool
    {
        throw new NotSupportedException('This driver does not support this operation yet.', 1626963248);
    }

    public function deleteFile(string $fileIdentifier): bool
    {
        ['scheme' => $scheme, 'identifier' => $identifier] = CantoUtility::splitCombinedIdentifier($fileIdentifier);
        $request = new BatchDeleteContentRequest();
        $request->addContent($scheme, $identifier);
        try {
            $this->cantoRepository->getClient()->asset()->batchDeleteContent($request);
        } catch (InvalidResponseException $e) {
            // replace with logger
            debug([$request, $e->getPrevious()->getMessage()]);
        }
        CantoUtility::flushCache($this->cantoRepository);

        return true;
    }

    public function moveFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $newFileName): string
    {
        throw new NotSupportedException('This driver does not support this operation yet.', 1626963285);
    }

    public function moveFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): array
    {
        throw new NotSupportedException('This driver does not support this operation yet.', 1626963299);
    }

    public function copyFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): bool
    {
        throw new NotSupportedException('This driver does not support this operation yet.', 1626963313);
    }

    public function setFileContents(string $fileIdentifier, string $contents): int
    {
        throw new NotSupportedException('This driver does not support this operation yet.', 1626963332);
    }

    private function sendFlashMessageToUser(string $messageHeader, string $messageText, ContextualFeedbackSeverity $messageSeverity): void
    {
        $message = GeneralUtility::makeInstance(
            FlashMessage::class,
            $messageText,
            $messageHeader,
            $messageSeverity,
            true
        );
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $messageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $messageQueue->enqueue($message);
    }
}

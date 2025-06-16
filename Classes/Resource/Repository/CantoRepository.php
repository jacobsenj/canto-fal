<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\Repository;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3Canto\CantoApi\Client;
use TYPO3Canto\CantoApi\Endpoint\Authorization\AuthorizationFailedException;
use TYPO3Canto\CantoApi\Endpoint\Authorization\NotAuthorizedException;
use TYPO3Canto\CantoApi\Http\Asset\GetContentDetailsRequest;
use TYPO3Canto\CantoApi\Http\Asset\SearchRequest;
use TYPO3Canto\CantoApi\Http\Authorization\OAuth2Request;
use TYPO3Canto\CantoApi\Http\InvalidResponseException;
use TYPO3Canto\CantoApi\Http\LibraryTree\GetDetailsRequest;
use TYPO3Canto\CantoApi\Http\LibraryTree\GetTreeRequest;
use TYPO3Canto\CantoApi\Http\LibraryTree\ListAlbumContentRequest;
use TYPO3Canto\CantoFal\Domain\Model\Dto\AssetSearch;
use TYPO3Canto\CantoFal\Domain\Model\Dto\AssetSearchResponse;
use TYPO3Canto\CantoFal\Resource\CantoClientFactory;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;
use TYPO3Canto\CantoFal\Resource\Event\BeforeLocalFileProcessingEvent;
use TYPO3Canto\CantoFal\Utility\CantoUtility;

class CantoRepository
{
    public const REGISTRY_NAMESPACE = 'cantoFal';
    public const CANTO_CACHE_TAG_BLUEPRINT = 'canto_storage_%s';

    /**
     * The session token is valid for 30 days.
     * This property contains the time in seconds, until the token should be renewed.
     * Default: 29 days
     */
    protected int $sessionTokenValid = 2505600;

    protected Client $client;

    protected Registry $registry;

    protected FrontendInterface $cantoFolderCache;

    protected FrontendInterface $cantoFileCache;

    protected array $driverConfiguration;

    protected int $storageUid;

    protected string $cantoCacheTag;

    protected EventDispatcher $dispatcher;

    public function __construct(
        Registry $registry,
        FrontendInterface $cantoFolderCache,
        FrontendInterface $cantoFileCache,
        EventDispatcher $dispatcher
    ) {
        $this->registry = $registry;
        $this->cantoFolderCache = $cantoFolderCache;
        $this->cantoFileCache = $cantoFileCache;
        $this->dispatcher = $dispatcher;
    }

    /**
     * @throws AuthorizationFailedException
     */
    public function initialize(int $storageUid, array $driverConfiguration): void
    {
        $this->driverConfiguration = $driverConfiguration;
        $this->storageUid = $storageUid;
        $this->cantoCacheTag = sprintf(self::CANTO_CACHE_TAG_BLUEPRINT, $this->storageUid);
        $this->client = $this->buildCantoClient();
        $this->authenticateAgainstCanto();
    }

    public function getCantoCacheTag(): string
    {
        return $this->cantoCacheTag;
    }

    public function setSessionTokenValid(int $sessionTokenValid): void
    {
        $this->sessionTokenValid = $sessionTokenValid;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @throws InvalidResponseException
     * @throws NotAuthorizedException
     */
    public function search(AssetSearch $search): AssetSearchResponse
    {
        $request = new SearchRequest();
        $request->setKeyword($search->getKeyword())
            ->setTags($search->getTags())
            ->setKeywords($search->getCategories())
            ->setSearchInField($search->getSearchInField())
            ->setStart($search->getStart())
            ->setLimit($search->getLimit())
            ->setApproval($search->getStatus())
            ->setScheme(implode('|', $search->getSchemes()));
        $response = $this->client->asset()->search($request);
        $assetSearchResponse = new AssetSearchResponse();

        return $assetSearchResponse
            ->setFound($response->getFound())
            ->setResults($response->getResults());
    }

    /**
     * @throws FolderDoesNotExistException
     */
    public function getFolderDetails(string $scheme, string $folderIdentifier): array
    {
        $combinedIdentifier = CantoUtility::buildCombinedIdentifier($scheme, $folderIdentifier);
        $cacheIdentifier = $this->buildValidCacheIdentifier($combinedIdentifier);
        if ($this->cantoFolderCache->has($cacheIdentifier)) {
            $cacheItem = $this->cantoFolderCache->get($cacheIdentifier);
            if (is_array($cacheItem)) {
                return $cacheItem;
            }
        }

        $request = new GetDetailsRequest($folderIdentifier, $scheme);
        try {
            $response = $this->client->libraryTree()->getDetails($request);
        } catch (NotAuthorizedException | InvalidResponseException $e) {
            throw new FolderDoesNotExistException(
                'Folder "' . $folderIdentifier . '" does not exist.',
                1626950904,
                $e
            );
        }
        $result = $response->getResponseData();
        $this->setFolderCache($combinedIdentifier, $result);
        return $result;
    }

    public function setFolderCache(string $folderIdentifier, array $result): void
    {
        $cacheIdentifier = $this->buildValidCacheIdentifier($folderIdentifier);
        if (!$this->cantoFolderCache->has($cacheIdentifier)) {
            $this->cantoFolderCache->set(
                $cacheIdentifier,
                $result,
                [$this->cantoCacheTag]
            );
        }
    }

    public function getFileDetails(string $scheme, string $fileIdentifier): ?array
    {
        $combinedIdentifier = CantoUtility::buildCombinedIdentifier($scheme, $fileIdentifier);
        $cacheIdentifier = $this->buildValidCacheIdentifier($combinedIdentifier);

        if ($this->cantoFileCache->has($cacheIdentifier)) {
            return $this->cantoFileCache->get($cacheIdentifier);
        }

        $request = new GetContentDetailsRequest($fileIdentifier, $scheme);
        try {
            $response = $this->client->asset()->getContentDetails($request);
        } catch (NotAuthorizedException | InvalidResponseException $e) {
            return null;
        }
        $result = $response->getResponseData();
        $this->setFileCache($combinedIdentifier, $result);

        return $result;
    }

    public function setFileCache(string $fileIdentifier, array $result): void
    {
        $cacheIdentifier = $this->buildValidCacheIdentifier($fileIdentifier);
        if (!$this->cantoFileCache->has($cacheIdentifier)) {
            $this->cantoFileCache->set(
                $cacheIdentifier,
                $result,
                [$this->cantoCacheTag]
            );
        }
    }

    public function getFileForLocalProcessing(string $fileIdentifier, bool $preview = true): string
    {
        $scheme = CantoUtility::getSchemeFromCombinedIdentifier($fileIdentifier);
        $identifier = CantoUtility::getIdFromCombinedIdentifier($fileIdentifier);
        $useMdc = CantoUtility::isMdcActivated($this->driverConfiguration);
        $fileData = $this->getFileDetails($scheme, $identifier);

        if ($fileData == null) {
            $extensionPath = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('canto_fal');

            // Relativer Pfad zum Bild innerhalb der Extension
            $imagePath = 'Resources/Public/Images/fallback.png';

            // Vollständiger Pfad zum Bild
            $fullImagePath = $extensionPath . $imagePath;

            CantoDriver::$transientCachedFiles[] = $fullImagePath;
            return $fullImagePath;
        }
        if (Environment::isCli() || ApplicationType::fromRequest($GLOBALS['TYPO3_REQUEST'])->isFrontend()) {
            $preview = false;
        }
        $event = new BeforeLocalFileProcessingEvent($fileData, $scheme, $preview);
        $this->dispatcher->dispatch($event);
        $sourcePath = $event->getSourcePath();
        if ($sourcePath === null) {
            throw new \RuntimeException(
                sprintf('Getting original url for file %s failed.', $fileIdentifier),
                1627391514
            );
        }
        $temporaryPath = GeneralUtility::tempnam('canto_clone_', '.' . $event->getFileExtension());
        if ($useMdc) {
            return '';
        }
        $this->downloadCantoFile($temporaryPath, $sourcePath, $fileIdentifier);

        touch($temporaryPath, CantoUtility::buildTimestampFromCantoDate($fileData['default']['Date modified']));
        if (!file_exists($temporaryPath)) {
            throw new \RuntimeException(
                'Copying file "' . $fileIdentifier . '" to temporary path "' . $temporaryPath . '" failed.',
                1320577649
            );
        }
        CantoDriver::$transientCachedFiles[] = $temporaryPath;
        return $temporaryPath;
    }

    private function downloadCantoFile(string $temporaryPath, ?string $sourcePath, string $fileIdentifier): void
    {
        try {
            $fileContentReadStream = $this->client
                ->asset()
                ->getAuthorizedUrlContent($sourcePath)
                ->getBody()
                ->detach();
            $tempFileWriteStream = fopen($temporaryPath, 'w');
            stream_copy_to_stream($fileContentReadStream, $tempFileWriteStream);
            fclose($tempFileWriteStream);
        } catch (NotAuthorizedException | InvalidResponseException $e) {
            throw new \RuntimeException(
                sprintf('Getting original file content for file %s failed.', $fileIdentifier),
                1627549128
            );
        }
    }

    /**
     * @return non-empty-string
     */
    public function generateAssetMdcUrl(string $identifier, ?string $downloadName): string
    {
        $domain = $this->driverConfiguration['mdcDomainName'];
        $awsAccountId = $this->driverConfiguration['mdcAwsAccountId'];
        $combinedIdentifier = CantoUtility::splitCombinedIdentifier($identifier);

        $queryParams = [
            'content-disposition' => $this->driverConfiguration['contentDisposition'],
        ];

        return sprintf(
            'https://%s/asset/%s/%s_%s/%s%s',
            $domain,
            $awsAccountId,
            $combinedIdentifier['scheme'],
            $combinedIdentifier['identifier'],
            $downloadName,
            HttpUtility::buildQueryString($queryParams, '?', true)
        );
    }

    /**
     * @return non-empty-string
     */
    public function generateMdcUrl(string $identifier): string
    {
        $domain = $this->driverConfiguration['mdcDomainName'];
        $awsAccountId = $this->driverConfiguration['mdcAwsAccountId'];
        $combinedIdentifier = CantoUtility::splitCombinedIdentifier($identifier);

        return sprintf('https://%s/image/%s/%s_%s/', $domain, $awsAccountId, $combinedIdentifier['scheme'], $combinedIdentifier['identifier']);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilesInFolder(
        string $folderIdentifier,
        int $start,
        int $limit,
        string $sortBy = ListAlbumContentRequest::SORT_BY_TIME,
        string $sortDirection = ListAlbumContentRequest::SORT_DIRECTION_ASC
    ): array {
        $request = new ListAlbumContentRequest($folderIdentifier);
        $request->setSortBy($sortBy)
            ->setSortDirection($sortDirection)
            ->setLimit($limit)
            ->setStart($start);
        try {
            $response = $this->client->libraryTree()->listAlbumContent($request);
        } catch (InvalidResponseException | NotAuthorizedException $e) {
            return [];
        }
        return $response->getResults();
    }

    /**
     * @return int<0, max>
     */
    public function countFilesInFolder(string $folderIdentifier): int
    {
        $request = new ListAlbumContentRequest($folderIdentifier);
        $request->setLimit(1);
        try {
            $response = $this->client->libraryTree()->listAlbumContent($request);
        } catch (InvalidResponseException | NotAuthorizedException $e) {
            return 0;
        }
        return max(0, $response->getFound());
    }

    public function getFolderIdentifierTree(string $sortBy, string $sortDirection): array
    {
        $treeIdentifier = sha1($this->storageUid . $sortBy . $sortDirection);
        $cacheIdentifier = sprintf('fulltree_%s', $treeIdentifier);
        if ($this->cantoFolderCache->has($cacheIdentifier)) {
            return $this->cantoFolderCache->get($cacheIdentifier);
        }

        try {
            $folderIdentifier = '';
            if ($this->driverConfiguration['rootFolderScheme'] === CantoUtility::SCHEME_FOLDER
                && $this->driverConfiguration['rootFolder'] !== '') {
                $folderIdentifier = $this->driverConfiguration['rootFolder'];
            }
            $response = $this->client->libraryTree()->getTree(new GetTreeRequest($folderIdentifier));
            $folderTree = $this->buildFolderTree($response->getResults());
            $this->cantoFolderCache->set(
                $cacheIdentifier,
                $folderTree,
                [$this->cantoCacheTag]
            );
        } catch (NotAuthorizedException | InvalidResponseException $e) {
            return [];
        }
        return $folderTree ?: [];
    }

    protected function buildFolderTree(array $treeItems): array
    {
        $folderIdentifiers = [];
        foreach ($treeItems as $folder) {
            $folderIdentifier = CantoUtility::buildCombinedIdentifier($folder['scheme'], $folder['id']);
            $folderIdentifiers[$folderIdentifier] = $this->buildFolderTree($folder['children'] ?? []);
            $this->setFolderCache($folderIdentifier, $folder);
        }
        return $folderIdentifiers;
    }

    protected function buildValidCacheIdentifier(string $cacheIdentifier): string
    {
        return sha1($cacheIdentifier);
    }

    /**
     * @throws AuthorizationFailedException
     */
    protected function authenticateAgainstCanto(): void
    {
        $accessTokenValidKey = sprintf('accessTokenForStorage%sValidUntil', $this->storageUid);
        $accessTokenKey = sprintf('accessTokenForStorage%s', $this->storageUid);
        $accessTokenValid = $this->registry->get(self::REGISTRY_NAMESPACE, $accessTokenValidKey, 0);
        $accessToken = $this->registry->get(self::REGISTRY_NAMESPACE, $accessTokenKey);
        $now = (new \DateTime())->getTimestamp();

        if ($accessToken === null || $accessTokenValid < $now) {
            $accessToken = $this->client
                ->authorizeWithClientCredentials(
                    $this->driverConfiguration['userId'] ?? '',
                    $this->driverConfiguration['scope'] ?? OAuth2Request::SCOPE_ADMIN
                )
                ->getAccessToken();
            $this->registry->set(self::REGISTRY_NAMESPACE, $accessTokenKey, $accessToken);
            $this->registry->set(
                self::REGISTRY_NAMESPACE,
                $accessTokenValidKey,
                $now + $this->sessionTokenValid
            );
        }
        $this->client->setAccessToken($accessToken);
    }

    protected function buildCantoClient(): Client
    {
        /** @var CantoClientFactory $cantoClientFactory */
        $cantoClientFactory = GeneralUtility::makeInstance(CantoClientFactory::class);
        return $cantoClientFactory->createClientFromDriverConfiguration($this->driverConfiguration);
    }
}

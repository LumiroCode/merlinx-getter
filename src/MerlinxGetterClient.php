<?php

declare(strict_types=1);

namespace Skionline\MerlinxGetter;

use Psr\SimpleCache\CacheInterface;
use Skionline\MerlinxGetter\Cache\FileKeyLock;
use Skionline\MerlinxGetter\Cache\FilesystemCacheFactory;
use Skionline\MerlinxGetter\Cache\NamespacedCache;
use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\Contract\OperationInterface;
use Skionline\MerlinxGetter\Exception\MerlinxGetterException;
use Skionline\MerlinxGetter\Http\AuthTokenProvider;
use Skionline\MerlinxGetter\Http\LoopbackHttpClient;
use Skionline\MerlinxGetter\Http\MerlinxHttpClient;
use Skionline\MerlinxGetter\Log\LoggerInterface;
use Skionline\MerlinxGetter\Operation\GetDetailsOperation;
use Skionline\MerlinxGetter\Operation\GetLiveAvailabilityOperation;
use Skionline\MerlinxGetter\Operation\PortalSearchOperation;
use Skionline\MerlinxGetter\Operation\RawTravelSearchOperation;
use Skionline\MerlinxGetter\Operation\SearchBaseOperation;
use Skionline\MerlinxGetter\Operation\SearchOperation;
use Skionline\MerlinxGetter\Search\Execution\SearchExecutionRequest;
use Skionline\MerlinxGetter\Search\Execution\SearchExecutionResult;
use Skionline\MerlinxGetter\Search\Profile\SearchEngineProfile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MerlinxGetterClient
{
	/** @var array<string, OperationInterface> */
	private array $operations = [];

	/** @var array<int, CacheInterface> */
	private array $managedCaches = [];

	/** @var array<int, callable():void> */
	private array $runtimeResetters = [];

	private readonly MerlinxGetterConfig $config;
	private readonly SearchEngineProfile $searchProfile;

	public function __construct(
		MerlinxGetterConfig $config,
		?HttpClientInterface $httpClient = null,
		?CacheInterface $cache = null,
		?LoggerInterface $logger = null
	)
	{
		$this->config = $config;
		$this->searchProfile = $config->searchProfile();

		[$tokenCache, $searchCache, $detailsCache, $liveAvailabilityCache, $searchBaseCache, $lockDir] = $this->buildCacheStores($cache);
		$lock = new FileKeyLock(
			$lockDir,
			$this->config->cacheSearchLockTimeoutMs,
			$this->config->cacheSearchLockRetryDelayMs
		);

		$tokenProvider = new AuthTokenProvider($this->config, $httpClient, $tokenCache, $lock);
		$merlinxClient = new MerlinxHttpClient($this->config, $tokenProvider, $httpClient);
		$loopbackClient = new LoopbackHttpClient($httpClient, $this->config->timeout);
		$searchOperation = new SearchOperation($this->config, $merlinxClient, $searchCache, $lock, logger: $logger);
		$this->runtimeResetters = [
			static function () use ($tokenProvider): void {
				$tokenProvider->clearRuntimeState();
			},
		];

		$this->registerOperation($searchOperation);
		$this->registerOperation(new GetDetailsOperation($this->config, $merlinxClient, $detailsCache, $lock));
		$this->registerOperation(new SearchBaseOperation($this->config, $merlinxClient, $searchBaseCache, $lock));
		$this->registerOperation(new RawTravelSearchOperation($merlinxClient));
		$this->registerOperation(new GetLiveAvailabilityOperation(
			$merlinxClient,
			$this->config,
			$liveAvailabilityCache,
			$this->config->cacheLiveAvailabilityTtlSeconds
		));
		$this->registerOperation(new PortalSearchOperation($loopbackClient));
	}

	public function clearCache(): bool
	{
		$ok = true;
		foreach ($this->runtimeResetters as $resetter) {
			try {
				$resetter();
			} catch (\Throwable) {
				$ok = false;
			}
		}

		foreach ($this->managedCaches as $cache) {
			try {
				$ok = $cache->clear() && $ok;
			} catch (\Throwable) {
				$ok = false;
			}
		}

		return $ok;
	}

	public function registerOperation(OperationInterface $operation): void
	{
		$this->operations[$operation->key()] = $operation;
	}

	public function executeSearch(SearchExecutionRequest $request): SearchExecutionResult
	{
		/** @var SearchOperation $operation */
		$operation = $this->operation('search', SearchOperation::class);
		return $operation->execute($request);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDetails(string $offerId): array
	{
		/** @var GetDetailsOperation $operation */
		$operation = $this->operation('getDetails', GetDetailsOperation::class);
		return $operation->execute($offerId);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDetailsFresh(string $offerId): array
	{
		/** @var GetDetailsOperation $operation */
		$operation = $this->operation('getDetails', GetDetailsOperation::class);
		return $operation->executeFresh($offerId);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function putDetails(string $offerId, array $payload): bool
	{
		/** @var GetDetailsOperation $operation */
		$operation = $this->operation('getDetails', GetDetailsOperation::class);
		return $operation->put($offerId, $payload);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getSearchBase(bool $force = false): array
	{
		/** @var SearchBaseOperation $operation */
		$operation = $this->operation('searchBase', SearchBaseOperation::class);
		return $operation->execute($force);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function executeRawSearch(array $body): array
	{
		/** @var RawTravelSearchOperation $operation */
		$operation = $this->operation('rawTravelSearch', RawTravelSearchOperation::class);
		return $operation->execute($body);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getLiveAvailability(string $offerId, ?string $action = 'checkstatus', bool $includeTfg = true, bool $force = false): array
	{
		/** @var GetLiveAvailabilityOperation $operation */
		$operation = $this->operation('getLiveAvailability', GetLiveAvailabilityOperation::class);
		return $operation->execute($offerId, $action, $includeTfg, $force);
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array{offers: array<int, array<string, mixed>>, query: array<string, mixed>, error: ?string, limitHit: bool}
	 */
	public function portalSearch(array $params = []): array
	{
		/** @var PortalSearchOperation $operation */
		$operation = $this->operation('portalSearch', PortalSearchOperation::class);
		return $operation->execute($params);
	}

	public function searchProfile(): SearchEngineProfile
	{
		return $this->searchProfile;
	}

	/**
	 * @template T of OperationInterface
	 * @param class-string<T> $class
	 * @return T
	 */
	private function operation(string $key, string $class): OperationInterface
	{
		$operation = $this->operations[$key] ?? null;
		if (!$operation instanceof $class) {
			throw new MerlinxGetterException('Operation is not registered: ' . $key);
		}

		return $operation;
	}

	/**
	 * @return array{0:CacheInterface,1:CacheInterface,2:CacheInterface,3:CacheInterface,4:CacheInterface,5:string}
	 */
	private function buildCacheStores(?CacheInterface $cache): array
	{
		if ($cache !== null) {
			$tokenCache = new NamespacedCache($cache, 'merlinx_getter.token.v2');
			$searchCache = new NamespacedCache($cache, 'merlinx_getter.search.v2');
			$detailsCache = new NamespacedCache($cache, 'merlinx_getter.details.v1');
			$liveAvailabilityCache = new NamespacedCache($cache, 'merlinx_getter.live_availability.v1');
			$searchBaseCache = new NamespacedCache($cache, 'merlinx_getter.search_base.v1');
			$this->managedCaches = [$tokenCache, $searchCache, $detailsCache, $liveAvailabilityCache, $searchBaseCache];

			$lockDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'merlinx-getter-locks';
			return [$tokenCache, $searchCache, $detailsCache, $liveAvailabilityCache, $searchBaseCache, $lockDir];
		}

		$factory = new FilesystemCacheFactory($this->config->cacheDir);
		$tokenCache = $factory->create('merlinx_getter.token.v2');
		$searchCache = $factory->create('merlinx_getter.search.v2');
		$detailsCache = $factory->create('merlinx_getter.details.v1');
		$liveAvailabilityCache = $factory->create('merlinx_getter.live_availability.v1');
		$searchBaseCache = $factory->create('merlinx_getter.search_base.v1');
		$this->managedCaches = [$tokenCache, $searchCache, $detailsCache, $liveAvailabilityCache, $searchBaseCache];

		$lockDir = rtrim($this->config->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'locks';
		return [$tokenCache, $searchCache, $detailsCache, $liveAvailabilityCache, $searchBaseCache, $lockDir];
	}
}

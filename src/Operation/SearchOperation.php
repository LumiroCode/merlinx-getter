<?php

declare(strict_types=1);

namespace Skionline\MerlinxGetter\Operation;

use JsonException;
use Psr\SimpleCache\CacheInterface;
use Skionline\MerlinxGetter\Cache\FileKeyLock;
use Skionline\MerlinxGetter\Cache\FilesystemCacheFactory;
use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\Contract\OperationInterface;
use Skionline\MerlinxGetter\Exception\ResponseFormatException;
use Skionline\MerlinxGetter\Http\MerlinxHttpClient;
use Skionline\MerlinxGetter\Log\LoggerInterface;
use Skionline\MerlinxGetter\Log\NullLogger;
use Skionline\MerlinxGetter\Search\Execution\SearchExecutionRequest;
use Skionline\MerlinxGetter\Search\Execution\SearchExecutionRequestBuilder;
use Skionline\MerlinxGetter\Search\Execution\SearchExecutionResult;
use Skionline\MerlinxGetter\Search\Util\ConfiguredResponseValueExcluder;
use Skionline\MerlinxGetter\Search\Util\ConfiguredFieldValuesPruner;
use Skionline\MerlinxGetter\Search\Util\SearchRequestFingerprint;
use Skionline\MerlinxGetter\Search\Util\TravelSearchResponseMerger;

final class SearchOperation implements OperationInterface
{
	private const SEARCH_CACHE_VERSION = 'search_engine_cache_v3';
	private const QUERY_DEDUPE_VERSION = 'search_engine_query_dedupe_v1';
	private const ERROR_CONTEXT_VERSION = 'search_engine_error_context_v1';
	private const SEARCH_CACHE_KEY_PREFIX = 'search.';
	private const SEARCH_REFRESH_LOCK_PREFIX = 'search_refresh.';
	private const SEARCH_ENDPOINT = '/v5/data/travel/search';
	private const MERLINX_MAX_PAGE_LIMIT = 499;
	private const MERLINX_MAX_CONCATENATED_LIMIT = 3500;

	private readonly TravelSearchResponseMerger $responseMerger;
	private readonly ConfiguredFieldValuesPruner $fieldValuesPruner;
	private readonly ConfiguredResponseValueExcluder $responseValueExcluder;
	private readonly CacheInterface $cache;
	private readonly FileKeyLock $lock;
	private readonly LoggerInterface $logger;
	private readonly string $configFingerprint;

	public function __construct(
		private readonly MerlinxGetterConfig $config,
		private readonly MerlinxHttpClient $client,
		?CacheInterface $cache = null,
		?FileKeyLock $lock = null,
		?TravelSearchResponseMerger $responseMerger = null,
		?ConfiguredFieldValuesPruner $fieldValuesPruner = null,
		?LoggerInterface $logger = null
	) {
		$this->responseMerger = $responseMerger ?? new TravelSearchResponseMerger();
		$this->fieldValuesPruner = $fieldValuesPruner ?? new ConfiguredFieldValuesPruner($config->enforcedAccommodationAttributes());
		$this->responseValueExcluder = new ConfiguredResponseValueExcluder($config->excludedValuesByPath());
		$this->cache = $cache ?? (new FilesystemCacheFactory($config->cacheDir))->create('merlinx_getter.search.v2');
		$lockDir = rtrim($config->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'locks';
		$this->lock = $lock ?? new FileKeyLock($lockDir, $config->cacheSearchLockTimeoutMs, $config->cacheSearchLockRetryDelayMs);
		$this->configFingerprint = $this->buildConfigFingerprint();
		$this->logger = $logger ?? new NullLogger();
	}

	public function key(): string
	{
		return 'search';
	}

	public function execute(SearchExecutionRequest $request): SearchExecutionResult
	{
		$request = $this->normalizeRequest($request);
		$cacheKey = $this->buildSearchCacheKey($request);
		$existing = $this->readCacheEnvelope($cacheKey);
		$now = time();

		if ($this->isFresh($existing, $now)) {
			return new SearchExecutionResult($existing['data'], $existing['meta']);
		}

		$staleCandidate = $this->resolveStaleEnvelope($existing, $now);
		$refreshLockKey = self::SEARCH_REFRESH_LOCK_PREFIX . $cacheKey;

		$freshResult = $this->lock->withLock($refreshLockKey, function () use ($cacheKey, $request, $staleCandidate): array {
			$lockedNow = time();
			$latest = $this->readCacheEnvelope($cacheKey);
			if ($this->isFresh($latest, $lockedNow)) {
				return [
					'data' => $latest['data'],
					'meta' => $latest['meta'],
				];
			}

			$stale = $this->resolveStaleEnvelope($latest, $lockedNow)
				?? $this->resolveStaleEnvelope($staleCandidate, $lockedNow);

			try {
				$fresh = $this->fetchFreshSearchData($request);
				$this->writeCacheEnvelope($cacheKey, $fresh['data'], $fresh['meta']);
				return $fresh;
			} catch (\Throwable $exception) {
				if ($stale !== null) {
					return [
						'data' => $stale['data'],
						'meta' => $stale['meta'],
					];
				}

				throw $exception;
			}
		});

		return new SearchExecutionResult($freshResult['data'], $freshResult['meta']);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function normalizeOptions(array $options): array
	{
		$normalized = [];
		foreach (array_keys($this->config->defaultSearchOptions) as $key) {
			if (array_key_exists($key, $options)) {
				$normalized[$key] = $options[$key];
			}
		}

		return array_merge($this->config->defaultSearchOptions, $normalized);
	}

	private function normalizeRequest(SearchExecutionRequest $request): SearchExecutionRequest
	{
		return $request->withOptions($this->normalizeOptions($request->options()));
	}

	private function buildSearchCacheKey(SearchExecutionRequest $request): string
	{
		$fingerprint = SearchRequestFingerprint::hash([
			'schema' => self::SEARCH_CACHE_VERSION,
			'config' => $this->configFingerprint,
			'body' => $request->toBody($this->config->defaultViewLimit),
			'options' => $request->options(),
		]);

		return self::SEARCH_CACHE_KEY_PREFIX . $fingerprint;
	}

	private function buildConfigFingerprint(): string
	{
		return SearchRequestFingerprint::hash([
			'schema' => self::SEARCH_CACHE_VERSION,
			'name' => $this->config->searchEngineName,
			'operators' => $this->config->searchEngineOperators,
			'conditions' => $this->config->searchEngineConditions,
			'availability_policy' => $this->config->searchEngineAvailabilityPolicy,
			'operator_policies' => $this->config->searchEngineOperatorPolicies,
			'response_filters' => $this->config->searchEngineResponseFilters,
			'runtime' => [
				'defaultViewLimit' => $this->config->defaultViewLimit,
				'options' => $this->config->defaultSearchOptions,
			],
		]);
	}

	/**
	 * @return array{data: array<string, mixed>, meta: array{limitHits: array<string, bool>}}
	 */
	private function fetchFreshSearchData(SearchExecutionRequest $request): array
	{
		$queries = SearchExecutionRequestBuilder::build($this->config, $request);
		$this->logger->debug('Built ' . count($queries) . ' search execution queries for request with fingerprint: ' . $this->buildErrorContextFingerprint($request));
		$this->logger->debug('Queries:', $queries);
		$queries = $this->dedupeQueries($queries);
		$this->logger->debug('After deduplication, ' . count($queries) . ' unique search execution queries remain for request with fingerprint: ' . $this->buildErrorContextFingerprint($request));
		$this->logger->debug('Queries:', $queries);
		$executed = $this->executeQueries($queries);
		$this->logger->debug('Executed search queries for request with fingerprint: ' . $this->buildErrorContextFingerprint($request) . '. Total views fetched: ' . count($executed['response']));
		$this->logger->debug('executed:', $executed);
		$data = $executed['response'];

		if (!is_array($data)) {
			throw new ResponseFormatException('MerlinX search response has unexpected format.');
		}

		return [
			'data' => $this->fieldValuesPruner->apply($data),
			'meta' => $executed['meta'],
		];
	}

	/**
	 * @param array<int, SearchExecutionRequest> $queries
	 * @return array<int, SearchExecutionRequest>
	 */
	private function dedupeQueries(array $queries): array
	{
		$seen = [];
		$unique = [];
		foreach ($queries as $query) {
			$fingerprint = SearchRequestFingerprint::hash([
				'schema' => self::QUERY_DEDUPE_VERSION,
				'body' => $query->toBody($this->config->defaultViewLimit),
			]);
			if (isset($seen[$fingerprint])) {
				continue;
			}

			$seen[$fingerprint] = true;
			$unique[] = $query;
		}

		return $unique;
	}

	/**
	 * @param array<int, SearchExecutionRequest> $queries
	 * @return array{response: array<string, mixed>, meta: array{limitHits: array<string, bool>}}
	 */
	private function executeQueries(array $queries): array
	{
		$carry = [];
		$latestExplicitViewLimits = [];
		$limitHitsByView = [];

		foreach ($queries as $query) {
			$this->applyLatestExplicitViewLimits($latestExplicitViewLimits, $query->views());
			$carry = $this->executePagedQuery($query, $carry, $latestExplicitViewLimits, $limitHitsByView);
			$this->synchronizeLimitHitsByView($carry, $latestExplicitViewLimits, $limitHitsByView);
		}

		return [
			'response' => $carry,
			'meta' => $this->buildResultMeta($limitHitsByView),
		];
	}

	/**
	 * @param array<string, mixed> $carry
	 * @param array<string, int> $explicitViewLimits
	 * @param array<string, bool> $limitHitsByView
	 * @return array<string, mixed>
	 */
	private function executePagedQuery(
		SearchExecutionRequest $request,
		array $carry,
		array $explicitViewLimits,
		array &$limitHitsByView
	): array
	{
		$options = $request->options();
		$views = $request->views();
		$viewsForRequest = $this->filterViewsByReachedViewLimit($carry === [] ? null : $carry, $views, $explicitViewLimits);
		if ($viewsForRequest === []) {
			return $carry;
		}

		$seenBookmarksByView = [];
		$pagesFetched = 0;

		$maxPages = ceil(self::MERLINX_MAX_CONCATENATED_LIMIT / self::MERLINX_MAX_PAGE_LIMIT);

		do {
			$pageRequest = $request->withViews($viewsForRequest);
			$this->logger->debug('Executing paginated search query with fingerprint: ' . $this->buildErrorContextFingerprint($pageRequest));
			$pageData = $this->fetchSearchPage($pageRequest, $options);
			$pagesFetched++;

			$pageData = $this->responseValueExcluder->apply($pageData);
			$carry = $this->responseMerger->merge($carry === [] ? null : $carry, $pageData);
			$this->synchronizeLimitHitsByView($carry, $explicitViewLimits, $limitHitsByView);

			$bookmarks = $this->extractViewBookmarks($pageData);
			$newBookmarks = $this->responseMerger->filterUnseenBookmarks($bookmarks, $seenBookmarksByView);
			$viewsForRequest = $this->filterViewsByBookmarks($viewsForRequest, $newBookmarks);
			$viewsForRequest = $this->filterViewsByNonEmptyPageItems($viewsForRequest, $pageData);
			$viewsForRequest = $this->filterViewsByReachedViewLimit($carry, $viewsForRequest, $explicitViewLimits);
			$viewsForRequest = $this->buildViewsWithBookmarks($viewsForRequest, $newBookmarks);

			$this->logger->debug('Will fetch next page for views: ' . implode(', ', array_keys($viewsForRequest)) . '. Pages fetched so far: ' . $pagesFetched);
		} while ($viewsForRequest !== [] && $pagesFetched < $maxPages);

		if ($viewsForRequest !== [] && $pagesFetched >= $maxPages) {
			$this->logger->warning('Search pagination stopped after reaching max pages cap.', [
				'maxPages' => $maxPages,
				'pagesFetched' => $pagesFetched,
				'remainingViews' => array_keys($viewsForRequest),
			]);
		}

		return $carry;
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function fetchSearchPage(SearchExecutionRequest $request, array $options): array
	{
		$queryFingerprint = $this->buildErrorContextFingerprint($request);
		$context = $options;
		$context['queryFingerprint'] = $queryFingerprint;

		$response = $this->client->request(
			'POST',
			self::SEARCH_ENDPOINT,
			[
				'json' => $request->toObject($this->config->defaultViewLimit),
				'headers' => ['Content-Type' => 'application/json'],
			],
			$context
		);

		$content = $response->body();

		try {
			$data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new ResponseFormatException('MerlinX search endpoint returned invalid JSON.', 0, $exception);
		}

		if (!is_array($data)) {
			throw new ResponseFormatException('MerlinX search response has unexpected format.');
		}

		return $data;
	}

	private function buildErrorContextFingerprint(SearchExecutionRequest $request): string
	{
		return SearchRequestFingerprint::hash([
			'schema' => self::ERROR_CONTEXT_VERSION,
			'body' => $request->toBody($this->config->defaultViewLimit),
		]);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, string>
	 */
	private function extractViewBookmarks(array $data): array
	{
		$bookmarks = [];
		foreach ($data as $viewName => $viewPayload) {
			if (!is_array($viewPayload)) {
				continue;
			}
			if (($viewPayload['more'] ?? null) !== true) {
				continue;
			}

			$bookmark = $viewPayload['pageBookmark'] ?? null;
			if (!is_string($bookmark) || $bookmark === '') {
				continue;
			}

			$bookmarks[$viewName] = $bookmark;
		}

		return $bookmarks;
	}

	/**
	 * @param array<string, mixed> $viewsForRequest
	 * @param array<string, string> $bookmarks
	 * @return array<string, mixed>
	 */
	private function buildViewsWithBookmarks(array $viewsForRequest, array $bookmarks): array
	{
		if ($bookmarks === []) {
			return [];
		}

		$views = [];
		foreach ($bookmarks as $viewName => $bookmark) {
			if (!array_key_exists($viewName, $viewsForRequest)) {
				continue;
			}

			$view = $viewsForRequest[$viewName];
			if (is_object($view)) {
				$view = (array) $view;
			}
			if (!is_array($view)) {
				continue;
			}

			if ($bookmark !== '') {
				$view['previousPageBookmark'] = $bookmark;
			}
			$views[$viewName] = $view;
		}

		return $views;
	}

	/**
	 * @param array<string, mixed> $viewsForRequest
	 * @return array<string, mixed>
	 */
	private function filterViewsByBookmarks(array $viewsForRequest, array $newBookmarks): array
	{
		$filtered = [];
		foreach ($newBookmarks as $viewName => $bookmark) {
			if ($bookmark === '') {
				continue;
			}

			$view = $viewsForRequest[$viewName] ?? null;
			if (is_object($view)) {
				$view = (array) $view;
			}
			if (!is_array($view)) {
				continue;
			}

			$filtered[$viewName] = $view;
		}

		return $filtered;
	}

	/**
	 * @param array<string, mixed> $viewsForRequest
	 * @param array<string, mixed> $pageData
	 * @return array<string, mixed>
	 */
	private function filterViewsByNonEmptyPageItems(array $viewsForRequest, array $pageData): array
	{
		$filtered = [];
		foreach ($viewsForRequest as $viewName => $viewPayload) {
			$pageView = $pageData[$viewName] ?? null;
			if (!is_array($pageView)) {
				continue;
			}

			if (!array_key_exists('items', $pageView)) {
				$filtered[$viewName] = $viewPayload;
				continue;
			}

			$items = $pageView['items'] ?? null;
			if (empty($items)) {
				continue;
			}

			$filtered[$viewName] = $viewPayload;
		}

		return $filtered;
	}

	/**
	 * @param array<string, mixed>|null $merged
	 * @param array<string, mixed> $viewsForRequest
	 * @return array<string, mixed>
	 */
	private function filterViewsByReachedViewLimit(?array $merged, array $viewsForRequest, array $explicitViewLimits): array
	{
		$filtered = [];
		foreach ($viewsForRequest as $viewName => $viewPayload) {
			$limit = $explicitViewLimits[$viewName] ?? 0;
			if ($limit <= 0) {
				$filtered[$viewName] = $viewPayload;
				continue;
			}

			$itemCount = $this->countMergedItemsForView($merged, $viewName);
			if (is_int($itemCount) && $itemCount >= $limit) {
				continue;
			}

			$filtered[$viewName] = $viewPayload;
		}

		return $filtered;
	}

	/**
	 * @param array<string, mixed>|null $merged
	 */
	private function countMergedItemsForView(?array $merged, string $viewName): ?int
	{
		if (!is_array($merged)) {
			return null;
		}

		$items = $merged[$viewName]['items'] ?? null;
		if (!is_array($items)) {
			return null;
		}

		return count($items);
	}

	/**
	 * @param array<string, mixed> $view
	 */
	private function resolveViewLimit(array $view): int
	{
		$limit = $view['limit'] ?? null;
		if (is_int($limit) && $limit > 0) {
			return $limit;
		}
		$limit = is_string($limit) && ctype_digit(trim($limit)) ? (int) trim($limit) : null;
		if ($limit > 0) {
			return $limit;
		}

		return 0;
	}

	/**
	 * @param array<string, mixed> $views
	 * @return array<string, int>
	 */
	private function extractExplicitViewLimits(array $views): array
	{
		$limits = [];
		foreach ($views as $viewName => $viewPayload) {
			if (!is_string($viewName) || $viewName === '') {
				continue;
			}

			$view = $viewPayload;
			if (is_object($view)) {
				$view = (array) $view;
			}
			if (!is_array($view)) {
				continue;
			}

			$limit = $this->resolveViewLimit($view);
			if ($limit <= 0) {
				continue;
			}

			$limits[$viewName] = $limit;
		}

		return $limits;
	}

	/**
	 * @param array<string, int> $latestExplicitViewLimits
	 * @param array<string, mixed> $views
	 */
	private function applyLatestExplicitViewLimits(array &$latestExplicitViewLimits, array $views): void
	{
		$explicitForQuery = $this->extractExplicitViewLimits($views);

		foreach ($views as $viewName => $viewPayload) {
			if (!is_string($viewName) || $viewName === '') {
				continue;
			}

			if (array_key_exists($viewName, $explicitForQuery)) {
				$latestExplicitViewLimits[$viewName] = $explicitForQuery[$viewName];
				continue;
			}

			unset($latestExplicitViewLimits[$viewName]);
		}
	}

	/**
	 * @param array<string, mixed> $merged
	 * @param array<string, int> $explicitViewLimits
	 * @param array<string, bool> $limitHitsByView
	 */
	private function synchronizeLimitHitsByView(array $merged, array $explicitViewLimits, array &$limitHitsByView): void
	{
		foreach (array_keys($limitHitsByView) as $viewName) {
			$limit = $explicitViewLimits[$viewName] ?? 0;
			$itemCount = $this->countMergedItemsForView($merged, $viewName);
			if ($limit <= 0 || !is_int($itemCount) || $itemCount < $limit) {
				unset($limitHitsByView[$viewName]);
			}
		}

		foreach ($explicitViewLimits as $viewName => $limit) {
			if ($limit <= 0) {
				continue;
			}

			$itemCount = $this->countMergedItemsForView($merged, $viewName);
			if (is_int($itemCount) && $itemCount >= $limit) {
				$limitHitsByView[$viewName] = true;
			}
		}
	}

	/**
	 * @param array<string, bool> $limitHitsByView
	 * @return array{limitHits: array<string, bool>}
	 */
	private function buildResultMeta(array $limitHitsByView): array
	{
		$limitHits = [];
		foreach ($limitHitsByView as $viewName => $isHit) {
			if (!is_string($viewName) || $viewName === '' || $isHit !== true) {
				continue;
			}

			$limitHits[$viewName] = true;
		}

		return ['limitHits' => $limitHits];
	}

	/**
	 * @return array{
	 *   createdAt:int,
	 *   freshUntil:int,
	 *   staleUntil:int,
	 *   data:array<string,mixed>,
	 *   meta:array{limitHits: array<string, bool>}
	 * }|null
	 */
	private function readCacheEnvelope(string $cacheKey): ?array
	{
		try {
			$payload = $this->cache->get($cacheKey);
		} catch (\Throwable) {
			return null;
		}

		if (!is_array($payload)) {
			return null;
		}

		$createdAt = $payload['createdAt'] ?? null;
		$freshUntil = $payload['freshUntil'] ?? null;
		$staleUntil = $payload['staleUntil'] ?? null;
		$data = $payload['data'] ?? null;
		$meta = $payload['meta'] ?? null;

		if (!$this->isIntegerLike($createdAt) || !$this->isIntegerLike($freshUntil) || !$this->isIntegerLike($staleUntil) || !is_array($data)) {
			return null;
		}

		return [
			'createdAt' => (int) $createdAt,
			'freshUntil' => (int) $freshUntil,
			'staleUntil' => (int) $staleUntil,
			'data' => $data,
			'meta' => $this->normalizeResultMeta($meta),
		];
	}

	/**
	 * @param array{
	 *   createdAt:int,
	 *   freshUntil:int,
	 *   staleUntil:int,
	 *   data:array<string,mixed>,
	 *   meta:array{limitHits: array<string, bool>}
	 * }|null $envelope
	 */
	private function isFresh(?array $envelope, int $now): bool
	{
		return $envelope !== null && $envelope['freshUntil'] >= $now;
	}

	/**
	 * @param array{
	 *   createdAt:int,
	 *   freshUntil:int,
	 *   staleUntil:int,
	 *   data:array<string,mixed>,
	 *   meta:array{limitHits: array<string, bool>}
	 * }|null $envelope
	 * @return array{
	 *   createdAt:int,
	 *   freshUntil:int,
	 *   staleUntil:int,
	 *   data:array<string,mixed>,
	 *   meta:array{limitHits: array<string, bool>}
	 * }|null
	 */
	private function resolveStaleEnvelope(?array $envelope, int $now): ?array
	{
		if ($envelope === null || $envelope['staleUntil'] < $now) {
			return null;
		}

		return $envelope;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array{limitHits: array<string, bool>} $meta
	 */
	private function writeCacheEnvelope(string $cacheKey, array $data, array $meta): void
	{
		$now = time();
		$freshUntil = $now + $this->config->cacheSearchTtlSeconds;
		$staleUntil = $freshUntil + $this->config->cacheSearchStaleSeconds;
		$ttl = max(1, $this->config->cacheSearchTtlSeconds + $this->config->cacheSearchStaleSeconds);

		try {
			$this->cache->set($cacheKey, [
				'createdAt' => $now,
				'freshUntil' => $freshUntil,
				'staleUntil' => $staleUntil,
				'data' => $data,
				'meta' => $this->normalizeResultMeta($meta),
			], $ttl);
		} catch (\Throwable) {
		}
	}

	/**
	 * @return array{limitHits: array<string, bool>}
	 */
	private function normalizeResultMeta(mixed $rawMeta): array
	{
		if (!is_array($rawMeta)) {
			return ['limitHits' => []];
		}

		$rawLimitHits = $rawMeta['limitHits'] ?? null;
		if (!is_array($rawLimitHits)) {
			return ['limitHits' => []];
		}

		$limitHits = [];
		foreach ($rawLimitHits as $viewName => $isHit) {
			if (!is_string($viewName) || $viewName === '' || $isHit !== true) {
				continue;
			}

			$limitHits[$viewName] = true;
		}

		return ['limitHits' => $limitHits];
	}

	private function isIntegerLike(mixed $value): bool
	{
		return is_int($value) || (is_string($value) && ctype_digit($value));
	}
}

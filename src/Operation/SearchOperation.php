<?php

declare(strict_types=1);

namespace Skionline\MerlinxGetter\Operation;

use JsonException;
use Psr\SimpleCache\CacheInterface;
use Skionline\MerlinxGetter\Cache\FileKeyLock;
use Skionline\MerlinxGetter\Cache\FilesystemCacheFactory;
use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\Contract\OperationInterface;
use Skionline\MerlinxGetter\Exception\HttpRequestException;
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
	private const SEARCH_CACHE_VERSION = 'search_engine_cache_v2';
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
			return new SearchExecutionResult($existing['data']);
		}

		$staleCandidate = $this->resolveStaleEnvelope($existing, $now);
		$refreshLockKey = self::SEARCH_REFRESH_LOCK_PREFIX . $cacheKey;

		$data = $this->lock->withLock($refreshLockKey, function () use ($cacheKey, $request, $staleCandidate): array {
			$lockedNow = time();
			$latest = $this->readCacheEnvelope($cacheKey);
			if ($this->isFresh($latest, $lockedNow)) {
				return $latest['data'];
			}

			$stale = $this->resolveStaleEnvelope($latest, $lockedNow)
				?? $this->resolveStaleEnvelope($staleCandidate, $lockedNow);

			try {
				$data = $this->fetchFreshSearchData($request);
				$this->writeCacheEnvelope($cacheKey, $data);
				return $data;
			} catch (\Throwable $exception) {
				if ($stale !== null) {
					return $stale['data'];
				}

				throw $exception;
			}
		});

		return new SearchExecutionResult($data);
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
	 * @return array<string, mixed>
	 */
	private function fetchFreshSearchData(SearchExecutionRequest $request): array
	{
		$queries = SearchExecutionRequestBuilder::build($this->config, $request);
		$queries = $this->dedupeQueries($queries);
		$data = $this->executeQueries($queries);

		if (!is_array($data)) {
			throw new ResponseFormatException('MerlinX search response has unexpected format.');
		}

		return $this->fieldValuesPruner->apply($data);
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
	 * @return array<string, mixed>
	 */
	private function executeQueries(array $queries): array
	{
		$carry = [];
		foreach ($queries as $query) {
			$payload = $this->executePagedQuery($query);
			$carry = $this->responseMerger->merge($carry === [] ? null : $carry, $payload);
		}

		return $carry;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function executePagedQuery(SearchExecutionRequest $request): array
	{
		$options = $request->options();
		$views = $request->views();
		$explicitViewLimits = $this->extractExplicitViewLimits($views);

		$merged = null;
		$viewsForRequest = $views;
		$seenBookmarksByView = [];
		$pagesFetched = 0;

		$maxPages = ceil(self::MERLINX_MAX_CONCATENATED_LIMIT / self::MERLINX_MAX_PAGE_LIMIT);

		do {
			$pageRequest = $request->withViews($viewsForRequest);
			$this->logger->debug('Executing paginated search query with fingerprint: ' . $this->buildErrorContextFingerprint($pageRequest));
			$pageData = $this->fetchSearchPage($pageRequest, $options);
			$pagesFetched++;

			$pageData = $this->responseValueExcluder->apply($pageData);
			$merged = $this->responseMerger->merge($merged, $pageData);

			$bookmarks = $this->extractViewBookmarks($pageData);
			$newBookmarks = $this->responseMerger->filterUnseenBookmarks($bookmarks, $seenBookmarksByView);
			$viewsForRequest = $this->filterViewsByBookmarks($viewsForRequest, $newBookmarks);
			$viewsForRequest = $this->filterViewsByNonEmptyPageItems($viewsForRequest, $pageData);
			$viewsForRequest = $this->filterViewsByReachedViewLimit($merged, $viewsForRequest, $explicitViewLimits);
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

		return $merged ?? [];
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function fetchSearchPage(SearchExecutionRequest $request, array $options): array
	{
		$maxAttempts = max(0, (int) ($options['rateLimitRetryMaxAttempts'] ?? 0));
		$retryDelayMs = max(0, (int) ($options['rateLimitRetryDelayMs'] ?? 0));

		for ($attempt = 0;; $attempt++) {
			$content = '';
			$status = 0;
			$headers = [];

			try {
				$response = $this->client->request('POST', self::SEARCH_ENDPOINT, [
					'json' => $request->toObject($this->config->defaultViewLimit),
					'headers' => ['Content-Type' => 'application/json'],
				]);
				$status = $response->statusCode();
				$headers = $response->headers();
				$content = $response->body();
			} catch (\Throwable $exception) {
				if ($this->isRateLimitedThrowable($exception) && $attempt < $maxAttempts) {
					$this->waitBeforeRateLimitRetry($retryDelayMs, null);
					$retryDelayMs = $this->nextRetryDelayMs($retryDelayMs, $options);
					continue;
				}

				throw new HttpRequestException(
					$this->buildSearchHttpErrorMessage(
						'MerlinX search failed: ' . $exception->getMessage() . '.',
						$request,
						$attempt,
						$maxAttempts
					),
					null,
					null,
					$exception
				);
			}

			if ($this->isRateLimitedResponse($status, $content)) {
				if ($attempt < $maxAttempts) {
					$retryAfterMs = $this->extractRetryAfterDelayMs($headers);
					$this->waitBeforeRateLimitRetry($retryDelayMs, $retryAfterMs);
					$retryDelayMs = $this->nextRetryDelayMs($retryDelayMs, $options);
					continue;
				}

				throw new HttpRequestException(
					$this->buildSearchHttpErrorMessage(
						'MerlinX search rate limit persisted after retries.',
						$request,
						$attempt,
						$maxAttempts,
						$content
					),
					$status,
					$content
				);
			}

			if ($status >= 400) {
				$errorType = $status >= 500 ? 'server error' : 'client error';
				throw new HttpRequestException(
					$this->buildSearchHttpErrorMessage(
						'MerlinX search failed with status ' . $status . ' (' . $errorType . ').',
						$request,
						$attempt,
						$maxAttempts,
						$content
					),
					$status,
					$content
				);
			}

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
	}

	private function buildSearchHttpErrorMessage(
		string $summary,
		SearchExecutionRequest $request,
		int $attempt,
		int $maxAttempts,
		?string $responseBody = null
	): string {
		$parts = [
			$summary,
			'Endpoint: POST ' . self::SEARCH_ENDPOINT . '.',
			'Attempt: ' . ($attempt + 1) . '/' . ($maxAttempts + 1) . '.',
			'Query fingerprint: ' . $this->buildErrorContextFingerprint($request) . '.',
		];

		if ($responseBody !== null) {
			$parts[] = 'Response snippet: ' . $this->buildDebugSnippet($responseBody) . '.';
		}

		$parts[] = 'Request payload snippet: ' . $this->buildDebugSnippet($request->toBody($this->config->defaultViewLimit)) . '.';

		return implode(' ', $parts);
	}

	private function buildErrorContextFingerprint(SearchExecutionRequest $request): string
	{
		return SearchRequestFingerprint::hash([
			'schema' => self::ERROR_CONTEXT_VERSION,
			'body' => $request->toBody($this->config->defaultViewLimit),
		]);
	}

	private function buildDebugSnippet(mixed $value, int $maxLength = 5000): string
	{
		if (is_string($value)) {
			$serialized = trim($value);
		} else {
			$encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
			$serialized = is_string($encoded) ? $encoded : '[unserializable payload]';
		}

		$normalized = preg_replace('/\s+/', ' ', trim($serialized));
		if (!is_string($normalized) || $normalized === '') {
			$normalized = '[empty]';
		}

		if (strlen($normalized) <= $maxLength) {
			return $normalized;
		}

		return substr($normalized, 0, $maxLength) . '...(truncated)';
	}

	private function isRateLimitedResponse(int $status, string $content): bool
	{
		return $status === 429 || $this->isRateLimitedPayload($content);
	}

	private function isRateLimitedPayload(string $content): bool
	{
		$payload = strtolower(trim($content));
		if ($payload === '') {
			return false;
		}

		return str_contains($payload, 'too many requests');
	}

	private function isRateLimitedThrowable(\Throwable $exception): bool
	{
		$message = strtolower($exception->getMessage());
		return str_contains($message, 'too many requests') || str_contains($message, 'status 429');
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function nextRetryDelayMs(int $currentDelayMs, array $options): int
	{
		if ($currentDelayMs <= 0) {
			return 0;
		}

		$multiplier = (float) ($options['rateLimitRetryBackoffMultiplier'] ?? 2.0);
		if ($multiplier < 1.0) {
			$multiplier = 1.0;
		}

		$maxDelayMs = max(0, (int) ($options['rateLimitRetryMaxDelayMs'] ?? $currentDelayMs));
		if ($maxDelayMs === 0) {
			return 0;
		}

		$nextDelayMs = (int) round($currentDelayMs * $multiplier);
		$nextDelayMs = max($currentDelayMs, $nextDelayMs);

		return min($nextDelayMs, $maxDelayMs);
	}

	private function waitBeforeRateLimitRetry(int $retryDelayMs, ?int $retryAfterMs = null): void
	{
		$delayMs = max($retryDelayMs, $retryAfterMs ?? 0);
		if ($delayMs > 0) {
			usleep($delayMs * 1000);
		}
	}

	/**
	 * @param array<string, array<int, string>> $headers
	 */
	private function extractRetryAfterDelayMs(array $headers): ?int
	{
		$retryAfter = $headers['retry-after'] ?? $headers['Retry-After'] ?? null;
		if (!is_array($retryAfter) && !is_string($retryAfter) && !is_int($retryAfter) && !is_float($retryAfter)) {
			return null;
		}

		$value = is_array($retryAfter) ? ($retryAfter[0] ?? null) : $retryAfter;
		if (!is_string($value) && !is_int($value) && !is_float($value)) {
			return null;
		}

		$stringValue = trim((string) $value);
		if ($stringValue === '') {
			return null;
		}

		if (is_numeric($stringValue)) {
			$seconds = max(0.0, (float) $stringValue);
			return (int) round($seconds * 1000);
		}

		$timestamp = strtotime($stringValue);
		if ($timestamp === false) {
			return null;
		}

		$seconds = max(0, $timestamp - time());
		return $seconds * 1000;
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
	 * @return array{createdAt:int,freshUntil:int,staleUntil:int,data:array<string,mixed>}|null
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

		if (!$this->isIntegerLike($createdAt) || !$this->isIntegerLike($freshUntil) || !$this->isIntegerLike($staleUntil) || !is_array($data)) {
			return null;
		}

		return [
			'createdAt' => (int) $createdAt,
			'freshUntil' => (int) $freshUntil,
			'staleUntil' => (int) $staleUntil,
			'data' => $data,
		];
	}

	/**
	 * @param array{createdAt:int,freshUntil:int,staleUntil:int,data:array<string,mixed>}|null $envelope
	 */
	private function isFresh(?array $envelope, int $now): bool
	{
		return $envelope !== null && $envelope['freshUntil'] >= $now;
	}

	/**
	 * @param array{createdAt:int,freshUntil:int,staleUntil:int,data:array<string,mixed>}|null $envelope
	 * @return array{createdAt:int,freshUntil:int,staleUntil:int,data:array<string,mixed>}|null
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
	 */
	private function writeCacheEnvelope(string $cacheKey, array $data): void
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
			], $ttl);
		} catch (\Throwable) {
		}
	}

	private function isIntegerLike(mixed $value): bool
	{
		return is_int($value) || (is_string($value) && ctype_digit($value));
	}
}

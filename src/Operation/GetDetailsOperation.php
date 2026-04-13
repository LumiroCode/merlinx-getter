<?php

declare(strict_types=1);

namespace Skionline\MerlinxGetter\Operation;

use JsonException;
use Psr\SimpleCache\CacheInterface;
use Skionline\MerlinxGetter\Cache\FileKeyLock;
use Skionline\MerlinxGetter\Cache\FilesystemCacheFactory;
use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\Contract\OperationInterface;
use Skionline\MerlinxGetter\Details\OfferDetailsCacheKeyResolver;
use Skionline\MerlinxGetter\Exception\InvalidInputException;
use Skionline\MerlinxGetter\Exception\ResponseFormatException;
use Skionline\MerlinxGetter\Http\MerlinxHttpClient;
use Skionline\MerlinxGetter\Search\Util\SearchRequestFingerprint;

final class GetDetailsOperation implements OperationInterface
{
	private const DETAILS_CACHE_VERSION = 'travel_details_cache_v1';
	private const DETAILS_CACHE_KEY_PREFIX = 'details.';
	private const DETAILS_REFRESH_LOCK_PREFIX = 'details_refresh.';
	private const DETAILS_ENDPOINT = '/v5/data/travel/details';

	private readonly CacheInterface $cache;
	private readonly FileKeyLock $lock;
	private readonly OfferDetailsCacheKeyResolver $cacheKeyResolver;
	private readonly string $configFingerprint;

	public function __construct(
		private readonly MerlinxGetterConfig $config,
		private readonly MerlinxHttpClient $client,
		?CacheInterface $cache = null,
		?FileKeyLock $lock = null,
		?OfferDetailsCacheKeyResolver $cacheKeyResolver = null,
	) {
		$this->cache = $cache ?? (new FilesystemCacheFactory($config->cacheDir))->create('merlinx_getter.details.v1');
		$lockDir = rtrim($config->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'locks';
		$this->lock = $lock ?? new FileKeyLock($lockDir, $config->cacheSearchLockTimeoutMs, $config->cacheSearchLockRetryDelayMs);
		$this->cacheKeyResolver = $cacheKeyResolver ?? new OfferDetailsCacheKeyResolver();
		$this->configFingerprint = SearchRequestFingerprint::hash([
			'schema' => self::DETAILS_CACHE_VERSION,
			'baseUrl' => $config->baseUrl,
			'domain' => $config->domain,
			'source' => $config->source,
			'type' => $config->type,
			'language' => $config->language,
		]);
	}

	public function key(): string
	{
		return 'getDetails';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function execute(string $offerId): array
	{
		$offerId = trim($offerId);
		if ($offerId === '') {
			throw new InvalidInputException('OfferId is required.');
		}

		$cacheKey = $this->buildCacheKey($offerId);
		if ($cacheKey === null) {
			return $this->fetchFreshDetails($offerId);
		}

		$existing = $this->readCacheEnvelope($cacheKey);
		$now = time();
		if ($this->isFresh($existing, $now)) {
			return $existing['data'];
		}

		$staleCandidate = $this->resolveStaleEnvelope($existing, $now);
		$refreshLockKey = self::DETAILS_REFRESH_LOCK_PREFIX . $cacheKey;

		return $this->lock->withLock($refreshLockKey, function () use ($cacheKey, $offerId, $staleCandidate): array {
			$lockedNow = time();
			$latest = $this->readCacheEnvelope($cacheKey);
			if ($this->isFresh($latest, $lockedNow)) {
				return $latest['data'];
			}

			$stale = $this->resolveStaleEnvelope($latest, $lockedNow)
				?? $this->resolveStaleEnvelope($staleCandidate, $lockedNow);

			try {
				$data = $this->fetchFreshDetails($offerId);
				$this->writeCacheEnvelope($cacheKey, $data);
				return $data;
			} catch (\Throwable $e) {
				if ($stale !== null) {
					return $stale['data'];
				}

				throw $e;
			}
		});
	}

	/**
	 * @return array<string, mixed>
	 */
	public function executeFresh(string $offerId): array
	{
		$offerId = trim($offerId);
		if ($offerId === '') {
			throw new InvalidInputException('OfferId is required.');
		}

		$data = $this->fetchFreshDetails($offerId);
		$cacheKey = $this->buildCacheKey($offerId);
		if ($cacheKey !== null) {
			$this->writeCacheEnvelope($cacheKey, $data);
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $detailsResponse
	 */
	public function put(string $offerId, array $detailsResponse): bool
	{
		$offerId = trim($offerId);
		if ($offerId === '') {
			throw new InvalidInputException('OfferId is required.');
		}
		if (!is_array($detailsResponse['result']['offer'] ?? null)) {
			throw new InvalidInputException('detailsResponse must contain result.offer array.');
		}

		$cacheKey = $this->buildCacheKey($offerId);
		if ($cacheKey === null) {
			return false;
		}

		$this->writeCacheEnvelope($cacheKey, $detailsResponse);
		return true;
	}

	private function buildCacheKey(string $offerId): ?string
	{
		$resolved = $this->cacheKeyResolver->resolve($offerId);
		if (($resolved['ok'] ?? false) !== true) {
			return null;
		}

		$cacheKeySource = trim((string) ($resolved['cacheKeySource'] ?? ''));
		if ($cacheKeySource === '') {
			return null;
		}

		return self::DETAILS_CACHE_KEY_PREFIX . SearchRequestFingerprint::hash([
			'schema' => self::DETAILS_CACHE_VERSION,
			'config' => $this->configFingerprint,
			'cacheKeySource' => $cacheKeySource,
		]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchFreshDetails(string $offerId): array
	{
		$response = $this->client->request('GET', self::DETAILS_ENDPOINT, [
			'query' => ['Base.OfferId' => $offerId],
			'headers' => ['Accept' => 'application/json'],
		]);

		$content = $response->body();
		try {
			$data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new ResponseFormatException('MerlinX details response is invalid JSON.', 0, $e);
		}

		if (!is_array($data)) {
			throw new ResponseFormatException('MerlinX details response has unexpected format.');
		}

		if (!is_array($data['result']['offer'] ?? null)) {
			throw new ResponseFormatException('MerlinX details response is missing result.offer.');
		}

		return $data;
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
		if (is_int($value)) {
			return true;
		}

		return is_string($value) && ctype_digit($value);
	}
}

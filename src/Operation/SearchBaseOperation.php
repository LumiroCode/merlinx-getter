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
use Skionline\MerlinxGetter\Search\Util\SearchRequestFingerprint;

final class SearchBaseOperation implements OperationInterface
{
	private const CACHE_SCHEMA = 'travel_search_base_cache_v1';
	private const CACHE_KEY = 'search_base';
	private const REFRESH_LOCK_KEY = 'search_base_refresh';
	private const ENDPOINT = '/v5/data/travel/searchbase';

	private readonly CacheInterface $cache;
	private readonly FileKeyLock $lock;
	private readonly string $cacheKey;

	public function __construct(
		private readonly MerlinxGetterConfig $config,
		private readonly MerlinxHttpClient $client,
		?CacheInterface $cache = null,
		?FileKeyLock $lock = null,
	) {
		$this->cache = $cache ?? (new FilesystemCacheFactory($config->cacheDir))->create('merlinx_getter.search_base.v1');
		$lockDir = rtrim($config->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'locks';
		$this->lock = $lock ?? new FileKeyLock($lockDir, $config->cacheSearchLockTimeoutMs, $config->cacheSearchLockRetryDelayMs);
		$this->cacheKey = self::CACHE_KEY . '.' . SearchRequestFingerprint::hash([
			'schema' => self::CACHE_SCHEMA,
			'baseUrl' => $config->baseUrl,
			'domain' => $config->domain,
			'source' => $config->source,
			'type' => $config->type,
			'language' => $config->language,
		]);
	}

	public function key(): string
	{
		return 'searchBase';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function execute(bool $force = false): array
	{
		$existing = $this->readCacheEnvelope($this->cacheKey);
		$now = time();
		if (!$force && $this->isFresh($existing, $now)) {
			return $existing['data'];
		}

		$staleCandidate = $this->resolveStaleEnvelope($existing, $now);

		return $this->lock->withLock(self::REFRESH_LOCK_KEY, function () use ($force, $staleCandidate): array {
			$lockedNow = time();
			$latest = $this->readCacheEnvelope($this->cacheKey);
			if (!$force && $this->isFresh($latest, $lockedNow)) {
				return $latest['data'];
			}

			$stale = $this->resolveStaleEnvelope($latest, $lockedNow)
				?? $this->resolveStaleEnvelope($staleCandidate, $lockedNow);

			try {
				$data = $this->fetchFreshSearchBase();
				$this->writeCacheEnvelope($this->cacheKey, $data);
				return $data;
			} catch (\Throwable $exception) {
				if ($stale !== null) {
					return $stale['data'];
				}

				throw $exception;
			}
		});
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchFreshSearchBase(): array
	{
		$response = $this->client->request('POST', self::ENDPOINT, [
			'json' => new \stdClass(),
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
		]);

		try {
			$data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new ResponseFormatException('MerlinX searchBase response is invalid JSON.', 0, $exception);
		}

		if (!is_array($data) || !$this->isValidSearchBasePayload($data)) {
			throw new ResponseFormatException('MerlinX searchBase response has unexpected format.');
		}

		return $data;
	}

	/**
	 * @param array<mixed> $data
	 */
	private function isValidSearchBasePayload(array $data): bool
	{
		if (is_string($data['status'] ?? null) && strcasecmp((string) $data['status'], 'ERROR') === 0) {
			return false;
		}

		return is_array($data['Status'] ?? null) && is_array($data['Sections'] ?? null);
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
		if (!$this->isValidSearchBasePayload($data)) {
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
		$freshUntil = $now + $this->config->cacheSearchBaseTtlSeconds;
		$staleUntil = $freshUntil + $this->config->cacheSearchBaseStaleSeconds;
		$ttl = max(1, $this->config->cacheSearchBaseTtlSeconds + $this->config->cacheSearchBaseStaleSeconds);

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

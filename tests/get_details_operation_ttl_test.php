<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;
use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\Http\AuthTokenProvider;
use Skionline\MerlinxGetter\Http\MerlinxHttpClient;
use Skionline\MerlinxGetter\Operation\GetDetailsOperation;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require __DIR__ . '/helpers/bootstrap.php';

final class RecordingDetailsCache implements CacheInterface
{
	public ?int $lastTtl = null;
	/** @var array<string, mixed>|null */
	public ?array $lastValue = null;

	public function get(string $key, mixed $default = null): mixed
	{
		return $default;
	}

	public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
	{
		$this->lastTtl = is_int($ttl) ? $ttl : null;
		$this->lastValue = is_array($value) ? $value : null;
		return true;
	}

	public function delete(string $key): bool
	{
		return true;
	}

	public function clear(): bool
	{
		return true;
	}

	public function getMultiple(iterable $keys, mixed $default = null): iterable
	{
		$values = [];
		foreach ($keys as $key) {
			if (is_string($key)) {
				$values[$key] = $default;
			}
		}
		return $values;
	}

	public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
	{
		return true;
	}

	public function deleteMultiple(iterable $keys): bool
	{
		return true;
	}

	public function has(string $key): bool
	{
		return false;
	}
}

try {
	$config = MerlinxGetterConfig::fromArray(baseMerlinxConfig([
		'cache' => [
			'dir' => testCacheDir(),
			'token' => ['ttlSeconds' => 600],
			'details' => ['ttlSeconds' => 86400],
			'liveAvailability' => ['ttlSeconds' => 30],
		],
		'search_engine' => [
			'cache' => [
				'search' => [
					'ttl_seconds' => 5,
					'stale_seconds' => 10,
				],
			],
		],
	]));

	$http = new MockHttpClient(static function (): MockResponse {
		return new MockResponse('{"token":"dummy-token"}', ['http_code' => 200]);
	});
	$tokenProvider = new AuthTokenProvider($config, $http);
	$client = new MerlinxHttpClient($config, $tokenProvider, $http);
	$cache = new RecordingDetailsCache();
	$operation = new GetDetailsOperation($config, $client, $cache);

	$offerId = str_repeat('C', 70) . 'TAIL_A|SNOW|NHx8';
	$payload = [
		'result' => [
			'offer' => [
				'Base' => ['OfferId' => $offerId],
			],
		],
	];

	assertSameValue(true, $operation->put($offerId, $payload), 'put should write valid details payload.');
	assertSameValue(86400, $cache->lastTtl, 'Details cache write should use one-day TTL.');
	assertTrue(is_array($cache->lastValue), 'Details cache should store an envelope.');

	$createdAt = $cache->lastValue['createdAt'] ?? null;
	$freshUntil = $cache->lastValue['freshUntil'] ?? null;
	$staleUntil = $cache->lastValue['staleUntil'] ?? null;
	assertTrue(is_int($createdAt), 'Details envelope should include integer createdAt.');
	assertTrue(is_int($freshUntil), 'Details envelope should include integer freshUntil.');
	assertTrue(is_int($staleUntil), 'Details envelope should include integer staleUntil.');
	assertSameValue(86400, $freshUntil - $createdAt, 'Details fresh window should be one day.');
	assertSameValue($freshUntil, $staleUntil, 'Details cache lifetime should not borrow search stale seconds.');
	assertSameValue(30, $config->cacheLiveAvailabilityTtlSeconds, 'Live availability TTL should remain unchanged.');

	echo "PASS: getDetails writes details cache entries with a one-day TTL without touching live availability TTL.\n";
	exit(0);
} catch (Throwable $e) {
	echo 'FAIL: ' . $e->getMessage() . "\n";
	exit(1);
}

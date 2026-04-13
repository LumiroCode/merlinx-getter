<?php

declare(strict_types=1);

use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\Exception\ResponseFormatException;
use Skionline\MerlinxGetter\MerlinxGetterClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require __DIR__ . '/helpers/bootstrap.php';

try {
	$validSearchBase = [
		'Status' => ['Code' => 'OK'],
		'Sections' => [
			'SearchBase' => [
				'Base' => [
					'StartDateMin' => ['date' => ['min' => '2026-12-01']],
				],
			],
		],
	];
	$secondValidSearchBase = [
		'Status' => ['Code' => 'OK'],
		'Sections' => [
			'SearchBase' => [
				'Base' => [
					'StartDateMin' => ['date' => ['min' => '2027-01-01']],
				],
			],
		],
	];
	$semanticError = ['status' => 'ERROR', 'error' => ['text' => 'SEARCHBASE BROKEN']];

	$cacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
		. DIRECTORY_SEPARATOR
		. 'merlinx-getter-searchbase-cache-'
		. str_replace('.', '-', uniqid('', true));
	if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
		throw new RuntimeException('Unable to create searchBase cache test directory.');
	}

	$searchBaseRequests = 0;
	$responses = [$validSearchBase, $secondValidSearchBase];
	$mock = new MockHttpClient(function (string $method, string $url, array $options = []) use (&$searchBaseRequests, &$responses): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			return new MockResponse(json_encode(['token' => 'dummy-token'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		if (str_contains($url, '/v5/data/travel/searchbase')) {
			$searchBaseRequests++;
			$payload = array_shift($responses);
			if (!is_array($payload)) {
				return new MockResponse('{"error":"unexpected searchBase request"}', ['http_code' => 500]);
			}
			return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		return new MockResponse('{"error":"unexpected request"}', ['http_code' => 500]);
	});

	$client = new MerlinxGetterClient(MerlinxGetterConfig::fromArray(baseMerlinxConfig([
		'cache' => ['dir' => $cacheDir],
		'search_engine' => [
			'cache' => [
				'search_base' => [
					'ttl_seconds' => 600,
					'stale_seconds' => 900,
				],
			],
		],
	])), $mock);

	$first = $client->getSearchBase();
	$second = $client->getSearchBase();
	$forced = $client->getSearchBase(true);

	assertSameValue($validSearchBase, $first, 'Initial searchBase payload mismatch.');
	assertSameValue($validSearchBase, $second, 'Cached searchBase payload mismatch.');
	assertSameValue($secondValidSearchBase, $forced, 'Forced searchBase refresh payload mismatch.');
	assertSameValue(2, $searchBaseRequests, 'Cached searchBase call should skip upstream and force should refetch once.');

	$errorClient = new MerlinxGetterClient(MerlinxGetterConfig::fromArray(baseMerlinxConfig([
		'cache' => ['dir' => rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'merlinx-getter-searchbase-error-' . str_replace('.', '-', uniqid('', true))],
	])), new MockHttpClient(static function (string $method, string $url, array $options = []) use ($semanticError): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			return new MockResponse(json_encode(['token' => 'dummy-token'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		if (str_contains($url, '/v5/data/travel/searchbase')) {
			return new MockResponse(json_encode($semanticError, JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		return new MockResponse('{"error":"unexpected request"}', ['http_code' => 500]);
	}));

	assertThrows(
		static fn() => $errorClient->getSearchBase(),
		ResponseFormatException::class,
		static function (Throwable $e): void {
			assertTrue(str_contains($e->getMessage(), 'searchBase'), 'Invalid searchBase payload should mention searchBase.');
		}
	);

	$staleCacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
		. DIRECTORY_SEPARATOR
		. 'merlinx-getter-searchbase-stale-'
		. str_replace('.', '-', uniqid('', true));
	if (!is_dir($staleCacheDir) && !mkdir($staleCacheDir, 0755, true) && !is_dir($staleCacheDir)) {
		throw new RuntimeException('Unable to create searchBase stale-cache test directory.');
	}

	$staleRequests = 0;
	$staleResponses = [$validSearchBase, $semanticError, $secondValidSearchBase];
	$staleClient = new MerlinxGetterClient(MerlinxGetterConfig::fromArray(baseMerlinxConfig([
		'cache' => ['dir' => $staleCacheDir],
		'search_engine' => [
			'cache' => [
				'search_base' => [
					'ttl_seconds' => 1,
					'stale_seconds' => 30,
				],
			],
		],
	])), new MockHttpClient(function (string $method, string $url, array $options = []) use (&$staleRequests, &$staleResponses): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			return new MockResponse(json_encode(['token' => 'dummy-token'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		if (str_contains($url, '/v5/data/travel/searchbase')) {
			$staleRequests++;
			$payload = array_shift($staleResponses);
			return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		return new MockResponse('{"error":"unexpected request"}', ['http_code' => 500]);
	}));

	$staleFirst = $staleClient->getSearchBase();
	sleep(2);
	$staleFallback = $staleClient->getSearchBase();
	$afterInvalid = $staleClient->getSearchBase(true);

	assertSameValue($validSearchBase, $staleFirst, 'Initial stale setup searchBase mismatch.');
	assertSameValue($validSearchBase, $staleFallback, 'Invalid refresh should serve stale valid searchBase.');
	assertSameValue($secondValidSearchBase, $afterInvalid, 'Invalid refresh must not overwrite stale valid cache.');
	assertSameValue(3, $staleRequests, 'Stale scenario should fetch initial, invalid refresh, and forced fresh recovery.');

	echo "PASS: getSearchBase caches only valid payloads, rejects semantic error payloads, and serves stale valid cache on refresh failure.\n";
	exit(0);
} catch (Throwable $e) {
	echo 'FAIL: ' . $e->getMessage() . "\n";
	exit(1);
}

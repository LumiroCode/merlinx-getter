<?php

declare(strict_types=1);

use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\Exception\HttpRequestException;
use Skionline\MerlinxGetter\Http\AuthTokenProvider;
use Skionline\MerlinxGetter\Http\MerlinxHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require __DIR__ . '/helpers/bootstrap.php';

try {
	$buildClient = static function (callable $callback): array {
		$mock = new MockHttpClient($callback);
		$config = MerlinxGetterConfig::fromArray(baseMerlinxConfig());
		$tokenProvider = new AuthTokenProvider($config, $mock);
		$httpClient = new MerlinxHttpClient($config, $tokenProvider, $mock);
		return [$httpClient, $mock];
	};

	// -----------------------------------------------------------------------------
	// Test 1: 412 + 'autherror' body → forceRefresh triggered → replay succeeds
	// Expected: no exception, response from the successful replay is returned.
	// can inspect the response and trigger auth recovery.
	// -----------------------------------------------------------------------------
	$tokenRequestCount = 0;
	$searchRequestCount = 0;
	[$client] = $buildClient(static function (string $method, string $url, array $options = []) use (&$tokenRequestCount, &$searchRequestCount): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			$tokenRequestCount++;
			return new MockResponse(json_encode(['token' => 'token-' . $tokenRequestCount], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		if (str_contains($url, '/v5/data/travel/search')) {
			$searchRequestCount++;
			if ($searchRequestCount === 1) {
				return new MockResponse('autherror', ['http_code' => 412]);
			}
			return new MockResponse(json_encode(['result' => ['offerList' => ['items' => []]]], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		return new MockResponse('unexpected', ['http_code' => 500]);
	});

	$response = $client->request('POST', '/v5/data/travel/search', ['json' => ['query' => 'test']]);

	assertSameValue(200, $response->statusCode(), 'Test 1: replay should return 200 after auth recovery.');
	assertSameValue(2, $tokenRequestCount, 'Test 1: forceRefresh should have triggered a second token fetch.');
	assertSameValue(2, $searchRequestCount, 'Test 1: search endpoint should be called twice (initial + replay).');

	// -----------------------------------------------------------------------------
	// Test 2: 412 + 'autherror' → forceRefresh triggered → replay ALSO 412 + 'autherror'
	// Expected: HttpRequestException thrown; token refresh was still attempted once.
	// so tokenRequestCount stays at 1 rather than 2.
	// -----------------------------------------------------------------------------
	$tokenRequestCount2 = 0;
	$searchRequestCount2 = 0;
	[$client2] = $buildClient(static function (string $method, string $url, array $options = []) use (&$tokenRequestCount2, &$searchRequestCount2): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			$tokenRequestCount2++;
			return new MockResponse(json_encode(['token' => 'token-' . $tokenRequestCount2], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		if (str_contains($url, '/v5/data/travel/search')) {
			$searchRequestCount2++;
			return new MockResponse('autherror', ['http_code' => 412]);
		}
		return new MockResponse('unexpected', ['http_code' => 500]);
	});

	assertThrows(
		static fn() => $client2->request('POST', '/v5/data/travel/search', ['json' => ['query' => 'test']]),
		HttpRequestException::class,
		static function (Throwable $e): void {
			assertSameValue(412, $e->statusCode(), 'Test 2: exception status should be 412.');
			assertTrue(str_contains((string) $e->responseBody(), 'autherror'), 'Test 2: exception body should contain autherror.');
		}
	);
	assertSameValue(2, $searchRequestCount2, 'Test 2: search endpoint should be called twice (initial + replay) before giving up.');
	assertSameValue(1, $tokenRequestCount2, 'Test 2: forceRefresh must have triggered one token HTTP request (initial getToken uses persistent cache).');

	// -----------------------------------------------------------------------------
	// Test 3: 400 (non-auth client error) → no token refresh, throws immediately
	// -----------------------------------------------------------------------------
	$tokenRequestCount3 = 0;
	$searchRequestCount3 = 0;
	[$client3] = $buildClient(static function (string $method, string $url, array $options = []) use (&$tokenRequestCount3, &$searchRequestCount3): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			$tokenRequestCount3++;
			return new MockResponse(json_encode(['token' => 'token-t3'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		if (str_contains($url, '/v5/data/travel/search')) {
			$searchRequestCount3++;
			return new MockResponse('{"error":"bad request"}', ['http_code' => 400]);
		}
		return new MockResponse('unexpected', ['http_code' => 500]);
	});

	assertThrows(
		static fn() => $client3->request('POST', '/v5/data/travel/search', ['json' => ['query' => 'test']]),
		HttpRequestException::class,
		static function (Throwable $e): void {
			assertSameValue(400, $e->statusCode(), 'Test 3: exception status should be 400.');
		}
	);
	assertSameValue(1, $searchRequestCount3, 'Test 3: 400 error must not trigger replay (search called exactly once).');
	assertTrue($tokenRequestCount3 <= 1, 'Test 3: 400 error must not trigger forceRefresh (no extra token requests).');

	// -----------------------------------------------------------------------------
	// Test 4: 500 (server error) → no token refresh, throws immediately
	// -----------------------------------------------------------------------------
	$tokenRequestCount4 = 0;
	$searchRequestCount4 = 0;
	[$client4] = $buildClient(static function (string $method, string $url, array $options = []) use (&$tokenRequestCount4, &$searchRequestCount4): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			$tokenRequestCount4++;
			return new MockResponse(json_encode(['token' => 'token-t4'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		if (str_contains($url, '/v5/data/travel/search')) {
			$searchRequestCount4++;
			return new MockResponse('{"error":"internal"}', ['http_code' => 500]);
		}
		return new MockResponse('unexpected', ['http_code' => 500]);
	});

	assertThrows(
		static fn() => $client4->request('POST', '/v5/data/travel/search', ['json' => ['query' => 'test']]),
		HttpRequestException::class,
		static function (Throwable $e): void {
			assertSameValue(500, $e->statusCode(), 'Test 4: exception status should be 500.');
		}
	);
	assertSameValue(1, $searchRequestCount4, 'Test 4: 500 error must not trigger replay (search called exactly once).');
	assertTrue($tokenRequestCount4 <= 1, 'Test 4: 500 error must not trigger forceRefresh (no extra token requests).');

	// -----------------------------------------------------------------------------
	// Test 5: 412 WITHOUT 'autherror' in body → isAuthError matches on status 412 alone
	//         → auth recovery IS triggered → replay also returns 412 → throws.
	// Verifies that the status-412 match is sufficient for recovery regardless of body.
	// -----------------------------------------------------------------------------
	$tokenRequestCount5 = 0;
	$searchRequestCount5 = 0;
	[$client5] = $buildClient(static function (string $method, string $url, array $options = []) use (&$tokenRequestCount5, &$searchRequestCount5): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			$tokenRequestCount5++;
			return new MockResponse(json_encode(['token' => 'token-t5'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		if (str_contains($url, '/v5/data/travel/search')) {
			$searchRequestCount5++;
			return new MockResponse('validation_error_missing_field', ['http_code' => 412]);
		}
		return new MockResponse('unexpected', ['http_code' => 500]);
	});

	assertThrows(
		static fn() => $client5->request('POST', '/v5/data/travel/search', ['json' => ['query' => 'test']]),
		HttpRequestException::class,
		static function (Throwable $e): void {
			assertSameValue(412, $e->statusCode(), 'Test 5: exception status should be 412.');
		}
	);
	assertSameValue(2, $searchRequestCount5, 'Test 5: 412 triggers auth recovery (search called twice: initial + replay).');
	assertSameValue(1, $tokenRequestCount5, 'Test 5: forceRefresh triggered one token HTTP request.');

	// -----------------------------------------------------------------------------
	// Test 6: rate-limited on attempt 0, 412 + 'autherror' on the rate-limit retry
	//         → auth recovery still triggered, replay succeeds.
	// Verifies that sendWithRetry surfaces auth errors appearing on a mid-loop attempt,
	// not only on attempt 0.
	// -----------------------------------------------------------------------------
	$tokenRequestCount6 = 0;
	$searchRequestCount6 = 0;
	[$client6] = $buildClient(static function (string $method, string $url, array $options = []) use (&$tokenRequestCount6, &$searchRequestCount6): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			$tokenRequestCount6++;
			return new MockResponse(json_encode(['token' => 'token-' . $tokenRequestCount6], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		if (str_contains($url, '/v5/data/travel/search')) {
			$searchRequestCount6++;
			if ($searchRequestCount6 === 1) {
				return new MockResponse('too many requests', ['http_code' => 429]);
			}
			if ($searchRequestCount6 === 2) {
				return new MockResponse('autherror', ['http_code' => 412]);
			}
			return new MockResponse(json_encode(['result' => ['offerList' => ['items' => []]]], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		return new MockResponse('unexpected', ['http_code' => 500]);
	});

	$response6 = $client6->request('POST', '/v5/data/travel/search', ['json' => ['query' => 'test']], ['rateLimitRetryDelayMs' => 0]);

	assertSameValue(200, $response6->statusCode(), 'Test 6: replay should return 200 after rate-limit retry then auth recovery.');
	assertSameValue(3, $searchRequestCount6, 'Test 6: search called 3 times (rate-limited + auth-error + auth-recovery replay).');
	assertSameValue(1, $tokenRequestCount6, 'Test 6: forceRefresh triggered one token HTTP request (initial getToken uses persistent cache).');

	echo "PASS: MerlinxHttpClient auth recovery path works correctly.\n";
	exit(0);
} catch (Throwable $e) {
	echo 'FAIL: ' . $e->getMessage() . "\n";
	exit(1);
}

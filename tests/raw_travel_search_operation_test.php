<?php

declare(strict_types=1);

use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\MerlinxGetterClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require __DIR__ . '/helpers/bootstrap.php';

try {
	$capturedPayloads = [];
	$searchRequests = 0;

	$mock = new MockHttpClient(function (string $method, string $url, array $options = []) use (&$capturedPayloads, &$searchRequests): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			return new MockResponse(json_encode(['token' => 'dummy-token'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		if (str_contains($url, '/v5/data/travel/search')) {
			$searchRequests++;
			$capturedPayloads[] = extractJsonPayload($options);
			return new MockResponse(json_encode([
				'offerList' => [
					'items' => [
						[
							'offer' => [
								'Base' => ['OfferId' => 'raw-' . $searchRequests],
							],
						],
					],
				],
				'debug' => 'must be removed by package HTTP client',
			], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		return new MockResponse('{"error":"unexpected request"}', ['http_code' => 500]);
	});

	$client = new MerlinxGetterClient(MerlinxGetterConfig::fromArray(baseMerlinxConfig()), $mock);
	$body = [
		'conditions' => [
			'search' => [
				'Base' => [
					'XCode' => ['EXACT'],
				],
			],
		],
		'views' => [
			'offerList' => [
				'limit' => 1,
			],
		],
	];

	$first = $client->executeRawSearch($body);
	$second = $client->executeRawSearch($body);

	assertSameValue($body, $capturedPayloads[0] ?? null, 'executeRawSearch should send exact caller body without condition merging.');
	assertSameValue($body, $capturedPayloads[1] ?? null, 'executeRawSearch should not mutate or cache repeated body.');
	assertSameValue(2, $searchRequests, 'executeRawSearch should skip package search cache.');
	assertTrue(!array_key_exists('debug', $first), 'executeRawSearch should return sanitized payload without debug field.');
	assertSameValue('raw-1', $first['offerList']['items'][0]['offer']['Base']['OfferId'] ?? null, 'First raw search payload mismatch.');
	assertSameValue('raw-2', $second['offerList']['items'][0]['offer']['Base']['OfferId'] ?? null, 'Second raw search payload mismatch.');

	echo "PASS: executeRawSearch sends exact request body, skips search cache, and returns sanitized raw payload.\n";
	exit(0);
} catch (Throwable $e) {
	echo 'FAIL: ' . $e->getMessage() . "\n";
	exit(1);
}

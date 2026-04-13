<?php

declare(strict_types=1);

use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\MerlinxGetterClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require __DIR__ . '/helpers/bootstrap.php';

try {
	$cacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
		. DIRECTORY_SEPARATOR
		. 'merlinx-getter-details-controls-'
		. str_replace('.', '-', uniqid('', true));
	if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
		throw new RuntimeException('Unable to create details controls test directory.');
	}

	$offerId = str_repeat('B', 70) . 'TAIL_A|SNOW|NHx8';
	$aliasOfferId = str_repeat('B', 70) . 'TAIL_B|SNOW|NHx8';
	$malformedOfferId = 'not-a-composite-offer-id';
	$detailsRequests = [];

	$mock = new MockHttpClient(function (string $method, string $url, array $options = []) use (&$detailsRequests): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			return new MockResponse(json_encode(['token' => 'dummy-token'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		if (str_contains($url, '/v5/data/travel/details')) {
			$query = (string) parse_url($url, PHP_URL_QUERY);
			$offerId = '';
			if (preg_match('/(?:^|&)Base\\.OfferId=([^&]+)/', $query, $matches) === 1) {
				$offerId = rawurldecode($matches[1]);
			}
			$detailsRequests[] = $offerId;
			return new MockResponse(json_encode([
				'result' => [
					'offer' => [
						'Base' => ['OfferId' => $offerId],
						'Accommodation' => ['Name' => 'Fetched ' . count($detailsRequests)],
					],
				],
			], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		return new MockResponse('{"error":"unexpected request"}', ['http_code' => 500]);
	});

	$client = new MerlinxGetterClient(MerlinxGetterConfig::fromArray(baseMerlinxConfig([
		'cache' => ['dir' => $cacheDir],
	])), $mock);

	$first = $client->getDetails($offerId);
	$fresh = $client->getDetailsFresh($offerId);
	$cached = $client->getDetails($offerId);

	assertSameValue('Fetched 1', $first['result']['offer']['Accommodation']['Name'] ?? null, 'Initial details payload mismatch.');
	assertSameValue('Fetched 2', $fresh['result']['offer']['Accommodation']['Name'] ?? null, 'Fresh details payload mismatch.');
	assertSameValue('Fetched 2', $cached['result']['offer']['Accommodation']['Name'] ?? null, 'getDetailsFresh should update cache for subsequent getDetails.');
	assertSameValue(2, count($detailsRequests), 'getDetailsFresh should bypass cache exactly once.');

	$manualPayload = [
		'result' => [
			'offer' => [
				'Base' => ['OfferId' => $offerId],
				'Accommodation' => ['Name' => 'Manual cache payload'],
			],
		],
	];
	assertSameValue(true, $client->putDetails($offerId, $manualPayload), 'putDetails should persist normalized OfferId payload.');
	$manualRead = $client->getDetails($aliasOfferId);
	assertSameValue('Manual cache payload', $manualRead['result']['offer']['Accommodation']['Name'] ?? null, 'Alias OfferId should reuse putDetails payload.');
	assertSameValue(2, count($detailsRequests), 'Reading alias after putDetails should not hit upstream.');

	assertSameValue(false, $client->putDetails($malformedOfferId, $manualPayload), 'putDetails should skip malformed OfferId cache writes.');
	$malformedRead = $client->getDetails($malformedOfferId);
	assertSameValue($malformedOfferId, $malformedRead['result']['offer']['Base']['OfferId'] ?? null, 'Malformed OfferId should still fetch fresh details.');
	assertSameValue(3, count($detailsRequests), 'Malformed OfferId putDetails skip should require upstream fetch.');

	echo "PASS: details fresh and put controls preserve cache bypass, update, alias reuse, and malformed-id skip behavior.\n";
	exit(0);
} catch (Throwable $e) {
	echo 'FAIL: ' . $e->getMessage() . "\n";
	exit(1);
}

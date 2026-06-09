<?php

declare(strict_types=1);

use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\Http\AuthTokenProvider;
use Skionline\MerlinxGetter\Http\MerlinxHttpClient;
use Skionline\MerlinxGetter\Operation\SearchOperation;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require __DIR__ . '/helpers/bootstrap.php';

try {
	$responses = [
		[
			'offerList' => [
				'more' => true,
				'pageBookmark' => 'bm-a',
				'items' => [
					'upstream-key-1' => [
						'offer' => [
							'Base' => [
								'OfferId' => 'offer-1|SNOW|NHx8',
								'Price' => ['Total' => ['Amount' => '120.00']],
							],
							'Accommodation' => [],
						],
					],
					'upstream-missing' => [
						'offer' => [
							'Base' => [],
						],
					],
				],
			],
		],
		[
			'offerList' => [
				'more' => true,
				'pageBookmark' => 'bm-b',
				'items' => [
					'upstream-key-1' => [
						'offer' => [
							'Base' => [
								'OfferId' => 'offer-1|SNOW|NHx8',
								'Price' => ['Total' => ['Amount' => '100.00']],
							],
							'Accommodation' => [
								'Name' => 'Cheapest Variant',
							],
						],
					],
				],
			],
		],
		[
			'offerList' => [
				'more' => false,
				'pageBookmark' => 'bm-c',
				'items' => [
					'upstream-key-dup' => [
						'offer' => [
							'Base' => [
								'OfferId' => 'offer-1|SNOW|NHx8',
							],
							'Accommodation' => [
								'Name' => 'Filled From Duplicate',
							],
						],
					],
					'upstream-key-2' => [
						'offer' => [
							'Base' => [
								'OfferId' => 'offer-2|SNOW|NHx8',
							],
						],
					],
				],
			],
		],
	];

	$searchRequests = 0;
	$mock = new MockHttpClient(function (string $method, string $url, array $options = []) use (&$responses, &$searchRequests): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			return new MockResponse(json_encode(['token' => 'dummy-token'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		if (str_contains($url, '/v5/data/travel/search')) {
			$searchRequests++;
			$index = $searchRequests - 1;
			if (!isset($responses[$index])) {
				return new MockResponse(json_encode(['error' => 'unexpected request'], JSON_THROW_ON_ERROR), ['http_code' => 500]);
			}
			return new MockResponse(json_encode($responses[$index], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}
		return new MockResponse(json_encode(['error' => 'unexpected request'], JSON_THROW_ON_ERROR), ['http_code' => 500]);
	});

	$config = MerlinxGetterConfig::fromArray(baseMerlinxConfig([
		'search_engine' => [
			'conditions' => [
				['search' => [], 'filter' => []],
				['search' => [], 'filter' => []],
			],
		],
	]));

	$tokenProvider = new AuthTokenProvider($config, $mock);
	$httpClient = new MerlinxHttpClient($config, $tokenProvider, $mock);
	$operation = new SearchOperation($config, $httpClient);

	$result = $operation->execute(searchRequest([], [], [], ['offerList' => ['limit' => 500]]))->response();

	assertSameValue(3, $searchRequests, 'Expected three /search requests (single deduped query with three pages).');

	$items = $result['offerList']['items'] ?? null;
	assertTrue(is_array($items), 'Merged offerList.items is missing or invalid.');
	assertSameValue(2, count($items), 'Merged offerList.items should include both unique OfferIds.');
	assertTrue(!($result['offerList']['more'] ?? true), 'Merged offerList.more should be false as per last response.');
	assertSameValue('bm-c', $result['offerList']['pageBookmark'] ?? null, 'Merged offerList.pageBookmark should match last response.');
	assertSameValue(
		['offer-1|SNOW|NHx8', 'offer-2|SNOW|NHx8'],
		array_keys($items),
		'Merged offerList.items should be keyed by full OfferId in first-seen order.'
	);
	assertSameValue(
		'100.00',
		$items['offer-1|SNOW|NHx8']['offer']['Base']['Price']['Total']['Amount'] ?? null,
		'Cheapest occurrence should force its fields.'
	);
	assertSameValue(
		'Cheapest Variant',
		$items['offer-1|SNOW|NHx8']['offer']['Accommodation']['Name'] ?? null,
		'Cheapest occurrence should force its fields.'
	);

	echo "PASS: SearchOperation keys offerList.items by OfferId, drops malformed entries, and fills duplicate gaps.\n";
	exit(0);
} catch (Throwable $e) {
	echo "FAIL: " . $e->getMessage() . "\n";
	exit(1);
}

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
			'groupedList' => [
				'more' => false,
				'pageBookmark' => 'group-bm-1',
				'items' => [
					'100' => [
						'groupKeyValue' => '100',
						'offer' => [
							'Base' => [
								'OfferId' => 'group-offer-100|NKRA|NHx8',
								'ObjectId' => '100',
								'Price' => [
									'Total' => [
										'Amount' => "1600.00",
									],
								]
							],
							'Accommodation' => [
								'XCode' => [
									'Id' => '100',
									'Name' => 'Hotel 100',
								],
								'Name' => '',
							],
						],
						'sortKeyValue' => [200],
					],
					'200' => [
						'offer' => [
							'Base' => [
								'OfferId' => 'group-offer-200|NKRA|NHx8',
								'ObjectId' => '200',
							],
							'Accommodation' => [
								'XCode' => [
									'Id' => '200',
									'Name' => 'Hotel 200',
								],
							],
						],
					],
				],
			],
		],
		[
			'groupedList' => [
				'more' => true,
				'pageBookmark' => 'group-bm-2',
				'items' => [
					[
						'offer' => [
							'Base' => [
								'ObjectId' => '100',
								'Price' => [
									'Total' => [
										'Amount' => "1500.00",
									],
								]
							],
							'Accommodation' => [
								'XCode' => [
									'Id' => '100',
								],
								'Name' => 'Hotel 100 Cheapest',
							],
						],
						'sortKeyValue' => [210],
					],
				],
			],
		],
		[
			'groupedList' => [
				'more' => false,
				'pageBookmark' => 'group-bm-3',
				'items' => [
					[
						'offer' => [
							'Base' => [
								'ObjectId' => '100',
							],
							'Accommodation' => [
								'XCode' => [
									'Id' => '100',
								],
								'Name' => 'Hotel 100 No Price',
							],
						],
						'sortKeyValue' => [210],
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
			$response = array_shift($responses);
			if (!is_array($response)) {
				return new MockResponse(json_encode(['error' => 'unexpected request'], JSON_THROW_ON_ERROR), ['http_code' => 500]);
			}
			return new MockResponse(json_encode($response, JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}

		return new MockResponse(json_encode(['error' => 'unexpected request'], JSON_THROW_ON_ERROR), ['http_code' => 500]);
	});

	$config = MerlinxGetterConfig::fromArray(baseMerlinxConfig([
		'search_engine' => [
			'conditions' => [
				['search' => ['Base' => ['XCountry' => '59']], 'filter' => []],
				['search' => ['Base' => ['XRegion' => '3781']], 'filter' => []],
			],
		],
	]));

	$tokenProvider = new AuthTokenProvider($config, $mock);
	$httpClient = new MerlinxHttpClient($config, $tokenProvider, $mock);
	$operation = new SearchOperation($config, $httpClient);

	$result = $operation->execute(searchRequest([], [], ['groupBy' => ['key' => 'Accommodation.XCode']], ['groupedList' => ['limit' => 100]]))->response();
	assertSameValue(3, $searchRequests, 'Expected one search per configured basis query.');

	$items = $result['groupedList']['items'] ?? null;
	assertTrue(is_array($items), 'Merged groupedList.items is missing or invalid.');
	assertSameValue(['100', '200'], array_map('strval', array_keys($items)), 'groupedList.items should be keyed by resolved group identity.');
	assertSameValue('100', $items[100]['groupKeyValue'] ?? null, 'Merged grouped item should force groupKeyValue to the resolved key.');
	assertSameValue('Hotel 100 Cheapest', $items[100]['offer']['Accommodation']['Name'] ?? null, 'Duplicated grouped item should select cheapest variant from payloads.');
	assertTrue(isset($items[100]['offer']['Base']) && !array_key_exists('OfferId', $items[100]['offer']['Base'] ?? []), 'Duplicated grouped item should select payload atomically, as a whole variant, without merging individual fields.');
	assertSameValue('group-bm-3', $result['groupedList']['pageBookmark'] ?? null, 'Grouped pagination metadata should come from the last payload.');

	echo "PASS: SearchOperation collapses groupedList basis overlap by group identity and fills duplicate gaps.\n";
	exit(0);
} catch (Throwable $e) {
	echo "FAIL: " . $e->getMessage() . "\n";
	exit(1);
}

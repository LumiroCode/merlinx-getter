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
	$searchRequestCount = 0;
	$capturedPayloads = [];

	$mock = new MockHttpClient(function (string $method, string $url, array $options = []) use (&$searchRequestCount, &$capturedPayloads): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			return new MockResponse(json_encode(['token' => 'dummy-token'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}

		if (str_contains($url, '/v5/data/travel/search')) {
			$capturedPayloads[] = extractJsonPayload($options);
			$searchRequestCount++;

			return new MockResponse(json_encode([
				'fieldValues' => [
					'fieldValues' => [
						'Accommodation.XNameAndCodeFullTextSearch' => ['Foo Hotel'],
					],
					'more' => false,
					'pageBookmark' => '',
				],
			], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}

		return new MockResponse(json_encode(['error' => 'unexpected request'], JSON_THROW_ON_ERROR), ['http_code' => 500]);
	});

	$config = MerlinxGetterConfig::fromArray(baseMerlinxConfig([
		'search_engine' => [
			'operators' => ['VITX', 'SNOW'],
			'conditions' => [
				[
					'search' => [
						'Base' => [
							'Operator' => ['VITX', 'SNOW'],
							'XCity' => [
								'Code' => 'LIM',
								'Name' => 'Limone',
							],
						],
						'Accommodation' => [
							'Attributes' => ['+location_ski_resorts'],
						],
					],
				],
			],
		],
	]));

	$tokenProvider = new AuthTokenProvider($config, $mock);
	$httpClient = new MerlinxHttpClient($config, $tokenProvider, $mock);
	$operation = new SearchOperation($config, $httpClient);

	$result = $operation->execute(searchRequest(
		search: [
			'Base' => [
				'Operator' => ['SNOW'],
			],
			'Accommodation' => [
				'XNameAndCodeFullTextSearch' => 'foo',
			],
		],
		filter: [],
		results: [],
		views: ['fieldValues' => ['fieldList' => ['Accommodation.XNameAndCodeFullTextSearch']]],
	))->response();

	assertTrue(is_array($result), 'Search response should be returned.');
	assertSameValue(1, $searchRequestCount, 'Scoped text search should be sent as one remote MerlinX search request.');
	assertSameValue(1, count($capturedPayloads), 'Exactly one search payload should be captured.');

	$search = $capturedPayloads[0]['conditions']['search'] ?? null;
	assertTrue(is_array($search), 'Captured search payload should contain conditions.search.');
	assertSameValue(['SNOW'], $search['Base']['Operator'] ?? null, 'User operator should narrow the configured operator scope.');
	assertSameValue('Limone', $search['Base']['XCity']['Name'] ?? null, 'Configured city scope should be present in the remote request.');
	assertSameValue(['+location_ski_resorts'], $search['Accommodation']['Attributes'] ?? null, 'Configured accommodation attribute scope should be present in the remote request.');
	assertSameValue('foo', $search['Accommodation']['XNameAndCodeFullTextSearch'] ?? null, 'User text search should be present in the remote request.');

	echo "PASS: SearchOperation sends user text search inside configured MerlinX scope in one remote request.\n";
	exit(0);
} catch (Throwable $e) {
	echo 'FAIL: ' . $e->getMessage() . "\n";
	exit(1);
}

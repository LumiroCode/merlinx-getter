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
	$mock = new MockHttpClient(static function (string $method, string $url): MockResponse {
		if (str_contains($url, '/v5/token/new')) {
			return new MockResponse(json_encode(['token' => 'dummy-token'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
		}

		if (str_contains($url, '/v5/data/travel/search')) {
			return new MockResponse(json_encode([
				'customList' => [
					'items' => [
						[
							'offer' => [
								'Base' => [
									'OfferId' => 'CUSTOM-EXCLUDED-CITY-OFFER',
									'XCity' => ['Name' => ' Limone Piemonte '],
								],
								'Accommodation' => [
									'Attributes' => ['location_city_break'],
								],
							],
						],
						[
							'offer' => [
								'Base' => [
									'OfferId' => 'CUSTOM-EXCLUDED-ATTRIBUTE-OFFER',
									'XCity' => ['Name' => 'Genoa'],
								],
								'Accommodation' => [
									'Attributes' => ['location_ski_resorts'],
								],
							],
						],
						[
							'offer' => [
								'Base' => [
									'OfferId' => 'CUSTOM-VISIBLE-CITY-OFFER',
									'XCity' => ['Name' => 'Genoa'],
								],
								'Accommodation' => [
									'Attributes' => ['location_city_break'],
								],
							],
						],
						[
							'offer' => [
								'Base' => [
									'OfferId' => 'CUSTOM-VISIBLE-NO-ACCOM-OFFER',
									'XCity' => ['Name' => 'Genoa'],
								],
								'Accommodation' => null,
							],
						],
						[
							'offer' => [
								'Base' => [
									'OfferId' => 'CUSTOM-VISIBLE-MISSING-ACCOM-OFFER',
									'XCity' => ['Name' => 'Genoa'],
								],
							],
						],
						[
							'offer' => [
								'Base' => [
									'OfferId' => 'CUSTOM-VISIBLE-EMPTY-ATTRIBUTES-OFFER',
									'XCity' => ['Name' => 'Genoa'],
								],
								'Accommodation' => [
									'Attributes' => [],
								],
							],
						],
					],
				],
				'groupedList' => [
					'items' => [
						[
							'groupKeyValue' => 'EXCLUDED-CITY-GROUP',
							'offer' => [
								'Base' => [
									'OfferId' => 'GROUP-OFFER-1',
									'XCity' => ['Name' => 'Limone Piemonte'],
								],
								'Accommodation' => [
									'Attributes' => ['location_city_break'],
								],
							],
						],
						[
							'groupKeyValue' => 'EXCLUDED-ATTRIBUTE-GROUP',
							'offer' => [
								'Base' => [
									'OfferId' => 'GROUP-OFFER-ATTRIBUTE',
									'XCity' => ['Name' => 'Alassio'],
								],
								'Accommodation' => [
									'Attributes' => ['location_ski_resorts'],
								],
							],
						],
						[
							'groupKeyValue' => 'VISIBLE-CITY-GROUP',
							'offer' => [
								'Base' => [
									'OfferId' => 'GROUP-OFFER-2',
									'XCity' => ['Name' => 'Alassio'],
								],
								'Accommodation' => [
									'Attributes' => ['location_city_break'],
								],
							],
						],
						[
							'groupKeyValue' => 'VISIBLE-NO-ACCOM-GROUP',
							'offer' => [
								'Base' => [
									'OfferId' => 'GROUP-OFFER-NO-ACCOM',
									'XCity' => ['Name' => 'Alassio'],
								],
								'Accommodation' => null,
							],
						],
						[
							'groupKeyValue' => 'VISIBLE-MISSING-ACCOM-GROUP',
							'offer' => [
								'Base' => [
									'OfferId' => 'GROUP-OFFER-MISSING-ACCOM',
									'XCity' => ['Name' => 'Alassio'],
								],
							],
						],
						[
							'groupKeyValue' => 'VISIBLE-EMPTY-ATTRIBUTES-GROUP',
							'offer' => [
								'Base' => [
									'OfferId' => 'GROUP-OFFER-EMPTY-ATTRIBUTES',
									'XCity' => ['Name' => 'Alassio'],
								],
								'Accommodation' => [
									'Attributes' => [],
								],
							],
						],
					],
				],
				'offerList' => [
					'items' => [
						[
							'offer' => [
								'Base' => [
									'OfferId' => 'EXCLUDED-CITY-OFFER',
									'XCity' => ['Name' => 'Limone Piemonte'],
								],
								'Accommodation' => [
									'Attributes' => ['location_city_break'],
								],
							],
						],
						[
							'offer' => [
								'Base' => [
									'OfferId' => 'EXCLUDED-ATTRIBUTE-OFFER',
									'XCity' => ['Name' => 'Rome'],
								],
								'Accommodation' => [
									'Attributes' => ['location_ski_resorts'],
								],
							],
						],
						[
							'offer' => [
								'Base' => [
									'OfferId' => 'VISIBLE-CITY-OFFER',
									'XCity' => ['Name' => 'Genoa'],
								],
								'Accommodation' => [
									'Attributes' => ['location_city_break'],
								],
							],
						],
						[
							'offer' => [
								'Base' => [
									'OfferId' => 'VISIBLE-NO-ACCOM-OFFER',
									'XCity' => ['Name' => 'Genoa'],
								],
								'Accommodation' => null,
							],
						],
						[
							'offer' => [
								'Base' => [
									'OfferId' => 'VISIBLE-MISSING-ACCOM-OFFER',
									'XCity' => ['Name' => 'Genoa'],
								],
							],
						],
						[
							'offer' => [
								'Base' => [
									'OfferId' => 'VISIBLE-EMPTY-ATTRIBUTES-OFFER',
									'XCity' => ['Name' => 'Genoa'],
								],
								'Accommodation' => [
									'Attributes' => [],
								],
							],
						],
						[
							'offer' => [
								'Base' => [
									'OfferId' => 'VISIBLE-EMPTY-STRING-ATTRIBUTES-OFFER',
									'XCity' => ['Name' => 'Genoa'],
								],
								'Accommodation' => [
									'Attributes' => '   ',
								],
							],
						],
					],
				],
				'fieldValues' => [
					'fieldValues' => [
						'Base.XCity' => ['Limone Piemonte', 'Genoa'],
						'Accommodation.XCity' => ['Alassio', 'limone-piemonte'],
						'Accommodation.Room' => [
							'DBL' => 'Pokój 2 os.',
							'SGL' => 'Pokój 1 os.',
						],
					],
					'more' => false,
					'pageBookmark' => '',
				],
				'unfilteredFieldValues' => [
					'fieldValues' => [
						'Base.XCity' => [' limone-piemonte ', 'Rome'],
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
			'response_filters' => [
				'exclude_values_by_path' => [
					'fieldValues.Base.XCity' => ['Limone Piemonte'],
					'fieldValues.Accommodation.XCity' => ['Limone Piemonte'],
					'offer.Base.XCity.Name' => ['Limone Piemonte'],
					'offer.Accommodation.XCity.Name' => ['Limone Piemonte'],
					'offer.Accommodation.Attributes' => ['location_ski_resorts'],
				],
			],
		],
	]));

	$tokenProvider = new AuthTokenProvider($config, $mock);
	$httpClient = new MerlinxHttpClient($config, $tokenProvider, $mock);
	$operation = new SearchOperation($config, $httpClient);

	$result = $operation->execute(searchRequest([], [], [], [
		'customList' => ['limit' => 100],
		'groupedList' => ['limit' => 100],
		'offerList' => ['limit' => 100],
		'fieldValues' => ['fieldList' => ['Base.XCity', 'Accommodation.XCity', 'Accommodation.Room']],
		'unfilteredFieldValues' => ['fieldList' => ['Base.XCity']],
	]))->response();

	$groupedItems = $result['groupedList']['items'] ?? null;
	assertTrue(is_array($groupedItems), 'groupedList.items should exist.');
	assertSameValue(4, count($groupedItems), 'Package SearchOperation should preserve items when filtered accommodation attributes are undefined.');
	assertTrue(!isset($groupedItems['EXCLUDED-CITY-GROUP']), 'Excluded city grouped item should be removed from package search response.');
	assertTrue(!isset($groupedItems['EXCLUDED-ATTRIBUTE-GROUP']), 'Grouped item with excluded accommodation attribute should be removed from package search response.');
	assertTrue(isset($groupedItems['VISIBLE-CITY-GROUP']), 'Visible city grouped item should be preserved in package search response.');
	assertTrue(isset($groupedItems['VISIBLE-NO-ACCOM-GROUP']), 'Grouped item with null accommodation should be preserved.');
	assertTrue(isset($groupedItems['VISIBLE-MISSING-ACCOM-GROUP']), 'Grouped item with missing accommodation should be preserved.');
	assertTrue(isset($groupedItems['VISIBLE-EMPTY-ATTRIBUTES-GROUP']), 'Grouped item with empty accommodation attributes should be preserved.');

	$offerItems = $result['offerList']['items'] ?? null;
	assertTrue(is_array($offerItems), 'offerList.items should exist.');
	assertSameValue(5, count($offerItems), 'Package SearchOperation should preserve offerList items when filtered accommodation attributes are undefined.');
	assertTrue(!isset($offerItems['EXCLUDED-CITY-OFFER']), 'Excluded city offer item should be removed from package search response.');
	assertTrue(!isset($offerItems['EXCLUDED-ATTRIBUTE-OFFER']), 'Offer item with excluded accommodation attribute should be removed from package search response.');
	assertTrue(isset($offerItems['VISIBLE-CITY-OFFER']), 'Visible city offer item should be preserved in package search response.');
	assertTrue(isset($offerItems['VISIBLE-NO-ACCOM-OFFER']), 'Offer item with null accommodation should be preserved.');
	assertTrue(isset($offerItems['VISIBLE-MISSING-ACCOM-OFFER']), 'Offer item with missing accommodation should be preserved.');
	assertTrue(isset($offerItems['VISIBLE-EMPTY-ATTRIBUTES-OFFER']), 'Offer item with empty accommodation attributes should be preserved.');
	assertTrue(isset($offerItems['VISIBLE-EMPTY-STRING-ATTRIBUTES-OFFER']), 'Offer item with empty-string accommodation attributes should be preserved.');

	$customItems = $result['customList']['items'] ?? null;
	assertTrue(is_array($customItems), 'customList.items should exist.');
	assertSameValue(4, count($customItems), 'Package SearchOperation should preserve generic item-list items when filtered accommodation attributes are undefined.');
	$customOfferIds = [];
	foreach ($customItems as $customItem) {
		$offerId = $customItem['offer']['Base']['OfferId'] ?? null;
		if (!is_string($offerId)) {
			continue;
		}

		$customOfferIds[trim($offerId)] = true;
	}
	assertTrue(!isset($customOfferIds['CUSTOM-EXCLUDED-ATTRIBUTE-OFFER']), 'Custom item with excluded accommodation attribute should be removed from package search response.');
	assertTrue(isset($customOfferIds['CUSTOM-VISIBLE-CITY-OFFER']), 'Visible city custom item should be preserved.');
	assertTrue(isset($customOfferIds['CUSTOM-VISIBLE-NO-ACCOM-OFFER']), 'Custom item with null accommodation should be preserved.');
	assertTrue(isset($customOfferIds['CUSTOM-VISIBLE-MISSING-ACCOM-OFFER']), 'Custom item with missing accommodation should be preserved.');
	assertTrue(isset($customOfferIds['CUSTOM-VISIBLE-EMPTY-ATTRIBUTES-OFFER']), 'Custom item with empty accommodation attributes should be preserved.');

	$fieldValues = $result['fieldValues'] ?? null;
	assertTrue(is_array($fieldValues), 'fieldValues should exist.');
	assertSameValue(['Genoa'], $fieldValues['Base.XCity'] ?? null, 'fieldValues.Base.XCity should exclude configured city names.');
	assertSameValue(['Alassio'], $fieldValues['Accommodation.XCity'] ?? null, 'fieldValues.Accommodation.XCity should exclude configured city names.');
	assertSameValue(
		['DBL' => 'Pokój 2 os.', 'SGL' => 'Pokój 1 os.'],
		$fieldValues['Accommodation.Room'] ?? null,
		'Unconfigured fieldValues keys should remain untouched.'
	);

	$unfilteredFieldValues = $result['unfilteredFieldValues'] ?? null;
	assertTrue(is_array($unfilteredFieldValues), 'unfilteredFieldValues should exist.');
	assertSameValue(['Rome'], $unfilteredFieldValues['Base.XCity'] ?? null, 'unfilteredFieldValues.Base.XCity should honor fieldValues path exclusions.');

	echo "PASS: SearchOperation applies configured path-based exclusions before merge for item views and fieldValues.\n";
	exit(0);
} catch (Throwable $e) {
	echo "FAIL: " . $e->getMessage() . "\n";
	exit(1);
}

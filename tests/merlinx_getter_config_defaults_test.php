<?php

declare(strict_types=1);

use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\Exception\ConfigException;

require __DIR__ . '/helpers/bootstrap.php';

try {
	$minimal = [
		'base_url' => 'https://mwsv5pro.merlinx.eu',
		'login' => 'dummy',
		'password' => 'dummy',
		'expedient' => 'dummy',
		'domain' => 'example.com',
		'search_engine' => [],
		'cache' => [
			'dir' => testCacheDir(),
			'token' => ['ttlSeconds' => 1800],
			'liveAvailability' => [
				'ttlSeconds' => 45,
			],
		],
	];

	$config = MerlinxGetterConfig::fromArray($minimal);
	assertSameValue('default', $config->searchEngineName, 'Default search engine name mismatch.');
	assertSameValue([], $config->searchEngineOperators, 'Default search operators mismatch.');
	assertSameValue([[
		'search' => [],
		'filter' => [],
		'results' => [],
		'views' => [],
	]], $config->searchEngineConditions, 'Default search conditions mismatch.');
	assertSameValue([], $config->inquiryableAvailabilityBases(), 'Default inquiryable bases mismatch.');
	assertSameValue(0, $config->inquiryableOnrequestMinDays(), 'Default inquiryable_onrequest_min_days mismatch.');
	assertSameValue([], $config->childAsAdultOperators(), 'Default child-as-adult operators mismatch.');
	assertSameValue([], $config->excludedValuesByPath(), 'Default excluded values-by-path map mismatch.');
	assertSameValue(1800, $config->cacheTokenTtlSeconds, 'cache.token.ttlSeconds mapping mismatch.');
	assertSameValue(300, $config->cacheSearchTtlSeconds, 'Default search ttl mismatch.');
	assertSameValue(900, $config->cacheSearchStaleSeconds, 'Default search stale ttl mismatch.');
	assertSameValue(300, $config->cacheSearchBaseTtlSeconds, 'Default searchBase ttl mismatch.');
	assertSameValue(900, $config->cacheSearchBaseStaleSeconds, 'Default searchBase stale ttl mismatch.');
	assertSameValue(45, $config->cacheLiveAvailabilityTtlSeconds, 'cache.liveAvailability.ttlSeconds mapping mismatch.');
	assertSameValue(3000, $config->cacheSearchLockTimeoutMs, 'Default cache.search.lockTimeoutMs mismatch.');
	assertSameValue(50, $config->cacheSearchLockRetryDelayMs, 'Default cache.search.lockRetryDelayMs mismatch.');
	assertSameValue(100, $config->defaultViewLimit, 'Default runtime default_view_limit mismatch.');
	assertSameValue(4, $config->defaultSearchOptions['rateLimitRetryMaxAttempts'] ?? null, 'Default retry attempts mismatch.');
	assertSameValue(500, $config->defaultSearchOptions['rateLimitRetryDelayMs'] ?? null, 'Default retry delay mismatch.');
	assertSameValue(2.0, $config->defaultSearchOptions['rateLimitRetryBackoffMultiplier'] ?? null, 'Default retry backoff mismatch.');
	assertSameValue(8000, $config->defaultSearchOptions['rateLimitRetryMaxDelayMs'] ?? null, 'Default retry max delay mismatch.');

	$override = array_merge($minimal, [
		'search_engine' => [
			'name' => 'overridden',
			'operators' => ['ABC', 'SNOW'],
			'conditions' => [
				['search' => ['Base' => ['XCity' => 'Test City']]],
			],
			'availability_policy' => [
				'inquiryable_bases' => ['available', 'onrequest'],
				'onrequest_min_days' => 7,
			],
			'operator_policies' => [
				'child_as_adult_operators' => ['ABC'],
			],
			'response_filters' => [
				'exclude_values_by_path' => [
					'fieldValues.Base.XCity' => [' Zakopane ', 'Saalbach', ''],
					'fieldValues.Accommodation.XCity' => ['zakopane'],
				],
			],
			'runtime' => [
				'default_view_limit' => 250,
				'rate_limit_retry_max_attempts' => 9,
				'rate_limit_retry_delay_ms' => 15,
				'rate_limit_retry_backoff_multiplier' => 3.0,
				'rate_limit_retry_max_delay_ms' => 90,
			],
			'cache' => [
				'search' => [
					'ttl_seconds' => 120,
					'stale_seconds' => 240,
					'lock_timeout_ms' => 900,
					'lock_retry_delay_ms' => 15,
				],
				'search_base' => [
					'ttl_seconds' => 180,
					'stale_seconds' => 360,
				],
			],
		],
		'cache' => [
			'dir' => testCacheDir(),
			'token' => ['ttlSeconds' => 1200],
			'liveAvailability' => [
				'ttlSeconds' => 20,
			],
		],
	]);

	$overrideConfig = MerlinxGetterConfig::fromArray($override);
	assertSameValue('overridden', $overrideConfig->searchEngineName, 'Explicit search engine name override mismatch.');
	assertSameValue(['ABC', 'SNOW'], $overrideConfig->searchEngineOperators, 'Explicit operators override mismatch.');
	assertSameValue(1, count($overrideConfig->searchEngineConditions), 'Explicit conditions override mismatch.');
	assertSameValue('Test City', $overrideConfig->searchEngineConditions[0]['search']['Base']['XCity'] ?? null, 'Explicit conditions value mismatch.');
	assertSameValue(['available', 'onrequest'], $overrideConfig->inquiryableAvailabilityBases(), 'Explicit inquiryable bases override mismatch.');
	assertSameValue(7, $overrideConfig->inquiryableOnrequestMinDays(), 'Explicit inquiryable_onrequest_min_days override mismatch.');
	assertSameValue(['ABC'], $overrideConfig->childAsAdultOperators(), 'Explicit child_as_adult_operators override mismatch.');
	assertSameValue([
		'fieldValues.Base.XCity' => ['Zakopane', 'Saalbach'],
		'fieldValues.Accommodation.XCity' => ['zakopane'],
	], $overrideConfig->excludedValuesByPath(), 'Explicit excluded values-by-path override mismatch.');
	assertSameValue(1200, $overrideConfig->cacheTokenTtlSeconds, 'Explicit token ttl override mismatch.');
	assertSameValue(120, $overrideConfig->cacheSearchTtlSeconds, 'Explicit search ttl override mismatch.');
	assertSameValue(240, $overrideConfig->cacheSearchStaleSeconds, 'Explicit search stale override mismatch.');
	assertSameValue(180, $overrideConfig->cacheSearchBaseTtlSeconds, 'Explicit searchBase ttl override mismatch.');
	assertSameValue(360, $overrideConfig->cacheSearchBaseStaleSeconds, 'Explicit searchBase stale override mismatch.');
	assertSameValue(20, $overrideConfig->cacheLiveAvailabilityTtlSeconds, 'Explicit live availability ttl override mismatch.');
	assertSameValue(900, $overrideConfig->cacheSearchLockTimeoutMs, 'Explicit lockTimeoutMs override mismatch.');
	assertSameValue(15, $overrideConfig->cacheSearchLockRetryDelayMs, 'Explicit lockRetryDelayMs override mismatch.');
	assertSameValue(250, $overrideConfig->defaultViewLimit, 'Explicit default_view_limit override mismatch.');
	assertSameValue(9, $overrideConfig->defaultSearchOptions['rateLimitRetryMaxAttempts'] ?? null, 'Explicit retry attempts override mismatch.');
	assertSameValue(15, $overrideConfig->defaultSearchOptions['rateLimitRetryDelayMs'] ?? null, 'Explicit retry delay override mismatch.');
	assertSameValue(3.0, $overrideConfig->defaultSearchOptions['rateLimitRetryBackoffMultiplier'] ?? null, 'Explicit retry backoff override mismatch.');
	assertSameValue(90, $overrideConfig->defaultSearchOptions['rateLimitRetryMaxDelayMs'] ?? null, 'Explicit retry max delay override mismatch.');

	$defaultLiveAvailabilityTtlConfig = MerlinxGetterConfig::fromArray(array_merge($minimal, [
		'cache' => [
			'dir' => testCacheDir(),
			'token' => ['ttlSeconds' => 1800],
		],
	]));
	assertSameValue(30, $defaultLiveAvailabilityTtlConfig->cacheLiveAvailabilityTtlSeconds, 'Default live availability ttl mismatch.');

	$rootConfig = [
		'merlinx' => [
			'base_url' => 'https://mwsv5pro.merlinx.eu',
			'login' => 'root-login',
			'password' => 'root-password',
			'expedient' => 'root-expedient',
			'domain' => 'root.example',
			'source' => 'B2C',
			'type' => 'web',
			'language' => 'pl',
			'search_engine' => [
				'name' => 'root-profile',
				'operators' => ['ROOT', 'SNOW'],
				'conditions' => [
					[
						'search' => [
							'Accommodation' => [
								'Attributes' => ['-location_ski_resorts'],
							],
						],
						'filter' => [
							'Accommodation' => [
								'Attributes' => ['+facility_pool'],
							],
						],
					],
				],
				'availability_policy' => [
					'inquiryable_bases' => ['available', 'onrequest'],
					'onrequest_min_days' => 5,
				],
				'operator_policies' => [
					'child_as_adult_operators' => ['SNOW'],
				],
				'cache' => [
					'search' => [
						'ttl_seconds' => 111,
						'stale_seconds' => 222,
						'lock_timeout_ms' => 333,
						'lock_retry_delay_ms' => 44,
					],
				],
			],
			'cache' => [
				'token' => ['ttl' => 77],
				'search_base' => ['ttl' => 444],
				'live_availability' => ['ttl' => 55],
			],
		],
		'system' => [
			'cache' => [
				'dir' => testCacheDir(),
			],
		],
	];

	$rootParsed = MerlinxGetterConfig::fromRootConfig($rootConfig);
	assertSameValue('root-profile', $rootParsed->searchEngineName, 'fromRootConfig should read search_engine name.');
	assertSameValue(['ROOT', 'SNOW'], $rootParsed->searchEngineOperators, 'fromRootConfig should read search_engine operators.');
	assertSameValue(111, $rootParsed->cacheSearchTtlSeconds, 'fromRootConfig should read search_engine.cache.search.ttl_seconds.');
	assertSameValue(222, $rootParsed->cacheSearchStaleSeconds, 'fromRootConfig should read search_engine.cache.search.stale_seconds.');
	assertSameValue(444, $rootParsed->cacheSearchBaseTtlSeconds, 'fromRootConfig should map root search_base ttl.');
	assertSameValue(222, $rootParsed->cacheSearchBaseStaleSeconds, 'fromRootConfig should default searchBase stale ttl to search stale ttl.');
	assertSameValue(77, $rootParsed->cacheTokenTtlSeconds, 'fromRootConfig should map root token ttl.');
	assertSameValue(55, $rootParsed->cacheLiveAvailabilityTtlSeconds, 'fromRootConfig should map root live_availability ttl.');
	assertSameValue(['location_ski_resorts' => true, 'facility_pool' => true], $rootParsed->enforcedAccommodationAttributes(), 'Config should derive enforced accommodation attributes from search conditions.');

	$profile = $rootParsed->searchProfile();
	assertSameValue(['ROOT', 'SNOW'], $profile->operators(), 'Search profile should expose operators.');
	assertSameValue(['SNOW'], $profile->childAsAdultOperators(), 'Search profile should expose child-as-adult operators.');
	assertSameValue([
		[
			'search' => ['-location_ski_resorts'],
			'filter' => ['+facility_pool'],
		],
	], $profile->accommodationAttributeRulesByCondition(), 'Search profile should expose per-condition accommodation attribute rules with signs preserved.');
	assertSameValue(['available', 'onrequest'], $profile->inquiryableAvailabilityPolicy()->baseStatuses(), 'Search profile should expose inquiryable availability policy.');
	assertTrue($profile->scopeFingerprint() !== '', 'Search profile should expose non-empty scope fingerprint.');
	assertTrue($profile->fingerprint('test_schema_v1', ['foo' => 'bar']) !== '', 'Search profile should expose fingerprint helper.');

	echo "PASS: MerlinxGetterConfig resolves canonical search_engine config, root-config parsing, and search profile metadata.\n";
	exit(0);
} catch (Throwable $e) {
	echo "FAIL: " . $e->getMessage() . "\n";
	exit(1);
}

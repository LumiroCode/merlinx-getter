<?php

declare(strict_types=1);

namespace Skionline\MerlinxGetter\Config;

use Skionline\MerlinxGetter\Exception\ConfigException;
use Skionline\MerlinxGetter\Search\Profile\SearchEngineProfile;

final class MerlinxGetterConfig
{
	private const DEFAULT_API_BASE_URL = 'https://mwsv5pro.merlinx.eu';
	private const DEFAULT_CACHE_TOKEN_TTL_SECONDS = 30;
	private const DEFAULT_CACHE_SEARCH_TTL_SECONDS = 300;
	private const DEFAULT_CACHE_SEARCH_STALE_SECONDS = 900;
	private const DEFAULT_CACHE_DETAILS_TTL_SECONDS = 86400;
	private const DEFAULT_CACHE_LIVE_AVAILABILITY_TTL_SECONDS = 30;
	private const DEFAULT_SEARCH_LOCK_TIMEOUT_MS = 3000;
	private const DEFAULT_SEARCH_LOCK_RETRY_DELAY_MS = 50;
	private const DEFAULT_TIMEOUT = 5.0;
	private const DEFAULT_DEFAULT_VIEW_LIMIT = 100;
	private ?SearchEngineProfile $searchProfile = null;
	/** @var array<string, true>|null */
	private ?array $enforcedAccommodationAttributes = null;
	/** @var array<int, array{search: array<int, string>, filter: array<int, string>}>|null */
	private ?array $accommodationAttributeRulesByCondition = null;

	/**
	 * @param array<string, mixed> $defaultSearchOptions
	 * @param array<int, string> $searchEngineOperators
	 * @param array<int, array<string, mixed>> $searchEngineConditions
	 * @param array{inquiryable_bases: array<int, string>, onrequest_min_days: int} $searchEngineAvailabilityPolicy
	 * @param array{child_as_adult_operators: array<int, string>} $searchEngineOperatorPolicies
	 * @param array<string, array<int, string>> $searchEngineResponseFilters
	 */
	private function __construct(
		public readonly string $baseUrl,
		public readonly string $login,
		public readonly string $password,
		public readonly string $expedient,
		public readonly string $domain,
		public readonly string $source,
		public readonly string $type,
		public readonly string $language,
		public readonly string $cacheDir,
		public readonly int $cacheTokenTtlSeconds,
		public readonly int $cacheSearchTtlSeconds,
		public readonly int $cacheSearchStaleSeconds,
		public readonly int $cacheDetailsTtlSeconds,
		public readonly int $cacheSearchBaseTtlSeconds,
		public readonly int $cacheSearchBaseStaleSeconds,
		public readonly int $cacheLiveAvailabilityTtlSeconds,
		public readonly int $cacheSearchLockTimeoutMs,
		public readonly int $cacheSearchLockRetryDelayMs,
		public readonly float $timeout,
		public readonly int $defaultViewLimit,
		public readonly array $defaultSearchOptions,
		public readonly string $searchEngineName,
		public readonly array $searchEngineOperators,
		public readonly array $searchEngineConditions,
		public readonly array $searchEngineAvailabilityPolicy,
		public readonly array $searchEngineOperatorPolicies,
		public readonly array $searchEngineResponseFilters,
	) {
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public static function fromArray(array $config): self
	{
		$merlinx = is_array($config['merlinx'] ?? null) ? $config['merlinx'] : $config;
		$searchEngine = is_array($merlinx['search_engine'] ?? null) ? $merlinx['search_engine'] : null;
		if ($searchEngine === null) {
			throw new ConfigException('MerlinX search_engine config is required.');
		}

		$login = self::requiredString($merlinx, ['login'], 'MerlinX login is required.');
		$password = self::requiredString($merlinx, ['password'], 'MerlinX password is required.');
		$expedient = self::requiredString($merlinx, ['expedient'], 'MerlinX expedient is required.');
		$domain = self::requiredString($merlinx, ['domain'], 'MerlinX domain is required.');
		
		$baseUrl = self::optionalString($merlinx, ['baseUrl'], self::DEFAULT_API_BASE_URL);
		$source = self::optionalString($merlinx, ['source'], 'B2C');
		$type = self::optionalString($merlinx, ['type'], 'web');
		$language = self::optionalString($merlinx, ['language'], 'pl');
		$timeout = self::toPositiveFloat($merlinx['mdsws']['timeout'] ?? null, self::DEFAULT_TIMEOUT);

		$cache = is_array($merlinx['cache'] ?? null) ? $merlinx['cache'] : [];
		$searchCache = is_array($searchEngine['cache']['search'] ?? null) ? $searchEngine['cache']['search'] : [];
		$searchBaseCache = is_array($searchEngine['cache']['search_base'] ?? null) ? $searchEngine['cache']['search_base'] : [];
		$cacheDir = self::optionalString($cache, ['dir'], sys_get_temp_dir() . '/merlinx-cache');
		$searchTtlSeconds = self::optionalNestedPositiveInt($searchCache, ['ttl_seconds'], self::DEFAULT_CACHE_SEARCH_TTL_SECONDS);
		$searchStaleSeconds = self::optionalNestedPositiveInt($searchCache, ['stale_seconds'], self::DEFAULT_CACHE_SEARCH_STALE_SECONDS);

		$runtime = is_array($searchEngine['runtime'] ?? null) ? $searchEngine['runtime'] : [];
		$defaultSearchOptions = [
			'rateLimitRetryMaxAttempts' => self::optionalNestedNonNegativeInt($runtime, ['rate_limit_retry_max_attempts'], 4),
			'rateLimitRetryDelayMs' => self::optionalNestedNonNegativeInt($runtime, ['rate_limit_retry_delay_ms'], 500),
			'rateLimitRetryBackoffMultiplier' => self::toPositiveFloat($runtime['rate_limit_retry_backoff_multiplier'] ?? null, 2.0),
			'rateLimitRetryMaxDelayMs' => self::optionalNestedNonNegativeInt($runtime, ['rate_limit_retry_max_delay_ms'], 8000),
		];

		return new self(
			baseUrl: rtrim($baseUrl, '/'),
			login: $login,
			password: $password,
			expedient: $expedient,
			domain: $domain,
			source: $source,
			type: $type,
			language: $language,
			cacheDir: $cacheDir,
			cacheTokenTtlSeconds: self::optionalNestedPositiveInt($cache, ['token', 'ttlSeconds'], self::DEFAULT_CACHE_TOKEN_TTL_SECONDS),
			cacheSearchTtlSeconds: $searchTtlSeconds,
			cacheSearchStaleSeconds: $searchStaleSeconds,
			cacheDetailsTtlSeconds: self::optionalNestedPositiveInt($cache, ['details', 'ttlSeconds'], self::DEFAULT_CACHE_DETAILS_TTL_SECONDS),
			cacheSearchBaseTtlSeconds: self::optionalNestedPositiveInt($searchBaseCache, ['ttl_seconds'], $searchTtlSeconds),
			cacheSearchBaseStaleSeconds: self::optionalNestedPositiveInt($searchBaseCache, ['stale_seconds'], $searchStaleSeconds),
			cacheLiveAvailabilityTtlSeconds: self::optionalNestedPositiveInt($cache, ['liveAvailability', 'ttlSeconds'], self::DEFAULT_CACHE_LIVE_AVAILABILITY_TTL_SECONDS),
			cacheSearchLockTimeoutMs: self::optionalNestedPositiveInt($searchCache, ['lock_timeout_ms'], self::DEFAULT_SEARCH_LOCK_TIMEOUT_MS),
			cacheSearchLockRetryDelayMs: self::optionalNestedPositiveInt($searchCache, ['lock_retry_delay_ms'], self::DEFAULT_SEARCH_LOCK_RETRY_DELAY_MS),
			timeout: $timeout,
			defaultViewLimit: self::optionalNestedPositiveInt($runtime, ['default_view_limit'], self::DEFAULT_DEFAULT_VIEW_LIMIT),
			defaultSearchOptions: $defaultSearchOptions,
			searchEngineName: self::optionalString($searchEngine, ['name'], 'default'),
			searchEngineOperators: self::resolveStringList(
				$searchEngine,
				[['operators']],
				[],
			),
			searchEngineConditions: self::resolveConditions(
				$searchEngine,
				[['conditions']],
				[[]],
			),
			searchEngineAvailabilityPolicy: self::resolveAvailabilityPolicy($searchEngine),
			searchEngineOperatorPolicies: self::resolveOperatorPolicies($searchEngine),
			searchEngineResponseFilters: self::resolveResponseFilters($searchEngine),
		);
	}

	/**
	 * @param array<string, mixed> $rootConfig
	 */
	public static function fromRootConfig(array $rootConfig): self
	{
		$merlinx = is_array($rootConfig['merlinx'] ?? null) ? $rootConfig['merlinx'] : [];
		$searchEngine = is_array($merlinx['search_engine'] ?? null) ? $merlinx['search_engine'] : [];
		$system = is_array($rootConfig['system'] ?? null) ? $rootConfig['system'] : [];
		$systemCache = is_array($system['cache'] ?? null) ? $system['cache'] : [];
		$cache = is_array($merlinx['cache'] ?? null) ? $merlinx['cache'] : [];
		$tokenCache = is_array($cache['token'] ?? null) ? $cache['token'] : [];
		$detailsCache = is_array($cache['details'] ?? null) ? $cache['details'] : [];
		$searchBaseCache = is_array($cache['search_base'] ?? null) ? $cache['search_base'] : [];
		$liveAvailabilityCache = is_array($cache['live_availability'] ?? null) ? $cache['live_availability'] : [];
		$searchEngineCache = is_array($searchEngine['cache'] ?? null) ? $searchEngine['cache'] : [];
		$searchCache = is_array($searchEngineCache['search'] ?? null) ? $searchEngineCache['search'] : [];

		return self::fromArray([
			'merlinx' => [
				'base_url' => $merlinx['base_url'] ?? null,
				'login' => $merlinx['login'] ?? null,
				'password' => $merlinx['password'] ?? null,
				'expedient' => $merlinx['expedient'] ?? null,
				'domain' => $merlinx['domain'] ?? null,
				'source' => $merlinx['source'] ?? null,
				'type' => $merlinx['type'] ?? null,
				'language' => $merlinx['language'] ?? null,
				'timeout' => $merlinx['timeout'] ?? null,
				'cache' => [
					'dir' => $systemCache['dir'] ?? null,
					'token' => [
						'ttlSeconds' => $tokenCache['ttl'] ?? null,
					],
					'details' => [
						'ttlSeconds' => $detailsCache['ttl'] ?? null,
					],
					'liveAvailability' => [
						'ttlSeconds' => $liveAvailabilityCache['ttl'] ?? null,
					],
				],
				'search_engine' => [
					'name' => $searchEngine['name'] ?? null,
					'operators' => $searchEngine['operators'] ?? null,
					'conditions' => $searchEngine['conditions'] ?? null,
					'availability_policy' => $searchEngine['availability_policy'] ?? null,
					'operator_policies' => $searchEngine['operator_policies'] ?? null,
					'response_filters' => $searchEngine['response_filters'] ?? null,
					'runtime' => $searchEngine['runtime'] ?? null,
					'cache' => [
						'search' => $searchCache,
						'search_base' => [
							'ttl_seconds' => $searchBaseCache['ttl'] ?? null,
							'stale_seconds' => $searchBaseCache['stale'] ?? null,
						],
					],
				],
			],
		]);
	}

	/**
	 * @return array<int, string>
	 */
	public function inquiryableAvailabilityBases(): array
	{
		return $this->searchEngineAvailabilityPolicy['inquiryable_bases'];
	}

	public function inquiryableOnrequestMinDays(): int
	{
		return $this->searchEngineAvailabilityPolicy['onrequest_min_days'];
	}

	/**
	 * @return array<int, string>
	 */
	public function childAsAdultOperators(): array
	{
		return $this->searchEngineOperatorPolicies['child_as_adult_operators'];
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function excludedValuesByPath(): array
	{
		return $this->searchEngineResponseFilters;
	}

	public function searchProfile(): SearchEngineProfile
	{
		if (!$this->searchProfile instanceof SearchEngineProfile) {
			$this->searchProfile = new SearchEngineProfile($this);
		}

		return $this->searchProfile;
	}

	/**
	 * @return array<int, array{search: array<int, string>, filter: array<int, string>}>
	 */
	public function accommodationAttributeRulesByCondition(): array
	{
		if (is_array($this->accommodationAttributeRulesByCondition)) {
			return $this->accommodationAttributeRulesByCondition;
		}

		$rulesByCondition = [];
		foreach ($this->searchEngineConditions as $condition) {
			if (!is_array($condition)) {
				continue;
			}

			$searchScope = is_array($condition['search'] ?? null) ? $condition['search'] : [];
			$filterScope = is_array($condition['filter'] ?? null) ? $condition['filter'] : [];
			$searchAccommodation = is_array($searchScope['Accommodation'] ?? null) ? $searchScope['Accommodation'] : [];
			$filterAccommodation = is_array($filterScope['Accommodation'] ?? null) ? $filterScope['Accommodation'] : [];

			$rulesByCondition[] = [
				'search' => $this->normalizeConfiguredAttributeRules(
					$searchAccommodation['Attributes'] ?? null
				),
				'filter' => $this->normalizeConfiguredAttributeRules(
					$filterAccommodation['Attributes'] ?? null
				),
			];
		}

		$this->accommodationAttributeRulesByCondition = $rulesByCondition === []
			? [['search' => [], 'filter' => []]]
			: $rulesByCondition;

		return $this->accommodationAttributeRulesByCondition;
	}

	/**
	 * @return array<string, true>
	 */
	public function enforcedAccommodationAttributes(): array
	{
		if (is_array($this->enforcedAccommodationAttributes)) {
			return $this->enforcedAccommodationAttributes;
		}

		$enforced = [];
		foreach ($this->accommodationAttributeRulesByCondition() as $condition) {
			foreach (['search', 'filter'] as $scopeKey) {
				foreach ($condition[$scopeKey] ?? [] as $attribute) {
					$normalized = self::normalizeConfiguredAttribute($attribute);
					if ($normalized === '') {
						continue;
					}

					$enforced[$normalized] = true;
				}
			}
		}

		$this->enforcedAccommodationAttributes = $enforced;

		return $this->enforcedAccommodationAttributes;
	}

	/**
	 * @param array<string, mixed> $searchEngine
	 * @return array{inquiryable_bases: array<int, string>, onrequest_min_days: int}
	 */
	private static function resolveAvailabilityPolicy(array $searchEngine): array
	{
		$policy = is_array($searchEngine['availability_policy'] ?? null) ? $searchEngine['availability_policy'] : [];

		return [
			'inquiryable_bases' => self::resolveStringList($policy, [['inquiryable_bases']], []),
			'onrequest_min_days' => self::resolveNonNegativeInt($policy, [['onrequest_min_days']], 0),
		];
	}

	/**
	 * @param array<string, mixed> $searchEngine
	 * @return array{child_as_adult_operators: array<int, string>}
	 */
	private static function resolveOperatorPolicies(array $searchEngine): array
	{
		$policy = is_array($searchEngine['operator_policies'] ?? null) ? $searchEngine['operator_policies'] : [];

		return [
			'child_as_adult_operators' => self::resolveStringList($policy, [['child_as_adult_operators']], []),
		];
	}

	/**
	 * @param array<string, mixed> $searchEngine
	 * @return array<string, array<int, string>>
	 */
	private static function resolveResponseFilters(array $searchEngine): array
	{
		$filters = is_array($searchEngine['response_filters'] ?? null) ? $searchEngine['response_filters'] : [];
		$pathMap = self::resolveStringListMap($filters, [['exclude_values_by_path']], []);

		return self::normalizeStringListMap($pathMap);
	}

	/**
	 * @param array<string, mixed> $source
	 * @param array<int, string> $keys
	 */
	private static function requiredString(array $source, array $keys, string $error): string
	{
		$value = self::optionalString($source, $keys, '');
		if ($value === '') {
			throw new ConfigException($error);
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $source
	 * @param array<int, string> $keys
	 */
	private static function optionalString(array $source, array $keys, string $default): string
	{
		foreach ($keys as $key) {
			$value = $source[$key] ?? null;
			if (!is_string($value) && !is_int($value) && !is_float($value)) {
				continue;
			}

			$normalized = trim((string) $value);
			if ($normalized !== '') {
				return $normalized;
			}
		}

		return $default;
	}

	/**
	 * @param array<string, mixed> $source
	 * @param array<int, string> $path
	 */
	private static function optionalNestedPositiveInt(array $source, array $path, int $default): int
	{
		$value = self::getNestedValue($source, $path);
		if (is_int($value) && $value > 0) {
			return $value;
		}
		if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
			return (int) $value;
		}

		return $default;
	}

	/**
	 * @param array<string, mixed> $source
	 * @param array<int, string> $path
	 */
	private static function optionalNestedNonNegativeInt(array $source, array $path, int $default): int
	{
		$value = self::getNestedValue($source, $path);
		if (is_int($value) && $value >= 0) {
			return $value;
		}
		if (is_string($value) && ctype_digit($value)) {
			return (int) $value;
		}

		return $default;
	}

	/**
	 * @param array<string, mixed> $source
	 * @param array<int, array<int, string>> $paths
	 * @param array<int, string> $default
	 * @return array<int, string>
	 */
	private static function resolveStringList(array $source, array $paths, array $default): array
	{
		foreach ($paths as $path) {
			if (!self::hasNestedKey($source, $path)) {
				continue;
			}

			return self::normalizeStringList(self::getNestedValue($source, $path));
		}

		return self::normalizeStringList($default);
	}

	/**
	 * @param array<string, mixed> $source
	 * @param array<int, array<int, string>> $paths
	 * @param array<string, array<int, string>> $default
	 * @return array<string, array<int, string>>
	 */
	private static function resolveStringListMap(array $source, array $paths, array $default): array
	{
		foreach ($paths as $path) {
			if (!self::hasNestedKey($source, $path)) {
				continue;
			}

			return self::normalizeStringListMap(self::getNestedValue($source, $path));
		}

		return self::normalizeStringListMap($default);
	}

	/**
	 * @param array<string, mixed> $source
	 * @param array<int, array<int, string>> $paths
	 * @param array<int, array<string, mixed>> $default
	 * @return array<int, array<string, mixed>>
	 */
	private static function resolveConditions(array $source, array $paths, array $default): array
	{
		foreach ($paths as $path) {
			if (!self::hasNestedKey($source, $path)) {
				continue;
			}

			return self::normalizeConditions(self::getNestedValue($source, $path));
		}

		return self::normalizeConditions($default);
	}

	/**
	 * @param array<string, mixed> $source
	 * @param array<int, array<int, string>> $paths
	 */
	private static function resolveNonNegativeInt(array $source, array $paths, int $default): int
	{
		foreach ($paths as $path) {
			if (!self::hasNestedKey($source, $path)) {
				continue;
			}

			$value = self::getNestedValue($source, $path);
			if (is_int($value) && $value >= 0) {
				return $value;
			}
			if (is_string($value) && ctype_digit($value)) {
				return (int) $value;
			}
		}

		return $default;
	}

	/**
	 * @return array<int, string>
	 */
	private static function normalizeStringList(mixed $raw): array
	{
		if (!is_array($raw)) {
			return [];
		}

		$normalized = [];
		foreach ($raw as $value) {
			if (!is_string($value) && !is_int($value) && !is_float($value)) {
				continue;
			}

			$value = trim((string) $value);
			if ($value === '' || in_array($value, $normalized, true)) {
				continue;
			}

			$normalized[] = $value;
		}

		return $normalized;
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	private static function normalizeStringListMap(mixed $raw): array
	{
		if (!is_array($raw)) {
			return [];
		}

		$normalized = [];
		foreach ($raw as $path => $values) {
			if (!is_string($path) && !is_int($path)) {
				continue;
			}

			$path = trim((string) $path);
			if ($path === '') {
				continue;
			}

			$existing = is_array($normalized[$path] ?? null) ? $normalized[$path] : [];
			$normalized[$path] = self::normalizeStringList(array_merge($existing, self::normalizeStringList($values)));
		}

		return $normalized;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalizeConditions(mixed $raw): array
	{
		if (!is_array($raw)) {
			return [['search' => [], 'filter' => [], 'results' => [], 'views' => []]];
		}

		$variants = array_is_list($raw) ? $raw : [$raw];
		$normalized = [];
		foreach ($variants as $variant) {
			if (!is_array($variant)) {
				continue;
			}

			$normalized[] = [
				'search' => is_array($variant['search'] ?? null) ? $variant['search'] : [],
				'filter' => is_array($variant['filter'] ?? null) ? $variant['filter'] : [],
				'results' => is_array($variant['results'] ?? null) ? $variant['results'] : [],
				'views' => is_array($variant['views'] ?? null) ? $variant['views'] : [],
			];
		}

		return $normalized === [] ? [['search' => [], 'filter' => [], 'results' => [], 'views' => []]] : $normalized;
	}

	private static function normalizeConfiguredAttribute(mixed $attribute): string
	{
		if (!is_string($attribute) && !is_int($attribute) && !is_float($attribute)) {
			return '';
		}

		$attribute = trim((string) $attribute);
		if ($attribute === '') {
			return '';
		}

		return ltrim($attribute, '+-');
	}

	/**
	 * @return array<int, string>
	 */
	private function normalizeConfiguredAttributeRules(mixed $attributes): array
	{
		if (!is_array($attributes)) {
			return [];
		}

		$normalized = [];
		foreach ($attributes as $attribute) {
			$rule = self::normalizeConfiguredAttributeRule($attribute);
			if ($rule === '' || isset($normalized[$rule])) {
				continue;
			}

			$normalized[$rule] = true;
		}

		return array_values(array_keys($normalized));
	}

	private static function normalizeConfiguredAttributeRule(mixed $attribute): string
	{
		if (!is_string($attribute) && !is_int($attribute) && !is_float($attribute)) {
			return '';
		}

		$attribute = trim((string) $attribute);
		if ($attribute === '') {
			return '';
		}

		$sign = $attribute[0] ?? '';
		$code = ltrim($attribute, '+-');
		if ($code === '') {
			return '';
		}

		return ($sign === '-' ? '-' : '+') . $code;
	}

	private static function toPositiveFloat(mixed $value, float $default): float
	{
		if (is_float($value) || is_int($value)) {
			return $value > 0 ? (float) $value : $default;
		}
		if (is_string($value) && is_numeric($value)) {
			$parsed = (float) $value;
			return $parsed > 0 ? $parsed : $default;
		}

		return $default;
	}

	/**
	 * @param array<string, mixed> $source
	 * @param array<int, string> $path
	 */
	private static function hasNestedKey(array $source, array $path): bool
	{
		$current = $source;
		$lastIndex = count($path) - 1;
		foreach ($path as $index => $segment) {
			if (!is_array($current) || !array_key_exists($segment, $current)) {
				return false;
			}
			if ($index === $lastIndex) {
				return true;
			}
			$current = $current[$segment];
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $source
	 * @param array<int, string> $path
	 */
	private static function getNestedValue(array $source, array $path): mixed
	{
		$current = $source;
		foreach ($path as $segment) {
			if (!is_array($current) || !array_key_exists($segment, $current)) {
				return null;
			}
			$current = $current[$segment];
		}

		return $current;
	}
}

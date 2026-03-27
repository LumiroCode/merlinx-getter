<?php

declare(strict_types=1);

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoloadPath)) {
	throw new RuntimeException('Package dependencies are not installed. Run composer install in the package root.');
}

require $autoloadPath;

spl_autoload_register(static function (string $class): void {
	$prefix = 'Skionline\\MerlinxGetter\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}

	$relative = substr($class, strlen($prefix));
	$relativePath = str_replace('\\', '/', $relative) . '.php';
	$path = __DIR__ . '/../src/' . $relativePath;
	if (is_file($path)) {
		require $path;
	}
});

function assertTrue(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
	if ($expected !== $actual) {
		throw new RuntimeException($message . ' Expected: ' . var_export($expected, true) . ' Actual: ' . var_export($actual, true));
	}
}

/**
 * @return array<string, mixed>
 */
function fixtureJson(string $relativePath): array
{
	$path = __DIR__ . '/fixtures/' . ltrim($relativePath, '/');
	if (!is_file($path)) {
		throw new RuntimeException('Fixture file does not exist: ' . $path);
	}

	$content = file_get_contents($path);
	if (!is_string($content) || trim($content) === '') {
		throw new RuntimeException('Fixture file is empty: ' . $path);
	}

	try {
		$decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
	} catch (JsonException $e) {
		throw new RuntimeException('Fixture JSON decode failed: ' . $path, 0, $e);
	}

	if (!is_array($decoded)) {
		throw new RuntimeException('Fixture JSON root must be an object/array: ' . $path);
	}

	return $decoded;
}

/**
 * @param class-string<Throwable> $expectedClass
 * @param callable(Throwable):void|null $extraAssert
 */
function assertThrows(callable $fn, string $expectedClass, ?callable $extraAssert = null): void
{
	try {
		$fn();
	} catch (Throwable $e) {
		if (!$e instanceof $expectedClass) {
			throw new RuntimeException(
				'Unexpected exception class. Expected: ' . $expectedClass . ' Actual: ' . $e::class . ' Message: ' . $e->getMessage()
			);
		}

		if ($extraAssert !== null) {
			$extraAssert($e);
		}

		return;
	}

	throw new RuntimeException('Expected exception was not thrown: ' . $expectedClass);
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>|null
 */
function extractJsonPayload(array $options): ?array
{
	$payload = $options['json'] ?? null;
	if (is_object($payload)) {
		$payload = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
	}
	if (is_array($payload)) {
		return $payload;
	}

	$body = $options['body'] ?? null;
	if (is_string($body) && $body !== '') {
		$decoded = json_decode($body, true);
		if (is_array($decoded)) {
			return $decoded;
		}
	}

	return null;
}

function testCacheDir(): string
{
	static $cacheDir = null;
	if (is_string($cacheDir) && $cacheDir !== '') {
		return $cacheDir;
	}

	$cacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
		. DIRECTORY_SEPARATOR
		. 'merlinx-getter-tests-'
		. str_replace('.', '-', uniqid('', true));

	if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
		throw new RuntimeException('Unable to create test cache directory.');
	}

	return $cacheDir;
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function baseMerlinxConfig(array $overrides = []): array
{
	$base = [
		'base_url' => 'https://mwsv5pro.merlinx.eu',
		'login' => 'dummy',
		'password' => 'dummy',
		'expedient' => 'dummy',
		'domain' => 'example.com',
		'source' => 'B2C',
		'type' => 'web',
		'language' => 'pl',
		'search_engine' => [
			'name' => 'test-profile',
			'operators' => ['SNOW'],
			'conditions' => [
				[
					'search' => [],
					'filter' => [],
				],
			],
			'availability_policy' => [
				'inquiryable_bases' => ['available', 'onrequest', 'unknown'],
				'onrequest_min_days' => 21,
			],
			'operator_policies' => [
				'child_as_adult_operators' => ['SNOW'],
			],
			'response_filters' => [],
			'cache' => [
				'search' => [
					'ttl_seconds' => 600,
					'stale_seconds' => 900,
					'lock_timeout_ms' => 3000,
					'lock_retry_delay_ms' => 50,
				],
			],
		],
		'cache' => [
			'dir' => testCacheDir(),
			'token' => ['ttlSeconds' => 600],
			'liveAvailability' => ['ttlSeconds' => 30],
		],
	];

	return array_replace_recursive($base, $overrides);
}

/**
 * @param array<string, mixed> $search
 * @param array<string, mixed> $filter
 * @param array<string, mixed> $results
 * @param array<string, mixed> $views
 * @param array<string, mixed> $options
 */
function searchRequest(
	array $search = [],
	array $filter = [],
	array $results = [],
	array $views = ['offerList' => []],
	array $options = [],
): \Skionline\MerlinxGetter\Search\Execution\SearchExecutionRequest {
	return \Skionline\MerlinxGetter\Search\Execution\SearchExecutionRequest::fromArrays(
		$search,
		$filter,
		$results,
		$views,
		$options,
	);
}

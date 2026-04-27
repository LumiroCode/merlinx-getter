<?php

declare(strict_types=1);

namespace Skionline\MerlinxGetter\Search\Execution;

final class ScopedConditionResolver
{
	/**
	 * @param array<string, mixed> $scope
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>|null
	 */
	public static function resolve(array $scope, array $request): ?array
	{
		return self::combineMaps($scope, $request);
	}

	/**
	 * @param array<string|int, mixed> $scope
	 * @param array<string|int, mixed> $request
	 * @param array<int, string> $path
	 * @return array<string|int, mixed>|null
	 */
	private static function combineMaps(array $scope, array $request, array $path = []): ?array
	{
		$resolved = [];
		$keys = [];

		foreach ($scope as $key => $_) {
			$keys[$key] = true;
		}
		foreach ($request as $key => $_) {
			$keys[$key] = true;
		}

		foreach (array_keys($keys) as $key) {
			$childPath = [...$path, (string) $key];
			$scopeHasKey = array_key_exists($key, $scope);
			$requestHasKey = array_key_exists($key, $request);
			[$satisfied, $value] = self::combinePresence(
				$childPath,
				$scopeHasKey,
				$scopeHasKey ? $scope[$key] : null,
				$requestHasKey,
				$requestHasKey ? $request[$key] : null,
			);

			if (!$satisfied) {
				return null;
			}

			if (!self::isNoConstraint($value)) {
				$resolved[$key] = $value;
			}
		}

		return $resolved;
	}

	/**
	 * @param array<int, string> $path
	 * @return array{0: bool, 1: mixed}
	 */
	private static function combinePresence(
		array $path,
		bool $scopeHasKey,
		mixed $scopeValue,
		bool $requestHasKey,
		mixed $requestValue,
	): array {
		if (self::isAccommodationAttributesPath($path)) {
			return [
				true,
				self::mergeAccommodationAttributeRules(
					$scopeHasKey && !self::isNoConstraint($scopeValue) ? $scopeValue : [],
					$requestHasKey && !self::isNoConstraint($requestValue) ? $requestValue : [],
				),
			];
		}

		if (!$scopeHasKey || self::isNoConstraint($scopeValue)) {
			return [true, $requestHasKey ? $requestValue : null];
		}

		if (!$requestHasKey || self::isNoConstraint($requestValue)) {
			return [true, $scopeValue];
		}

		return self::combineValues($path, $scopeValue, $requestValue);
	}

	/**
	 * @param array<int, string> $path
	 * @return array{0: bool, 1: mixed}
	 */
	private static function combineValues(array $path, mixed $scopeValue, mixed $requestValue): array
	{
		$scopeRange = is_array($scopeValue) ? self::normalizeRange($scopeValue) : null;
		$requestRange = is_array($requestValue) ? self::normalizeRange($requestValue) : null;
		if ($scopeRange !== null || $requestRange !== null) {
			return self::combineRangeValues($scopeRange, $scopeValue, $requestRange, $requestValue);
		}

		if (is_array($scopeValue) && is_array($requestValue)) {
			$scopeIsList = array_is_list($scopeValue);
			$requestIsList = array_is_list($requestValue);

			if ($scopeIsList && $requestIsList) {
				return self::combineLists($scopeValue, $requestValue);
			}

			if (!$scopeIsList && !$requestIsList) {
				$resolved = self::combineMaps($scopeValue, $requestValue, $path);
				return $resolved === null ? [false, null] : [true, $resolved];
			}

			return [false, null];
		}

		if (is_array($scopeValue)) {
			if (!array_is_list($scopeValue)) {
				return [false, null];
			}

			return self::combineListWithScalar($scopeValue, $requestValue);
		}

		if (is_array($requestValue)) {
			if (!array_is_list($requestValue)) {
				return [false, null];
			}

			[$satisfied] = self::combineListWithScalar($requestValue, $scopeValue);
			return $satisfied ? [true, $scopeValue] : [false, null];
		}

		return self::normalizeComparableValue($scopeValue) === self::normalizeComparableValue($requestValue)
			? [true, $scopeValue]
			: [false, null];
	}

	/**
	 * @param array{Min?: mixed, Max?: mixed}|null $scopeRange
	 * @param array{Min?: mixed, Max?: mixed}|null $requestRange
	 * @return array{0: bool, 1: mixed}
	 */
	private static function combineRangeValues(?array $scopeRange, mixed $scopeValue, ?array $requestRange, mixed $requestValue): array
	{
		if ($scopeRange !== null && $requestRange !== null) {
			return self::combineRanges($scopeRange, $requestRange);
		}

		if ($scopeRange !== null) {
			return self::combineRangeWithScalar($scopeRange, $requestValue);
		}

		if ($requestRange !== null) {
			return self::combineRangeWithScalar($requestRange, $scopeValue);
		}

		return [false, null];
	}

	/**
	 * @param array{Min?: mixed, Max?: mixed} $scopeRange
	 * @param array{Min?: mixed, Max?: mixed} $requestRange
	 * @return array{0: bool, 1: mixed}
	 */
	private static function combineRanges(array $scopeRange, array $requestRange): array
	{
		$min = null;
		$max = null;

		if (array_key_exists('Min', $scopeRange)) {
			$min = $scopeRange['Min'];
		}
		if (array_key_exists('Min', $requestRange) && ($min === null || self::compareRangeScalars($requestRange['Min'], $min) > 0)) {
			$min = $requestRange['Min'];
		}

		if (array_key_exists('Max', $scopeRange)) {
			$max = $scopeRange['Max'];
		}
		if (array_key_exists('Max', $requestRange) && ($max === null || self::compareRangeScalars($requestRange['Max'], $max) < 0)) {
			$max = $requestRange['Max'];
		}

		if ($min !== null && $max !== null && self::compareRangeScalars($min, $max) > 0) {
			return [false, null];
		}

		$resolved = [];
		if ($min !== null) {
			$resolved['Min'] = $min;
		}
		if ($max !== null) {
			$resolved['Max'] = $max;
		}

		return $resolved === [] ? [false, null] : [true, $resolved];
	}

	/**
	 * @param array{Min?: mixed, Max?: mixed} $range
	 * @return array{0: bool, 1: mixed}
	 */
	private static function combineRangeWithScalar(array $range, mixed $value): array
	{
		if (!self::isRangeScalar($value)) {
			return [false, null];
		}

		if (array_key_exists('Min', $range) && self::compareRangeScalars($value, $range['Min']) < 0) {
			return [false, null];
		}

		if (array_key_exists('Max', $range) && self::compareRangeScalars($value, $range['Max']) > 0) {
			return [false, null];
		}

		return [true, $value];
	}

	/**
	 * @param array<string|int, mixed> $value
	 * @return array{Min?: mixed, Max?: mixed}|null
	 */
	private static function normalizeRange(array $value): ?array
	{
		if (array_is_list($value)) {
			return null;
		}

		foreach (array_keys($value) as $key) {
			if ($key !== 'Min' && $key !== 'Max') {
				return null;
			}
		}

		$hasMin = array_key_exists('Min', $value);
		$hasMax = array_key_exists('Max', $value);
		if (!$hasMin && !$hasMax) {
			return null;
		}

		$range = [];
		if ($hasMin) {
			if (!self::isRangeScalar($value['Min'])) {
				return null;
			}
			$range['Min'] = $value['Min'];
		}
		if ($hasMax) {
			if (!self::isRangeScalar($value['Max'])) {
				return null;
			}
			$range['Max'] = $value['Max'];
		}

		if (
			array_key_exists('Min', $range)
			&& array_key_exists('Max', $range)
			&& self::compareRangeScalars($range['Min'], $range['Max']) > 0
		) {
			return null;
		}

		return $range;
	}

	private static function isRangeScalar(mixed $value): bool
	{
		return is_string($value) || is_int($value) || is_float($value);
	}

	private static function compareRangeScalars(mixed $left, mixed $right): int
	{
		if (is_numeric($left) && is_numeric($right)) {
			return (float) $left <=> (float) $right;
		}

		return strcmp((string) $left, (string) $right);
	}

	/**
	 * @param array<int, mixed> $scope
	 * @param array<int, mixed> $request
	 * @return array{0: bool, 1: mixed}
	 */
	private static function combineLists(array $scope, array $request): array
	{
		$requestValues = [];
		foreach ($request as $value) {
			$requestValues[self::normalizeComparableValue($value)] = true;
		}

		$resolved = [];
		$seen = [];
		foreach ($scope as $value) {
			$normalized = self::normalizeComparableValue($value);
			if (!isset($requestValues[$normalized]) || isset($seen[$normalized])) {
				continue;
			}

			$seen[$normalized] = true;
			$resolved[] = $value;
		}

		return $resolved === [] ? [false, null] : [true, $resolved];
	}

	/**
	 * @param array<int, mixed> $list
	 * @return array{0: bool, 1: mixed}
	 */
	private static function combineListWithScalar(array $list, mixed $value): array
	{
		$needle = self::normalizeComparableValue($value);
		foreach ($list as $item) {
			if (self::normalizeComparableValue($item) === $needle) {
				return [true, [$item]];
			}
		}

		return [false, null];
	}

	private static function isNoConstraint(mixed $value): bool
	{
		return $value === null || $value === [];
	}

	private static function normalizeComparableValue(mixed $value): string
	{
		if (!is_array($value)) {
			return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
		}

		if (array_is_list($value)) {
			$normalized = [];
			foreach ($value as $item) {
				$normalized[] = self::normalizeComparableValue($item);
			}

			sort($normalized);
			return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
		}

		ksort($value);
		$normalized = [];
		foreach ($value as $key => $item) {
			$normalized[(string) $key] = self::normalizeComparableValue($item);
		}

		return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
	}

	/**
	 * @param array<int, string> $path
	 */
	private static function isAccommodationAttributesPath(array $path): bool
	{
		return $path === ['Accommodation', 'Attributes'];
	}

	/**
	 * @return array<int, string>
	 */
	private static function mergeAccommodationAttributeRules(mixed $scopeValue, mixed $requestValue): array
	{
		$rulesByBaseCode = [];
		$normalizedSources = [
			self::normalizeAccommodationAttributeRules($scopeValue),
			self::normalizeAccommodationAttributeRules($requestValue),
		];

		foreach (['', '+', '-'] as $sign) {
			foreach ($normalizedSources as $rules) {
				foreach ($rules as $rule) {
					if ($rule['sign'] !== $sign) {
						continue;
					}

					$rulesByBaseCode[$rule['baseCode']] = $rule['rule'];
				}
			}
		}

		return array_values($rulesByBaseCode);
	}

	/**
	 * @return array<int, array{sign: string, baseCode: string, rule: string}>
	 */
	private static function normalizeAccommodationAttributeRules(mixed $value): array
	{
		$values = is_array($value)
			? (array_is_list($value) ? $value : array_values($value))
			: [$value];

		$rules = [];
		foreach ($values as $attribute) {
			if (!is_string($attribute) && !is_int($attribute) && !is_float($attribute)) {
				continue;
			}

			$raw = trim((string) $attribute);
			if ($raw === '') {
				continue;
			}

			$first = $raw[0] ?? '';
			$sign = $first === '+' || $first === '-' ? $first : '';
			$baseCode = ltrim($raw, '+-');
			if ($baseCode === '') {
				continue;
			}

			$rules[] = [
				'sign' => $sign,
				'baseCode' => $baseCode,
				'rule' => $sign . $baseCode,
			];
		}

		return $rules;
	}
}

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

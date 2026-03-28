<?php

declare(strict_types=1);

namespace Skionline\MerlinxGetter\Search\Util;

final class ConfiguredResponseValueExcluder
{
	/** @var array<string, array<string, true>> */
	private readonly array $excludedValuesByPathCanonicalMap;
	/** @var array<string, array<string, true>> */
	private readonly array $excludedFieldValuesByFieldCanonicalMap;

	/**
	 * @param array<string, array<int, string>> $excludedValuesByPath
	 */
	public function __construct(array $excludedValuesByPath)
	{
		$itemPathMap = [];
		$fieldValueMap = [];

		foreach ($excludedValuesByPath as $path => $values) {
			if (!is_string($path) || trim($path) === '') {
				continue;
			}

			$canonicalValues = $this->canonicalizeValues($values);
			if ($canonicalValues === []) {
				continue;
			}

			$path = trim($path);
			if (str_starts_with($path, 'fieldValues.')) {
				$fieldKey = substr($path, strlen('fieldValues.'));
				if ($fieldKey === '') {
					continue;
				}

				$fieldValueMap[$fieldKey] = $canonicalValues;
				continue;
			}

			$itemPathMap[$path] = $canonicalValues;
		}

		$this->excludedValuesByPathCanonicalMap = $itemPathMap;
		$this->excludedFieldValuesByFieldCanonicalMap = $fieldValueMap;
	}

	public function hasExclusions(): bool
	{
		return $this->excludedValuesByPathCanonicalMap !== []
			|| $this->excludedFieldValuesByFieldCanonicalMap !== [];
	}

	/**
	 * @param array<string, mixed> $response
	 * @return array<string, mixed>
	 */
	public function apply(array $response): array
	{
		if (!$this->hasExclusions()) {
			return $response;
		}

		foreach ($response as $viewName => $view) {
			if (!is_string($viewName) || !is_array($view)) {
				continue;
			}

			if (($viewName === 'fieldValues' || $viewName === 'unfilteredFieldValues')
				&& $this->excludedFieldValuesByFieldCanonicalMap !== []
			) {
				$response[$viewName] = $this->applyFieldValuesView($view);
			}

			if ($this->excludedValuesByPathCanonicalMap !== []) {
				$response[$viewName] = $this->applyItemsView($response[$viewName] ?? []);
			}
		}

		return $response;
	}

	/**
	 * @param array<string, mixed> $view
	 * @return array<string, mixed>
	 */
	private function applyItemsView(array $view): array
	{
		$items = $view['items'] ?? null;
		if (!is_array($items)) {
			return $view;
		}

		$filteredItems = [];
		foreach ($items as $itemKey => $item) {
			if (!is_array($item)) {
				$filteredItems[$itemKey] = $item;
				continue;
			}

			if ($this->shouldExcludeItem($item)) {
				continue;
			}

			$filteredItems[$itemKey] = $item;
		}

		$view['items'] = $filteredItems;
		return $view;
	}

	/**
	 * @param array<string, mixed> $view
	 * @return array<string, mixed>
	 */
	private function applyFieldValuesView(array $view): array
	{
		if (is_array($view['fieldValues'] ?? null)) {
			$view['fieldValues'] = $this->applyFieldValueMap($view['fieldValues']);
			return $view;
		}

		if (is_array($view['values'] ?? null)) {
			$view['values'] = $this->applyFieldValueMap($view['values']);
			return $view;
		}

		return $this->applyFieldValueMap($view);
	}

	/**
	 * @param array<string, mixed> $fieldValues
	 * @return array<string, mixed>
	 */
	private function applyFieldValueMap(array $fieldValues): array
	{
		foreach ($this->excludedFieldValuesByFieldCanonicalMap as $fieldKey => $forbiddenCanonicalMap) {
			if (!array_key_exists($fieldKey, $fieldValues)) {
				continue;
			}

			$fieldValues[$fieldKey] = $this->filterFieldValueNode($fieldValues[$fieldKey], $forbiddenCanonicalMap);
		}

		return $fieldValues;
	}

	/**
	 * @param array<string, true> $forbiddenCanonicalMap
	 */
	private function filterFieldValueNode(mixed $value, array $forbiddenCanonicalMap): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		$filtered = [];
		$isList = array_is_list($value);
		foreach ($value as $key => $node) {
			if ($this->matchesForbiddenCanonical($node, $forbiddenCanonicalMap)) {
				continue;
			}

			if ($this->matchesForbiddenCanonical($key, $forbiddenCanonicalMap)) {
				continue;
			}

			$filtered[$key] = $node;
		}

		return $isList ? array_values($filtered) : $filtered;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function shouldExcludeItem(array $item): bool
	{
		if (!is_array($item['offer'] ?? null)) {
			return false;
		}

		foreach ($this->excludedValuesByPathCanonicalMap as $path => $forbiddenCanonicalMap) {
			$value = $this->valueAtPath($item, $path);
			if ($this->isExcludedPathValueUndefined($value)) {
				continue;
			}

			if ($this->matchesForbiddenCanonical($value, $forbiddenCanonicalMap)) {
				return true;
			}
		}

		return false;
	}

	private function isExcludedPathValueUndefined(mixed $value): bool
	{
		if ($value === null) {
			return true;
		}

		if (is_string($value)) {
			return trim($value) === '';
		}

		return is_array($value) && $value === [];
	}

	private function valueAtPath(mixed $source, string $path): mixed
	{
		if (!is_array($source)) {
			return null;
		}

		$current = $source;
		foreach (explode('.', $path) as $segment) {
			if (!is_array($current) || !array_key_exists($segment, $current)) {
				return null;
			}

			$current = $current[$segment];
		}

		return $current;
	}

	/**
	 * @param array<string, true> $forbiddenCanonicalMap
	 */
	private function matchesForbiddenCanonical(mixed $value, array $forbiddenCanonicalMap): bool
	{
		if (is_array($value)) {
			foreach ($value as $nestedValue) {
				if ($this->matchesForbiddenCanonical($nestedValue, $forbiddenCanonicalMap)) {
					return true;
				}
			}

			return false;
		}

		$canonical = self::canonicalizeValue($value);
		return $canonical !== '' && isset($forbiddenCanonicalMap[$canonical]);
	}

	/**
	 * @param array<int, mixed> $values
	 * @return array<string, true>
	 */
	private function canonicalizeValues(array $values): array
	{
		$canonicalMap = [];
		foreach ($values as $value) {
			$canonical = self::canonicalizeValue($value);
			if ($canonical === '') {
				continue;
			}

			$canonicalMap[$canonical] = true;
		}

		return $canonicalMap;
	}

	private static function canonicalizeValue(mixed $value): string
	{
		if (!is_string($value) && !is_int($value) && !is_float($value)) {
			return '';
		}

		$text = trim((string) $value);
		if ($text === '') {
			return '';
		}

		$converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
		$text = is_string($converted) ? $converted : $text;
		$text = strtolower($text);
		$text = preg_replace("/oe/i", 'o', $text) ?? $text;
		$text = preg_replace("/ae/i", 'a', $text) ?? $text;
		$text = preg_replace("/ue/i", 'u', $text) ?? $text;
		$text = preg_replace("/dj/i", 'd', $text) ?? $text;
		$text = preg_replace("/dh/i", 'd', $text) ?? $text;
		$text = preg_replace("/['`’\.]/i", '', $text) ?? $text;
		$text = preg_replace('/[^a-z0-9]+/', '', $text) ?? '';

		return $text;
	}
}

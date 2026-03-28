<?php

declare(strict_types=1);

namespace Skionline\MerlinxGetter\Search\Util;

final class TravelSearchResponseMerger
{
	/**
	 * Filters out already-seen page bookmarks per view and updates seen state in-place.
	 *
	 * @param array<string, string> $bookmarks
	 * @param array<string, array<string, true>> $seenBookmarksByView
	 * @return array<string, string>
	 */
	public function filterUnseenBookmarks(array $bookmarks, array &$seenBookmarksByView): array
	{
		$unseenBookmarks = [];
		foreach ($bookmarks as $viewName => $bookmark) {
			if (!isset($seenBookmarksByView[$viewName])) {
				$seenBookmarksByView[$viewName] = [];
			}
			if (isset($seenBookmarksByView[$viewName][$bookmark])) {
				continue;
			}

			$seenBookmarksByView[$viewName][$bookmark] = true;
			$unseenBookmarks[$viewName] = $bookmark;
		}

		return $unseenBookmarks;
	}

	/**
	 * @param array<string, mixed>|null $base
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed>
	 */
	public function merge(?array $base, array $incoming): array
	{
		$merged = is_array($base) ? $base : [];

		foreach ($incoming as $viewName => $payload) {
			$viewName = (string) $viewName;
			$existing = $merged[$viewName] ?? null;

			if (!is_array($payload)) {
				$merged[$viewName] = $payload;
				continue;
			}

			$merged[$viewName] = match ($viewName) {
				'offerList' => $this->mergeOfferListView(is_array($existing) ? $existing : [], $payload),
				'groupedList' => $this->mergeGroupedListView(is_array($existing) ? $existing : [], $payload),
				'fieldValues', 'unfilteredFieldValues' => $this->mergeFieldValuesView(is_array($existing) ? $existing : [], $payload),
				'regionList' => $this->mergeRegionListView(is_array($existing) ? $existing : [], $payload),
				default => $this->mergeFallbackView(is_array($existing) ? $existing : [], $payload),
			};
		}

		return $merged;
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed>
	 */
	private function mergeOfferListView(array $base, array $incoming): array
	{
		$items = $this->normalizeOfferListItems($base['items'] ?? null);
		foreach ($this->normalizeOfferListItems($incoming['items'] ?? null) as $offerId => $item) {
			if (!isset($items[$offerId])) {
				$items[$offerId] = $item;
				continue;
			}

			$items[$offerId] = $this->mergeEntityPreferFirst($items[$offerId], $item);
		}

		return $this->mergeViewEnvelope($base, $incoming, $items);
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed>
	 */
	private function mergeGroupedListView(array $base, array $incoming): array
	{
		$items = $this->normalizeGroupedListItems($base['items'] ?? null);
		foreach ($this->normalizeGroupedListItems($incoming['items'] ?? null) as $groupKey => $item) {
			if (!isset($items[$groupKey])) {
				$items[$groupKey] = $item;
				continue;
			}

			$items[$groupKey] = $this->mergeEntityPreferFirst($items[$groupKey], $item);
			$items[$groupKey]['groupKeyValue'] = (string) $groupKey;
		}

		return $this->mergeViewEnvelope($base, $incoming, $items);
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed>
	 */
	private function mergeFieldValuesView(array $base, array $incoming): array
	{
		$merged = $this->normalizeFieldValuesPayload($base);
		$incoming = $this->normalizeFieldValuesPayload($incoming);
		foreach ($incoming as $field => $values) {
			if (!array_key_exists($field, $merged)) {
				$merged[$field] = $values;
				continue;
			}

			$existing = $merged[$field];
			if (!is_array($existing) || !is_array($values)) {
				$merged[$field] = $this->hasMeaningfulValue($existing) ? $existing : $values;
				continue;
			}

			if (array_is_list($existing) || array_is_list($values)) {
				$merged[$field] = $this->mergeListsUnique($existing, $values);
				continue;
			}

			$merged[$field] = $this->mergeLabelMapPreferFirst($existing, $values);
		}

		return $merged;
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed>
	 */
	private function mergeRegionListView(array $base, array $incoming): array
	{
		$merged = $this->deepFillMissing($base, $incoming);
		if (array_key_exists('more', $incoming)) {
			$merged['more'] = $incoming['more'];
		}
		if (array_key_exists('pageBookmark', $incoming)) {
			$merged['pageBookmark'] = $incoming['pageBookmark'];
		}

		return $merged;
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed>
	 */
	private function mergeFallbackView(array $base, array $incoming): array
	{
		$merged = $this->deepFillMissing($base, $incoming);
		if (array_key_exists('more', $incoming)) {
			$merged['more'] = $incoming['more'];
		}
		if (array_key_exists('pageBookmark', $incoming)) {
			$merged['pageBookmark'] = $incoming['pageBookmark'];
		}

		return $merged;
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $incoming
	 * @param array<string, array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	private function mergeViewEnvelope(array $base, array $incoming, array $items): array
	{
		unset($base['items'], $incoming['items']);
		$merged = $this->deepFillMissing($base, $incoming);
		$merged['items'] = $items;

		if (array_key_exists('more', $incoming)) {
			$merged['more'] = $incoming['more'];
		}
		if (array_key_exists('pageBookmark', $incoming)) {
			$merged['pageBookmark'] = $incoming['pageBookmark'];
		}

		return $merged;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function normalizeOfferListItems(mixed $rawItems): array
	{
		if (!is_array($rawItems)) {
			return [];
		}

		$normalized = [];
		$missingOfferIdCount = 0;
		$sampleItemKeys = [];
		$sampleItemKeyFingerprints = [];
		foreach ($rawItems as $item) {
			if (!is_array($item)) {
				continue;
			}

			$offerId = $this->extractOfferId($item);
			if ($offerId === '') {
				$missingOfferIdCount++;
				$itemKeys = array_map(static fn ($key): string => (string) $key, array_keys($item));
				$fingerprint = implode('|', $itemKeys);
				if (
					$fingerprint !== ''
					&& !isset($sampleItemKeyFingerprints[$fingerprint])
					&& count($sampleItemKeys) < 3
				) {
					$sampleItemKeyFingerprints[$fingerprint] = true;
					$sampleItemKeys[] = $itemKeys;
				}
				continue;
			}

			if (!isset($normalized[$offerId])) {
				$normalized[$offerId] = $item;
				continue;
			}

			$normalized[$offerId] = $this->mergeEntityPreferFirst($normalized[$offerId], $item);
		}

		if ($missingOfferIdCount > 0) {
			$this->emitWarning('offer_list_item_missing_offer_id', [
				'missing_count' => $missingOfferIdCount,
				'sample_item_keys' => $sampleItemKeys,
			]);
		}

		return $normalized;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function normalizeGroupedListItems(mixed $rawItems): array
	{
		if (!is_array($rawItems)) {
			return [];
		}

		$isAssociativeSource = !array_is_list($rawItems);
		$normalized = [];
		foreach ($rawItems as $sourceKey => $item) {
			if (!is_array($item)) {
				continue;
			}

			$groupKey = $this->resolveGroupedItemKey($item, $isAssociativeSource ? $sourceKey : null);
			if ($groupKey === '') {
				$this->emitWarning('grouped_list_item_missing_group_key', [
					'source_key' => is_string($sourceKey) || is_int($sourceKey) ? (string) $sourceKey : '',
					'item_keys' => array_keys($item),
				]);
				continue;
			}

			$item['groupKeyValue'] = $groupKey;
			if (!isset($normalized[$groupKey])) {
				$normalized[$groupKey] = $item;
				continue;
			}

			$normalized[$groupKey] = $this->mergeEntityPreferFirst($normalized[$groupKey], $item);
			$normalized[$groupKey]['groupKeyValue'] = (string) $groupKey;
		}

		return $normalized;
	}

	private function extractOfferId(array $item): string
	{
		$offer = is_array($item['offer'] ?? null) ? $item['offer'] : [];
		$base = is_array($offer['Base'] ?? null) ? $offer['Base'] : [];
		$offerId = $base['OfferId'] ?? null;

		return $this->normalizeIdentity($offerId);
	}

	private function resolveGroupedItemKey(array $item, mixed $sourceKey): string
	{
		$offer = is_array($item['offer'] ?? null) ? $item['offer'] : [];
		$accommodation = is_array($offer['Accommodation'] ?? null) ? $offer['Accommodation'] : [];
		$base = is_array($offer['Base'] ?? null) ? $offer['Base'] : [];

		foreach ([
			$item['groupKeyValue'] ?? null,
			$sourceKey,
			$accommodation['XCode']['Id'] ?? null,
			$base['ObjectId'] ?? null,
			$base['XCode']['Id'] ?? null,
		] as $candidate) {
			$normalized = $this->normalizeIdentity($candidate);
			if ($normalized !== '') {
				return $normalized;
			}
		}

		return '';
	}

	private function normalizeIdentity(mixed $value): string
	{
		if (!is_string($value) && !is_int($value) && !is_float($value)) {
			return '';
		}

		return trim((string) $value);
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed>
	 */
	private function mergeEntityPreferFirst(array $base, array $incoming): array
	{
		$merged = $base;
		foreach ($incoming as $key => $value) {
			if (!array_key_exists($key, $merged)) {
				$merged[$key] = $value;
				continue;
			}

			$merged[$key] = $this->fillMissingValue($merged[$key], $value);
		}

		return $merged;
	}

	private function fillMissingValue(mixed $base, mixed $incoming): mixed
	{
		if (!$this->hasMeaningfulValue($base)) {
			return $incoming;
		}
		if (!$this->hasMeaningfulValue($incoming)) {
			return $base;
		}

		if (is_array($base) && is_array($incoming)) {
			if ($this->shouldTreatAsList($base, $incoming)) {
				return $this->mergeListsUnique($base, $incoming);
			}

			return $this->deepFillMissing($base, $incoming);
		}

		return $base;
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed>
	 */
	private function deepFillMissing(array $base, array $incoming): array
	{
		$merged = $base;
		foreach ($incoming as $key => $value) {
			if (!array_key_exists($key, $merged)) {
				$merged[$key] = $value;
				continue;
			}

			$existing = $merged[$key];
			if (is_array($existing) && is_array($value)) {
				if ($this->shouldTreatAsList($existing, $value)) {
					$merged[$key] = $this->mergeListsUnique($existing, $value);
					continue;
				}

				$merged[$key] = $this->deepFillMissing($existing, $value);
				continue;
			}

			if (!$this->hasMeaningfulValue($existing) && $this->hasMeaningfulValue($value)) {
				$merged[$key] = $value;
			}
		}

		return $merged;
	}

	private function hasMeaningfulValue(mixed $value): bool
	{
		if ($value === null) {
			return false;
		}
		if (is_string($value)) {
			return trim($value) !== '';
		}
		if (is_array($value)) {
			return $value !== [];
		}

		return true;
	}

	/**
	 * Empty arrays are ambiguous in PHP, so treat them as lists only when the
	 * non-empty side is list-like too.
	 *
	 * @param array<mixed> $base
	 * @param array<mixed> $incoming
	 */
	private function shouldTreatAsList(array $base, array $incoming): bool
	{
		if ($base === []) {
			return array_is_list($incoming);
		}
		if ($incoming === []) {
			return array_is_list($base);
		}

		return array_is_list($base) && array_is_list($incoming);
	}

	private function isFieldValuesView(string $viewName): bool
	{
		return $viewName === 'fieldValues' || $viewName === 'unfilteredFieldValues';
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function normalizeFieldValuesPayload(array $payload): array
	{
		$normalized = $payload;
		if (array_key_exists('fieldValues', $payload) && is_array($payload['fieldValues'])) {
			$normalized = $payload['fieldValues'];
		}

		if (!is_array($normalized)) {
			return [];
		}

		unset($normalized['more'], $normalized['pageBookmark']);

		return $normalized;
	}

	/**
	 * @param array<mixed> $base
	 * @param array<mixed> $incoming
	 * @return array<mixed>
	 */
	private function mergeListsUnique(array $base, array $incoming): array
	{
		$merged = [];
		$seen = [];

		foreach (array_merge($base, $incoming) as $item) {
			$key = $this->listItemFingerprint($item);
			if ($key === '' || isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;
			$merged[] = $item;
		}

		return $merged;
	}

	private function listItemFingerprint(mixed $item): string
	{
		if (is_scalar($item) || $item === null) {
			return (string) $item;
		}

		$encoded = json_encode($item);

		return is_string($encoded) ? $encoded : '';
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed>
	 */
	private function mergeLabelMapPreferFirst(array $base, array $incoming): array
	{
		foreach ($incoming as $key => $value) {
			if (!array_key_exists($key, $base)) {
				$base[$key] = $value;
				continue;
			}
			$existing = $base[$key] ?? null;
			if (!$this->isNonEmptyLabel($existing) && $this->isNonEmptyLabel($value)) {
				$base[$key] = $value;
			}
		}

		return $base;
	}

	private function isNonEmptyLabel(mixed $value): bool
	{
		return is_string($value) && trim($value) !== '';
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function emitWarning(string $code, array $context = []): void
	{
		$payload = json_encode([
			'component' => 'merlinx-getter.search-response-merger',
			'code' => $code,
			'context' => $context,
		]);

		if (is_string($payload) && $payload !== '') {
			error_log($payload);
		}
	}
}

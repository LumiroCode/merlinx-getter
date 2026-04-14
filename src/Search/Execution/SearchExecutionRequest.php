<?php

declare(strict_types=1);

namespace Skionline\MerlinxGetter\Search\Execution;

use Skionline\MerlinxGetter\Search\Util\ToObjectDeep;

final class SearchExecutionRequest
{
	private readonly array $search;
	private readonly array $filter;
	private readonly array $results;
	private readonly array $views;
	private readonly array $options;
	/**
	 * @param array<string, mixed> $search
	 * @param array<string, mixed> $filter
	 * @param array<string, mixed> $results
	 * @param array<string, mixed> $views
	 * @param array<string, mixed> $options
	 */
	private function __construct(
		$search,
		$filter,
		$results,
		$views,
		$options,
	) {
		if (is_array($views)) {
			foreach ($views as $viewKey => $view) {
				if (is_array($view) && isset($view['fieldList']) && is_array($view['fieldList'])) {
					$views[$viewKey]['fieldList'][] = 'Base.OfferId';
					$views[$viewKey]['fieldList'] = array_values($views[$viewKey]['fieldList']);
					$views[$viewKey]['fieldList'] = array_unique($views[$viewKey]['fieldList']);
				}
			}
		}
		$this->search = $search;
		$this->filter = $filter;
		$this->results = $results;
		$this->views = $views;
		$this->options = $options;
	}

	/**
	 * @param array<string, mixed> $search
	 * @param array<string, mixed> $filter
	 * @param array<string, mixed> $results
	 * @param array<string, mixed> $views
	 * @param array<string, mixed> $options
	 */
	public static function fromArrays(
		array $search = [],
		array $filter = [],
		array $results = [],
		array $views = ['offerList' => []],
		array $options = [],
	): self {
		return new self($search, $filter, $results, $views, $options);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function search(): array
	{
		return $this->search;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function filter(): array
	{
		return $this->filter;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function results(): array
	{
		return $this->results;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function views(): array
	{
		return $this->views;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function options(): array
	{
		return $this->options;
	}

	/**
	 * @param array<string, mixed> $views
	 */
	public function withViews(array $views): self
	{
		return new self($this->search, $this->filter, $this->results, $views, $this->options);
	}

	/**
	 * @param array<string, mixed> $options
	 */
	public function withOptions(array $options): self
	{
		return new self($this->search, $this->filter, $this->results, $this->views, $options);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toBody(int $defaultViewLimit): array
	{
		$body = [
			'conditions' => [
				'search' => $this->search,
				'filter' => $this->filter,
			],
			'views' => $this->normalizeViews($defaultViewLimit),
		];

		if ($this->results !== []) {
			$body['results'] = $this->results;
		}

		return $body;
	}

	public function toObject(int $defaultViewLimit): object
	{
		$body = $this->toBody($defaultViewLimit);
		$object = ToObjectDeep::apply($body);

		foreach (['search', 'filter'] as $conditionType) {
			if (!isset($object->conditions->{$conditionType})) {
				continue;
			}

			$condition = $body['conditions'][$conditionType] ?? [];
			$base = is_array($condition['Base'] ?? null) ? $condition['Base'] : [];
			if (isset($object->conditions->{$conditionType}->Base)) {
				foreach (['Availability', 'Catalog', 'Operator', 'ComponentsCombinations', 'Transfer', 'Refundable', 'Resident'] as $field) {
					if (isset($base[$field])) {
						$object->conditions->{$conditionType}->Base->{$field} = (array) $base[$field];
					}
				}
				if (isset($base['ParticipantsList'])) {
					$object->conditions->{$conditionType}->Base->ParticipantsList = (array) ToObjectDeep::apply($base['ParticipantsList']);
				}
			}

			$accommodation = is_array($condition['Accommodation'] ?? null) ? $condition['Accommodation'] : [];
			if (isset($object->conditions->{$conditionType}->Accommodation)) {
				foreach (['ExtAgentAttributes', 'XCode', 'XService', 'Rooms', 'Attributes'] as $field) {
					if (isset($accommodation[$field])) {
						$object->conditions->{$conditionType}->Accommodation->{$field} = (array) $accommodation[$field];
					}
				}
			}

			$transportFlight = is_array($condition['Transport']['Flight'] ?? null) ? $condition['Transport']['Flight'] : [];
			if (isset($object->conditions->{$conditionType}->Transport->Flight)) {
				foreach (['Luggage', 'Stops', 'AirlineType'] as $field) {
					if (isset($transportFlight[$field])) {
						$object->conditions->{$conditionType}->Transport->Flight->{$field} = (array) $transportFlight[$field];
					}
				}
			}

			$customRules = is_array($condition['Custom']['Rules'] ?? null) ? $condition['Custom']['Rules'] : [];
			if (isset($object->conditions->{$conditionType}->Custom->Rules)) {
				foreach (['include', 'exclude'] as $field) {
					if (isset($customRules[$field])) {
						$object->conditions->{$conditionType}->Custom->Rules->{$field} = (array) $customRules[$field];
					}
				}
			}
		}

		foreach ($this->normalizeViews($defaultViewLimit) as $viewKey => $view) {
			if (isset($view['fieldList']) && isset($object->views->{$viewKey})) {
				$object->views->{$viewKey}->fieldList = (array) $view['fieldList'];
			}
		}

		return $object;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function normalizeViews(int $defaultViewLimit): array
	{
		$normalized = [];
		foreach ($this->views as $viewName => $view) {
			if (!is_string($viewName) || $viewName === '') {
				continue;
			}

			if (is_object($view)) {
				$view = (array) $view;
			}
			if (!is_array($view)) {
				$view = [];
			}

			$view['limit'] = self::normalizeViewLimit($view['limit'] ?? null, $defaultViewLimit);
			$normalized[$viewName] = $view;
		}

		return $normalized;
	}

	private static function normalizeViewLimit(mixed $limit, int $defaultViewLimit): int
	{
		if (is_int($limit) && $limit > 0) {
			return $limit;
		}

		if (is_string($limit)) {
			$limit = trim($limit);
			if ($limit !== '' && ctype_digit($limit) && (int) $limit > 0) {
				return (int) $limit;
			}
		}

		return max(1, $defaultViewLimit);
	}
}

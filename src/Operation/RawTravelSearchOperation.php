<?php

declare(strict_types=1);

namespace Skionline\MerlinxGetter\Operation;

use JsonException;
use Skionline\MerlinxGetter\Contract\OperationInterface;
use Skionline\MerlinxGetter\Exception\ResponseFormatException;
use Skionline\MerlinxGetter\Http\MerlinxHttpClient;
use Skionline\MerlinxGetter\Search\Util\SearchRequestFingerprint;

final class RawTravelSearchOperation implements OperationInterface
{
	private const ENDPOINT = '/v5/data/travel/search';
	private const ERROR_CONTEXT_VERSION = 'raw_travel_search_error_context_v1';

	public function __construct(private readonly MerlinxHttpClient $client)
	{
	}

	public function key(): string
	{
		return 'rawTravelSearch';
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function execute(array $body): array
	{
		$response = $this->client->request(
			'POST',
			self::ENDPOINT,
			[
				'json' => $body,
				'headers' => [
					'Accept' => 'application/json',
					'Content-Type' => 'application/json',
				],
			],
			['queryFingerprint' => $this->buildQueryFingerprint($body)]
		);

		try {
			$data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new ResponseFormatException('MerlinX raw travel search response is invalid JSON.', 0, $exception);
		}

		if (!is_array($data)) {
			throw new ResponseFormatException('MerlinX raw travel search response has unexpected format.');
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function buildQueryFingerprint(array $body): string
	{
		return SearchRequestFingerprint::hash([
			'schema' => self::ERROR_CONTEXT_VERSION,
			'body' => $body,
		]);
	}
}

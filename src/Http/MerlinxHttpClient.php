<?php

declare(strict_types=1);

namespace Skionline\MerlinxGetter\Http;

use JsonException;
use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\Exception\HttpRequestException;
use Skionline\MerlinxGetter\Http\Auxiliary\HttpErrorReporter;
use Skionline\MerlinxGetter\Http\Auxiliary\RateLimitRetryEngine;
use Skionline\MerlinxGetter\Http\Models\RetryPolicy;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MerlinxHttpClient
{
	private readonly HttpClientInterface $httpClient;
	private readonly AuthTokenProvider $tokenProvider;
	private readonly string $baseUrl;
	private readonly string $domain;
	private readonly float $timeout;
	private readonly RetryPolicy $defaultRetryPolicy;
	private readonly RateLimitRetryEngine $retryEngine;
	private readonly HttpErrorReporter $errorReporter;

	public function __construct(MerlinxGetterConfig $config, AuthTokenProvider $tokenProvider, ?HttpClientInterface $httpClient = null)
	{
		$this->httpClient = $httpClient ?? HttpClient::create();
		$this->tokenProvider = $tokenProvider;
		$this->baseUrl = rtrim($config->baseUrl, '/');
		$this->domain = $config->domain;
		$this->timeout = $config->timeout;
		$this->defaultRetryPolicy = RetryPolicy::fromConfig($config);
		$this->retryEngine = new RateLimitRetryEngine();
		$this->errorReporter = new HttpErrorReporter();
	}

	/**
	 * @param array<string, mixed> $options
	 * @param array<string, mixed> $context
	 */
	public function request(string $method, string $uri, array $options = [], array $context = []): HttpResponse
	{
		$url = $this->buildUrl($uri);
		$isAuthRequest = $this->isAuthUri($uri);
		$options = $this->withTimeout($options);
		$options = $isAuthRequest ? $options : $this->withAuthHeaders($options);
		$policy = $this->resolveRetryPolicy($context);
		$queryFingerprint = $this->resolveQueryFingerprint($context);

		$response = $this->sendWithRetry($method, $url, $options, $policy, $queryFingerprint);
		if ($isAuthRequest) {
			return $response;
		}

		if ($this->isAuthError($response->statusCode(), $response->body())) {
			$freshToken = $this->tokenProvider->forceRefresh();
			$optionsWithNewToken = $this->withAuthHeaders($options, $freshToken);
			$retried = $this->sendWithRetry($method, $url, $optionsWithNewToken, $policy, $queryFingerprint);
			if ($this->isAuthError($retried->statusCode(), $retried->body())) {
				throw new HttpRequestException(
					$this->errorReporter->buildMessage(
						'MerlinX HTTP request failed with status ' . $retried->statusCode() . ' (auth error persisted after token refresh)',
						$method,
						$this->endpointForErrorMessage($url),
						responseBody: $retried->body(),
						requestSnippet: $this->resolveRequestSnippet($options),
						queryFingerprint: $queryFingerprint,
					),
					$retried->statusCode(),
					$retried->body()
				);
			}
			return $retried;
		}

		return $response;
	}

	private function buildUrl(string $uri): string
	{
		if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
			return $uri;
		}
		return $this->baseUrl . '/' . ltrim($uri, '/');
	}

	private function isAuthUri(string $uri): bool
	{
		return str_contains($uri, '/v5/token/new');
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function withTimeout(array $options): array
	{
		if (!isset($options['timeout'])) {
			$options['timeout'] = $this->timeout;
		}
		return $options;
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function withAuthHeaders(array $options, ?string $token = null): array
	{
		$headers = $options['headers'] ?? [];
		if (!is_array($headers)) {
			$headers = [];
		}

		$headers['X-TOKEN'] = $token ?? $this->tokenProvider->getToken();
		if ($this->domain !== '') {
			$headers['X-DOMAIN'] = $this->domain;
		}

		$options['headers'] = $headers;
		return $options;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function sendWithRetry(string $method, string $url, array $options, RetryPolicy $policy, ?string $queryFingerprint = null): HttpResponse
	{
		$retryDelayMs = $policy->initialDelayMs();
		$maxAttempts = $policy->maxAttempts();
		$endpoint = $this->endpointForErrorMessage($url);
		$requestSnippet = $this->resolveRequestSnippet($options);

		for ($attempt = 0;; $attempt++) {
			$status = 0;
			$headers = [];
			$body = '';

			try {
				$response = $this->httpClient->request($method, $url, $options);
				$status = $response->getStatusCode();
				$headers = $response->getHeaders(false);
				$body = $response->getContent(false);
			} catch (ClientExceptionInterface | RedirectionExceptionInterface | ServerExceptionInterface | TransportExceptionInterface $e) {
				if ($this->retryEngine->isRateLimitedThrowable($e) && $attempt < $maxAttempts) {
					$this->retryEngine->wait($retryDelayMs, null);
					$retryDelayMs = $this->retryEngine->nextDelayMs($retryDelayMs, $policy);
					continue;
				}

				throw new HttpRequestException(
					$this->errorReporter->buildMessage(
						'MerlinX HTTP request failed: ' . $e->getMessage(),
						$method,
						$endpoint,
						$attempt + 1,
						$maxAttempts + 1,
						null,
						$requestSnippet,
						$queryFingerprint,
					),
					null,
					null,
					$e
				);
			}

			$body = $this->removeDebugField($body);
			if ($this->retryEngine->isRateLimited($status, $body)) {
				if ($attempt < $maxAttempts) {
					$retryAfterMs = $this->retryEngine->extractRetryAfterMs($headers);
					$this->retryEngine->wait($retryDelayMs, $retryAfterMs);
					$retryDelayMs = $this->retryEngine->nextDelayMs($retryDelayMs, $policy);
					continue;
				}

				throw new HttpRequestException(
					$this->errorReporter->buildMessage(
						'MerlinX HTTP rate limit persisted after retries',
						$method,
						$endpoint,
						$attempt + 1,
						$maxAttempts + 1,
						$body,
						$requestSnippet,
						$queryFingerprint,
					),
					$status,
					$body
				);
			}

			if ($status >= 400) {
				if ($this->isAuthError($status, $body)) {
					return new HttpResponse($status, $headers, $body, $attempt + 1);
				}

				$errorType = $status >= 500 ? 'server error' : 'client error';
				throw new HttpRequestException(
					$this->errorReporter->buildMessage(
						'MerlinX HTTP request failed with status ' . $status . ' (' . $errorType . ')',
						$method,
						$endpoint,
						$attempt + 1,
						$maxAttempts + 1,
						$body,
						$requestSnippet,
						$queryFingerprint,
					),
					$status,
					$body
				);
			}

			return new HttpResponse($status, $headers, $body, $attempt + 1);
		}
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function resolveRetryPolicy(array $context): RetryPolicy
	{
		$retryKeys = [
			'rateLimitRetryMaxAttempts',
			'rateLimitRetryDelayMs',
			'rateLimitRetryBackoffMultiplier',
			'rateLimitRetryMaxDelayMs',
		];

		$hasOverride = false;
		foreach ($retryKeys as $retryKey) {
			if (array_key_exists($retryKey, $context)) {
				$hasOverride = true;
				break;
			}
		}

		if (!$hasOverride) {
			return $this->defaultRetryPolicy;
		}

		return RetryPolicy::fromOptions([
			'rateLimitRetryMaxAttempts' => $context['rateLimitRetryMaxAttempts'] ?? $this->defaultRetryPolicy->maxAttempts(),
			'rateLimitRetryDelayMs' => $context['rateLimitRetryDelayMs'] ?? $this->defaultRetryPolicy->initialDelayMs(),
			'rateLimitRetryBackoffMultiplier' => $context['rateLimitRetryBackoffMultiplier'] ?? $this->defaultRetryPolicy->backoffMultiplier(),
			'rateLimitRetryMaxDelayMs' => $context['rateLimitRetryMaxDelayMs'] ?? $this->defaultRetryPolicy->maxDelayMs(),
		]);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function resolveQueryFingerprint(array $context): ?string
	{
		$fingerprint = $context['queryFingerprint'] ?? null;
		if (!is_string($fingerprint)) {
			return null;
		}

		$fingerprint = trim($fingerprint);
		return $fingerprint === '' ? null : $fingerprint;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function resolveRequestSnippet(array $options): ?string
	{
		if (array_key_exists('json', $options)) {
			return $this->errorReporter->buildSnippet($options['json']);
		}

		if (array_key_exists('body', $options)) {
			return $this->errorReporter->buildSnippet($options['body']);
		}

		return null;
	}

	private function endpointForErrorMessage(string $url): string
	{
		$path = parse_url($url, PHP_URL_PATH);
		$query = parse_url($url, PHP_URL_QUERY);

		$endpoint = is_string($path) && $path !== '' ? $path : $url;
		if (is_string($query) && $query !== '') {
			$endpoint .= '?' . $query;
		}

		return $endpoint;
	}

	private function isAuthError(int $statusCode, string $body): bool
	{
		if ($statusCode === 412) {
			return true;
		}
		if ($statusCode === 401) {
			return true;
		}

		return stripos($body, 'autherror') !== false
			|| stripos($body, 'TOKEN CORRUPTED') !== false;
	}

	private function removeDebugField(string $body): string
	{
		$trimmed = trim($body);
		if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
			return $body;
		}

		try {
			$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($data)) {
				return $body;
			}

			$cleaned = $this->removeDebugFieldRecursive($data);
			$result = json_encode($cleaned, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			return is_string($result) ? $result : $body;
		} catch (JsonException) {
			return $body;
		}
	}

	private function removeDebugFieldRecursive(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		$result = [];
		foreach ($value as $key => $val) {
			if ($key === 'debug') {
				continue;
			}
			$result[$key] = $this->removeDebugFieldRecursive($val);
		}

		return $result;
	}
}

# skionline/merlinx-getter

Standalone read-only MerlinX package.

Its search scope is intentionally narrow: typed search request in, canonical `search_engine` config applied, aggregated MerlinX `/v5/data/travel/search` response out.

## Scope

- `POST /v5/token/new`
- `POST /v5/data/travel/search`
- `GET /v5/data/travel/details`
- `POST /v5/data/travel/checkonline`
- `POST https://www.skionline.pl/wxp/?p=ofertyResultsJson`

Out of scope for the package search engine:

- `/searchbase`
- query normalization outside the search request payload
- promoted-offer matching
- presentation formatting

## Installation

```bash
composer install
```

To install this package from another local project via a Composer path repository:

```json
{
	"repositories": [
		{
			"type": "path",
			"url": "../merlinx-getter"
		}
	],
	"require": {
		"skionline/merlinx-getter": "*@dev"
	}
}
```

Then run:

```bash
composer install
```

## Release

The repository includes a strict tag-driven release script at `./release`.
It builds runtime-only release artifacts before tagging.

Release rules:

- must run from clean working tree
- must run on `main`
- local `main` must be in sync with `origin/main`
- tests (`composer test`) must pass
- generated tags are canonical `vX.Y.Z`
- historical tags in both `vX.Y.Z` and `X.Y.Z` are parsed when computing latest version
- release bundle is built from an explicit allowlist (`composer.json`, `composer.lock`, `README.md`, `src/`)
- release artifacts are written to `dist/` as `merlinx-getter-X.Y.Z.tar.gz`
- only the new tag is pushed (no branch push)
- release archive scope is validated from that allowlist (so folders like `docs/` are never bundled)

Usage:

```bash
# preview next patch release, run full checks, do not build/tag/push
./release --bump patch --dry-run

# release next minor version from latest existing tag
./release --bump minor

# release explicit version
./release --version 2.0.0
```

Common failure cases:

- dirty worktree: commit/stash/discard changes and retry
- branch mismatch: switch to `main`
- out-of-sync branch: pull/rebase/push until `HEAD == origin/main`
- failing tests: fix test failures before retry
- invalid/existing target tag: choose a valid, unused semver version
- archive scope failure: adjust `RELEASE_INCLUDE_PATHS` in `./release`

## Public API

Entry point: `Skionline\MerlinxGetter\MerlinxGetterClient`

Methods:

- `executeSearch(SearchExecutionRequest $request): SearchExecutionResult`
- `getDetails(string $offerId): array`
- `getLiveAvailability(string $offerId, ?string $action = 'checkstatus', bool $includeTfg = true, bool $force = false): array`
- `portalSearch(array $params = []): array`
- `clearCache(): bool`

There is no legacy array-based `search(...)` API and no legacy winter query-builder API.

## Quickstart

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Skionline\MerlinxGetter\MerlinxGetterClient;
use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\Search\Execution\SearchExecutionRequest;

$config = [
	'system' => [
		'cache' => [
			'dir' => __DIR__ . '/cache',
		],
	],
	'merlinx' => [
		'base_url' => 'https://mwsv5pro.merlinx.eu',
		'login' => 'YOUR_LOGIN',
		'password' => 'YOUR_PASSWORD',
		'expedient' => 'YOUR_EXPEDIENT',
		'domain' => 'example.com',
		'cache' => [
			'token' => [
				'ttl' => 3300,
			],
		],
		'search_engine' => [
			'name' => 'default',
			'operators' => ['NKRA', 'VITX'],
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
			'response_filters' => [
				'exclude_values_by_path' => [
					'offer.Base.XCity.Name' => ['Limone Piemonte'],
					'offer.Accommodation.XCity.Name' => ['Limone Piemonte'],
				],
			],
			'runtime' => [
				'default_view_limit' => 100,
				'rate_limit_retry_max_attempts' => 4,
				'rate_limit_retry_delay_ms' => 500,
				'rate_limit_retry_backoff_multiplier' => 2.0,
				'rate_limit_retry_max_delay_ms' => 8000,
			],
			'cache' => [
				'search' => [
					'ttl_seconds' => 300,
					'stale_seconds' => 900,
					'lock_timeout_ms' => 3000,
					'lock_retry_delay_ms' => 50,
				],
			],
		],
	],
];

$client = new MerlinxGetterClient(MerlinxGetterConfig::fromRootConfig($config));

$request = SearchExecutionRequest::fromArrays(
	search: [
		'Base' => [
			'StartDate' => ['Min' => '2026-03-01', 'Max' => '2026-03-31'],
		],
	],
	views: [
		'offerList' => ['limit' => 500],
		'fieldValues' => ['fieldList' => ['Base.StartDate']],
	],
);

$result = $client->executeSearch($request);
$response = $result->response();

print_r($response);
```

## Search Config

Canonical search config lives under `search_engine`.

Application-owned helper/read-model caches must live under `merlinx.helper_cache`; they are not part of the package contract.

Required MerlinX connection keys:

- `base_url` or `baseUrl`
- `login`
- `password`
- `expedient`
- `domain`

When using `MerlinxGetterConfig::fromRootConfig()`, the required root cache keys are:

- `system.cache.dir`
- `merlinx.cache.token.ttl`

Search-engine keys:

- `name`
- `operators`
- `conditions`
- `availability_policy.inquiryable_bases`
- `availability_policy.onrequest_min_days`
- `operator_policies.child_as_adult_operators`
- `response_filters.exclude_values_by_path`
- `runtime.default_view_limit`
- `runtime.rate_limit_retry_max_attempts`
- `runtime.rate_limit_retry_delay_ms`
- `runtime.rate_limit_retry_backoff_multiplier`
- `runtime.rate_limit_retry_max_delay_ms`
- `cache.search.ttl_seconds`
- `cache.search.stale_seconds`
- `cache.search.lock_timeout_ms`
- `cache.search.lock_retry_delay_ms`

Defaults when omitted:

- `source`: `B2C`
- `type`: `web`
- `language`: `pl`
- `timeout`: `15.0`
- `search_engine.name`: `default`
- `search_engine.operators`: `[]`
- `search_engine.conditions`: one empty condition
- `search_engine.runtime.default_view_limit`: `100`
- `search_engine.runtime.rate_limit_retry_max_attempts`: `4`
- `search_engine.runtime.rate_limit_retry_delay_ms`: `500`
- `search_engine.runtime.rate_limit_retry_backoff_multiplier`: `2.0`
- `search_engine.runtime.rate_limit_retry_max_delay_ms`: `8000`
- `search_engine.cache.search.ttl_seconds`: `300`
- `search_engine.cache.search.stale_seconds`: `900`
- `search_engine.cache.search.lock_timeout_ms`: `3000`
- `search_engine.cache.search.lock_retry_delay_ms`: `50`
- `merlinx.cache.live_availability.ttl`: `30`

## Search Request Contract

`SearchExecutionRequest::fromArrays()` accepts:

- `search`: MerlinX `conditions.search`
- `filter`: MerlinX `conditions.filter`
- `results`: MerlinX `results`
- `views`: MerlinX `views`
- `options`: runtime-only search options

Supported runtime options:

- `rateLimitRetryMaxAttempts`
- `rateLimitRetryDelayMs`
- `rateLimitRetryBackoffMultiplier`
- `rateLimitRetryMaxDelayMs`

## Search Engine Behavior

The package search engine owns:

- config-driven query expansion from `search_engine.conditions`
- default operator injection from `search_engine.operators`
- child-as-adult operator split from `search_engine.operator_policies`
- request-body dedupe
- persistent cache keying from config + request payload + runtime options
- response-driven pagination follow-through based on `more` + `pageBookmark`
- repeated-bookmark stop guard
- empty-page stop guard (`empty(items)`)
- soft limit stop when merged item count reaches the explicitly requested view limit
- shared MerlinX HTTP rate-limit retry/backoff across search, details, checkonline, and token acquisition
- `fieldValues` normalization and merge
- enforced `Accommodation.Attributes` pruning derived from `search_engine.conditions`
- configured path-based exclusions (`response_filters.exclude_values_by_path`) applied on each fetched page before merge

Important pagination rules:

- missing or invalid view limits are materialized as `default_view_limit`
- default `default_view_limit` is `100`
- defaulted view limits do not act as follow-through soft-stop limits
- already-fetched items are not trimmed when the soft limit stop is reached

## Search Result Contract

`SearchExecutionResult` exposes:

- `response(): array`
- `view(string $name): ?array`
- `meta(): array{limitHits: array<string, bool>}`

The payload is the merged MerlinX search response after package-owned processing.

View-specific merge rules:

- `offerList.items` is keyed by full `offer.Base.OfferId`
- `groupedList.items` is keyed by resolved group identity (`groupKeyValue` / fallback group key)
- duplicate collisions are `first wins`; later duplicates only fill missing fields
- `fieldValues` / `unfilteredFieldValues` are flattened and merged content-aware
- `regionList` stays a keyed tree

## Other Operations

`getDetails()`:

- caches fresh and stale responses
- uses alias-aware cache keys derived from composite `OfferId`
- bypasses cache when the `OfferId` cannot be normalized safely

`getLiveAvailability()`:

- calls `/v5/data/travel/checkonline`
- caches by `(offerId, action, includeTFG)` for `merlinx.cache.live_availability.ttl`
- `force=true` bypasses the cache

`portalSearch()`:

- calls the hardcoded public endpoint `https://www.skionline.pl/wxp/?p=ofertyResultsJson`

## Verification

Package search behavior is covered by tests for:

- canonical `search_engine` config resolution
- typed request/result contract
- pagination and repeated-bookmark stop
- query dedupe
- stale cache fallback
- rate-limit retry
- child-as-adult split
- fieldValues merge
- city-name exclusions

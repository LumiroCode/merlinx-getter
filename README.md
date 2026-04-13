# skionline/merlinx-getter

Standalone read-only MerlinX package.

Its search scope is intentionally narrow: typed search request in, canonical `search_engine` config applied, aggregated MerlinX `/v5/data/travel/search` response out.

## Scope

- `POST /v5/token/new`
- `POST /v5/data/travel/search`
- `POST /v5/data/travel/searchbase`
- `GET /v5/data/travel/details`
- `POST /v5/data/travel/checkonline`
- `POST https://www.skionline.pl/wxp/?p=ofertyResultsJson`

Out of scope for the package search engine:

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

## License

This package is proprietary software owned by SkiOnline.pl.

- Copyright (c) SkiOnline.pl. All rights reserved.
- See `LICENSE` for the full terms.

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
- release bundle is built from an explicit allowlist (`composer.json`, `composer.lock`, `LICENSE`, `README.md`, `src/`)
- release artifacts are written to `dist/` as `merlinx-getter-X.Y.Z.tar.gz`
- the artifact contains `composer.json` with an injected `"version": "X.Y.Z"` to support Composer `artifact` repositories
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
- `executeRawSearch(array $body): array`
- `getSearchBase(bool $force = false): array`
- `getDetails(string $offerId): array`
- `getDetailsFresh(string $offerId): array`
- `putDetails(string $offerId, array $payload): bool`
- `getLiveAvailability(string $offerId, ?string $action = 'checkstatus', bool $includeTfg = true, bool $force = false): array`
- `portalSearch(array $params = []): array`
- `clearCache(): bool`

There is no legacy array-based `search(...)` API and no legacy winter query-builder API.

## Quickstart

### 1. Instantiate the client

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
		],
	],
];

$client = new MerlinxGetterClient(MerlinxGetterConfig::fromRootConfig($config));
```

### 2. Search for offers

```php
$request = SearchExecutionRequest::fromArrays(
	search: [
		'Base' => [
			'StartDate' => ['Min' => '2026-03-01', 'Max' => '2026-03-31'],
		],
	],
	views: [
		'offerList' => ['limit' => 100],
		'fieldValues' => ['fieldList' => ['Base.StartDate', 'Base.XCity.Name']],
	],
);

$result = $client->executeSearch($request);

// Iterate over matched offers (keyed by full OfferId)
foreach ($result->view('offerList')['items'] ?? [] as $offerId => $item) {
	$base = $item['offer']['Base'];
	echo $offerId . ': ' . $base['StartDate'] . ' — ' . $base['Price']['Total']['amount'] . ' ' . $base['Price']['Total']['currency'] . PHP_EOL;
}

// Check whether the requested view limit was reached
if ($result->meta()['limitHits']['offerList'] ?? false) {
	echo 'Soft limit reached — there may be more results.' . PHP_EOL;
}

// Available start dates from the fieldValues view
$dates = $result->view('fieldValues')['Base.StartDate'] ?? [];
```

### 3. Get offer details

`getDetails()` accepts the composite `OfferId` from the search result (e.g. `BASE_ID|OPERATOR|ROOMCODE`).

```php
$details = $client->getDetails('BASE_ID|SNOW|NHx8');

$offer = $details['result']['offer'];
echo $offer['Accommodation']['Name'] . PHP_EOL;
echo $offer['Base']['Price']['Total']['amount'] . ' ' . $offer['Base']['Price']['Total']['currency'] . PHP_EOL;
```

### 4. Check live availability

```php
$availability = $client->getLiveAvailability('BASE_ID|SNOW|NHx8');

foreach ($availability['results'] ?? [] as $slot) {
	echo $slot['OfferId'] . ': ' . $slot['Availability']['base'] . PHP_EOL;
}

// Force a cache bypass (e.g. user is about to book)
$fresh = $client->getLiveAvailability('BASE_ID|SNOW|NHx8', force: true);
```

### 5. Portal search

```php
$portal = $client->portalSearch(['sortBy' => 'price', 'sortDirection' => 'asc']);

foreach ($portal['offers'] as $offer) {
	echo $offer['id'] . ': ' . $offer['name'] . PHP_EOL;
}

if ($portal['limitHit']) {
	echo 'Portal result set was truncated.' . PHP_EOL;
}
```

## Search Config

### Configuration Methods

The most significant recent enhancement is the **`search_engine` configuration** — a powerful way to define and enforce canonical search behavior across your application.

There are two main ways to configure `MerlinxGetterClient`:

#### 1. From Root Config (Recommended for Most Projects)

Use `MerlinxGetterConfig::fromRootConfig()` when you have a unified application configuration file (e.g., `config.php` in your project root). This method expects the full configuration array and automatically extracts MerlinX settings:

```php
$config = require __DIR__ . '/config.php'; // Your project's unified config

$client = new MerlinxGetterClient(
    MerlinxGetterConfig::fromRootConfig($config)
);
```

**Benefits:**
- Single source of truth for all configuration
- Easy to manage environment-specific settings
- Automatically handles default values

#### 2. From Array (For Advanced Control)

Use `MerlinxGetterConfig::fromArray()` when you need fine-grained control over MerlinX-specific settings:

```php
$config = [
    'system' => [ /* ... */ ],
    'merlinx' => [ /* ... */ ],
];

$client = new MerlinxGetterClient(
    MerlinxGetterConfig::fromArray($config['merlinx'])
);
```

**Benefits:**
- Explicit control over each configuration parameter
- Useful for specialized use cases or testing
- Minimal configuration footprint

---

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
- `cache.search_base.ttl_seconds`
- `cache.search_base.stale_seconds`

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
- `search_engine.cache.search_base.ttl_seconds`: same as `search_engine.cache.search.ttl_seconds`
- `search_engine.cache.search_base.stale_seconds`: same as `search_engine.cache.search.stale_seconds`
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
- automatic auth recovery on stale token: a 412 `autherror`, HTTP 401, or MerlinX token-corruption response triggers a forced token refresh and a single request replay; if the replayed request also returns an auth error, `HttpRequestException` is thrown
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

`getDetailsFresh()` bypasses details cache for the read and updates the cache when the `OfferId` can be normalized.

`putDetails()` overwrites a normalized details cache entry with a caller-provided `result.offer` payload and returns `false` when the `OfferId` cannot be normalized.

`getSearchBase()`:

- calls `/v5/data/travel/searchbase`
- caches only payloads with top-level `Status` and `Sections` arrays
- rejects semantic MerlinX error payloads instead of caching them
- serves the last valid stale payload when refresh fails and stale cache is still available

`executeRawSearch()`:

- calls `/v5/data/travel/search` with the exact caller-provided body
- skips search-engine condition merging and persistent search cache
- still uses package auth recovery, rate-limit retry, and debug-field sanitization

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
- auth recovery on stale token (force-refresh + replay, exception on persistent failure)

<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
	$request = searchRequest(
		['Base' => ['Operator' => ['VITX']]],
		['Base' => ['PriceRange' => 'ANY']],
		['mode' => 'request'],
		['offerList' => ['limit' => 10]],
		['rateLimitRetryMaxAttempts' => 7, 'ignored' => 'drop-me']
	);

	$normalizedOptions = [
		'rateLimitRetryMaxAttempts' => 7,
		'rateLimitRetryDelayMs' => 500,
		'rateLimitRetryBackoffMultiplier' => 2.0,
		'rateLimitRetryMaxDelayMs' => 8000,
	];

	$normalizedRequest = $request->withOptions($normalizedOptions);

	assertSameValue($request->search(), $normalizedRequest->search(), 'withOptions should preserve search payload.');
	assertSameValue($request->filter(), $normalizedRequest->filter(), 'withOptions should preserve filter payload.');
	assertSameValue($request->results(), $normalizedRequest->results(), 'withOptions should preserve results payload.');
	assertSameValue($request->views(), $normalizedRequest->views(), 'withOptions should preserve views payload.');
	assertSameValue($normalizedOptions, $normalizedRequest->options(), 'withOptions should replace runtime options with normalized options only.');

	$nextPageRequest = $normalizedRequest->withViews(['offerList' => ['limit' => 5, 'previousPageBookmark' => 'bookmark-1']]);
	assertSameValue($normalizedOptions, $nextPageRequest->options(), 'withViews should preserve normalized runtime options.');

	echo "PASS: SearchExecutionRequest can replace runtime options while preserving the rest of the payload.\n";
	exit(0);
} catch (Throwable $e) {
	echo 'FAIL: ' . $e->getMessage() . "\n";
	exit(1);
}

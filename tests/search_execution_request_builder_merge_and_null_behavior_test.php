<?php

declare(strict_types=1);

use Skionline\MerlinxGetter\Config\MerlinxGetterConfig;
use Skionline\MerlinxGetter\Search\Execution\SearchExecutionRequest;
use Skionline\MerlinxGetter\Search\Execution\SearchExecutionRequestBuilder;
use Skionline\MerlinxGetter\Search\Policy\VariantOperatorSearchGroups;

require __DIR__ . '/helpers/bootstrap.php';

/**
 * @param array<string, mixed> $conditionSearch
 * @param array<string, mixed> $conditionFilter
 * @param array<string, mixed> $conditionResults
 * @param array<string, mixed> $conditionViews
 * @param array<int, string> $operators
 */
function builderConfig(
	array $conditionSearch = [],
	array $conditionFilter = [],
	array $conditionResults = [],
	array $conditionViews = [],
	array $operators = ['SNOW'],
): MerlinxGetterConfig {
	return MerlinxGetterConfig::fromArray(baseMerlinxConfig([
		'search_engine' => [
			'operators' => $operators,
			'conditions' => [
				[
					'search' => $conditionSearch,
					'filter' => $conditionFilter,
					'results' => $conditionResults,
					'views' => $conditionViews,
				],
			],
		],
	]));
}

function firstBuiltQuery(MerlinxGetterConfig $config, SearchExecutionRequest $request, string $message): SearchExecutionRequest
{
	$queries = SearchExecutionRequestBuilder::build($config, $request);
	assertSameValue(1, count($queries), $message);

	return $queries[0];
}

function assertNoBuiltQueries(MerlinxGetterConfig $config, SearchExecutionRequest $request, string $message): void
{
	$queries = SearchExecutionRequestBuilder::build($config, $request);
	assertSameValue(0, count($queries), $message);
}

/**
 * @param array<int, mixed> $attributes
 * @return array<string, string>
 */
function attributeRulesByBaseCode(array $attributes): array
{
	$rules = [];
	foreach ($attributes as $attribute) {
		assertTrue(
			is_string($attribute) || is_int($attribute) || is_float($attribute),
			'Accommodation.Attributes should contain scalar attribute rules.'
		);

		$rule = trim((string) $attribute);
		$baseCode = ltrim($rule, '+-');
		assertTrue($rule !== '' && $baseCode !== '', 'Accommodation.Attributes should not contain empty rules.');
		$rules[$baseCode] = $rule;
	}

	ksort($rules);
	return $rules;
}

try {
	$scopeConfig = builderConfig(
		[
			'Base' => [
				'Operator' => ['VITX', 'SNOW'],
				'XCity' => [
					'Code' => 'LIM',
					'Name' => 'Limone',
				],
			],
		],
		operators: ['VITX', 'SNOW'],
	);

	$emptyRequestQuery = firstBuiltQuery(
		$scopeConfig,
		searchRequest(),
		'Empty request should still execute the configured search scope.'
	);
	assertSameValue(['VITX', 'SNOW'], $emptyRequestQuery->search()['Base']['Operator'] ?? null, 'Configured operators should be preserved for an empty request.');
	assertSameValue('Limone', $emptyRequestQuery->search()['Base']['XCity']['Name'] ?? null, 'Configured city scope should be preserved for an empty request.');

	$operatorSubsetQuery = firstBuiltQuery(
		$scopeConfig,
		searchRequest([
			'Base' => [
				'Operator' => ['SNOW'],
			],
		]),
		'Request operator subset should still execute inside configured scope.'
	);
	assertSameValue(['SNOW'], $operatorSubsetQuery->search()['Base']['Operator'] ?? null, 'Request operator subset should narrow configured operators instead of unioning them.');
	assertSameValue('Limone', $operatorSubsetQuery->search()['Base']['XCity']['Name'] ?? null, 'Request operator refinement should keep configured city scope.');

	$dateConfig = builderConfig(
		[
			'Base' => [
				'Operator' => ['SNOW'],
				'StartDate' => ['2026-03-01', '2026-03-02'],
			],
		],
		operators: ['SNOW'],
	);
	$dateSubsetQuery = firstBuiltQuery(
		$dateConfig,
		searchRequest([
			'Base' => [
				'StartDate' => ['2026-03-02', '2026-03-03'],
			],
		]),
		'Overlapping date lists should still execute inside configured scope.'
	);
	assertSameValue(['2026-03-02'], $dateSubsetQuery->search()['Base']['StartDate'] ?? null, 'Overlapping date lists should narrow to their intersection.');
	assertNoBuiltQueries(
		$dateConfig,
		searchRequest([
			'Base' => [
				'StartDate' => ['2026-04-01'],
			],
		]),
		'Disjoint date lists should make the branch unsatisfiable.'
	);

	$cityConfig = builderConfig(
		[
			'Base' => [
				'Operator' => ['SNOW'],
				'XCity' => [
					'Code' => 'LIM',
					'Name' => 'Limone',
				],
			],
		],
		operators: ['SNOW'],
	);
	assertNoBuiltQueries(
		$cityConfig,
		searchRequest([
			'Base' => [
				'XCity' => [
					'Code' => 'GEN',
					'Name' => 'Genoa',
				],
			],
		]),
		'Conflicting scalar/object city request should not fetch outside the configured city scope.'
	);

	$textQuery = firstBuiltQuery(
		$cityConfig,
		searchRequest([
			'Base' => [
				'XNameAndCodePartial' => 'foo',
			],
		]),
		'Text search should execute once inside configured scope.'
	);
	assertSameValue('Limone', $textQuery->search()['Base']['XCity']['Name'] ?? null, 'Text search should keep configured city scope.');
	assertSameValue('foo', $textQuery->search()['Base']['XNameAndCodePartial'] ?? null, 'Text search request field should refine the configured scope.');

	$fullDatasetConfig = builderConfig(operators: ['SNOW']);
	$fullDatasetQuery = firstBuiltQuery(
		$fullDatasetConfig,
		searchRequest([
			'Base' => [
				'XNameAndCodePartial' => 'foo',
			],
		]),
		'Empty configured condition should be permissive and still execute a user search request.'
	);
	assertSameValue('foo', $fullDatasetQuery->search()['Base']['XNameAndCodePartial'] ?? null, 'Full-dataset scoped request should preserve user text search.');

	$attributeConfig = builderConfig(
		[
			'Accommodation' => [
				'Attributes' => [
					'facility_pool',
					'facility_spa',
					'+facility_wifi',
					'-facility_adult',
				],
			],
		],
		operators: ['SNOW'],
	);
	$attributeQuery = firstBuiltQuery(
		$attributeConfig,
		searchRequest([
			'Accommodation' => [
				'Attributes' => [
					'facility_sauna',
					'+facility_pool',
					'+facility_spa',
					'-facility_spa',
					'-facility_smoking',
				],
			],
		]),
		'Accommodation attribute merge should collapse to one query even when raw lists do not intersect.'
	);
	$attributes = $attributeQuery->search()['Accommodation']['Attributes'] ?? null;
	assertTrue(is_array($attributes) && array_is_list($attributes), 'Accommodation.Attributes should be emitted as a list.');
	$attributeRules = attributeRulesByBaseCode($attributes);
	assertSameValue(count($attributeRules), count($attributes), 'Each base accommodation attribute should appear at most once after precedence merge.');
	assertSameValue(
		[
			'facility_adult' => '-facility_adult',
			'facility_pool' => '+facility_pool',
			'facility_sauna' => 'facility_sauna',
			'facility_smoking' => '-facility_smoking',
			'facility_spa' => '-facility_spa',
			'facility_wifi' => '+facility_wifi',
		],
		$attributeRules,
		'Accommodation.Attributes should merge unprefixed attrs, then + overrides, then - overrides.'
	);

	$groupsPolicy = new VariantOperatorSearchGroups(['SNOW']);
	$noOperatorsGroups = $groupsPolicy->build([], [['code' => 'ADULT']]);
	assertSameValue(1, count($noOperatorsGroups), 'Empty operators should still produce one group.');
	assertTrue(array_key_exists('operators', $noOperatorsGroups[0]), 'No-operators branch should return operators key.');
	assertSameValue(null, $noOperatorsGroups[0]['operators'], 'No-operators branch should keep operators as null.');
	assertSameValue([['code' => 'ADULT']], $noOperatorsGroups[0]['participants'] ?? null, 'No-operators branch should preserve participants.');

	$noParticipantsGroups = $groupsPolicy->build(['VITX'], []);
	assertSameValue(1, count($noParticipantsGroups), 'Empty participants should still produce one group.');
	assertSameValue(['VITX'], $noParticipantsGroups[0]['operators'] ?? null, 'No-participants branch should preserve operators.');
	assertTrue(array_key_exists('participants', $noParticipantsGroups[0]), 'No-participants branch should return participants key.');
	assertSameValue(null, $noParticipantsGroups[0]['participants'], 'No-participants branch should keep participants as null.');

	$nullLikeRequest = searchRequest([
		'Base' => [
			'Operator' => null,
		],
	]);
	$nullLikeQueries = SearchExecutionRequestBuilder::build($scopeConfig, $nullLikeRequest);
	assertSameValue(1, count($nullLikeQueries), 'Null operator input should still produce one query.');
	$nullLikeBase = $nullLikeQueries[0]->search()['Base'] ?? [];
	assertSameValue(['VITX', 'SNOW'], $nullLikeBase['Operator'] ?? null, 'Builder should normalize null operator input to configured operators.');
	assertTrue(!array_key_exists('ParticipantsList', $nullLikeBase), 'Builder should not emit ParticipantsList key when participant group value is null/empty.');

	$noOperatorConfigArray = baseMerlinxConfig();
	$noOperatorConfigArray['search_engine']['operators'] = [];
	$noOperatorConfig = MerlinxGetterConfig::fromArray($noOperatorConfigArray);
	$noOperatorRequest = searchRequest([
		'Base' => [
			'Operator' => [],
		],
	]);
	$noOperatorQueries = SearchExecutionRequestBuilder::build($noOperatorConfig, $noOperatorRequest);
	assertSameValue(1, count($noOperatorQueries), 'Empty configured operators should still produce one query.');
	$noOperatorBase = $noOperatorQueries[0]->search()['Base'] ?? [];
	assertTrue(!array_key_exists('Operator', $noOperatorBase), 'Builder should omit Base.Operator when normalized operator list is empty.');

	$duplicateNoOpConfig = MerlinxGetterConfig::fromArray(baseMerlinxConfig([
		'search_engine' => [
			'operators' => ['VITX'],
			'conditions' => [
				[
					'search' => [],
					'filter' => [],
				],
				[
					'search' => [],
					'filter' => [],
				],
			],
		],
	]));
	$duplicateNoOpRequest = searchRequest([
		'Base' => [
			'Operator' => ['VITX'],
		],
	]);
	$duplicateNoOpQueries = SearchExecutionRequestBuilder::build($duplicateNoOpConfig, $duplicateNoOpRequest);
	assertSameValue(1, count($duplicateNoOpQueries), 'Duplicate permissive config branches should still execute one deduped user-shaped request.');

	echo "PASS: SearchExecutionRequestBuilder scoped request behavior and VariantOperatorSearchGroups null behavior are covered.\n";
	exit(0);
} catch (Throwable $e) {
	echo 'FAIL: ' . $e->getMessage() . "\n";
	exit(1);
}

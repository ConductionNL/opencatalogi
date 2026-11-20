<?php

/**
 * The feed modal's conflict policy and its per-reason overrides (harvest-conflict-policies).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Harvest;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\Harvest\ConflictPolicy;
use OCA\OpenCatalogi\Service\Harvest\ConflictRulesCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Each choice stores its OpenRegister strategy, and the overrides compile to a
 * decision table that OpenRegister's own validator and evaluator accept.
 */
#[CoversClass(ConflictPolicy::class)]
#[CoversClass(ConflictRulesCompiler::class)]
class ConflictPolicyFormTest extends TestCase {

	/**
	 * Each of the four choices stores its strategy, and reads back as itself.
	 *
	 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
	 */
	public function testEachChoiceStoresItsStrategy(): void {
		$expected = [
			'manual-review' => 'manual',
			'overlay' => 'source-wins',
			'shadow-local' => 'local-wins',
			'reject-on-conflict' => 'reject',
		];
		foreach ($expected as $choice => $strategy) {
			$this->assertSame($strategy, ConflictPolicy::strategyFor(choice: $choice), $choice);
			$this->assertSame($choice, ConflictPolicy::choiceFor(strategy: $strategy), $strategy);
		}

		$this->expectException(InvalidArgumentException::class);
		ConflictPolicy::strategyFor(choice: 'newest-wins');
	}

	/**
	 * A feed saved without a choice, or with a strategy the modal does not offer,
	 * reads and stores as "Ask a person".
	 *
	 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
	 */
	public function testAFeedWithoutAChoiceReadsAskAPerson(): void {
		$this->assertSame('manual', ConflictPolicy::strategyFor(choice: null));
		$this->assertSame('manual', ConflictPolicy::strategyFor(choice: ''));
		$this->assertSame('manual-review', ConflictPolicy::choiceFor(strategy: null));
		$this->assertSame('manual-review', ConflictPolicy::choiceFor(strategy: 'newest-wins'));
	}

	/**
	 * Two overrides compile to one FIRST-hit row per reason, which reads back
	 * into the same selects.
	 *
	 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
	 */
	public function testOverridesCompileToOneRowPerReason(): void {
		$table = ConflictRulesCompiler::compile(overrides: ['local-edited' => 'overlay', 'pre-existing-claim' => 'reject-on-conflict']);

		$this->assertSame([
			'hitPolicy' => 'FIRST',
			'inputs' => [['name' => 'conflictReason', 'type' => 'string']],
			'outputs' => [['name' => 'strategy', 'type' => 'string']],
			'rules' => [
				['id' => 'local-edited', 'inputEntries' => ['"local-edited"'], 'outputEntries' => ['source-wins']],
				['id' => 'pre-existing-claim', 'inputEntries' => ['"pre-existing-claim"'], 'outputEntries' => ['reject']],
			],
		], $table);
		$this->assertSame(
			['foreign' => false, 'overrides' => ['local-edited' => 'overlay', 'pre-existing-claim' => 'reject-on-conflict']],
			ConflictRulesCompiler::read(table: $table)
		);

		// One override writes one row; local-deleted is never written.
		$one = ConflictRulesCompiler::compile(overrides: ['local-edited' => 'default', 'pre-existing-claim' => 'shadow-local', 'local-deleted' => 'overlay']);
		$this->assertSame(['pre-existing-claim'], array_column($one['rules'], 'id'));
	}

	/**
	 * OpenRegister accepts the compiled table and evaluates it: an overridden
	 * reason picks its strategy, any other reason matches no row.
	 *
	 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
	 */
	public function testOpenRegisterEvaluatesTheCompiledTable(): void {
		$validatorClass = 'OCA\\OpenRegister\\Service\\Dmn\\DecisionTableValidator';
		$evaluatorClass = 'OCA\\OpenRegister\\Service\\Dmn\\DecisionTableEvaluator';
		$this->assertTrue(class_exists($evaluatorClass), 'OpenRegister is checked out beside the app');

		$table = ConflictRulesCompiler::compile(overrides: ['pre-existing-claim' => 'reject-on-conflict']);
		$this->assertSame([], (new $validatorClass())->validate(table: $table));

		$evaluator = new $evaluatorClass();
		$hit = $evaluator->evaluate(decisionTable: $table, inputs: ['conflictReason' => 'pre-existing-claim']);
		$this->assertSame('reject', $hit['outputs']['strategy']);
		$this->assertSame(['pre-existing-claim'], $hit['matchedRuleIds']);

		// A reason without a row matches nothing; OpenRegister's resolver answers
		// that with the feed's conflictStrategy (app-harvest-fetchers-and-flow-node D-8).
		try {
			$evaluator->evaluate(decisionTable: $table, inputs: ['conflictReason' => 'local-edited']);
			$this->fail('a reason without a row must not match');
		} catch (\Throwable $e) {
			$this->assertSame('no_rule_matched', $e->getMessage());
		}
	}

	/**
	 * With every reason on "Same as the default" no table is written.
	 *
	 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
	 */
	public function testNoOverrideWritesNoTable(): void {
		$this->assertNull(ConflictRulesCompiler::compile(overrides: []));
		$this->assertNull(ConflictRulesCompiler::compile(overrides: ['local-edited' => 'default', 'pre-existing-claim' => 'default']));
		$this->assertSame(
			['foreign' => false, 'overrides' => ['local-edited' => 'default', 'pre-existing-claim' => 'default']],
			ConflictRulesCompiler::read(table: null)
		);
	}

	/**
	 * A table with more inputs than conflictReason is someone else's: it reads
	 * as foreign and survives a save unchanged, whatever the selects say.
	 *
	 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
	 */
	public function testAForeignTableIsLeftUntouched(): void {
		$foreign = [
			'hitPolicy' => 'UNIQUE',
			'inputs' => [['name' => 'conflictReason', 'type' => 'string'], ['name' => 'externalId', 'type' => 'string']],
			'outputs' => [['name' => 'strategy', 'type' => 'string']],
			'rules' => [['id' => 'r1', 'inputEntries' => ['"local-edited"', '"x"'], 'outputEntries' => ['reject']]],
		];

		$this->assertSame($foreign, ConflictRulesCompiler::compile(overrides: ['local-edited' => 'overlay'], existing: $foreign));
		$this->assertTrue(ConflictRulesCompiler::read(table: $foreign)['foreign']);
	}
}

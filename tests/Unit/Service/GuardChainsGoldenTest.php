<?php

/**
 * Golden tests for two lookups whose every branch decides an answer:
 * ServiceCatalogueService::decideAvailability() (why a catalogue entry
 * cannot be requested) and TooiVocabularyService::resolveSoortHandeling()
 * (which handling type a declared value names).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every branch of decideAvailability() and resolveSoortHandeling().
 */
class GuardChainsGoldenTest extends TestCase {

	/**
	 * Entries and the availability decided for them.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function entries(): array {
		$binding = ['caseType' => 'ct-1', 'audience' => 'resident', 'formName' => 'Form'];
		return [
			'no binding' => [['title' => 'A'], ['title' => 'A', 'available' => false, 'unavailableReason' => 'no-form-binding']],
			'binding not array' => [['formBinding' => 'x'], ['formBinding' => 'x', 'available' => false, 'unavailableReason' => 'no-form-binding']],
			'empty binding' => [['formBinding' => []], ['formBinding' => [], 'available' => false, 'unavailableReason' => 'no-form-binding']],
			'no case type' => [['formBinding' => ['caseType' => ' '] + $binding], ['formBinding' => ['caseType' => ' '] + $binding, 'available' => false, 'unavailableReason' => 'incomplete-form-binding']],
			'no audience' => [['formBinding' => ['audience' => ''] + $binding], ['formBinding' => ['audience' => ''] + $binding, 'available' => false, 'unavailableReason' => 'incomplete-form-binding']],
			'no form name' => [['formBinding' => ['formName' => "\t"] + $binding], ['formBinding' => ['formName' => "\t"] + $binding, 'available' => false, 'unavailableReason' => 'incomplete-form-binding']],
			'form name missing' => [['formBinding' => ['caseType' => 'ct-1', 'audience' => 'r']], ['formBinding' => ['caseType' => 'ct-1', 'audience' => 'r'], 'available' => false, 'unavailableReason' => 'incomplete-form-binding']],
			'unknown case type' => [['formBinding' => ['caseType' => 'nope'] + $binding], ['formBinding' => ['caseType' => 'nope'] + $binding, 'available' => false, 'unavailableReason' => 'unknown-case-type']],
			'unpublished case type' => [['formBinding' => ['caseType' => 'ct-2'] + $binding], ['formBinding' => ['caseType' => 'ct-2'] + $binding, 'available' => false, 'unavailableReason' => 'unpublished-case-type']],
			'padded case type' => [['formBinding' => ['caseType' => ' ct-1 '] + $binding, 'unavailableReason' => 'old'], ['formBinding' => ['caseType' => ' ct-1 '] + $binding, 'available' => true]],
			'available' => [['formBinding' => $binding, 'available' => false, 'unavailableReason' => 'old'], ['formBinding' => $binding, 'available' => true]],
			'published flag missing' => [['formBinding' => ['caseType' => 'ct-3'] + $binding], ['formBinding' => ['caseType' => 'ct-3'] + $binding, 'available' => true]],
		];

	}//end entries()

	/**
	 * The availability decided for one entry.
	 *
	 * @param array<string, mixed> $entry The entry.
	 * @param array<string, mixed> $expected The decided entry.
	 *
	 * @return void
	 */
	#[DataProvider('entries')]
	public function testDecideAvailability(array $entry, array $expected): void {
		$appManager = $this->createMock(IAppManager::class);
		$service = new ServiceCatalogueService(
			new OpenRegisterLookup($this->createMock(ContainerInterface::class), $appManager),
			$this->createMock(LoggerInterface::class)
		);
		$caseTypes = ['ct-1' => ['published' => true], 'ct-2' => ['published' => false], 'ct-3' => []];

		$this->assertSame($expected, $service->decideAvailability($entry, $caseTypes));

	}//end testDecideAvailability()

	/**
	 * Declared handling types and the member each names.
	 *
	 * @return array<string, array{0: string|null, 1: string|null}>
	 */
	public static function handlings(): array {
		return [
			'none'         => [null, 'ontvangst'],
			'blank'        => ['  ', 'ontvangst'],
			'label'        => ['vaststelling', 'vaststelling'],
			'padded upper' => [' Ondertekening ', 'ondertekening'],
			'bare id'      => ['c_641ecd76', 'vaststelling'],
			'upper id'     => ['C_E1EC050E', 'ondertekening'],
			'kern uri'     => [TooiVocabularyService::KERN_BASE . 'c_dfcee535', 'ontvangst'],
			'upper uri'    => [strtoupper(TooiVocabularyService::KERN_BASE) . 'C_641ECD76', 'vaststelling'],
			'unknown'      => ['besluit', null],
			'other uri'    => ['https://example.org/c_641ecd76', null],
		];

	}//end handlings()

	/**
	 * The member one declared handling type names.
	 *
	 * @param string|null $value The declared handling type.
	 * @param string|null $label The expected member's label, or null for none.
	 *
	 * @return void
	 */
	#[DataProvider('handlings')]
	public function testResolveSoortHandeling(?string $value, ?string $label): void {
		$resolved = (new TooiVocabularyService())->resolveSoortHandeling($value);
		if ($label === null) {
			$this->assertNull($resolved);
			return;
		}

		$this->assertSame($label, $resolved['label']);
		$this->assertStringStartsWith(TooiVocabularyService::KERN_BASE . 'c_', $resolved['uri']);
		$this->assertSame(array_keys($resolved), ['uri', 'label']);

	}//end testResolveSoortHandeling()
}//end class

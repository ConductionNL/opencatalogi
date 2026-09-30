<?php

/**
 * The Woo citizen journey schemas, validated as OpenRegister will see them.
 *
 * The register is assembled with SettingsService's own private deep merge (the
 * monolith plus every register.d fragment), and payloads are validated with the
 * same Opis validator OpenRegister uses. A payload the shipped schema refuses
 * cannot pass here.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for the collection, savedSearch and publication fragments.
 */
class WooJourneyRegisterTest extends TestCase {

	/**
	 * The effective register, merged the way loadSettings() merges it.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		$root = dirname(__DIR__, 3);
		$data = json_decode((string)file_get_contents($root . '/lib/Settings/publication_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);
		$fragments = glob($root . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string)file_get_contents($fragment), true);
			$this->assertIsArray($overlay, basename($fragment) . ' must parse');
			$data = $merge->invoke(null, $data, $overlay);
		}

		return $data;
	}

	/**
	 * Validate a payload against one schema of the effective register, with
	 * OpenRegister's widening of optional top-level properties to accept null.
	 *
	 * @param string               $slug    The schema slug.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return array{0: bool, 1: array<string, mixed>}
	 */
	private function validate(string $slug, array $payload): array {
		$fragment = $this->register()['components']['schemas'][$slug];
		$properties = $fragment['properties'];
		foreach ($properties as $name => $property) {
			if (in_array($name, $fragment['required'] ?? [], true) === false && isset($property['enum']) === false && is_string($property['type'] ?? null) === true) {
				$properties[$name]['type'] = [$property['type'], 'null'];
			}
		}

		$schema = ['type' => 'object', 'properties' => $properties, 'required' => ($fragment['required'] ?? [])];
		$result = (new Validator())->validate(json_decode((string)json_encode($payload)), (string)json_encode($schema));
		$errors = [];
		if ($result->isValid() === false) {
			$errors = (new ErrorFormatter())->format($result->error());
		}

		return [$result->isValid(), $errors];
	}

	/**
	 * A real dossier payload, the shape the collection service writes.
	 *
	 * @return array<string, mixed>
	 */
	private function dossier(): array {
		return [
			'title' => 'Windpark',
			'description' => 'Alles over het windpark',
			'owner' => 'subject-1',
			'items' => [
				[
					'id' => '0b6d0b1e-3e0b-4b0b-9b0b-0b0b0b0b0b01',
					'publication' => '0b6d0b1e-3e0b-4b0b-9b0b-0b0b0b0b0b02',
					'attachment' => null,
					'note' => 'Lees paragraaf 3',
					'addedAt' => '2026-09-30T10:00:00+00:00',
					'addedBy' => 'resident',
					'title' => 'Besluit windpark',
				],
				[
					'id' => '0b6d0b1e-3e0b-4b0b-9b0b-0b0b0b0b0b03',
					'publication' => '0b6d0b1e-3e0b-4b0b-9b0b-0b0b0b0b0b02',
					'attachment' => '4711',
					'note' => '',
					'addedAt' => '2026-09-30T10:05:00+00:00',
					'addedBy' => 'dossiq',
				],
			],
			'share' => null,
			'sourceOf' => ['case-123'],
		];
	}

	public function testTheRegisterCarriesBothNewSchemas(): void {
		$register = $this->register();
		$this->assertContains('collection', $register['components']['registers']['publication']['schemas']);
		$this->assertContains('savedSearch', $register['components']['registers']['publication']['schemas']);
		$this->assertSame('0.5.0', $register['components']['registers']['publication']['version']);
	}

	public function testADossierAsTheServiceWritesItIsAccepted(): void {
		[$valid, $errors] = $this->validate('collection', $this->dossier());
		$this->assertTrue($valid, json_encode($errors));
	}

	public function testASharedDossierIsAccepted(): void {
		$dossier = $this->dossier();
		$dossier['share'] = ['token' => '0b6d0b1e-3e0b-4b0b-9b0b-0b0b0b0b0b09.' . str_repeat('a', 48), 'createdAt' => '2026-09-30T10:00:00+00:00'];
		[$valid, $errors] = $this->validate('collection', $dossier);
		$this->assertTrue($valid, json_encode($errors));
	}

	public function testADossierWithoutOwnerIsRefused(): void {
		$dossier = $this->dossier();
		unset($dossier['owner']);
		[$valid] = $this->validate('collection', $dossier);
		$this->assertFalse($valid);
	}

	public function testAnItemWithoutPublicationIsRefused(): void {
		$dossier = $this->dossier();
		unset($dossier['items'][0]['publication']);
		[$valid] = $this->validate('collection', $dossier);
		$this->assertFalse($valid);
	}

	public function testTheDossierIsClosedToEveryoneButAdmin(): void {
		$authorization = $this->register()['components']['schemas']['collection']['authorization'];
		foreach (['read', 'create', 'update', 'delete'] as $verb) {
			$this->assertSame(['admin'], $authorization[$verb]);
		}
	}

	public function testASavedSearchAsTheServiceWritesItIsAccepted(): void {
		[$valid, $errors] = $this->validate('savedSearch', [
			'title' => 'Windpark',
			'owner' => 'subject-1',
			'query' => [
				'text' => 'windpark',
				'filters' => ['informatiecategorie' => ['infocat014'], 'organisation' => [], 'periodFrom' => '2026-01-01', 'periodTo' => ''],
				'catalog' => '',
			],
			'frequency' => 'daily',
			'active' => true,
			'lastRunAt' => '2026-09-30T07:00:00+00:00',
			'lastNotifiedAt' => '2026-09-30T07:00:00.123456+00:00',
			'lastMatches' => [['publication' => 'abc', 'title' => 'Besluit', 'url' => 'https://example.org/p/abc', 'publicationDate' => '2026-09-30T06:00:00+00:00']],
			'matchCount' => 1,
		]);
		$this->assertTrue($valid, json_encode($errors));
	}

	public function testAnUnknownFrequencyIsRefused(): void {
		[$valid, $errors] = $this->validate('savedSearch', ['title' => 'x', 'owner' => 's', 'query' => ['text' => 'x'], 'frequency' => 'hourly']);
		$this->assertFalse($valid);
		$this->assertArrayHasKey('/frequency', $errors);
	}

	/**
	 * @spec openspec/changes/woo-dossier-publication/specs/publications/spec.md#requirement-a-publication-records-its-kind-source-case-and-period-req-wdp-001
	 */
	public function testADecisionPublicationIsAccepted(): void {
		[$valid, $errors] = $this->validate('publication', [
			'title' => 'Woo-besluit windpark',
			'wooCategory' => 'infocat014',
			'publicationKind' => 'woo-besluit',
			'caseReference' => 'case-123',
			'period' => ['from' => '2025-01-01', 'to' => '2025-12-31'],
		]);
		$this->assertTrue($valid, json_encode($errors));
	}

	/**
	 * @spec openspec/changes/woo-dossier-publication/specs/publications/spec.md#requirement-a-publication-records-its-kind-source-case-and-period-req-wdp-001
	 */
	public function testAnUnknownKindIsRefused(): void {
		[$valid, $errors] = $this->validate('publication', ['title' => 'x', 'publicationKind' => 'concept']);
		$this->assertFalse($valid);
		$this->assertArrayHasKey('/publicationKind', $errors);
	}

	/**
	 * @spec openspec/changes/woo-dossier-publication/specs/publications/spec.md#requirement-a-publication-records-its-kind-source-case-and-period-req-wdp-001
	 */
	public function testThePublicationKeepsItsExistingPropertiesAndGainsAVersion(): void {
		$publication = $this->register()['components']['schemas']['publication'];
		$this->assertArrayHasKey('wooCategory', $publication['properties']);
		$this->assertArrayHasKey('organization', $publication['properties']);
		$this->assertSame('0.0.6', $publication['version']);
		$this->assertSame(['title'], $publication['required']);
	}
}

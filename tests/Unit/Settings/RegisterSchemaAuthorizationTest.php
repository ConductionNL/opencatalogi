<?php

/**
 * Every shipped register schema that holds sensitive-looking fields declares who may read it.
 *
 * OpenRegister reads a schema without an authorization block as readable and
 * writable by everyone, anonymous callers included. A schema that stores a
 * token, a hash, an address, a party, an answer or the user who did something
 * must therefore carry an explicit block.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec exclude test-only guard over the shipped register configuration.
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads every register document in lib/Settings as it ships.
 */
class RegisterSchemaAuthorizationTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	/**
	 * A property name that looks like it holds a secret or personal data.
	 */
	private const SENSITIVE = '/token|hash|address|party|answer|By$/i';

	/**
	 * Only administrators read or write these.
	 */
	private const ADMIN_ONLY = ['read' => ['admin'], 'create' => ['admin'], 'update' => ['admin'], 'delete' => ['admin']];

	/**
	 * Signed-in users read these; only administrators write them.
	 */
	private const SIGNED_IN_READ = ['read' => ['authenticated'], 'create' => ['admin'], 'update' => ['admin'], 'delete' => ['admin']];

	/**
	 * Anyone reads these; only administrators write them.
	 */
	private const PUBLIC_READ = ['read' => ['public'], 'create' => ['admin'], 'update' => ['admin'], 'delete' => ['admin']];

	/**
	 * The exact block of each schema that holds a secret, personal data or something an administrator alone writes.
	 */
	private const BLOCKS = [
		'inspection' => self::ADMIN_ONLY,
		'zienswijzeAsk' => self::ADMIN_ONLY,
		'depublication' => self::ADMIN_ONLY,
		'obligationSource' => self::ADMIN_ONLY,
		'statusSubscription' => self::ADMIN_ONLY,
		'recordVote' => self::ADMIN_ONLY,
		'articleVerdict' => self::ADMIN_ONLY,
		'publicationRule' => self::SIGNED_IN_READ,
		'publicationProcess' => self::SIGNED_IN_READ,
		'instanceBanner' => self::SIGNED_IN_READ,
		'serviceStatus' => self::PUBLIC_READ,
		'noticeBoard' => self::PUBLIC_READ,
		'serviceCatalogueEntry' => self::PUBLIC_READ,
		'caseTypeDefinition' => self::PUBLIC_READ,
		'notice' => [
			'read' => [['group' => 'public', 'match' => ['startDate' => ['$lte' => '$now'], 'endDate' => ['$gte' => '$now']]], 'authenticated'],
			'create' => ['admin'],
			'update' => ['admin'],
			'delete' => ['admin'],
		],
		'knowledgeArticle' => [
			'read' => [['group' => 'public', 'match' => ['draft' => false]], 'authenticated'],
			'create' => ['admin'],
			'update' => ['admin'],
			'delete' => ['admin'],
		],
	];

	/**
	 * The exact property rules of those schemas: every other property follows its schema's block.
	 *
	 * `reveal: explicit` keeps a hidden value out of filters, sorting, free-text search and facets.
	 */
	private const PROPERTY_RULES = [
		'serviceStatus' => ['stateSetBy' => ['read' => ['admin'], 'reveal' => 'explicit']],
		'noticeBoard' => ['moderator' => ['read' => ['admin'], 'reveal' => 'explicit']],
		'knowledgeArticle' => ['sourceCase' => ['read' => ['authenticated'], 'reveal' => 'explicit']],
		'caseTypeDefinition' => ['importedSnapshot' => ['read' => ['admin']]],
	];

	/**
	 * Every schema in the shipped register documents, merged by slug the way the import merges fragments.
	 *
	 * @return array<string, array{properties: array<int, string>, authorization: array<string, mixed>, propertyRules: array<string, mixed>, files: array<int, string>}>
	 */
	private static function schemas(): array {
		$files = array_merge(
			glob(self::SETTINGS . '/*.json'),
			glob(self::SETTINGS . '/register.d/*.json')
		);
		sort($files);

		$schemas = [];
		foreach ($files as $file) {
			$document = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			foreach (($document['components']['schemas'] ?? []) as $slug => $schema) {
				if (is_array($schema) === false) {
					continue;
				}

				$merged = ($schemas[$slug] ?? ['properties' => [], 'authorization' => [], 'propertyRules' => [], 'files' => []]);
				$merged['properties'] = array_values(array_unique(array_merge($merged['properties'], array_keys($schema['properties'] ?? []))));
				$merged['authorization'] = array_replace($merged['authorization'], (array)($schema['authorization'] ?? []));
				$merged['propertyRules'] = array_replace($merged['propertyRules'], array_filter(array_map(static fn (mixed $property): mixed => ($property['authorization'] ?? null), (array)($schema['properties'] ?? []))));
				$merged['files'][] = basename($file);
				$schemas[$slug] = $merged;
			}
		}

		return $schemas;
	}//end schemas()

	/**
	 * The guard finds schemas to check at all, so an empty glob cannot pass it.
	 */
	public function testTheRegisterDocumentsAreRead(): void {
		$schemas = self::schemas();

		$this->assertArrayHasKey('publication', $schemas);
		$this->assertArrayHasKey('inspection', $schemas);
		$this->assertContains('token', $schemas['inspection']['properties']);
	}//end testTheRegisterDocumentsAreRead()

	/**
	 * A schema with a sensitive-looking property declares an authorization block.
	 */
	public function testEverySchemaWithSensitiveFieldsDeclaresAuthorization(): void {
		$open = [];
		foreach (self::schemas() as $slug => $schema) {
			$sensitive = preg_grep(self::SENSITIVE, $schema['properties']);
			if ($sensitive !== [] && $schema['authorization'] === []) {
				$open[] = $slug . ' (' . implode(', ', $sensitive) . ') in ' . implode(', ', $schema['files']);
			}
		}

		$this->assertSame([], $open, 'Schemas with sensitive fields and no authorization block, readable by anyone');
	}//end testEverySchemaWithSensitiveFieldsDeclaresAuthorization()

	/**
	 * Every schema declares an authorization block, in its own file or in a fragment that extends it.
	 */
	public function testEverySchemaDeclaresAuthorization(): void {
		$open = [];
		foreach (self::schemas() as $slug => $schema) {
			if ($schema['authorization'] === []) {
				$open[] = $slug . ' in ' . implode(', ', $schema['files']);
			}
		}

		$this->assertSame([], $open, 'Schemas without an authorization block, readable and writable by anyone');
	}//end testEverySchemaDeclaresAuthorization()

	/**
	 * Each listed schema ships exactly its block, so widening any action fails here.
	 */
	public function testEachSchemaShipsExactlyItsBlock(): void {
		$schemas = self::schemas();

		foreach (self::BLOCKS as $slug => $block) {
			$this->assertSame($block, $schemas[$slug]['authorization'], $slug);
		}
	}//end testEachSchemaShipsExactlyItsBlock()

	/**
	 * Each listed schema ships exactly its property rules, so removing or widening one fails here.
	 */
	public function testEachSchemaShipsExactlyItsPropertyRules(): void {
		$schemas = self::schemas();

		foreach (array_keys(self::BLOCKS) as $slug) {
			$this->assertSame((self::PROPERTY_RULES[$slug] ?? []), $schemas[$slug]['propertyRules'], $slug);
		}
	}//end testEachSchemaShipsExactlyItsPropertyRules()
}//end class

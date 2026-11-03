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
	 * Schemas whose objects are personal data or secrets: never readable by the public.
	 */
	private const NOT_PUBLIC = [
		'inspection',
		'zienswijzeAsk',
		'statusSubscription',
		'recordVote',
		'articleVerdict',
		'depublication',
		'publicationProcess',
		'publicationRule',
		'obligationSource',
	];

	/**
	 * Every schema in the shipped register documents, merged by slug the way the import merges fragments.
	 *
	 * @return array<string, array{properties: array<int, string>, authorization: array<string, mixed>, files: array<int, string>}>
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

				$merged = ($schemas[$slug] ?? ['properties' => [], 'authorization' => [], 'files' => []]);
				$merged['properties'] = array_values(array_unique(array_merge($merged['properties'], array_keys($schema['properties'] ?? []))));
				$merged['authorization'] = array_replace($merged['authorization'], (array)($schema['authorization'] ?? []));
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
	 * The personal-data and secret schemas grant nothing to the public, and writes only to administrators.
	 */
	public function testThePrivateSchemasAreNotPublic(): void {
		$schemas = self::schemas();

		foreach (self::NOT_PUBLIC as $slug) {
			$authorization = $schemas[$slug]['authorization'];
			$this->assertNotSame([], $authorization, $slug);
			$this->assertStringNotContainsString('public', json_encode($authorization, JSON_THROW_ON_ERROR), $slug);
			foreach (['create', 'update', 'delete'] as $action) {
				$this->assertSame(['admin'], $authorization[$action], $slug . ' ' . $action);
			}
		}
	}//end testThePrivateSchemasAreNotPublic()

	/**
	 * A knowledge article is public only once it is no longer a draft.
	 */
	public function testADraftKnowledgeArticleIsNotPublic(): void {
		$read = self::schemas()['knowledgeArticle']['authorization']['read'];

		$this->assertSame([['group' => 'public', 'match' => ['draft' => false]], 'authenticated'], $read);
	}//end testADraftKnowledgeArticleIsNotPublic()
}//end class

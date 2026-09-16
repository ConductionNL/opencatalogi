<?php

/**
 * The connection declaration integriq reads.
 *
 * `lib/Settings/connections.json` is static JSON that integriq turns into the
 * rows of OpenCatalogi's Integrations page. Nothing in OpenCatalogi reads it at
 * runtime, so a broken file fails nowhere in this repo: integriq skips it whole
 * and the page goes empty on some other instance. Every assertion here is a
 * way that file could go wrong without a sound.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-001-opencatalogi-declares-its-outside-connections-in-one-static-file
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\AppInfo\Application;
use OCA\OpenCatalogi\Service\Connection\ConnectionReporter;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Guards lib/Settings/connections.json against hydra connection-registry D2 and D12.
 *
 * @coversNothing
 */
class ConnectionsDeclarationTest extends TestCase {

	/**
	 * Integriq's schema, fetched with `gh api` from integriq `development` on
	 * 2026-09-14, where the file was last changed in
	 * a93665880f7f552d8280b84a1f5ce402507c4466. It carries the hydra#673 and
	 * hydra#676 amendments (`reportedOnly`, `adapter.jsonPath`,
	 * `adapter.simulatedValues`, `requiredConfig` objects).
	 *
	 * @var string
	 */
	private const SCHEMA = '/tests/Fixtures/Integriq/connections.schema.json';

	/**
	 * The keys the file declares, in declared order.
	 *
	 * @var array<int, string>
	 */
	private const DECLARED_KEYS = ['directory', 'broadcast', 'woo-index'];

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The raw declaration file.
	 *
	 * @return string
	 */
	private function raw(): string {
		$raw = file_get_contents($this->root() . '/lib/Settings/connections.json');
		$this->assertIsString($raw, 'lib/Settings/connections.json must exist');

		return $raw;
	}//end raw()

	/**
	 * The decoded declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$decoded = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($decoded);

		return $decoded;
	}//end declaration()

	/**
	 * The vendored schema.
	 *
	 * @return string
	 */
	private function schema(): string {
		$schema = file_get_contents($this->root() . self::SCHEMA);
		$this->assertIsString($schema);

		return $schema;
	}//end schema()

	/**
	 * The file validates against integriq's JSON Schema.
	 *
	 * @return void
	 */
	public function testTheFileValidatesAgainstIntegriqsSchema(): void {
		$result = (new Validator())->validate(json_decode($this->raw()), $this->schema());

		$errors = [];
		if ($result->hasError() === true) {
			$errors = (new ErrorFormatter())->format($result->error());
		}

		$this->assertTrue($result->isValid(), (string) json_encode($errors, JSON_PRETTY_PRINT));
	}//end testTheFileValidatesAgainstIntegriqsSchema()

	/**
	 * The schema check can fail: a misspelled field is refused.
	 *
	 * Without this control a validator that accepts everything would pass the
	 * test above as well.
	 *
	 * @return void
	 */
	public function testTheSchemaRefusesAnUnknownField(): void {
		$declaration = json_decode($this->raw());
		$declaration->connections[0]->setingsUrl = '/settings/admin/opencatalogi#section-federation-sync';

		$this->assertFalse((new Validator())->validate($declaration, $this->schema())->isValid());
	}//end testTheSchemaRefusesAnUnknownField()

	/**
	 * The file names the app it ships in.
	 *
	 * Integriq refuses a file whose `app` differs from the app it was read from.
	 *
	 * @return void
	 */
	public function testTheFileNamesThisApp(): void {
		// Deliberately file_get_contents() + simplexml_load_string() rather than
		// simplexml_load_file(). Under the Nextcloud bootstrap lib/base.php calls
		// libxml_set_external_entity_loader() with a loader returning null, and
		// that resolver also handles the primary document, so load_file() returns
		// false for a well-formed info.xml. Parsing a string never touches it.
		$infoXml = simplexml_load_string(
			(string)file_get_contents($this->root() . '/appinfo/info.xml')
		);

		$this->assertNotFalse($infoXml);
		$this->assertSame((string) $infoXml->id, $this->declaration()['app']);
		$this->assertSame(Application::APP_ID, $this->declaration()['app']);
		$this->assertSame(ConnectionReporter::APP_ID, $this->declaration()['app']);
	}//end testTheFileNamesThisApp()

	/**
	 * The keys are unique, in rising order, and the ones the reporter sends.
	 *
	 * A row is keyed by app and key, so a second entry with the same key would
	 * overwrite the first. A report for a key the file does not declare is
	 * refused by integriq with only a warning in its log.
	 *
	 * @return void
	 */
	public function testTheKeysAreUniqueOrderedAndKnownToTheReporter(): void {
		$connections = $this->declaration()['connections'];
		$keys        = array_column($connections, 'key');

		$this->assertSame(array_values(array_unique($keys)), $keys, 'a key is declared twice');
		$this->assertSame(self::DECLARED_KEYS, $keys);
		$this->assertSame(ConnectionReporter::KEYS, $keys);

		$orders = array_column($connections, 'order');
		$sorted = $orders;
		sort($sorted);
		$this->assertSame($sorted, $orders);
		$this->assertCount(count($keys), array_unique($orders));
	}//end testTheKeysAreUniqueOrderedAndKnownToTheReporter()

	/**
	 * Every refresh the reporter can send names a declared key.
	 *
	 * @return void
	 */
	public function testEveryRefreshNamesADeclaredKey(): void {
		$keys = array_column($this->declaration()['connections'], 'key');

		$this->assertSame([], array_diff(array_keys(ConnectionReporter::REFRESH_KEYS), $keys));
		$this->assertSame([], array_diff(ConnectionReporter::THROTTLED_KEYS, $keys));
	}//end testEveryRefreshNamesADeclaredKey()

	/**
	 * No text a reader sees carries an em-dash or a Title Case title (voice rule 8).
	 *
	 * @return void
	 */
	public function testNoTextBreaksTheVoiceRules(): void {
		$this->assertStringNotContainsString("\u{2014}", $this->raw());
		$this->assertStringNotContainsString('--', $this->raw());

		foreach ($this->declaration()['connections'] as $connection) {
			$words = explode(' ', (string) $connection['title']);
			foreach (array_slice($words, 1) as $word) {
				$this->assertSame(mb_strtolower($word), $word, $connection['key'] . ' title is not sentence case');
			}
		}
	}//end testNoTextBreaksTheVoiceRules()

	/**
	 * Every settings link lands on a section id the admin settings page renders.
	 *
	 * The admin section is `opencatalogi` (OpenCatalogiAdmin::getSection()). A
	 * link to an id no element carries opens the settings page at the top and
	 * logs nothing.
	 *
	 * @return void
	 */
	public function testEverySettingsLinkPointsAtASectionThatExists(): void {
		$settingsPage = (string) file_get_contents($this->root() . '/src/views/settings/Settings.vue');
		$admin        = (string) file_get_contents($this->root() . '/lib/Settings/OpenCatalogiAdmin.php');
		$this->assertStringContainsString("\$sectionName = 'opencatalogi';", $admin);

		$linked = [];
		foreach ($this->declaration()['connections'] as $connection) {
			if (array_key_exists('settingsUrl', $connection) === false) {
				continue;
			}

			$url = (string) $connection['settingsUrl'];
			$this->assertMatchesRegularExpression(
				'~^/settings/admin/opencatalogi#section-[a-z0-9-]+$~',
				$url,
				$connection['key'] . ' links somewhere other than an OpenCatalogi admin section'
			);

			$anchor = substr($url, ((int) strpos($url, '#') + 1));
			$this->assertSame(
				1,
				substr_count($settingsPage, 'id="' . $anchor . '"'),
				$connection['key'] . ' links to #' . $anchor . ', and Settings.vue does not carry that id once'
			);
			$linked[] = $connection['key'];
		}

		$this->assertSame(['directory', 'woo-index'], $linked);
	}//end testEverySettingsLinkPointsAtASectionThatExists()

	/**
	 * Every row is reported only, and none carries config for integriq to guess from.
	 *
	 * `default_directory_url` falls back to a constant when unset, so a
	 * requiredConfig on it would read Not configured on a default install.
	 *
	 * @return void
	 */
	public function testEveryConnectionIsReportedOnly(): void {
		foreach ($this->declaration()['connections'] as $connection) {
			$key = (string) $connection['key'];
			$this->assertTrue($connection['reportedOnly'] ?? false, $key);
			$this->assertArrayNotHasKey('requiredConfig', $connection, $key);
			$this->assertArrayNotHasKey('adapter', $connection, $key);
			$this->assertStringStartsWith('Not checked yet.', (string) ($connection['unconfiguredMessage'] ?? ''), $key);
		}

		$directory = (string) file_get_contents($this->root() . '/lib/Service/DirectoryService.php');
		$this->assertStringContainsString('Application::DEFAULT_DIRECTORY_URL', $directory);
	}//end testEveryConnectionIsReportedOnly()
}//end class

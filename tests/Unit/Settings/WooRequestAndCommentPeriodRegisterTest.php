<?php

/**
 * Register-shape test for the Woo request and the comment period.
 *
 * The register is read the way `SettingsService::loadSettings()` assembles it:
 * the `lib/Settings/publication_register.json` monolith deep-merged with every
 * `lib/Settings/register.d/*.json` fragment. A test that read the monolith alone
 * would pass while the effective register was broken.
 *
 * The important assertion here is not that the schemas exist. It is that a REAL
 * payload, built by the REAL writer, is accepted by the REAL schema fragment:
 * four merged, unit-green fixes in this fleet could never run live because the
 * service wrote something its own schema refused, and a unit test that builds its
 * own payload cannot see that.
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

namespace Unit\Settings;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Woo\WooRequestService;
use PHPUnit\Framework\TestCase;

/**
 * Guards the shipped Woo request and comment period schemas.
 */
class WooRequestAndCommentPeriodRegisterTest extends TestCase {

	/**
	 * The two schemas this change ships.
	 *
	 * @var array<int, string>
	 */
	private const SLUGS = ['wooRequest', 'commentPeriod'];

	/**
	 * Assemble the effective register exactly as SettingsService does.
	 *
	 * @return array<string, mixed> The merged register configuration.
	 */
	private function effectiveRegister(): array {
		$root = dirname(__DIR__, 3);

		$base = json_decode((string)file_get_contents($root . '/lib/Settings/publication_register.json'), true);
		$this->assertIsArray($base, 'publication_register.json must parse as JSON');

		$fragments = glob($root . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$data = json_decode((string)file_get_contents($fragment), true);
			$this->assertIsArray($data, $fragment . ' must parse as JSON');
			$base = $this->deepMerge($base, $data);
		}

		return $base;

	}//end effectiveRegister()

	/**
	 * Mirror of `SettingsService::deepMergeConfig()`.
	 *
	 * @param array<mixed> $base The base document.
	 * @param array<mixed> $overlay The fragment to merge on top.
	 *
	 * @return array<mixed> The merged result.
	 */
	private function deepMerge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
			) {
				$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
				$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
				if ($baseIsList === true && $overlayIsList === true) {
					$base[$key] = array_merge($base[$key], $value);
				} else {
					$base[$key] = $this->deepMerge($base[$key], $value);
				}
			} else {
				$base[$key] = $value;
			}
		}

		return $base;

	}//end deepMerge()

	/**
	 * 🔴 Every schema declares a slug.
	 *
	 * A fragment without one is REJECTED by the import while the app's re-import
	 * reports success. Sixteen of a sibling app's schemas went dark for nine days
	 * exactly this way.
	 *
	 * @return void
	 */
	public function testEverySchemaDeclaresASlug(): void {
		$schemas = $this->effectiveRegister()['components']['schemas'];

		foreach (self::SLUGS as $slug) {
			$this->assertArrayHasKey($slug, $schemas, 'The effective register must ship a "' . $slug . '" schema.');
			$this->assertSame(
				$slug,
				($schemas[$slug]['slug'] ?? null),
				'The "' . $slug . '" schema must declare its slug: a slugless fragment is rejected in silence.'
			);
		}

	}//end testEverySchemaDeclaresASlug()

	/**
	 * Both schemas are wired into the publication register itself.
	 *
	 * @return void
	 */
	public function testBothSchemasAreRegisteredOnThePublicationRegister(): void {
		$register = $this->effectiveRegister()['components']['registers']['publication'];

		foreach (self::SLUGS as $slug) {
			$this->assertContains($slug, $register['schemas']);
			$this->assertArrayHasKey($slug, $register['configuration']['schemas']);
		}

	}//end testBothSchemasAreRegisteredOnThePublicationRegister()

	/**
	 * Every property carries a title and a description, so no generated form or
	 * table header leaks a raw technical key.
	 *
	 * @return void
	 */
	public function testEveryPropertyCarriesATitleAndADescription(): void {
		$schemas = $this->effectiveRegister()['components']['schemas'];

		foreach (self::SLUGS as $slug) {
			foreach ($schemas[$slug]['properties'] as $name => $property) {
				$this->assertNotEmpty(($property['title'] ?? ''), $slug . '.' . $name . ' must declare a title.');
				$this->assertNotEmpty(
					($property['description'] ?? ''),
					$slug . '.' . $name . ' must declare a description.'
				);
			}
		}

	}//end testEveryPropertyCarriesATitleAndADescription()

	/**
	 * The schemas ship as a register.d fragment, never inlined into the monolith.
	 *
	 * Only a fragment change alters the folded import version, so a monolith-only
	 * edit leaves the version-gated import closed and the schema never provisions.
	 *
	 * @return void
	 */
	public function testTheSchemasShipAsAFragment(): void {
		$monolith = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/publication_register.json');

		foreach (self::SLUGS as $slug) {
			$this->assertStringNotContainsString($slug, $monolith);
		}

	}//end testTheSchemasShipAsAFragment()

	/**
	 * 🔴 The REAL payload the REAL writer produces is accepted by the REAL schema.
	 *
	 * Every key is a declared property, every required property is present, and
	 * every enum value is a member. This is the check that catches a null against
	 * a date-time, a property the writer invented, and an enum member the schema
	 * never listed.
	 *
	 * @return void
	 */
	public function testTheWriterProducesAPayloadTheSchemaAccepts(): void {
		$schema = $this->effectiveRegister()['components']['schemas']['wooRequest'];
		$payload = (new WooRequestService())->receive(
			input: [
				'requestedInformation' => 'Every email about the bridge contract.',
				'requesterName' => 'J. de Vries',
				'requesterEmail' => 'j@example.org',
				'requesterPhone' => '+31 6 12345678',
				'requesterAddress' => 'Dorpsstraat 1, Utrecht',
				'channel' => 'post',
			],
			receivedBy: 'alice',
			now: new DateTimeImmutable('2026-03-02T09:00:00+00:00')
		);

		$this->assertPayloadFits(schema: $schema, payload: $payload, slug: 'wooRequest');

		// And again after the term is recorded, because that write adds a
		// `date-time` property and is the one that would carry a null.
		$armed = (new WooRequestService())->withTerm(
			request: $payload,
			term: ['timer' => 't-1', 'dueAt' => '2026-03-30T09:00:00+00:00', 'extensionCount' => 1]
		);
		$this->assertPayloadFits(schema: $schema, payload: $armed, slug: 'wooRequest');

	}//end testTheWriterProducesAPayloadTheSchemaAccepts()

	/**
	 * 🔴 The comment period writer also produces a payload its schema accepts.
	 *
	 * Built through the real service over a faked term engine, because the end
	 * date is the engine's answer and not this app's.
	 *
	 * @return void
	 */
	public function testTheCommentPeriodWriterProducesAPayloadTheSchemaAccepts(): void {
		$schema = $this->effectiveRegister()['components']['schemas']['commentPeriod'];

		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) {
				if ($id === 'OCA\OpenRegister\Service\Flow\Timer\SlaCalculator') {
					return new \Unit\Service\Publication\FakeSlaCalculator();
				}

				return new \Unit\Service\Publication\FakeCalendarService();
			}
		);

		$config = $this->createMock(\OCP\IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$urls = $this->createMock(\OCP\IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $p): string => 'https://example.org' . $p);

		$appManager = $this->createMock(\OCP\App\IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$service = new \OCA\OpenCatalogi\Service\Publication\CommentPeriodService(
			new \OCA\OpenCatalogi\Service\Publication\TermRoll($container, $appManager),
			$config,
			$urls
		);

		// 40 days from a Monday lands on a Saturday, so this payload is the one
		// that ALSO carries the two roll-explanation properties.
		$payload = $service->open(
			publication: ['id' => 'pub-1'],
			termDays: 40,
			legalRemedy: 'bezwaar',
			announcementUrl: 'https://officielebekendmakingen.nl/stcrt-2026-1',
			automaticWithdrawal: true,
			openedBy: 'alice',
			start: new DateTimeImmutable('2026-03-02T09:00:00+00:00')
		);

		$this->assertArrayHasKey('rolledBy', $payload, 'This payload must exercise the roll explanation properties.');
		$this->assertPayloadFits(schema: $schema, payload: $payload, slug: 'commentPeriod');

	}//end testTheCommentPeriodWriterProducesAPayloadTheSchemaAccepts()

	/**
	 * Assert a payload fits a schema: declared keys, required keys, enum members,
	 * and no null against a typed property.
	 *
	 * @param array<string, mixed> $schema The schema.
	 * @param array<string, mixed> $payload The payload.
	 * @param string $slug The schema slug, for the message.
	 *
	 * @return void
	 */
	private function assertPayloadFits(array $schema, array $payload, string $slug): void {
		$properties = $schema['properties'];

		foreach (array_keys($payload) as $key) {
			if ($key === 'id' || $key === 'uuid') {
				continue;
			}

			$this->assertArrayHasKey(
				$key,
				$properties,
				$slug . ' has no property "' . $key . '", so the writer writes something the schema has no home for.'
			);
		}

		foreach (($schema['required'] ?? []) as $required) {
			$this->assertArrayHasKey(
				$required,
				$payload,
				$slug . '.' . $required . ' is required and the writer does not set it.'
			);
			$this->assertNotSame('', $payload[$required], $slug . '.' . $required . ' is required and written empty.');
		}

		foreach ($payload as $key => $value) {
			$property = ($properties[$key] ?? []);

			$this->assertNotNull(
				$value,
				$slug . '.' . $key . ' is written as null. A null against a typed property passes a unit test and is '
				. 'refused by the register the first time it runs live.'
			);

			if (isset($property['enum']) === true && $value !== '') {
				$this->assertContains(
					$value,
					$property['enum'],
					$slug . '.' . $key . ' is written as "' . var_export($value, true) . '", which the enum does not list.'
				);
			}

			if (($property['format'] ?? '') === 'date-time' && $value !== '') {
				$this->assertNotFalse(
					strtotime((string)$value),
					$slug . '.' . $key . ' is `format: date-time` and "' . (string)$value . '" is not a date-time.'
				);
			}
		}

	}//end assertPayloadFits()
	/**
	 * Every demo row the app ships for these two schemas is one the app admits.
	 *
	 * ADR-111 rule 1 asks for three rows per schema, and counting them only
	 * proves somebody wrote something. Demo data that fails its own schema is
	 * dropped in silence on a fresh install, in front of whoever asked for the
	 * demo, so each row is held to the same shape the writer is held to.
	 *
	 * @return void
	 */
	public function testEveryShippedDemoRowIsOneTheAppAdmits(): void {
		$root = dirname(__DIR__, 3);
		$mock = json_decode(
			(string)file_get_contents($root . '/lib/Settings/opencatalogi_mock_register.json'),
			true
		);
		$this->assertIsArray($mock, 'opencatalogi_mock_register.json must parse as JSON');

		$schemas = $this->effectiveRegister()['components']['schemas'];
		$seen = ['wooRequest' => 0, 'commentPeriod' => 0];

		foreach (($mock['components']['objects'] ?? []) as $index => $row) {
			$slug = (string)($row['@self']['schema'] ?? '');
			if (array_key_exists($slug, $seen) === false) {
				continue;
			}

			$seen[$slug]++;

			$this->assertSame(
				'publication',
				(string)($row['@self']['register'] ?? ''),
				$slug . ' demo row ' . (string)$index . ' names a register this app does not supply.'
			);
			$this->assertNotSame(
				'',
				trim((string)($row['@self']['slug'] ?? '')),
				$slug . ' demo row ' . (string)$index . ' has no slug, and a fragment without one is refused on import.'
			);

			$body = $row;
			unset($body['@self']);
			$this->assertPayloadFits($schemas[$slug], $body, $slug);

			foreach ($body as $key => $value) {
				$maxLength = ($schemas[$slug]['properties'][$key]['maxLength'] ?? null);
				if ($maxLength === null || is_string($value) === false) {
					continue;
				}

				$this->assertLessThanOrEqual(
					$maxLength,
					mb_strlen($value),
					$slug . '.' . $key . ' is longer than its maxLength, so the column truncates or the save fails.'
				);
			}
		}

		foreach ($seen as $slug => $count) {
			$this->assertSame(
				3,
				$count,
				$slug . ' ships ' . (string)$count . ' demo row(s). ADR-111 rule 1 asks for three: one row cannot show '
				. 'a list as a list and leaves a detail page with no sibling to page to.'
			);
		}

	}//end testEveryShippedDemoRowIsOneTheAppAdmits()

	/**
	 * The demo dataset version moves forward, never back.
	 *
	 * A version that moves backwards reads as an older dataset than the one
	 * already imported, and the importer then declines to replace it.
	 *
	 * @return void
	 */
	public function testTheDemoDatasetVersionMovesForward(): void {
		$root = dirname(__DIR__, 3);
		$mock = json_decode(
			(string)file_get_contents($root . '/lib/Settings/opencatalogi_mock_register.json'),
			true
		);

		$this->assertIsArray($mock);
		$this->assertGreaterThanOrEqual(
			0,
			version_compare((string)($mock['info']['version'] ?? '0.0.0'), '1.0.2'),
			'The demo dataset version must be at least 1.0.2, the version that shipped the Woo request and comment '
			. 'period rows.'
		);

	}//end testTheDemoDatasetVersionMovesForward()
}//end class

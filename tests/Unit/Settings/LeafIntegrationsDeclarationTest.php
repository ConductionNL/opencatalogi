<?php

/**
 * The integration leaves OpenCatalogi declares (leaf-integrations).
 *
 * Reads the SHIPPED register, the monolith with every register.d fragment merged
 * onto it through SettingsService::deepMergeConfig() itself, and the shipped
 * manifest, so the leaf surface asserted here is the one an instance imports and
 * renders.
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
 *
 * @spec openspec/changes/leaf-integrations/specs/integration-leaves/spec.md
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the leaf declarations in the register and the manifest.
 */
#[CoversNothing]
class LeafIntegrationsDeclarationTest extends TestCase {

	/**
	 * Properties a mail template must never set: they are the publication gate,
	 * the lifecycle or the editor's classification.
	 */
	private const FORBIDDEN_TEMPLATE_KEYS = [
		'publicationDate',
		'depublicationDate',
		'status',
		'organization',
		'themes',
	];

	/**
	 * The shipped register, monolith plus every register.d fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		$settings = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($settings . '/publication_register.json'), true);

		$merge = new \ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);

		$fragments = glob($settings . '/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string)file_get_contents($fragment), true);
			$merged = $merge->invoke(null, $merged, $overlay);
		}

		return $merged;
	}

	/**
	 * The publication schema's configuration as shipped.
	 *
	 * @return array<string, mixed>
	 */
	private function publicationConfiguration(): array {
		return $this->register()['components']['schemas']['publication']['configuration'];
	}

	/**
	 * The shipped manifest.
	 *
	 * @return array<string, mixed>
	 */
	private function manifest(): array {
		$file = __DIR__ . '/../../../src/manifest.json';
		return json_decode((string)file_get_contents($file), true);
	}

	/**
	 * A manifest page by id.
	 *
	 * @param string $id The page id.
	 *
	 * @return array<string, mixed>
	 */
	private function page(string $id): array {
		foreach ($this->manifest()['pages'] as $page) {
			if ($page['id'] === $id) {
				return $page;
			}
		}

		$this->fail("No manifest page $id");
	}

	/**
	 * The publication links mail and calendar, beside its existing keys.
	 *
	 * @return void
	 */
	public function testPublicationDeclaresMailAndCalendar(): void {
		$configuration = $this->publicationConfiguration();

		$this->assertSame(['mail', 'calendar'], $configuration['linkedTypes']);
		$this->assertFalse($configuration['autoPublish']);
	}

	/**
	 * The template maps exactly title, summary and a provenance description.
	 *
	 * @return void
	 */
	public function testTheMailTemplateMapsOnlyProseProperties(): void {
		$template = $this->publicationConfiguration()['mailObjectTemplate'];

		$this->assertSame(
			[
				'title' => '{{subject}}',
				'summary' => '{{preview}}',
				'description' => "Received by email from {{senderName}} <{{sender}}> on {{date}}.\n\n{{preview}}",
			],
			$template
		);
	}

	/**
	 * The template cannot smuggle publication state (REQ-002).
	 *
	 * @return void
	 */
	public function testTheMailTemplateSetsNoGateLifecycleOrClassificationField(): void {
		$template = $this->publicationConfiguration()['mailObjectTemplate'];

		foreach (array_keys($template) as $key) {
			$this->assertNotContains($key, self::FORBIDDEN_TEMPLATE_KEYS);
			$this->assertStringNotContainsStringIgnoringCase('retention', $key);
		}
	}

	/**
	 * Every template key is a real publication property, and every value is a
	 * scalar, as OpenRegister's mailObjectTemplate validation requires.
	 *
	 * @return void
	 */
	public function testEveryTemplateKeyIsAPublicationProperty(): void {
		$schema = $this->register()['components']['schemas']['publication'];

		foreach ($schema['configuration']['mailObjectTemplate'] as $key => $value) {
			$this->assertArrayHasKey($key, $schema['properties']);
			$this->assertIsString($value);
		}
	}

	/**
	 * No schema other than publication carries a leaf declaration.
	 *
	 * @return void
	 */
	public function testNoOtherSchemaDeclaresLeaves(): void {
		$declaring = [];
		foreach ($this->register()['components']['schemas'] as $slug => $schema) {
			$configuration = $schema['configuration'] ?? [];
			if (isset($configuration['linkedTypes']) === true || isset($configuration['mailObjectTemplate']) === true) {
				$declaring[] = $slug;
			}
		}

		$this->assertSame(['publication'], $declaring);
	}

	/**
	 * PublicationDetail carries the calendar leaf as a planning widget, placed in
	 * the layout.
	 *
	 * @return void
	 */
	public function testPublicationDetailHasThePlanningWidget(): void {
		$config = $this->page('PublicationDetail')['config'];

		$widgets = array_values(array_filter($config['widgets'], static fn (array $w): bool => $w['id'] === 'pub-calendar'));
		$this->assertSame(
			[
				[
					'id' => 'pub-calendar',
					'type' => 'integration',
					'integrationId' => 'calendar',
					'title' => 'Planning (does not publish)',
					'icon' => 'CalendarClock',
				],
			],
			$widgets
		);

		$placed = array_filter($config['layout'], static fn (array $cell): bool => $cell['widgetId'] === 'pub-calendar');
		$this->assertCount(1, $placed);
	}

	/**
	 * The integration widgets are the pre-existing three plus the calendar leaf,
	 * and the pre-existing ones are unchanged.
	 *
	 * @return void
	 */
	public function testTheIntegrationWidgetsAreExactlyTheDeclaredSurface(): void {
		$found = [];
		foreach ($this->manifest()['pages'] as $page) {
			foreach ($page['config']['widgets'] ?? [] as $widget) {
				if (($widget['type'] ?? null) === 'integration') {
					$found[$widget['id']] = $widget['integrationId'];
				}
			}
		}

		ksort($found);
		$this->assertSame(
			[
				'org-image' => 'photos',
				'pub-calendar' => 'calendar',
				'pub-links' => 'bookmarks',
				'pub-photos' => 'photos',
			],
			$found
		);
	}

	/**
	 * Every declared linked type has a widget or, for mail, the Mail sidebar.
	 *
	 * @return void
	 */
	public function testEveryLinkedTypeIsRendered(): void {
		$rendered = ['mail'];
		foreach ($this->page('PublicationDetail')['config']['widgets'] as $widget) {
			if (($widget['type'] ?? null) === 'integration') {
				$rendered[] = $widget['integrationId'];
			}
		}

		foreach ($this->publicationConfiguration()['linkedTypes'] as $type) {
			$this->assertContains($type, $rendered);
		}
	}
}

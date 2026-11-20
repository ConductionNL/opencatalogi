<?php

/**
 * The five Woo schemas opt in to OpenRegister's read logging.
 *
 * @category Test
 * @package  Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Repair\SeedWooProcessingActivity;
use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Read against the register the app imports: the monolith with every fragment merged in (REQ-WAB-001).
 */
#[CoversNothing]
class WooReadLoggingDeclarationTest extends TestCase {

	/**
	 * The five schemas, with the version each had before this change.
	 */
	private const BEFORE = [
		'wooAssessment' => '0.6.0',
		'wooBatch'      => '0.3.0',
		'depublication' => '0.2.2',
		'wooRequest'    => '0.2.0',
		'publication'   => '0.0.8',
	];

	/**
	 * The merged register, as SettingsService builds it for the import.
	 *
	 * @return array The register document.
	 */
	public static function mergedRegister(): array {
		$root  = dirname(__DIR__, 3);
		$read  = new ReflectionMethod(SettingsService::class, 'readConfigurationFile');
		$merge = new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments');

		return $merge->invoke(null, $read->invoke(null, $root.'/lib/Settings/publication_register.json'), $root.'/lib/Settings/register.d');

	}//end mergedRegister()

	/**
	 * Each schema declares logReads with the seeded activity, and its version is bumped.
	 *
	 * @return void
	 */
	public function testTheFiveSchemasOptIn(): void {
		$schemas = self::mergedRegister()['components']['schemas'];

		foreach (self::BEFORE as $slug => $before) {
			$processing = ($schemas[$slug]['configuration']['x-openregister-processing'] ?? null);
			$this->assertIsArray($processing, $slug.' does not declare x-openregister-processing');
			$this->assertTrue($processing['logReads'], $slug);
			$this->assertSame(SeedWooProcessingActivity::CODE, $processing['activity'], $slug);
			$this->assertSame(SeedWooProcessingActivity::CODE, $processing['attribution']['default'], $slug);
			$this->assertTrue(version_compare($schemas[$slug]['version'], $before, '>'), $slug.' version was not bumped past '.$before);
		}

		$this->assertTrue(($schemas['publication']['configuration']['autoPublish'] ?? null) !== null, 'the merge dropped publication autoPublish');

	}//end testTheFiveSchemasOptIn()

	/**
	 * The fragment sorts last, so no later fragment's version overwrites the bump.
	 *
	 * @return void
	 */
	public function testTheFragmentIsMergedLast(): void {
		$files = glob(dirname(__DIR__, 3).'/lib/Settings/register.d/*.json');
		sort($files);

		$this->assertStringEndsWith('/woo-x-access-boundary-hardening.json', (string) end($files));

	}//end testTheFragmentIsMergedLast()
}//end class

<?php

/**
 * Tests for CouncilSourceTemplates: reading the template file and finding a source's system.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\CouncilSourceTemplates;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\CouncilSourceTemplates
 */
class CouncilSourceTemplatesTest extends TestCase {

	/**
	 * A scratch app root.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Make the scratch root.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/council-templates-' . bin2hex(random_bytes(4));
		mkdir($this->root);
	}//end setUp()

	/**
	 * Remove the scratch root.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->root . '/sources.json');
		@rmdir($this->root);
		parent::tearDown();
	}//end tearDown()

	/**
	 * The shipped file lists Notubiz and iBabs, each with its source.
	 *
	 * @return void
	 */
	public function testTheShippedFileListsBothSystems(): void {
		$templates = new CouncilSourceTemplates(appRoot: __DIR__ . '/../../..', file: '/lib/Settings/council-documents-sources.json');

		$all = $templates->all();

		$this->assertSame(['notubiz', 'ibabs'], array_keys($all));
		$this->assertSame('notubiz', $templates->kindOf(sourceSlug: (string)$all['notubiz']['source']));
		$this->assertSame('ibabs', $templates->kindOf(sourceSlug: (string)$all['ibabs']['source']));
	}//end testTheShippedFileListsBothSystems()

	/**
	 * An unknown source is refused with its slug in the message.
	 *
	 * @return void
	 */
	public function testAnUnknownSourceIsRefused(): void {
		file_put_contents($this->root . '/sources.json', json_encode(['kinds' => ['notubiz' => ['source' => 'notubiz-src']]]));
		$templates = new CouncilSourceTemplates(appRoot: $this->root, file: '/sources.json');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Choose a Notubiz or iBabs source: "other" is not one.');
		$templates->kindOf(sourceSlug: 'other');
	}//end testAnUnknownSourceIsRefused()

	/**
	 * A missing file is refused, naming the file, without a PHP warning.
	 *
	 * @return void
	 */
	public function testAMissingFileIsRefused(): void {
		$templates = new CouncilSourceTemplates(appRoot: $this->root, file: '/absent.json');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The council source file /absent.json is missing or not valid.');
		$templates->all();
	}//end testAMissingFileIsRefused()

	/**
	 * A file without a `kinds` object is refused.
	 *
	 * @return void
	 */
	public function testAFileWithoutKindsIsRefused(): void {
		file_put_contents($this->root . '/sources.json', '{"kinds": "none"}');
		$templates = new CouncilSourceTemplates(appRoot: $this->root, file: '/sources.json');

		$this->expectException(RuntimeException::class);
		$templates->all();
	}//end testAFileWithoutKindsIsRefused()
}//end class

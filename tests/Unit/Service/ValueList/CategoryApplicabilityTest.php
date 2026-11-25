<?php

/**
 * Tests for the organisation type and its applicability proposal (REQ-WVL-004).
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\ValueList;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\ValueList\CategoryApplicability;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The proposal per organisation type, and the stored type.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
 */
#[CoversClass(CategoryApplicability::class)]
class CategoryApplicabilityTest extends TestCase {

	/**
	 * The stored app config.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * Temporary tables written by a test.
	 *
	 * @var list<string>
	 */
	private array $files = [];

	/**
	 * Remove the temporary tables.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}
	}//end tearDown()

	/**
	 * The applicability over an in-memory app config.
	 *
	 * @param string|null $file The table, the shipped one by default.
	 *
	 * @return CategoryApplicability
	 */
	private function applicability(?string $file = null): CategoryApplicability {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		return new CategoryApplicability(config: $config, file: ($file ?? CategoryApplicability::TABLE));
	}//end applicability()

	/**
	 * A table written for one test.
	 *
	 * @param string $json The table.
	 *
	 * @return string The path.
	 */
	private function tableFile(string $json): string {
		$file = (string)tempnam(sys_get_temp_dir(), 'applicability');
		file_put_contents($file, $json);
		$this->files[] = $file;
		return $file;
	}//end tableFile()

	/**
	 * A water board is proposed to hide the meeting documents of the Staten-Generaal, with the article cited.
	 *
	 * @return void
	 */
	public function testAWaterBoardIsProposedTheStatesGeneralDocuments(): void {
		$proposal = $this->applicability()->proposalFor(type: 'waterschap');

		$this->assertSame(expected: ['infocat007'], actual: array_column($proposal, 'category'));
		$this->assertSame(expected: 'https://identifier.overheid.nl/tooi/def/thes/kern/c_c76862ab', actual: $proposal[0]['tooiUri']);
		$this->assertSame(expected: 'Wet open overheid, artikel 3.3', actual: $proposal[0]['article']);
		$this->assertStringContainsString(needle: 'Staten-Generaal', haystack: $proposal[0]['basis']);
		$this->assertSame(expected: [], actual: $this->applicability()->proposalFor(type: 'overig'));
	}//end testAWaterBoardIsProposedTheStatesGeneralDocuments()

	/**
	 * The type is stored; an unknown type is refused and a stale stored one reads as unset.
	 *
	 * @return void
	 */
	public function testTheTypeIsStoredAndAnUnknownOneRefused(): void {
		$applicability = $this->applicability();
		$this->assertNull(actual: $applicability->organisationType());

		$applicability->setOrganisationType(type: 'waterschap');
		$this->assertSame(expected: 'waterschap', actual: $applicability->organisationType());
		$this->assertSame(expected: 'waterschap', actual: $this->stored[CategoryApplicability::TYPE_KEY]);

		$this->stored[CategoryApplicability::TYPE_KEY] = 'kerkgenootschap';
		$this->assertNull(actual: $applicability->organisationType());

		$this->expectException(exception: InvalidArgumentException::class);
		$applicability->setOrganisationType(type: 'kerkgenootschap');
	}//end testTheTypeIsStoredAndAnUnknownOneRefused()

	/**
	 * An entry without a cited basis is never proposed.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutACitedBasisIsNotProposed(): void {
		$file = $this->tableFile(
			json: (string)json_encode(
				[
					'types' => ['gemeente'],
					'entries' => [
						['category' => 'infocat007', 'tooiUri' => 'u7', 'notApplicableTo' => ['gemeente'], 'article' => 'Woo 3.3', 'basis' => 'scope'],
						['category' => 'infocat013', 'tooiUri' => 'u13', 'notApplicableTo' => ['gemeente'], 'article' => 'Woo 3.3', 'basis' => ' '],
						['category' => 'infocat011', 'tooiUri' => 'u11', 'notApplicableTo' => ['gemeente']],
					],
				]
			)
		);

		$this->assertSame(expected: ['infocat007'], actual: array_column($this->applicability(file: $file)->proposalFor(type: 'gemeente'), 'category'));
	}//end testAnEntryWithoutACitedBasisIsNotProposed()

	/**
	 * An unreadable table offers no type and proposes nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableTableProposesNothing(): void {
		$applicability = $this->applicability(file: $this->tableFile(json: 'not json'));

		$this->assertSame(expected: [], actual: $applicability->types());
		$this->assertSame(expected: [], actual: $applicability->proposalFor(type: 'gemeente'));
		$this->assertSame(expected: [], actual: $this->applicability(file: '/nonexistent/table.json')->types());
	}//end testAnUnreadableTableProposesNothing()
}//end class

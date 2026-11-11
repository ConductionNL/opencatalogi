<?php

/**
 * Pins how the agent tools read a search result row into a source.
 *
 * Runs `OpenCatalogiToolProvider::invokeTool('opencatalogi.searchCatalog')`
 * over result rows of every shape the normalisation distinguishes: an
 * array, an object whose jsonSerialize() answers an array, one whose
 * jsonSerialize() answers something else and falls through to getObject(),
 * one with only getObject(), one whose getObject() answers no array, a
 * plain object, and a scalar.
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
 * @covers \OCA\OpenCatalogi\Mcp\OpenCatalogiToolProvider
 */

declare(strict_types=1);

namespace Unit\Mcp;

use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenCatalogi\Mcp\OpenCatalogiToolProvider;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the result-row normalisation of OpenCatalogiToolProvider.
 */
class ToolProviderNormaliseTest extends TestCase {

	/**
	 * Every row shape becomes the source the normalisation reads from it.
	 *
	 * @return void
	 */
	public function testEveryRowShapeBecomesItsSource(): void {
		$rows = [
			['uuid' => 'array-uuid', 'title' => 'Array'],
			new class {
				/**
				 * @return array<string, string>
				 */
				public function jsonSerialize(): array {
					return ['uuid' => 'serialised-uuid', 'title' => 'Serialised'];
				}
			},
			new class {
				/**
				 * @return string
				 */
				public function jsonSerialize(): string {
					return 'not an array';
				}

				/**
				 * @return array<string, string>
				 */
				public function getObject(): array {
					return ['uuid' => 'fallback-uuid', 'title' => 'Fallback'];
				}
			},
			new class {
				/**
				 * @return array<string, string>
				 */
				public function getObject(): array {
					return ['id' => 'object-id', 'name' => 'Object'];
				}
			},
			new class {
				/**
				 * The slug the cast reads.
				 *
				 * @var string
				 */
				public string $slug = 'cast-slug';

				/**
				 * @return string
				 */
				public function getObject(): string {
					return 'not an array';
				}
			},
			(object)['id' => 'plain-id'],
			42,
		];

		$sources = $this->sourcesFor(rows: $rows);

		$this->assertSame(
			[
				['array-uuid', 'Array'],
				['serialised-uuid', 'Serialised'],
				['fallback-uuid', 'Fallback'],
				['object-id', 'Object'],
				['cast-slug', 'Publication'],
				['plain-id', 'Publication'],
				['', 'Publication'],
			],
			array_map(
				static fn (array $source): array => [$source['uuid'], $source['label']],
				$sources
			)
		);

	}//end testEveryRowShapeBecomesItsSource()

	/**
	 * Run a search that answers the given rows and return its sources.
	 *
	 * @param list<mixed> $rows The result rows.
	 *
	 * @return list<array<string, string>> The sources.
	 */
	private function sourcesFor(array $rows): array {
		$publications = $this->createMock(PublicationServiceInterface::class);
		$publications->method('index')->willReturn(new JSONResponse(['results' => $rows]));

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$provider = new OpenCatalogiToolProvider(
			publicationService: $publications,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class)
		);
		$answer = $provider->invokeTool('opencatalogi.searchCatalog', ['query' => 'woo']);

		return $answer['sources'];

	}//end sourcesFor()
}//end class

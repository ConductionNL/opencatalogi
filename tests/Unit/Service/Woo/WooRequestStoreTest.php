<?php

/**
 * Unit tests for WooRequestStore.
 *
 * The store raises rather than answering an empty result, and that is what is
 * asserted here: a request store that reports no requests when it was never
 * configured hides a statutory backlog behind a clean-looking screen.
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

namespace Unit\Service\Woo;

use JsonSerializable;
use OCA\OpenCatalogi\Service\Woo\WooRequestStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A result that answers jsonSerialize, as an OpenRegister entity does.
 */
class WooRequestSerialisableResult implements JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $payload What it serialises to.
	 */
	public function __construct(private readonly array $payload) {
	}//end __construct()

	/**
	 * The payload.
	 *
	 * @return array<string, mixed> The payload.
	 */
	public function jsonSerialize(): array {
		return $this->payload;
	}//end jsonSerialize()
}//end class

/**
 * A duck-typed fake of the consumed OpenRegister ObjectService, answering
 * whatever shape the test hands it.
 */
class WooRequestShapedObjectService {

	public mixed $answer = [];

	public bool $findThrows = false;

	public function find(string $id, mixed ...$rest): mixed {
		if ($this->findThrows === true) {
			throw new RuntimeException('not found');
		}

		return $this->answer;
	}//end find()

	public function saveObject(array $object, mixed ...$rest): mixed {
		return $this->answer;
	}//end saveObject()

	public function searchObjectsPaginated(array $query, mixed ...$rest): array {
		return ['results' => [$this->answer]];
	}//end searchObjectsPaginated()
}//end class

/**
 * Unit tests for WooRequestStore.
 */
class WooRequestStoreTest extends TestCase {

	private WooRequestShapedObjectService $objects;

	/**
	 * Fresh fake per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new WooRequestShapedObjectService();

	}//end setUp()

	/**
	 * Build the store.
	 *
	 * @param string $configured What the register and schema keys answer.
	 * @param bool   $installed  Whether OpenRegister is installed.
	 * @param bool   $bound      Whether the container binds the ObjectService.
	 *
	 * @return WooRequestStore The store.
	 */
	private function build(
		string $configured = '42',
		bool $installed = true,
		bool $bound = true
	): WooRequestStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($configured);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($bound) {
				if ($bound === false) {
					throw new RuntimeException('not bound: ' . $id);
				}

				return $this->objects;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($installed);

		return new WooRequestStore($config, $container, $appManager);

	}//end build()

	/**
	 * An unconfigured register names the two keys, so an operator can fix it.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterNamesBothKeys(): void {
		$store = $this->build(configured: '');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/publication_register \/ woo_request_schema/');
		$store->all();

	}//end testAnUnconfiguredRegisterNamesBothKeys()

	/**
	 * ADR-083: without OpenRegister installed the store refuses by name, so the
	 * refusal is a fixable message rather than a container error.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterInstalledTheStoreRefusesByName(): void {
		$store = $this->build(installed: false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/OpenRegister is not installed/');
		$store->all();

	}//end testWithoutOpenRegisterInstalledTheStoreRefusesByName()

	/**
	 * An installed OpenRegister whose service cannot be resolved still raises,
	 * rather than reading as an empty backlog.
	 *
	 * @return void
	 */
	public function testAnUnresolvableObjectServiceRaises(): void {
		$store = $this->build(bound: false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/OpenRegister is unavailable/');
		$store->all();

	}//end testAnUnresolvableObjectServiceRaises()

	/**
	 * A request that is not there reads as null, not as an empty request.
	 *
	 * @return void
	 */
	public function testAMissingRequestReadsAsNull(): void {
		$this->objects->findThrows = true;

		$this->assertNull($this->build()->find(requestId: 'req-1'));

	}//end testAMissingRequestReadsAsNull()

	/**
	 * A result that reads as nothing is a missing request too.
	 *
	 * @return void
	 */
	public function testAResultThatReadsAsNothingIsAMissingRequest(): void {
		$this->objects->answer = 'not an object at all';

		$this->assertNull($this->build()->find(requestId: 'req-1'));

	}//end testAResultThatReadsAsNothingIsAMissingRequest()

	/**
	 * An entity that serialises itself is read through jsonSerialize, which is
	 * the shape OpenRegister actually returns.
	 *
	 * @return void
	 */
	public function testAnEntityIsReadThroughJsonSerialize(): void {
		$this->objects->answer = new WooRequestSerialisableResult(
			['id' => 'req-1', 'reference' => 'WOO-2026-ABCDEF']
		);

		$found = $this->build()->find(requestId: 'req-1');

		$this->assertSame('WOO-2026-ABCDEF', $found['reference']);
		$this->assertSame('req-1', $found['id']);

	}//end testAnEntityIsReadThroughJsonSerialize()

	/**
	 * A result wrapping its properties under `object` is unwrapped, and the
	 * outer id wins over the inner one.
	 *
	 * @return void
	 */
	public function testAWrappedResultIsUnwrappedAndKeepsTheOuterId(): void {
		$this->objects->answer = [
			'id' => 'req-outer',
			'object' => ['reference' => 'WOO-2026-ABCDEF', 'id' => 'req-inner'],
		];

		$found = $this->build()->find(requestId: 'req-1');

		$this->assertSame('WOO-2026-ABCDEF', $found['reference']);
		$this->assertSame('req-outer', $found['id']);

	}//end testAWrappedResultIsUnwrappedAndKeepsTheOuterId()

	/**
	 * A found request without an id carries the id it was asked for, so the
	 * caller can write it back.
	 *
	 * @return void
	 */
	public function testAFoundRequestWithoutAnIdCarriesTheOneItWasAskedFor(): void {
		$this->objects->answer = ['reference' => 'WOO-2026-ABCDEF'];

		$found = $this->build()->find(requestId: 'req-9');

		$this->assertSame('req-9', $found['id']);

	}//end testAFoundRequestWithoutAnIdCarriesTheOneItWasAskedFor()

	/**
	 * Saving answers with the stored request.
	 *
	 * @return void
	 */
	public function testSavingAnswersWithTheStoredRequest(): void {
		$this->objects->answer = ['id' => 'req-1', 'reference' => 'WOO-2026-ABCDEF'];

		$saved = $this->build()->save(record: ['reference' => 'WOO-2026-ABCDEF']);

		$this->assertSame('req-1', $saved['id']);

	}//end testSavingAnswersWithTheStoredRequest()

	/**
	 * Reading everything reads the rows out of the paginated result.
	 *
	 * @return void
	 */
	public function testReadingEverythingReadsTheRowsOut(): void {
		$this->objects->answer = ['id' => 'req-1', 'reference' => 'WOO-2026-ABCDEF'];

		$all = $this->build()->all();

		$this->assertCount(1, $all);
		$this->assertSame('WOO-2026-ABCDEF', $all[0]['reference']);

	}//end testReadingEverythingReadsTheRowsOut()
}//end class

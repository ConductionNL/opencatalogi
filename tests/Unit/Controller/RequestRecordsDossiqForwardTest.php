<?php

/**
 * Unit tests for RequestRecords' hand-over to dossiq.
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

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\InformationRequestServiceInterface;
use OCA\OpenCatalogi\Contract\InformationRequestStoreInterface;
use OCA\OpenCatalogi\Controller\RequestRecords;
use OCA\OpenCatalogi\Service\Woo\DossiqWooForward;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * RequestRecords hands a new request to dossiq only while dossiq is installed.
 *
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-a-new-request-is-forwarded-to-dossiq-and-minted-only-there-req-whd-002
 */
#[CoversClass(RequestRecords::class)]
#[UsesClass(DossiqWooForward::class)]
class RequestRecordsDossiqForwardTest extends TestCase {

	/**
	 * Without a hand-over the request stays with OpenCatalogi.
	 *
	 * @return void
	 */
	public function testNoHandOverWithoutAForward(): void {
		$this->assertNull($this->records(forward: null)->dossiqForward());
	}//end testNoHandOverWithoutAForward()

	/**
	 * While dossiq is not enabled the request stays with OpenCatalogi.
	 *
	 * @return void
	 */
	public function testNoHandOverWhileDossiqIsNotEnabled(): void {
		$this->assertNull($this->records(forward: $this->forward(enabled: false))->dossiqForward());
	}//end testNoHandOverWhileDossiqIsNotEnabled()

	/**
	 * While dossiq is enabled the forward is handed out.
	 *
	 * @return void
	 */
	public function testHandsOverWhileDossiqIsEnabled(): void {
		$forward = $this->forward(enabled: true);
		$this->assertSame($forward, $this->records(forward: $forward)->dossiqForward());
	}//end testHandsOverWhileDossiqIsEnabled()

	/**
	 * Records over doubles of the service and the store.
	 *
	 * @param DossiqWooForward|null $forward The hand-over.
	 *
	 * @return RequestRecords
	 */
	private function records(?DossiqWooForward $forward): RequestRecords {
		return new RequestRecords(
			$this->createMock(InformationRequestServiceInterface::class),
			$this->createMock(InformationRequestStoreInterface::class),
			$forward
		);
	}//end records()

	/**
	 * A real forward over an app manager that answers whether dossiq is enabled.
	 *
	 * @param bool $enabled What the app manager answers.
	 *
	 * @return DossiqWooForward
	 */
	private function forward(bool $enabled): DossiqWooForward {
		$apps = $this->createMock(IAppManager::class);
		$apps->expects(self::once())->method('isEnabledForAnyone')->with('dossiq')->willReturn($enabled);

		return new DossiqWooForward(
			appManager: $apps,
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end forward()
}//end class

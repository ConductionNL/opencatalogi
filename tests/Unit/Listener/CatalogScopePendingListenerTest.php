<?php

/**
 * Tests for CatalogScopePendingListener: a register event completes a catalogue
 * scope only when the register is one the scope waits for.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Listener;

use OCA\OpenCatalogi\Listener\CatalogScopePendingListener;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Listener\CatalogScopePendingListener
 */
class CatalogScopePendingListenerTest extends TestCase {

	/**
	 * Settings service double; only the backfill is observed.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Build a listener whose pending record holds the given JSON.
	 *
	 * @param string $pending The stored `catalog_scope_pending` value.
	 *
	 * @return CatalogScopePendingListener
	 */
	private function listener(string $pending): CatalogScopePendingListener {
		$this->settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['backfillCatalogScopes'])
			->getMock();

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')
			->willReturnCallback(
				static fn (string $app, string $key, string $default = ''): string => ($key === SettingsService::CATALOG_SCOPE_PENDING_KEY ? $pending : $default)
			);

		return new CatalogScopePendingListener(
			settingsService: $this->settings,
			appConfig: $config,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end listener()

	/**
	 * A real register entity.
	 *
	 * @param int $id The register id.
	 * @param string $slug The register slug.
	 *
	 * @return Register
	 */
	private function register(int $id, string $slug): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);

		return $register;
	}//end register()

	/**
	 * Creating the pending register by slug runs the backfill.
	 *
	 * @return void
	 */
	public function testCreatingAPendingRegisterRunsTheBackfill(): void {
		$listener = $this->listener('["stackiq"]');
		$this->settings->expects($this->once())->method('backfillCatalogScopes');

		$listener->handle(new RegisterCreatedEvent(register: $this->register(20, 'StackIQ')));
	}//end testCreatingAPendingRegisterRunsTheBackfill()

	/**
	 * Updating a resolved register whose schemas are still pending (by id) runs the backfill.
	 *
	 * @return void
	 */
	public function testUpdatingARegisterPendingByIdRunsTheBackfill(): void {
		$listener = $this->listener('["20"]');
		$this->settings->expects($this->once())->method('backfillCatalogScopes');

		$listener->handle(
			new RegisterUpdatedEvent(newRegister: $this->register(20, 'stackiq'), oldRegister: $this->register(20, 'stackiq'))
		);
	}//end testUpdatingARegisterPendingByIdRunsTheBackfill()

	/**
	 * An unrelated register event does nothing.
	 *
	 * @return void
	 */
	public function testAnUnrelatedRegisterDoesNothing(): void {
		$listener = $this->listener('["stackiq"]');
		$this->settings->expects($this->never())->method('backfillCatalogScopes');

		$listener->handle(
			new RegisterUpdatedEvent(newRegister: $this->register(5, 'publication'), oldRegister: $this->register(5, 'publication'))
		);
	}//end testAnUnrelatedRegisterDoesNothing()

	/**
	 * Nothing pending (the normal state once resolved) does nothing.
	 *
	 * @return void
	 */
	public function testNothingPendingDoesNothing(): void {
		$listener = $this->listener('[]');
		$this->settings->expects($this->never())->method('backfillCatalogScopes');

		$listener->handle(new RegisterCreatedEvent(register: $this->register(20, 'stackiq')));
	}//end testNothingPendingDoesNothing()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$listener = $this->listener('["stackiq"]');
		$this->settings->expects($this->never())->method('backfillCatalogScopes');

		$listener->handle(new ObjectCreatedEvent(object: new ObjectEntity()));
	}//end testAnotherEventIsIgnored()

	/**
	 * A failing backfill is logged, never thrown into the other app's import.
	 *
	 * @return void
	 */
	public function testAFailingBackfillDoesNotBreakTheImport(): void {
		$listener = $this->listener('["stackiq"]');
		// Twice: the second call proves the re-entrancy flag was released after the throw.
		$this->settings->expects($this->exactly(2))
			->method('backfillCatalogScopes')
			->willThrowException(new RuntimeException('boom'));

		$listener->handle(new RegisterCreatedEvent(register: $this->register(20, 'stackiq')));
		$listener->handle(new RegisterCreatedEvent(register: $this->register(20, 'stackiq')));
	}//end testAFailingBackfillDoesNotBreakTheImport()

	/**
	 * The listener is registered for both register events, so it has a call site.
	 *
	 * @return void
	 */
	public function testTheListenerIsRegistered(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

		$this->assertStringContainsString('registerEventListener(RegisterCreatedEvent::class, CatalogScopePendingListener::class)', $source);
		$this->assertStringContainsString('registerEventListener(RegisterUpdatedEvent::class, CatalogScopePendingListener::class)', $source);
	}//end testTheListenerIsRegistered()
}//end class

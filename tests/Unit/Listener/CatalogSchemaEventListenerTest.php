<?php
/**
 * Unit tests for CatalogSchemaEventListener.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\CatalogSchemaEventListener;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CatalogSchemaEventListener.
 *
 * The listener was previously registered against the post-save events
 * (`ObjectCreatedEvent` / `ObjectUpdatedEvent`) and called
 * `CatalogiService::rewriteSchemasAndRegisters(...)`, which internally invoked
 * `saveObject(...)` and re-emitted `ObjectUpdatedEvent` -> infinite loop on every
 * catalog update and soft-delete (CAT-011, CAT-012). These tests guard that the
 * listener now subscribes to the *pre-save* events only and pushes its mutation back
 * via `setModifiedData(...)` instead of triggering a second save.
 */
class CatalogSchemaEventListenerTest extends TestCase
{
    private const CATALOG_SCHEMA   = 'cat-schema';
    private const CATALOG_REGISTER = 'cat-reg';

    /**
     * @var CatalogiService&MockObject Mock catalog service for stubbing the rewrite computation.
     */
    private CatalogiService|MockObject $catalogiService;

    /**
     * @var LoggerInterface&MockObject Mock logger for asserting error logging on failure.
     */
    private LoggerInterface|MockObject $logger;

    /**
     * @var IAppConfig&MockObject Mock app config returning the catalog schema/register keys.
     */
    private IAppConfig|MockObject $appConfig;

    /**
     * @var CatalogSchemaEventListener The system under test.
     */
    private CatalogSchemaEventListener $listener;

    /**
     * Build the listener with mocked dependencies.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->catalogiService = $this->createMock(CatalogiService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->appConfig       = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')
            ->willReturnCallback(
                    function (string $app, string $key, string $default) {
                        return match ($key) {
                            'catalog_schema'   => self::CATALOG_SCHEMA,
                            'catalog_register' => self::CATALOG_REGISTER,
                            default            => $default,
                        };
                    }
                    );

        $this->listener = new CatalogSchemaEventListener(
            $this->catalogiService,
            $this->logger,
            $this->appConfig
        );
    }//end setUp()

    /**
     * The listener must early-return for events it does not subscribe to.
     *
     * @return void
     */
    public function testHandleIgnoresUnsupportedEvent(): void
    {
        $this->catalogiService->expects($this->never())->method('computeRewrittenRegistersAndSchemas');

        $this->listener->handle($this->createMock(Event::class));
        $this->assertTrue(true);
    }//end testHandleIgnoresUnsupportedEvent()

    /**
     * Regression guard for CAT-011 + CAT-012: the listener MUST NOT subscribe to
     * the post-save events (those are what caused the infinite loop). If anyone
     * accidentally re-points it at those events again, this test fails.
     *
     * @return void
     */
    public function testHandleIgnoresPostSaveEvents(): void
    {
        $this->catalogiService->expects($this->never())->method('computeRewrittenRegistersAndSchemas');

        $entity = $this->createCatalogEntity(['registers' => ['my-register']]);
        $this->listener->handle(new ObjectCreatedEvent($entity));
        $this->listener->handle(new ObjectUpdatedEvent($entity, null));
        $this->assertTrue(true);
    }//end testHandleIgnoresPostSaveEvents()

    /**
     * On `ObjectCreatingEvent` for a catalog the rewritten payload is published via setModifiedData.
     *
     * @return void
     */
    public function testHandleSetsModifiedDataOnObjectCreating(): void
    {
        $entity = $this->createCatalogEntity(
                [
                    'registers' => ['my-register'],
                    'schemas'   => ['my-schema'],
                ]
                );

        $this->catalogiService->expects($this->once())
            ->method('computeRewrittenRegistersAndSchemas')
            ->with(['registers' => ['my-register'], 'schemas' => ['my-schema']])
            ->willReturn(['registers' => [42], 'schemas' => [7]]);

        $event = new ObjectCreatingEvent($entity);
        $this->listener->handle($event);

        $this->assertSame(['registers' => [42], 'schemas' => [7]], $event->getModifiedData());
        $this->assertFalse($event->isPropagationStopped(), 'Listener must never stop propagation.');
    }//end testHandleSetsModifiedDataOnObjectCreating()

    /**
     * Same behaviour for `ObjectUpdatingEvent`: only the changed key lands in modifiedData.
     *
     * @return void
     */
    public function testHandleSetsModifiedDataOnObjectUpdating(): void
    {
        $entity = $this->createCatalogEntity(
                [
                    'registers' => ['publication'],
                    'schemas'   => ['12'],
                ]
                );

        $this->catalogiService->expects($this->once())
            ->method('computeRewrittenRegistersAndSchemas')
            ->willReturn(['registers' => [99]]);

        $event = new ObjectUpdatingEvent($entity, null);
        $this->listener->handle($event);

        $this->assertSame(['registers' => [99]], $event->getModifiedData());
        $this->assertFalse($event->isPropagationStopped());
    }//end testHandleSetsModifiedDataOnObjectUpdating()

    /**
     * Listener must merge with any modifiedData already set by other listeners on the same event.
     *
     * @return void
     */
    public function testHandleMergesWithExistingModifiedData(): void
    {
        $entity = $this->createCatalogEntity(['registers' => ['my-register']]);

        $this->catalogiService->method('computeRewrittenRegistersAndSchemas')
            ->willReturn(['registers' => [42]]);

        $event = new ObjectUpdatingEvent($entity, null);
        $event->setModifiedData(['someOtherField' => 'x']);
        $this->listener->handle($event);

        $this->assertSame(
            ['someOtherField' => 'x', 'registers' => [42]],
            $event->getModifiedData()
        );
    }//end testHandleMergesWithExistingModifiedData()

    /**
     * Idempotency: when the compute method returns no changes, modifiedData stays empty.
     *
     * @return void
     */
    public function testHandleSkipsWhenNothingNeedsRewriting(): void
    {
        $entity = $this->createCatalogEntity(['registers' => ['1'], 'schemas' => ['2']]);

        $this->catalogiService->method('computeRewrittenRegistersAndSchemas')
            ->willReturn([]);

        $event = new ObjectUpdatingEvent($entity, null);
        $this->listener->handle($event);

        $this->assertSame([], $event->getModifiedData());
    }//end testHandleSkipsWhenNothingNeedsRewriting()

    /**
     * Objects on a different schema must be ignored without invoking the service.
     *
     * @return void
     */
    public function testHandleIgnoresNonCatalogObjectDifferentSchema(): void
    {
        $this->catalogiService->expects($this->never())->method('computeRewrittenRegistersAndSchemas');

        $entity = $this->createMockEntity('other-schema', self::CATALOG_REGISTER, []);
        $this->listener->handle(new ObjectUpdatingEvent($entity, null));
        $this->assertTrue(true);
    }//end testHandleIgnoresNonCatalogObjectDifferentSchema()

    /**
     * Objects in a different register must be ignored without invoking the service.
     *
     * @return void
     */
    public function testHandleIgnoresNonCatalogObjectDifferentRegister(): void
    {
        $this->catalogiService->expects($this->never())->method('computeRewrittenRegistersAndSchemas');

        $entity = $this->createMockEntity(self::CATALOG_SCHEMA, 'other-reg', []);
        $this->listener->handle(new ObjectUpdatingEvent($entity, null));
        $this->assertTrue(true);
    }//end testHandleIgnoresNonCatalogObjectDifferentRegister()

    /**
     * Failure inside the service is caught: the listener logs and lets the original save proceed.
     *
     * @return void
     */
    public function testHandleLogsAndContinuesOnException(): void
    {
        $entity = $this->createCatalogEntity(['registers' => ['ghost']]);

        $this->catalogiService->method('computeRewrittenRegistersAndSchemas')
            ->willThrowException(new \RuntimeException('Register ghost not found.'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Exception in catalog schema event listener'));

        $event = new ObjectUpdatingEvent($entity, null);
        $this->listener->handle($event);

        // On failure the original (un-rewritten) data MUST flow through unchanged.
        $this->assertSame([], $event->getModifiedData());
        $this->assertFalse($event->isPropagationStopped());
    }//end testHandleLogsAndContinuesOnException()

    /**
     * Build an ObjectEntity mock pre-configured for the catalog schema/register.
     *
     * @param array $object The decoded object payload returned by `getObject()`.
     *
     * @return ObjectEntity
     */
    private function createCatalogEntity(array $object): ObjectEntity
    {
        return $this->createMockEntity(self::CATALOG_SCHEMA, self::CATALOG_REGISTER, $object);
    }//end createCatalogEntity()

    /**
     * Build an ObjectEntity mock with arbitrary schema/register and object payload.
     *
     * @param string $schema   Value returned by the magic `getSchema()`.
     * @param string $register Value returned by the magic `getRegister()`.
     * @param array  $object   Value returned by `getObject()`.
     *
     * @return ObjectEntity
     */
    private function createMockEntity(string $schema, string $register, array $object): ObjectEntity
    {
        $entity = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRegister', 'getSchema', 'getObject'])
            ->getMock();
        $entity->method('getSchema')->willReturn($schema);
        $entity->method('getRegister')->willReturn($register);
        $entity->method('getObject')->willReturn($object);

        return $entity;
    }//end createMockEntity()
}//end class

<?php
/**
 * Unit tests for RegisterSchemaLinkService.
 *
 * The service is 309 lines behind a single public method and shipped with no
 * test at all — which is what dropped the coverage ratchet by 0.36% on this
 * change. AttachOrphanSchemasTest, added alongside it, covers a SettingsService
 * transform, not this class.
 *
 * The behaviour worth pinning is not "it links schemas" but WHEN IT DOES
 * NOTHING. reconcile() wraps its whole body in `catch (\Throwable) { return; }`
 * so a broken linkage can never sink an otherwise successful settings import.
 * That is the right call and it is also a silent-failure path: every one of
 * "no register found", "nothing missing", and "the mapper threw" looks
 * identical from the outside — no exception, no return value, no log. The only
 * way to tell them apart is whether update() was called, so that is what these
 * tests assert on.
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

namespace Unit\Service;

use OCA\OpenCatalogi\Service\RegisterSchemaLinkService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for RegisterSchemaLinkService::reconcile().
 */
class RegisterSchemaLinkServiceTest extends TestCase
{

    private ContainerInterface|MockObject $container;

    private IAppManager|MockObject $appManager;

    private RegisterSchemaLinkService $service;

    protected function setUp(): void
    {
        $this->container  = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        // The mapper accessors gate on OpenRegister being installed; default to
        // installed so the interesting branches are reachable.
        $this->appManager->method('getInstalledApps')->willReturn(['opencatalogi', 'openregister']);

        $this->service = new RegisterSchemaLinkService($this->container, $this->appManager);

    }//end setUp()

    /**
     * Build a Register test double carrying the given schema list.
     *
     * @param array $schemas The stored `schemas` value.
     *
     * @return Register|MockObject
     */
    private function makeRegister(array $schemas): Register|MockObject
    {
        // addMethods(), because the STUB is thinner than the real entity.
        //
        // The unit suite resolves OCA\OpenRegister\Db\Register to
        // tests/Stubs/OpenRegister/Db/Register.php, which implements only
        // jsonSerialize(). The real class extends Nextcloud's Entity and gets
        // getSlug()/setSlug() from its __call magic. So neither route works
        // off the shelf: createMock() refuses to configure a method the stub
        // does not declare, and `new Register()` + setSlug() dies with
        // "Call to undefined method" because the stub has no __call.
        //
        // addMethods() declares them on the mock. It is deprecated in PHPUnit
        // 10, and the durable fix is to widen the stub to mirror the accessors
        // this app actually depends on — an incomplete stub silently caps what
        // the suite can test, which is how this class reached 309 lines with no
        // test at all. That belongs in its own change rather than inside a
        // feature PR, so it is flagged here rather than done here.
        $register = $this->getMockBuilder(Register::class)
            ->addMethods(['getSlug', 'getSchemas', 'getConfiguration', 'setSchemas', 'setConfiguration'])
            ->getMock();

        $register->method('getSlug')->willReturn('publication');
        $register->method('getSchemas')->willReturn($schemas);
        $register->method('getConfiguration')->willReturn([]);

        return $register;

    }//end makeRegister()

    /**
     * Build a RegisterMapper double.
     *
     * Same stub-is-thinner-than-the-real-class situation as makeRegister():
     * tests/Stubs/OpenRegister/Db/RegisterMapper.php does not declare find() or
     * update(), so they have to be added to the mock.
     *
     * @return RegisterMapper|MockObject
     */
    private function makeMapper(): RegisterMapper|MockObject
    {
        // find() exists on the stub, update() does not — and PHPUnit refuses to
        // mix them: addMethods() rejects a method that exists, onlyMethods()
        // rejects one that does not.
        return $this->getMockBuilder(RegisterMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->addMethods(['update'])
            ->getMock();

    }//end makeMapper()

    /**
     * A schema id already held by the register is not re-linked.
     *
     * This is the idempotence guarantee the docblock claims — the method runs on
     * every import, so a no-op second run is the difference between "safe to
     * call always" and "rewrites the register every time".
     *
     * @return void
     */
    public function testHeldSchemaIsNotRelinked(): void
    {
        $register = $this->makeRegister([7]);

        // Schema's stub is thin too — getId() has to be added.
        $schema = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
            ->addMethods(['getId', 'getSlug'])
            ->getMock();
        $schema->method('getId')->willReturn(7);
        $schema->method('getSlug')->willReturn('held-schema');

        $mapper = $this->makeMapper();
        // The assertion: nothing is persisted. A run that rewrote an unchanged
        // register would still "succeed" and would be invisible without this.
        $mapper->expects($this->never())->method('update');
        $this->container->method('get')->willReturn($mapper);

        $this->service->reconcile(
            [
                'registers' => [$register],
                'schemas'   => [$schema],
            ]
        );

    }//end testHeldSchemaIsNotRelinked()

    /**
     * With no publication register anywhere, the service must not persist.
     *
     * @return void
     */
    public function testNoPublicationRegisterMeansNoWrite(): void
    {
        $mapper = $this->makeMapper();
        $mapper->method('find')->willReturn(null);
        $mapper->expects($this->never())->method('update');
        $this->container->method('get')->willReturn($mapper);

        $this->service->reconcile(['registers' => [], 'schemas' => []]);

    }//end testNoPublicationRegisterMeansNoWrite()

    /**
     * An import result with no schemas at all is a no-op.
     *
     * @return void
     */
    public function testEmptyImportResultIsANoOp(): void
    {
        $register = $this->makeRegister([1, 2]);

        $mapper = $this->makeMapper();
        $mapper->expects($this->never())->method('update');
        $this->container->method('get')->willReturn($mapper);

        $this->service->reconcile(['registers' => [$register], 'schemas' => []]);

    }//end testEmptyImportResultIsANoOp()

    /**
     * A throwing mapper must be swallowed, not propagated.
     *
     * reconcile() runs at the tail of a settings import. If it threw, a
     * successful import would surface to the admin as a failure. The catch is
     * deliberate — this test is what stops someone "tidying" it away, and it is
     * the only executable statement of that intent.
     *
     * @return void
     */
    public function testAThrowingDependencyNeverEscapes(): void
    {
        $register = $this->makeRegister([]);

        $mapper = $this->makeMapper();
        $mapper->method('update')->willThrowException(new \RuntimeException('register table is gone'));
        $this->container->method('get')->willReturn($mapper);

        // Schema's stub is thin too — getId() has to be added.
        $schema = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
            ->addMethods(['getId', 'getSlug'])
            ->getMock();
        $schema->method('getId')->willReturn(42);
        $schema->method('getSlug')->willReturn('new-schema');

        // No expectException: the point is that nothing escapes.
        $this->service->reconcile(
            [
                'registers' => [$register],
                'schemas'   => [$schema],
            ]
        );

        $this->addToAssertionCount(1);

    }//end testAThrowingDependencyNeverEscapes()

    /**
     * An unresolvable container must not escape either.
     *
     * getRegisterMapper() throws RuntimeException when OpenRegister is absent;
     * reconcile() must absorb that exactly as it absorbs a mapper failure.
     *
     * @return void
     */
    public function testMissingOpenRegisterNeverEscapes(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['opencatalogi']);

        $service = new RegisterSchemaLinkService($this->container, $appManager);

        $service->reconcile(['registers' => [], 'schemas' => []]);

        $this->addToAssertionCount(1);

    }//end testMissingOpenRegisterNeverEscapes()
}//end class

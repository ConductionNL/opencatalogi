<?php
/**
 * Unit tests for OoapiController.
 *
 * Covers the OOAPI-008 consumer-credential auth gate (401 anonymous, 403
 * disallowed consumer, 200 authenticated+allowed), unknown/disabled catalog
 * 404s, register-not-configured 503, CORS preflight, and 404 propagation for
 * unresolved resources. OoapiService/CatalogiService are mocked; only the
 * controller's routing/auth/response-shaping logic is under test here.
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

use OCA\OpenCatalogi\Controller\OoapiController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\OoapiService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for OoapiController.
 */
class OoapiControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private OoapiService|MockObject $ooapiService;
    private CatalogiService|MockObject $catalogiService;
    private IUserSession|MockObject $userSession;
    private ContainerInterface|MockObject $container;
    private OoapiController $controller;

    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->ooapiService     = $this->createMock(OoapiService::class);
        $this->catalogiService  = $this->createMock(CatalogiService::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->container        = $this->createMock(ContainerInterface::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->controller = new OoapiController(
            'opencatalogi',
            $this->request,
            $this->ooapiService,
            $this->catalogiService,
            $this->userSession,
            $l10n,
            $this->createMock(LoggerInterface::class),
            $this->container,
            null
        );
    }

    /**
     * Configure the user session to report an authenticated, allowed consumer.
     */
    private function authenticateAsAllowedConsumer(string $uid='surf-consumer'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->ooapiService->method('isConsumerAllowed')->with($uid)->willReturn(true);

    }//end authenticateAsAllowedConsumer()

    public function testPreflightedCorsReturnsResponse(): void
    {
        $this->request->method('getHeader')->with('Origin')->willReturn('');
        $response = $this->controller->preflightedCors();
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testCoursesAnonymousReturns401(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->courses('hva-onderwijs');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(401, $response->getStatus());
    }

    public function testCoursesDisallowedConsumerReturns403(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('random-user');
        $this->userSession->method('getUser')->willReturn($user);
        $this->ooapiService->method('isConsumerAllowed')->with('random-user')->willReturn(false);

        $response = $this->controller->courses('hva-onderwijs');

        $this->assertSame(403, $response->getStatus());
    }

    public function testCoursesUnknownCatalogReturns404(): void
    {
        $this->authenticateAsAllowedConsumer();
        $this->catalogiService->method('getCatalogBySlug')->with('nope')->willReturn(null);

        $response = $this->controller->courses('nope');

        $this->assertSame(404, $response->getStatus());
    }

    public function testCoursesDisabledCatalogReturns404(): void
    {
        $this->authenticateAsAllowedConsumer();
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'cat-1', 'hasOoapi' => false]);
        $this->ooapiService->method('isOoapiEnabled')->willReturn(false);

        $response = $this->controller->courses('hva-onderwijs');

        $this->assertSame(404, $response->getStatus());
    }

    public function testCoursesReturns503WhenRegisterUnconfigured(): void
    {
        $this->authenticateAsAllowedConsumer();
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'cat-1', 'hasOoapi' => true]);
        $this->ooapiService->method('isOoapiEnabled')->willReturn(true);

        $this->container->method('get')->willReturnCallback(
            static function (string $id) {
                if ($id === 'OCA\OpenRegister\Service\RegisterResolverService') {
                    return new class {
                        public function resolveRegisterId(string $a, string $k): string
                        {
                            throw new \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException('unconfigured: '.$k);
                        }

                        public function resolveSchemaId(string $a, string $k): string
                        {
                            return '0';
                        }
                    };
                }

                throw new \RuntimeException('unexpected container lookup: '.$id);
            }
        );

        $response = $this->controller->courses('hva-onderwijs');

        $this->assertSame(503, $response->getStatus());
    }

    public function testCoursesSuccessReturnsPaginatedEnvelope(): void
    {
        $this->authenticateAsAllowedConsumer();
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'cat-1', 'hasOoapi' => true]);
        $this->ooapiService->method('isOoapiEnabled')->willReturn(true);
        $this->wireResolvedRegisterConfiguration('3', '5');

        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => match ($key) {
                'pageNumber' => 2,
                'pageSize'   => 10,
                default      => $default,
            }
        );

        $this->ooapiService->expects($this->once())
            ->method('listCourses')
            ->with($this->anything(), '3', '5', 2, 10)
            ->willReturn(['items' => [['courseId' => 'c1']], 'pageNumber' => 2, 'pageSize' => 10, 'hasNext' => false]);

        $response = $this->controller->courses('hva-onderwijs');

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(2, $data['pageNumber']);
        $this->assertSame(10, $data['pageSize']);
        $this->assertFalse($data['hasNext']);
        $this->assertCount(1, $data['items']);
    }

    public function testCourseNotFoundReturns404(): void
    {
        $this->authenticateAsAllowedConsumer();
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'cat-1', 'hasOoapi' => true]);
        $this->ooapiService->method('isOoapiEnabled')->willReturn(true);
        $this->wireResolvedRegisterConfiguration('3', '5');
        $this->ooapiService->method('getResource')->willReturn(null);

        $response = $this->controller->course('hva-onderwijs', 'missing-id');

        $this->assertSame(404, $response->getStatus());
    }

    public function testOrganizationsReturnsEmptyListWhenCatalogHasNoOrganization(): void
    {
        $this->authenticateAsAllowedConsumer();
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'cat-1', 'hasOoapi' => true]);
        $this->ooapiService->method('isOoapiEnabled')->willReturn(true);
        $this->wireResolvedRegisterConfiguration('3', '5');
        $this->ooapiService->method('renderOrganization')->willReturn(null);

        $response = $this->controller->organizations('hva-onderwijs');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame([], $response->getData()['items']);
    }

    public function testCourseOfferingsFiltersToParentCourse(): void
    {
        $this->authenticateAsAllowedConsumer();
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'cat-1', 'hasOoapi' => true]);
        $this->ooapiService->method('isOoapiEnabled')->willReturn(true);
        $this->wireResolvedRegisterConfiguration('3', '5');
        $this->request->method('getParam')->willReturnCallback(static fn($key, $default=null) => $default);

        $this->ooapiService->expects($this->once())
            ->method('listOfferings')
            ->with($this->anything(), '3', '5', 'course-1', 1, OoapiService::DEFAULT_PAGE_SIZE)
            ->willReturn(['items' => [], 'pageNumber' => 1, 'pageSize' => OoapiService::DEFAULT_PAGE_SIZE, 'hasNext' => false]);

        $response = $this->controller->courseOfferings('hva-onderwijs', 'course-1');

        $this->assertSame(200, $response->getStatus());
    }

    public function testValidateAdminActionReturnsViolations(): void
    {
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'cat-1']);
        $this->wireResolvedRegisterConfiguration('3', '5');
        $this->ooapiService->method('validateCatalog')->willReturn([['id' => 'c1', 'missing' => ['name']]]);

        $response = $this->controller->validate('hva-onderwijs');

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['valid']);
        $this->assertCount(1, $data['violations']);
    }

    /**
     * Wire the container so ResolvesRegisterConfiguration resolves the given
     * register/schema pair regardless of which config keys are requested.
     */
    private function wireResolvedRegisterConfiguration(string $register, string $schema): void
    {
        $this->container->method('get')->willReturnCallback(
            static function (string $id) use ($register, $schema) {
                if ($id === 'OCA\OpenRegister\Service\RegisterResolverService') {
                    return new class ($register, $schema) {
                        public function __construct(private string $register, private string $schema)
                        {
                        }

                        public function resolveRegisterId(string $a, string $k): string
                        {
                            return $this->register;
                        }

                        public function resolveSchemaId(string $a, string $k): string
                        {
                            return $this->schema;
                        }
                    };
                }

                throw new \RuntimeException('unexpected container lookup: '.$id);
            }
        );

    }//end wireResolvedRegisterConfiguration()

    /*
     * ------------------------------------------------------------------
     * programs / program / offering
     *
     * These three routed, publicly-reachable endpoints had no contract
     * test of any kind (gate-25). They are the OOAPI "programme" and
     * "offering" resources, and they read a DIFFERENT register/schema
     * pair from the course endpoints — `ooapi_programs_*` and
     * `ooapi_offerings_*` — so course coverage says nothing about them.
     * ------------------------------------------------------------------
     */

    public function testProgramsAnonymousReturns401(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->programs('hva-onderwijs');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(401, $response->getStatus());

    }//end testProgramsAnonymousReturns401()

    public function testProgramsDisallowedConsumerReturns403(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('random-user');
        $this->userSession->method('getUser')->willReturn($user);
        $this->ooapiService->method('isConsumerAllowed')->with('random-user')->willReturn(false);

        $response = $this->controller->programs('hva-onderwijs');

        $this->assertSame(403, $response->getStatus());

    }//end testProgramsDisallowedConsumerReturns403()

    public function testProgramsUnknownCatalogReturns404(): void
    {
        $this->authenticateAsAllowedConsumer();
        $this->catalogiService->method('getCatalogBySlug')->with('nope')->willReturn(null);

        $response = $this->controller->programs('nope');

        $this->assertSame(404, $response->getStatus());

    }//end testProgramsUnknownCatalogReturns404()

    public function testProgramsSuccessReturnsPaginatedEnvelope(): void
    {
        $this->authenticateAsAllowedConsumer();
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'cat-1', 'hasOoapi' => true]);
        $this->ooapiService->method('isOoapiEnabled')->willReturn(true);
        $this->wireResolvedRegisterConfiguration('7', '9');

        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => match ($key) {
                'pageNumber' => 3,
                'pageSize'   => 25,
                default      => $default,
            }
        );

        // Asserts the PROGRAMS register/schema pair reaches the service —
        // a regression that swapped it for the courses pair would still
        // return 200 with a well-formed envelope, so the envelope alone
        // is not a sufficient assertion.
        $this->ooapiService->expects($this->once())
            ->method('listPrograms')
            ->with($this->anything(), '7', '9', 3, 25)
            ->willReturn(
                [
                    'items'      => [['programId' => 'p1']],
                    'pageNumber' => 3,
                    'pageSize'   => 25,
                    'hasNext'    => true,
                ]
            );

        $response = $this->controller->programs('hva-onderwijs');

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(3, $data['pageNumber']);
        $this->assertSame(25, $data['pageSize']);
        $this->assertTrue($data['hasNext']);
        $this->assertCount(1, $data['items']);
        $this->assertSame('p1', $data['items'][0]['programId']);

    }//end testProgramsSuccessReturnsPaginatedEnvelope()

    public function testProgramAnonymousReturns401(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->program('hva-onderwijs', 'p1');

        $this->assertSame(401, $response->getStatus());

    }//end testProgramAnonymousReturns401()

    public function testProgramNotFoundReturns404(): void
    {
        $this->authenticateAsAllowedConsumer();
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'cat-1', 'hasOoapi' => true]);
        $this->ooapiService->method('isOoapiEnabled')->willReturn(true);
        $this->wireResolvedRegisterConfiguration('7', '9');
        $this->ooapiService->method('getResource')->willReturn(null);

        $response = $this->controller->program('hva-onderwijs', 'missing-id');

        $this->assertSame(404, $response->getStatus());

    }//end testProgramNotFoundReturns404()

    public function testProgramResolvesByProgramIdField(): void
    {
        $this->authenticateAsAllowedConsumer();
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'cat-1', 'hasOoapi' => true]);
        $this->ooapiService->method('isOoapiEnabled')->willReturn(true);
        $this->wireResolvedRegisterConfiguration('7', '9');

        // The id FIELD is the contract here: OOAPI addresses a programme by
        // `programId`, not by the object's internal id, so a lookup against
        // the wrong field would 404 every real request.
        $this->ooapiService->expects($this->once())
            ->method('getResource')
            ->with($this->anything(), '7', '9', 'p1', 'programId')
            ->willReturn(['programId' => 'p1', 'name' => 'Bachelor']);

        $response = $this->controller->program('hva-onderwijs', 'p1');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('p1', $response->getData()['programId']);

    }//end testProgramResolvesByProgramIdField()

    public function testOfferingAnonymousReturns401(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->offering('hva-onderwijs', 'o1');

        $this->assertSame(401, $response->getStatus());

    }//end testOfferingAnonymousReturns401()

    public function testOfferingNotFoundReturns404(): void
    {
        $this->authenticateAsAllowedConsumer();
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'cat-1', 'hasOoapi' => true]);
        $this->ooapiService->method('isOoapiEnabled')->willReturn(true);
        $this->wireResolvedRegisterConfiguration('11', '13');
        $this->ooapiService->method('getResource')->willReturn(null);

        $response = $this->controller->offering('hva-onderwijs', 'missing-id');

        $this->assertSame(404, $response->getStatus());

    }//end testOfferingNotFoundReturns404()

    public function testOfferingResolvesByOfferingIdFieldOnItsOwnRegisterPair(): void
    {
        $this->authenticateAsAllowedConsumer();
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['id' => 'cat-1', 'hasOoapi' => true]);
        $this->ooapiService->method('isOoapiEnabled')->willReturn(true);
        $this->wireResolvedRegisterConfiguration('11', '13');

        $this->ooapiService->expects($this->once())
            ->method('getResource')
            ->with($this->anything(), '11', '13', 'o1', 'offeringId')
            ->willReturn(['offeringId' => 'o1', 'courseId' => 'c1']);

        $response = $this->controller->offering('hva-onderwijs', 'o1');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('o1', $response->getData()['offeringId']);
        // The parent-course reference is part of the OOAPI offering contract.
        $this->assertSame('c1', $response->getData()['courseId']);

    }//end testOfferingResolvesByOfferingIdFieldOnItsOwnRegisterPair()
}

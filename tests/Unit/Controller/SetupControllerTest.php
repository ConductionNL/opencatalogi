<?php
/**
 * Unit tests for SetupController (ADR-042 first-time-setup contract).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/setup-wizard-server-contract/specs/first-time-onboarding/spec.md#requirement-setup-server-contract-endpoints-onb-005
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\SetupController;
use OCA\OpenCatalogi\Service\BroadcastService;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SetupController.
 */
class SetupControllerTest extends TestCase
{
    private IRequest|MockObject $request;
    private IAppConfig|MockObject $config;
    private SettingsService|MockObject $settingsService;
    private DirectoryService|MockObject $directoryService;
    private BroadcastService|MockObject $broadcastService;
    private ContainerInterface|MockObject $container;
    private IL10N|MockObject $l10n;
    private LoggerInterface|MockObject $logger;
    private IUserSession|MockObject $userSession;
    private SetupController $controller;

    /**
     * Backing store for the mocked IAppConfig getValueString/setValueString.
     *
     * @var array<string,string>
     */
    private array $configValues = [];

    protected function setUp(): void
    {
        $this->request          = $this->createMock(IRequest::class);
        $this->config           = $this->createMock(IAppConfig::class);
        $this->settingsService  = $this->createMock(SettingsService::class);
        $this->directoryService = $this->createMock(DirectoryService::class);
        $this->broadcastService = $this->createMock(BroadcastService::class);
        $this->container        = $this->createMock(ContainerInterface::class);
        $this->l10n             = $this->createMock(IL10N::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
        $this->userSession      = $this->createMock(IUserSession::class);

        // Default to a signed-in user so status()'s login guard passes; the
        // anonymous path is asserted explicitly in its own test.
        $this->userSession->method('getUser')->willReturn($this->createMock(\OCP\IUser::class));

        $this->l10n->method('t')
            ->willReturnCallback(
                fn(string $text, array $params = []) => $params === [] ? $text : vsprintf($text, $params)
            );

        // A stateful IAppConfig backed by $this->configValues.
        $this->config->method('getValueString')
            ->willReturnCallback(
                fn(string $app, string $key, string $default = '') => $this->configValues[$key] ?? $default
            );
        $this->config->method('setValueString')
            ->willReturnCallback(
                function (string $app, string $key, string $value) {
                    $this->configValues[$key] = $value;
                    return true;
                }
            );

        $this->directoryService->method('getDefaultDirectoryUrl')
            ->willReturn('https://directory.opencatalogi.nl/apps/opencatalogi/api/directory');

        $this->controller = new SetupController(
            'opencatalogi',
            $this->request,
            $this->config,
            $this->settingsService,
            $this->directoryService,
            $this->broadcastService,
            $this->container,
            $this->l10n,
            $this->logger,
            $this->userSession
        );
    }

    public function testStatusRejectsAnonymous(): void
    {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn(null);
        $controller = new SetupController(
            'opencatalogi',
            $this->request,
            $this->config,
            $this->settingsService,
            $this->directoryService,
            $this->broadcastService,
            $this->container,
            $this->l10n,
            $this->logger,
            $session
        );

        $response = $controller->status();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    /**
     * Seed the four register/schema keys so registersConfigured() is true.
     */
    private function wireRegisters(): void
    {
        $this->configValues['catalog_register']     = '14';
        $this->configValues['catalog_schema']       = '54';
        $this->configValues['publication_register'] = '14';
        $this->configValues['listing_register']     = '14';
        $this->configValues['listing_schema']       = '55';
    }

    /**
     * Wire the container to return an ObjectService whose searchObjects yields $result.
     */
    private function mockObjectService(array $result = []): MockObject
    {
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjects')->willReturn($result);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        return $objectService;
    }

    /**
     * Build an ObjectEntity mock whose jsonSerialize() returns a safe wrapper.
     *
     * @param array<string,mixed> $object The inner object payload.
     */
    private function makeEntity(array $object = []): MockObject
    {
        $entity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn(['object' => $object]);
        return $entity;
    }

    public function testStatusConfigCheckDoneWhenRegistersWired(): void
    {
        $this->wireRegisters();
        $this->configValues['default_catalog_scope'] = 'public';
        $this->mockObjectService([$this->makeEntity()]);

        $response = $this->controller->status();
        $body     = $response->getData();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(2, $body['version']);
        $this->assertTrue($body['steps']['config-check']['done']);
        $this->assertTrue($body['steps']['catalog-scope']['done']);
        $this->assertTrue($body['steps']['create-catalog']['done']);
        $this->assertTrue($body['completed']);
    }

    public function testStatusConfigCheckNotDoneWhenRegistersMissing(): void
    {
        // Only some keys set — catalog_register missing.
        $this->configValues['catalog_schema']       = '54';
        $this->configValues['publication_register'] = '14';
        $this->configValues['listing_register']     = '14';
        $this->mockObjectService([]);

        $body = $this->controller->status()->getData();

        $this->assertFalse($body['steps']['config-check']['done']);
        $this->assertFalse($body['completed']);
    }

    public function testStatusCreateCatalogDoneViaOnboardingFlag(): void
    {
        $this->wireRegisters();
        $this->configValues['default_catalog_scope']      = 'internal';
        $this->configValues['onboarding_completed_version'] = '2';
        // No ObjectService needed — the onboarding flag short-circuits catalogExists().

        $body = $this->controller->status()->getData();

        $this->assertTrue($body['steps']['create-catalog']['done']);
        $this->assertTrue($body['completed']);
    }

    public function testConfigPersistsOnlyWhitelistedKeys(): void
    {
        $this->request->method('getParams')->willReturn(
            [
                'default_catalog_scope' => 'private',
                'catalog_register'      => '999',
                'unrelated_key'         => 'nope',
            ]
        );

        $body = $this->controller->config()->getData();

        $this->assertContains('default_catalog_scope', $body['saved']);
        $this->assertNotContains('catalog_register', $body['saved']);
        $this->assertNotContains('unrelated_key', $body['saved']);
        $this->assertSame('private', $this->configValues['default_catalog_scope']);
        // The non-whitelisted key was not written.
        $this->assertArrayNotHasKey('catalog_register', $this->configValues);
    }

    public function testActionReloadSettingsSucceedsWhenRegistersBecomeConfigured(): void
    {
        $this->settingsService->expects($this->once())
            ->method('loadSettings')
            ->willReturnCallback(
                function () {
                    $this->wireRegisters();
                    return [];
                }
            );

        $body = $this->controller->action('reload-settings')->getData();

        $this->assertTrue($body['success']);
    }

    public function testActionReloadSettingsFailsWhenStillUnconfigured(): void
    {
        $this->settingsService->method('loadSettings')->willReturn([]);

        $body = $this->controller->action('reload-settings')->getData();

        $this->assertFalse($body['success']);
    }

    public function testCreateFirstCatalogCreatesWhenNoneExists(): void
    {
        $this->wireRegisters();
        $this->configValues['default_catalog_scope'] = 'public';
        $objectService = $this->mockObjectService([]);

        $objectService->expects($this->once())->method('saveObject');

        $body = $this->controller->action('create-first-catalog')->getData();

        $this->assertTrue($body['success']);
        $this->assertSame('2', $this->configValues['onboarding_completed_version']);
    }

    /**
     * The seeded catalog must include `registers` + `schemas` so
     * `PublicationService::getCatalogFilters()` has a scope to union.
     * Regression coverage for WOO-529: without these arrays the /search
     * endpoint returns 0 local rows on a fresh install and the create-
     * publication modal falls into the WOO-527 "not configured" state.
     */
    public function testCreateFirstCatalogSeedsRegistersAndSchemasFromPublicationConfig(): void
    {
        $this->wireRegisters();
        $this->configValues['publication_schema']    = '77';
        $this->configValues['default_catalog_scope'] = 'public';
        $objectService = $this->mockObjectService([]);

        $capturedObject = null;
        $objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(function ($object) use (&$capturedObject) {
                $capturedObject = $object;
                return null;
            });

        $body = $this->controller->action('create-first-catalog')->getData();

        $this->assertTrue($body['success']);
        $this->assertIsArray($capturedObject, 'saveObject received a payload');
        $this->assertArrayHasKey('registers', $capturedObject, 'catalog has registers scope');
        $this->assertArrayHasKey('schemas', $capturedObject, 'catalog has schemas scope');
        $this->assertSame(['14'], $capturedObject['registers']);
        $this->assertSame(['77'], $capturedObject['schemas']);
    }

    /**
     * Absent publication_register / publication_schema keys must not abort
     * catalog creation — the wizard still produces a catalog that an admin
     * can retro-fit later. Only the corresponding array is omitted.
     */
    public function testCreateFirstCatalogOmitsScopeArraysWhenPublicationKeysMissing(): void
    {
        $this->wireRegisters();
        unset($this->configValues['publication_register']);
        // publication_schema was never set in wireRegisters; make it explicit.
        $this->configValues['default_catalog_scope'] = 'public';
        $objectService = $this->mockObjectService([]);

        $capturedObject = null;
        $objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(function ($object) use (&$capturedObject) {
                $capturedObject = $object;
                return null;
            });

        $body = $this->controller->action('create-first-catalog')->getData();

        $this->assertTrue($body['success']);
        $this->assertArrayNotHasKey('registers', $capturedObject);
        $this->assertArrayNotHasKey('schemas', $capturedObject);
    }

    public function testCreateFirstCatalogIsIdempotentWhenCatalogExists(): void
    {
        $this->wireRegisters();
        $objectService = $this->mockObjectService([$this->makeEntity()]);

        $objectService->expects($this->never())->method('saveObject');

        $body = $this->controller->action('create-first-catalog')->getData();

        $this->assertTrue($body['success']);
    }

    public function testConnectFederationSyncsTheNationalDirectory(): void
    {
        $url = 'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory';

        $this->directoryService->expects($this->once())
            ->method('syncDirectory')
            ->with($url)
            ->willReturn(['listings_created' => 3, 'listings_updated' => 1]);

        $this->broadcastService->expects($this->once())
            ->method('broadcast')
            ->with($url)
            ->willReturn([$url => true]);

        $body = $this->controller->action('connect-federation')->getData();

        $this->assertTrue($body['success']);
        $this->assertSame(3, $body['details']['listings_created']);
        $this->assertSame(1, $body['details']['listings_updated']);
        $this->assertTrue($body['details']['advertised']);
        $this->assertStringContainsString('Fetched 3 new and 1 updated', $body['message']);
        $this->assertStringContainsString('announced to the directory', $body['message']);
    }

    public function testConnectFederationZeroListingsReportsEmptyDirectoryAndAdvertise(): void
    {
        $url = 'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory';

        $this->directoryService->method('syncDirectory')
            ->willReturn(['listings_created' => 0, 'listings_updated' => 0]);

        $this->broadcastService->method('broadcast')
            ->willReturn([$url => true]);

        $body = $this->controller->action('connect-federation')->getData();

        $this->assertTrue($body['success']);
        $this->assertSame(0, $body['details']['listings_created']);
        $this->assertSame(0, $body['details']['listings_updated']);
        $this->assertTrue($body['details']['advertised']);
        // No misleading "connected" — instead explain what 0/0 actually means.
        $this->assertStringContainsString('no other peer instances registered yet', $body['message']);
        $this->assertStringContainsString('announced to the directory', $body['message']);
    }

    public function testConnectFederationBroadcastFailureIsReportedButStepSucceeds(): void
    {
        $this->directoryService->method('syncDirectory')
            ->willReturn(['listings_created' => 0, 'listings_updated' => 0]);

        $this->broadcastService->method('broadcast')
            ->willThrowException(new \RuntimeException('remote unreachable'));

        $body = $this->controller->action('connect-federation')->getData();

        // Pull succeeded → step is still success:true, but advertise=false surfaces
        // in the details and the message tells the admin explicitly.
        $this->assertTrue($body['success']);
        $this->assertFalse($body['details']['advertised']);
        $this->assertStringContainsString('could not be announced', $body['message']);
    }

    public function testConnectFederationFailureIsNonFatal(): void
    {
        $this->directoryService->method('syncDirectory')
            ->willThrowException(new \RuntimeException('unreachable'));

        $response = $this->controller->action('connect-federation');
        $body     = $response->getData();

        // Non-fatal: success=false but HTTP 200 so the optional step stays skippable.
        $this->assertFalse($body['success']);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testUnknownActionReturnsBadRequest(): void
    {
        $response = $this->controller->action('does-not-exist');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertFalse($response->getData()['success']);
    }
}//end class

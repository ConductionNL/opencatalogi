<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\SettingsController;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SettingsController.
 */
class SettingsControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private SettingsService|MockObject $settingsService;
    private IL10N|MockObject $l10n;
    private IUserSession|MockObject $userSession;
    private SettingsController $controller;

    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->l10n            = $this->createMock(IL10N::class);
        $this->userSession     = $this->createMock(IUserSession::class);

        $this->l10n->method('t')
            ->willReturnCallback(fn(string $text, array $params = []) => $text);

        // The controller guards its read/write endpoints on an authenticated user.
        // Default to a logged-in user so the per-method happy/error paths run; tests
        // that assert the unauthenticated path override this with willReturn(null).
        $this->userSession->method('getUser')
            ->willReturn($this->createMock(\OCP\IUser::class));

        $this->controller = new SettingsController(
            'opencatalogi',
            $this->request,
            $this->settingsService,
            $this->l10n,
            $this->userSession
        );
    }

    // NOTE: getObjectService() / getConfigurationService() were removed from
    // SettingsController — the OpenRegister service resolution now lives in
    // SettingsService (and is covered by SettingsServiceTest). The former
    // testGetObjectService*/testGetConfigurationService* cases were retired, along
    // with the container/appManager constructor args they depended on.

    public function testIndexReturnsSettings(): void
    {
        $settingsData = [
            'configuration' => ['catalog_register' => '1', 'catalog_schema' => '5'],
        ];

        $this->settingsService->method('getSettings')
            ->willReturn($settingsData);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexReturns500OnException(): void
    {
        $this->settingsService->method('getSettings')
            ->willThrowException(new \Exception('Settings error'));

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testCreateUpdatesSettings(): void
    {
        $params = ['theme' => 'dark', 'language' => 'nl'];
        $result = ['theme' => 'dark', 'language' => 'nl', 'updated' => true];

        $this->request->method('getParams')
            ->willReturn($params);

        $this->settingsService->method('updateSettings')
            ->with($params)
            ->willReturn($result);

        $response = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testCreateReturns500OnException(): void
    {
        $this->request->method('getParams')
            ->willReturn(['invalid' => 'data']);

        $this->settingsService->method('updateSettings')
            ->willThrowException(new \Exception('Update failed'));

        $response = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    /**
     * `update()` is the canonical write (PUT /api/settings): it passes the
     * request params straight to SettingsService::updateSettings() and returns
     * the persisted values.
     *
     * @spec openspec/specs/admin-settings/spec.md#requirement-admin-settings-page-loads-and-saves-configuration-set-or-006
     */
    public function testUpdateWritesRequestParamsAndReturnsPersistedValues(): void
    {
        $params = ['catalog_register' => '1', 'catalog_schema' => '5'];
        $result = ['catalog_register' => '1', 'catalog_schema' => '5'];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($params);

        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($params)
            ->willReturn($result);

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertSame($result, $response->getData());
    }

    /**
     * `update()` surfaces a service failure as a 500 with the error message,
     * matching the shape `create()` has always returned.
     *
     * @spec openspec/specs/admin-settings/spec.md#requirement-admin-settings-page-loads-and-saves-configuration-set-or-006
     */
    public function testUpdateReturns500OnException(): void
    {
        $this->request->method('getParams')
            ->willReturn(['invalid' => 'data']);

        $this->settingsService->method('updateSettings')
            ->willThrowException(new \Exception('Update failed'));

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertSame(['error' => 'Update failed'], $response->getData());
    }

    /**
     * `create()` (the legacy POST alias) delegates to `update()` and is
     * behaviourally identical — same service call, same payload out.
     *
     * @spec openspec/specs/admin-settings/spec.md#requirement-admin-settings-page-loads-and-saves-configuration-set-or-006
     */
    public function testCreateDelegatesToUpdate(): void
    {
        $params = ['theme_register' => '2'];
        $result = ['theme_register' => '2'];

        $this->request->method('getParams')
            ->willReturn($params);

        // The single expectation is the proof of delegation: create() must
        // reach updateSettings() exactly once, via update().
        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($params)
            ->willReturn($result);

        $createResponse = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $createResponse);
        $this->assertEquals(200, $createResponse->getStatus());
        $this->assertSame($result, $createResponse->getData());
    }

    /**
     * `update()` is the app-configuration write, not a catch-all: it must not
     * absorb the sibling surfaces this controller also hosts. Those keep their
     * own routes (`POST /api/settings/publishing`, `POST /api/settings/import`)
     * and their own payload and response contracts.
     *
     * @spec openspec/specs/admin-settings/spec.md#requirement-admin-settings-page-loads-and-saves-configuration-set-or-006
     */
    public function testUpdateDoesNotTouchPublishingOptionsOrImportSurfaces(): void
    {
        $this->request->method('getParams')
            ->willReturn(['auto_publish_objects' => 'true']);

        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->willReturn(['auto_publish_objects' => 'true']);

        // update() routes everything through updateSettings(); the dedicated
        // publishing-options and import entry points must stay untouched.
        $this->settingsService->expects($this->never())
            ->method('updatePublishingOptions');
        $this->settingsService->expects($this->never())
            ->method('getPublishingOptions');
        $this->settingsService->expects($this->never())
            ->method('manualImport');

        $response = $this->controller->update();

        $this->assertEquals(200, $response->getStatus());
    }

    public function testLoadReturnsSettings(): void
    {
        $loadedSettings = ['registers' => [], 'schemas' => [], 'imported' => true];

        $this->settingsService->method('loadSettings')
            ->willReturn($loadedSettings);

        $response = $this->controller->load();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testLoadReturns500OnException(): void
    {
        $this->settingsService->method('loadSettings')
            ->willThrowException(new \Exception('Load failed'));

        $response = $this->controller->load();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testGetPublishingOptionsReturnsOptions(): void
    {
        $options = ['autopublish' => true, 'defaultSchema' => 5];

        $this->settingsService->method('getPublishingOptions')
            ->willReturn($options);

        $response = $this->controller->getPublishingOptions();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testGetPublishingOptionsReturns500OnException(): void
    {
        $this->settingsService->method('getPublishingOptions')
            ->willThrowException(new \Exception('Options error'));

        $response = $this->controller->getPublishingOptions();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testUpdatePublishingOptionsReturnsUpdatedOptions(): void
    {
        $params = ['autopublish' => false];
        $result = ['autopublish' => false, 'updated' => true];

        $this->request->method('getParams')
            ->willReturn($params);

        $this->settingsService->method('updatePublishingOptions')
            ->with($params)
            ->willReturn($result);

        $response = $this->controller->updatePublishingOptions();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testUpdatePublishingOptionsReturns500OnException(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->settingsService->method('updatePublishingOptions')
            ->willThrowException(new \Exception('Update failed'));

        $response = $this->controller->updatePublishingOptions();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testGetVersionInfoReturnsVersionData(): void
    {
        $versionData = ['app_version' => '1.2.3', 'config_version' => '2.0'];

        $this->settingsService->method('getVersionInfo')
            ->willReturn($versionData);

        $response = $this->controller->getVersionInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testGetVersionInfoReturns500OnException(): void
    {
        $this->settingsService->method('getVersionInfo')
            ->willThrowException(new \Exception('Version error'));

        $response = $this->controller->getVersionInfo();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    public function testManualImportReturnsSuccessResult(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->settingsService->method('manualImport')
            ->with(false)
            ->willReturn(['success' => true, 'imported' => 5]);

        $response = $this->controller->manualImport();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testManualImportWithForceFlag(): void
    {
        $this->request->method('getParams')
            ->willReturn(['force' => true]);

        $this->settingsService->method('manualImport')
            ->with(true)
            ->willReturn(['success' => true, 'imported' => 10]);

        $response = $this->controller->manualImport();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testManualImportReturns400OnFailure(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->settingsService->method('manualImport')
            ->with(false)
            ->willReturn(['success' => false, 'message' => 'Nothing to import']);

        $response = $this->controller->manualImport();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
    }

    public function testManualImportReturns500OnException(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->settingsService->method('manualImport')
            ->willThrowException(new \Exception('Import crash'));

        $response = $this->controller->manualImport();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }
}

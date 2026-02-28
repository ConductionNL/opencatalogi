<?php
/**
 * OpenCatalogi Settings Controller
 *
 * This file contains the controller class for handling settings in the OpenCatalogi application.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Controller;

use OCP\IAppConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use OCA\OpenCatalogi\Service\SettingsService;

/**
 * Controller for handling settings-related operations in the OpenCatalogi.
 */
class SettingsController extends Controller
{

    /**
     * SettingsController constructor.
     *
     * @param string             $appName         The name of the app
     * @param IRequest           $request         The request object
     * @param IAppConfig         $config          The app configuration
     * @param ContainerInterface $container       The container
     * @param IAppManager        $appManager      The app manager
     * @param SettingsService    $settingsService The settings service
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly SettingsService $settingsService,
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Retrieve the current settings.
     *
     * @return JSONResponse JSON response containing the current settings.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        try {
            $data = $this->settingsService->getSettings();
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end index()


    /**
     * Handle the post request to update settings.
     *
     * @return JSONResponse JSON response containing the updated settings.
     *
     * @NoCSRFRequired
     */
    public function create(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateSettings($data);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end create()


    /**
     * Load the settings from the publication_register.json file.
     *
     * @return JSONResponse JSON response containing the settings.
     *
     * @NoCSRFRequired
     */
    public function load(): JSONResponse
    {
        try {
            $result = $this->settingsService->loadSettings();
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end load()


    /**
     * Get the current publishing options.
     *
     * @return JSONResponse JSON response containing the current publishing options.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getPublishingOptions(): JSONResponse
    {
        try {
            $data = $this->settingsService->getPublishingOptions();
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end getPublishingOptions()


    /**
     * Update the publishing options.
     *
     * @return JSONResponse JSON response containing the updated publishing options.
     *
     * @NoCSRFRequired
     */
    public function updatePublishingOptions(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updatePublishingOptions($data);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end updatePublishingOptions()


    /**
     * Get version information for the app and configuration.
     *
     * @return JSONResponse JSON response containing version information.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getVersionInfo(): JSONResponse
    {
        try {
            $data = $this->settingsService->getVersionInfo();
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end getVersionInfo()


    /**
     * Manually trigger configuration import.
     *
     * @return JSONResponse JSON response containing import results.
     *
     * @NoCSRFRequired
     */
    public function manualImport(): JSONResponse
    {
        try {
            $params      = $this->request->getParams();
            $forceImport = isset($params['force']) && $params['force'] === true;

            $result = $this->settingsService->manualImport($forceImport);

            if ($result['success']) {
                return new JSONResponse($result);
            } else {
                return new JSONResponse($result, 400);
            }
        } catch (\Exception $e) {
            return new JSONResponse(
                [
                    'success' => false,
                    'message' => 'Import failed: '.$e->getMessage(),
                    'error'   => $e->getMessage(),
                ],
                500
            );
        }//end try

    }//end manualImport()


}//end class

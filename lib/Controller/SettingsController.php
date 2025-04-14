<?php

namespace OCA\OpenCatalogi\Controller;

use OCP\IAppConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\OpenCatalogi\Service\ObjectService;

/**
 * @class SettingsController
 * @category Controller
 * @package OpenCatalogi
 * @subpackage Controller
 * @author Conduction Team
 * @copyright 2023 Conduction
 * @license EUPL-1.2
 * @version 1.0.0
 * @link https://github.com/OpenCatalogi/opencatalogi
 * 
 * Controller for handling settings-related operations in the OpenCatalogi.
 */
class SettingsController extends Controller
{
	/**
	 * SettingsController constructor.
	 *
	 * @param string $appName The name of the app
	 * @param IAppConfig $config The app configuration
	 * @param IRequest $request The request object
	 * @param ObjectService $objectService The object service
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly ObjectService $objectService
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Retrieve the current settings.
	 *
	 * @return JSONResponse JSON response containing the current settings
	 *
	 * @NoCSRFRequired
	 */
	public function index(): JSONResponse
	{
		// Initialize the data array
		$data = [];
		$data['objectTypes'] = ['catalog', 'listing', 'organization', 'publication', 'theme', 'page', 'menu','glossery'];
		$data['openRegisters'] = false;
		$data['availableRegisters'] = [];

		// Check if the OpenRegister service is available
		$openRegisters = $this->objectService->getOpenRegisters();
		if ($openRegisters !== null) {
			$data['openRegisters'] = true;
			$data['availableRegisters'] = $openRegisters->getRegisters();
		}

		// Build defaults array dynamically based on object types
		$defaults = [];
		foreach ($data['objectTypes'] as $type) {
			// Always use openregister as source
			$defaults["{$type}_source"] = 'openregister';
			$defaults["{$type}_schema"] = '';
			$defaults["{$type}_register"] = '';
		}

		// Get the current values for the object types from the configuration
		try {
			foreach ($defaults as $key => $defaultValue) {
				$data['configuration'][$key] = $this->config->getValueString($this->appName, $key, $defaultValue);
			}
			return new JSONResponse($data);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Handle the post request to update settings.
	 *
	 * @return JSONResponse JSON response containing the updated settings
	 *
	 * @NoCSRFRequired
	 */
	public function create(): JSONResponse
	{
		// Get all parameters from the request
		$data = $this->request->getParams();

		try {
			// Update each setting in the configuration
			foreach ($data as $key => $value) {
				$this->config->setValueString($this->appName, $key, $value);
				// Retrieve the updated value to confirm the change
				$data[$key] = $this->config->getValueString($this->appName, $key);
			}
			return new JSONResponse($data);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}
}

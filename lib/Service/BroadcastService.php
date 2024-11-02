<?php

namespace OCA\OpenCatalogi\Service;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Uid\Uuid;
use OCA\OpenCatalogi\Service\DirectoryService;

/**
 * Service class for handling directory-related operations
 */
class BroadcastService
{
	/** @var string The name of the app */
	private string $appName = 'opencatalogi';

	/** @var Client The HTTP client for making requests */
	private Client $client;

	/**
	 * Constructor for DirectoryService
	 *
	 * @param IURLGenerator $urlGenerator URL generator interface
	 * @param IAppConfig $config App configuration interface
	 * @param DirectoryService $directoryService Directory service for handling directories
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $config,
		private readonly DirectoryService $directoryService,
	)
	{
		$this->client = new Client([]);
	}

	/**
	 * Broadcast this OpenCatalogi directory to one or more instances
	 *
	 * @param string|null $url Optional URL of a specific instance to broadcast to
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface|GuzzleException
	 */
	public function broadcast(?string $url = null): void {
		// Initialize hooks array
		$hooks = [];

		// If URL is provided, add it to hooks
		if ($url !== null) {
			$hooks[] = $url;
		}
		// Otherwise get all unique directory URLs
		else {
			$listings = $this->directoryService->getListings();
			$hooks = array_unique(array_column($listings['results'], 'directory'));
		}

		// Get the URL of this directory
		$directoryUrl = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('opencatalogi.directory.index'));

		// Broadcast to each hook
		foreach ($hooks as $hook) {
			// Send POST request with directory URL
			try {
				$this->client->post($hook, [
					'json' => [
						'directory' => $directoryUrl
					]
				]);
				
				// Log successful broadcast
				trigger_error(
					"Successfully broadcasted to {$hook}",
					E_USER_NOTICE
				);
			} catch (\Exception $e) {
				// Throw a warning since broadcasting failure shouldn't break the application flow
				// but we still want to notify about the issue
				trigger_error(
					"Failed to broadcast to {$hook}: " . $e->getMessage(),
					E_USER_WARNING
				);
			}
		}
	}
}

<?php
/**
 * Service for broadcasting this OpenCatalogi directory to other instances.
 *
 * Provides functionality to notify external instances about this directory
 * through HTTP POST requests, either to a specific URL or to all known directories.
 *
 * @category Service
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

namespace OCA\OpenCatalogi\Service;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * BroadcastService Class
 *
 * This class provides functionality to broadcast this OpenCatalogi directory to other instances.
 * It allows for broadcasting to a specific URL or to all known directories.
 */
class BroadcastService
{

    /**
     * @var string The name of the app
     */
    private string $appName = 'opencatalogi';

    /**
     * @var Client The HTTP client for making requests
     */
    private Client $client;

    /**
     * @var int Maximum number of broadcast retries on failure
     */
    private const MAX_RETRIES = 3;

    /**
     * @var int Timeout for HTTP requests in seconds
     */
    private const REQUEST_TIMEOUT = 30;


    /**
     * Constructor for BroadcastService
     *
     * @param IURLGenerator      $urlGenerator URL generator interface
     * @param IAppConfig         $config       App configuration interface
     * @param ContainerInterface $container    Server container for dependency injection
     * @param IAppManager        $appManager   App manager for checking installed apps
     * @param LoggerInterface    $logger       Logger for recording broadcast activities
     */
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
        // Initialize HTTP client with default configuration
        $this->client = new Client([
            'timeout' => self::REQUEST_TIMEOUT,
            'connect_timeout' => 10,
            'verify' => true, // Enable SSL verification for security
        ]);

    }//end __construct()


    /**
     * Attempts to retrieve the OpenRegister ObjectService from the container.
     *
     * This method checks if the OpenRegister app is installed and available,
     * then attempts to retrieve the ObjectService from the dependency container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The OpenRegister ObjectService if available
     * 
     * @throws \RuntimeException                       When OpenRegister service is not available
     * @throws ContainerExceptionInterface             When container access fails
     * @throws NotFoundExceptionInterface              When service is not found in container
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        // Check if OpenRegister app is installed and enabled
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            try {
                // Attempt to retrieve the ObjectService from the container
                return $this->container->get('OCA\OpenRegister\Service\ObjectService');
            } catch (ContainerExceptionInterface | NotFoundExceptionInterface $e) {
                // Log the container error for debugging
                $this->logger->error('Failed to retrieve OpenRegister ObjectService from container: ' . $e->getMessage());
                throw $e;
            }
        }

        // Throw exception when OpenRegister is not available
        throw new \RuntimeException('OpenRegister service is not available. Ensure OpenRegister app is installed and enabled.');

    }//end getObjectService()


    /**
     * Get the current directory URL for this OpenCatalogi instance
     *
     * This method generates the absolute URL for this directory's index endpoint
     * which will be sent to other instances during broadcast.
     *
     * @return string The absolute URL of this directory
     */
    private function getCurrentDirectoryUrl(): string
    {
        // Generate the absolute URL for the directory index endpoint
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('opencatalogi.directory.index')
        );

    }//end getCurrentDirectoryUrl()


    /**
     * Retrieve all unique directory URLs from existing listings
     *
     * This method fetches all listing objects and extracts unique directory URLs
     * to determine which external instances should receive broadcast notifications.
     *
     * @return array<string> Array of unique directory URLs
     * 
     * @throws DoesNotExistException              When required data is not found
     * @throws MultipleObjectsReturnedException   When duplicate objects are found
     * @throws ContainerExceptionInterface        When container access fails
     * @throws NotFoundExceptionInterface         When service is not found in container
     */
    private function getDirectoryUrls(): array
    {
        try {
            // Retrieve all listing objects from OpenRegister
            $listings = $this->getObjectService()->getObjects(objectType: 'listing');
            
            // Extract unique directory URLs from the listings
            $directoryUrls = array_unique(array_column($listings, 'directory'));
            
            // Filter out empty or invalid URLs
            return array_filter($directoryUrls, fn($url) => !empty($url) && filter_var($url, FILTER_VALIDATE_URL));
            
        } catch (\Exception $e) {
            // Log the error and re-throw for caller handling
            $this->logger->error('Failed to retrieve directory URLs: ' . $e->getMessage());
            throw $e;
        }

    }//end getDirectoryUrls()


    /**
     * Send broadcast request to a specific URL with retry logic
     *
     * This method handles the actual HTTP POST request with built-in retry logic
     * for handling temporary network failures or service unavailability.
     *
     * @param string $url         The target URL to broadcast to
     * @param string $directoryUrl The URL of this directory to include in broadcast
     * 
     * @return bool True if broadcast was successful, false otherwise
     */
    private function sendBroadcastRequest(string $url, string $directoryUrl): bool
    {
        $attempt = 0;
        
        // Retry logic for handling temporary failures
        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            
            try {
                // Send POST request with directory URL payload
                $response = $this->client->post(
                    $url,
                    [
                        'json' => [
                            'directory' => $directoryUrl,
                            'timestamp' => (new DateTime())->format('c'),
                            'source' => $this->appName,
                        ],
                        'headers' => [
                            'User-Agent' => 'OpenCatalogi-Broadcast/1.0',
                            'Content-Type' => 'application/json',
                        ],
                    ]
                );

                // Check if the response indicates success
                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    $this->logger->info("Successfully broadcasted to {$url} on attempt {$attempt}");
                    return true;
                }
                
                // Log non-success status code
                $this->logger->warning("Broadcast to {$url} returned status {$response->getStatusCode()} on attempt {$attempt}");
                
            } catch (GuzzleException $e) {
                // Log the attempt failure
                $this->logger->warning("Broadcast attempt {$attempt} to {$url} failed: " . $e->getMessage());
                
                // If this was the last attempt, log as error
                if ($attempt === self::MAX_RETRIES) {
                    $this->logger->error("All {$attempt} broadcast attempts to {$url} failed. Final error: " . $e->getMessage());
                } else {
                    // Wait before retrying (exponential backoff)
                    sleep($attempt * 2);
                }
            }
        }
        
        return false;

    }//end sendBroadcastRequest()


    /**
     * Broadcast this OpenCatalogi directory to one or more instances
     *
     * This method handles broadcasting the current directory information to external
     * OpenCatalogi instances. It can broadcast to a specific URL or to all known directories.
     *
     * @param string|null $url Optional URL of a specific instance to broadcast to.
     *                         If null, broadcasts to all known directories.
     * 
     * @return array<string, bool> Associative array of URLs and their broadcast success status
     * 
     * @throws DoesNotExistException              When required data is not found
     * @throws MultipleObjectsReturnedException   When duplicate objects are found
     * @throws ContainerExceptionInterface        When container access fails
     * @throws NotFoundExceptionInterface         When service is not found in container
     */
    public function broadcast(?string $url = null): array
    {
        // Get the URL of this directory to include in broadcast payload
        $directoryUrl = $this->getCurrentDirectoryUrl();
        
        // Initialize results array to track success/failure per URL
        $results = [];
        
        // Determine target URLs for broadcasting
        if ($url !== null) {
            // Validate the provided URL
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException("Invalid URL provided for broadcast: {$url}");
            }
            $targetUrls = [$url];
        } else {
            // Get all known directory URLs
            $targetUrls = $this->getDirectoryUrls();
        }

        // If no target URLs found, log warning and return empty results
        if (empty($targetUrls)) {
            $this->logger->warning('No target URLs found for broadcasting');
            return $results;
        }

        // Log the start of broadcast operation
        $this->logger->info('Starting broadcast to ' . count($targetUrls) . ' target(s)');

        // Broadcast to each target URL
        foreach ($targetUrls as $targetUrl) {
            // Skip broadcasting to self to avoid loops
            if ($targetUrl === $directoryUrl) {
                $this->logger->debug("Skipping broadcast to self: {$targetUrl}");
                continue;
            }
            
            // Attempt to send broadcast request
            $success = $this->sendBroadcastRequest($targetUrl, $directoryUrl);
            $results[$targetUrl] = $success;
        }

        // Log summary of broadcast operation
        $successCount = count(array_filter($results));
        $totalCount = count($results);
        $this->logger->info("Broadcast completed: {$successCount}/{$totalCount} successful");

        return $results;

    }//end broadcast()


}//end class

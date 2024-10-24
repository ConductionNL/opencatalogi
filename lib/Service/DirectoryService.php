<?php

namespace OCA\OpenCatalogi\Service;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenCatalogi\Db\Catalog;
use OCA\OpenCatalogi\Db\CatalogMapper;
use OCA\OpenCatalogi\Db\Listing;
use OCA\OpenCatalogi\Db\ListingMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service class for handling directory-related operations
 */
class DirectoryService
{
	/** @var string The name of the app */
	private string $appName = 'opencatalogi';

	/** @var Client The HTTP client for making requests */
	private Client $client;

	/** @var array The list of external publication types that are used by this instance */
	private array $externalPublicationTypes = [];

	/**
	 * Constructor for DirectoryService
	 *
	 * @param IURLGenerator $urlGenerator URL generator interface
	 * @param IAppConfig $config App configuration interface
	 * @param ObjectService $objectService Object service for handling objects
	 * @param CatalogMapper $catalogMapper Mapper for catalog objects
	 * @param ListingMapper $listingMapper Mapper for listing objects
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $config,
		private readonly ObjectService $objectService,
		private readonly CatalogMapper $catalogMapper,
		private readonly ListingMapper $listingMapper,
	)
	{
		$this->client = new Client([]);
	}

	/**
	 * Register to all unique external directories.
	 *
	 * @return array An array of registration results
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
	 * @throws GuzzleException
	 */
	public function updateAllExternalDirectories(): array
	{
		// Get all directories
		$listings = $this->getListings();

		// Extract unique directory URLs
		$uniqueDirectories = array_unique(array_column($listings['results'], 'directory'));

		$results = [];

		// Register to each unique directory
		foreach ($uniqueDirectories as $directoryUrl) {
			$statusCode = $this->updateExternalDirectory($directoryUrl);
			$results[] = [
				'url' => $directoryUrl,
				'statusCode' => $statusCode
			];
		}

		return $results;
	}

	/**
	 * Register the local directory to the external directory.
	 *
	 * @param string $directoryUrl The URL of the external directory.
	 * @return int The status code of the response.
	 * @throws GuzzleException
	 */
	public function updateExternalDirectory(string $directoryUrl): int
	{
		$body = [
			'url' => $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('opencatalogi.directory.index'))
		];

		try {
			// Send POST request to register
			$response = $this->client->post($directoryUrl, [
				'json' => $body
			]);

			return $response->getStatusCode();
		} catch (\Exception $e) {
			// Log the error or handle it as needed
			return 500; // Return a 500 status code to indicate an error
		}
	}

	/**
	 * Get the list of external publication types that are used by this instance
	 *
	 * @return array The list of external publication types
	 */
	private function getExternalPublicationTypes(): array
	{
		if (empty($this->externalPublicationTypes)) {
			$result = $this->objectService->getObjects('publicationType');
			$this->externalPublicationTypes = array_filter($result, function($pt) {
				return !empty($pt['source']);
			});
		}
		return $this->externalPublicationTypes;
	}

	/**
	 * Convert a listing object or array to a directory array
	 *
	 * @param Listing|array $listing The listing object or array to convert
	 * @return array The converted directory array
	 */
	private function getDirectoryFromListing(Listing|array $listing): array
	{
		// Serialize the listing if it's a Listing object
		if ($listing instanceof Listing) {
			$listing = $listing->jsonSerialize();
		}

		// Set id to uuid @todo this breaks stuff when trying to find and update a listing
//		$listing['id'] = $listing['uuid'];

		// Remove unneeded fields
		unset($listing['status'], $listing['lastSync'], $listing['default'], $listing['available'], $listing['catalog'], $listing['statusCode'],
//			$listing['uuid'], //@todo this breaks stuff when trying to find and update a listing
			$listing['hash']);

		// Process publication types
		if (isset($listing['publicationTypes']) && is_array($listing['publicationTypes'])) {
			foreach ($listing['publicationTypes'] as &$publicationType) {
				// Convert publicationType to array if it's an object
				if ($publicationType instanceof \JsonSerializable) {
					$publicationType = $publicationType->jsonSerialize();
				}

				// set listed and owner to false by default
				$publicationType['listed'] = false;
				$publicationType['owner'] = false;
				
				// check if this publication type is used by this instance
				if (isset($publicationType['source'])) {
					// Get all external publication types used by this instance
					$externalPublicationTypes = $this->getExternalPublicationTypes();
					
					// Filter external types to find matches with the current publication type
					$matchingTypes = array_filter($externalPublicationTypes, function($externalType) use ($publicationType) {
						// Check if the external type has a source and if it matches the current publication type's source
						return isset($externalType['source']) && $externalType['source'] === $publicationType['source'];
					});
					
					// Set 'listed' to true if there are any matching types, false otherwise
					$publicationType['listed'] = !empty($matchingTypes);
				}
			}
		}

		// TODO: This should be mapped to the stoplight documentation
		return $listing;
	}

	/**
	 * Convert a catalog object or array to a directory array
	 *
	 * @param Catalog|array $catalog The catalog object or array to convert
	 * @return array The converted directory array
	 */
	private function getDirectoryFromCatalog(Catalog|array $catalog): array
	{
		// Serialize the catalog if it's a Catalog object
		if ($catalog instanceof Catalog) {
			$catalog = $catalog->jsonSerialize();
		}

		// Set id to uuid if it's not a valid UUID and uuid field exists with a valid UUID
		if (
			(!isset($catalog['id']) && isset($catalog['uuid']))
			|| 
			(!Uuid::isValid($catalog['id']) && isset($catalog['uuid']) && Uuid::isValid($catalog['uuid'])) 
			) {
			$catalog['id'] = $catalog['uuid'];
		}

		// Remove unneeded fields
		unset($catalog['image'], $catalog['uuid']);
		// Keep $catalog['listed'] as it is needed later on to filter out the catalogi that are not listed!

		// Add the search and directory urls
		$catalog['search'] = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute("opencatalogi.search.index"));
		$catalog['directory'] = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute("opencatalogi.directory.index"));

		// Process publication types
		if (isset($catalog['publicationTypes']) && is_array($catalog['publicationTypes'])) {
			foreach ($catalog['publicationTypes'] as &$publicationType) {
				// Convert publicationType to array if it's an object
				if ($publicationType instanceof \JsonSerializable) {
					$publicationType = $publicationType->jsonSerialize();
				}
				$publicationType['listed'] = true;
				$publicationType['owner'] = true;
				if (!isset($publicationType['source']) || empty($publicationType['source'])) {
					$publicationType['source'] = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute("opencatalogi.directory.publicationType", ['id' => $publicationType['id']]));
				}
			}
		}

		// TODO: This should be mapped to the stoplight documentation
		return $catalog;
	}

	/**
	 * Get all listings to scan.
	 *
	 * @return array An array containing 'results' and 'total' count
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
	 */
	private function getListings(): array
	{
		// Get all the listings
		$listings = $this->objectService->getObjects(objectType: 'listing');
		$listings = array_map([$this, 'getDirectoryFromListing'], $listings);

		// TODO: Define when a listed item should not be shown (e.g. when secret or trusted is true), this is a product decision

		// Create a wrapper array with 'results' and 'total'
		$listings = [
			'results' => $listings,
			'total' => count($listings)
		];

		return array_unique($listings);
	}

	/**
	 * Get all directories to scan.
	 *
	 * @return array An array containing 'results' (merged directories) and 'total' count
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
	 */
	public function getDirectories(): array
	{
		// Get all the listings
		$listings = $this->objectService->getObjects(objectType: 'listing');
		$listings = array_map([$this, 'getDirectoryFromListing'], $listings);

		// TODO: Define when a listed item should not be shown (e.g. when secret or trusted is true), this is a product decision

		// Get all the catalogi
		$catalogi = $this->objectService->getObjects(objectType: 'catalog', extend: ['publicationTypes', 'organization']);
		$catalogi = array_map([$this, 'getDirectoryFromCatalog'], $catalogi);

		// Filter out the catalogi that are not listed
		$catalogi = array_filter($catalogi, function($catalog) {
			return $catalog['listed'] !== false;
		});

		// Remove the 'listed' property from all catalogi objects
		$catalogi = array_map(function($catalog) {
			unset($catalog['listed']);
			return $catalog;
		}, $catalogi);

		// Merge listings and catalogi into a new array
		$mergedDirectories = array_merge($listings, $catalogi);

		// Create a wrapper array with 'results' and 'total'
		$directories = [
			'results' => $mergedDirectories,
			'total' => count($mergedDirectories)
		];

		return array_unique($directories);
	}

	/**
	 * Run a synchronisation based on cron
	 *
	 * @return array An array containing synchronization results
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
	 * @throws GuzzleException
	 */
	public function doCronSync(): array {
		$results = [];
		$listings = $this->getListings();

		// Extract unique directory URLs
		$uniqueDirectories = array_unique(array_column($listings['results'], 'directory'));

		// Sync each unique directory
		foreach ($uniqueDirectories as $directoryUrl) {
			$result = $this->syncExternalDirectory($directoryUrl);
			$results = array_merge_recursive($results, $result);
		}

		return $results;
	}

	/**
	 * Validate an external listing.
	 *
	 * @param array $listing The listing to validate
	 * @return bool True if the listing is valid, false otherwise
	 */
	public function validateExternalListing(array $listing): bool
	{
		if (empty($listing['id']) || !Uuid::isValid($listing['id'])) {
			return false;
		}

		// TODO: Implement validation logic here
		return true;
	}

	/**
	 * Update a listing
	 *
	 * @param array $newListing The new listing
	 * @param Listing $oldListing The old listing
	 *
	 * @return array The updated listing
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
	 */
	public function updateListing(array $newListing, Listing $oldListing): array{
		$filteredOldListing = $this->getDirectoryFromListing($oldListing->jsonSerialize());

		// Let's see if these changed by checking them against the hash
		$newHash = hash('sha256', json_encode($newListing));
		$oldHash = hash('sha256', json_encode($filteredOldListing));
		if ($newHash === $oldHash) {
			return $oldListing->jsonSerialize();
		}

		// If we get here, the listing has changed
		$oldListing->hydrate($newListing);
		// Do not update version, because we copy the version from the source
		$listing = $this->objectService->saveObject('listing', $oldListing->jsonSerialize(), false);

		return $listing->jsonSerialize();
	}

	/**
	 * Synchronize with an external directory
	 *
	 * @param string $url The URL of the external directory
	 *
	 * @return array An array containing synchronization results
	 * @throws DoesNotExistException|MultipleObjectsReturnedException|ContainerExceptionInterface|NotFoundExceptionInterface
	 * @throws GuzzleException|\OCP\DB\Exception
	 */
	public function syncExternalDirectory(string $url): array
	{
		// Get the directory data
		$result = $this->client->get($url);

		// Fallback to the /api/directory endpoint if the result is not JSON
		if (str_contains($result->getHeader('Content-Type')[0], 'application/json') === false) {
			$url = rtrim($url, '/').'/apps/opencatalogi/api/directory';
			$result = $this->client->get($url);
		}


		$results = json_decode($result->getBody()->getContents(), true);
		$addedListings = [];
		$updatedListings = [];
		$invalidListings = [];
		foreach ($results['results'] as $listing) {
			// Validate the listing (Note: at this point 'uuid' has been moved to the 'id' field in each $listing)
			if ($this->validateExternalListing($listing) === false) {
				$invalidListings[] = $listing['directory'].'/'.$listing['id'];
				continue;
			}

			// Check if we already have this listing
			// TODO: This is tricky because it requires a local database call so won't work with open registers
			$oldListing = $this->objectService->getObjects(
				objectType: 'listing',
				limit: 1,
				filters: [
					['id'=>$listing['id'], 'directory'=>$listing['directory']]
				]
			);
			if ($oldListing !== null && is_array($oldListing) && !empty($oldListing)) {
				$this->updateListing($listing, $oldListing[0]);
				// @todo listing will be added to updatedList even if nothing changed...
				$updatedListings[] = $listing['directory'].'/'.$listing['id'];

				continue;
			}

			// Save the new listing
			$listingObject = $this->objectService->saveObject('listing', $listing);
			$listing = $listingObject->jsonSerialize();
			$addedListings[] = $listing['directory'].'/'.$listing['id'];
		}

		return [
			'invalidListings' => $invalidListings,
			'addedListings' => $addedListings,
			'updatedListings' => $updatedListings,
			'total' => count($addedListings) + count($updatedListings)
		];
	}

	/**
	 * @todo
	 *
	 * @param string|null $id
	 *
	 * @return array
	 * @throws
	 */
	public function synchronise(?string $id = null): array
	{
		// Fetch the listing object by its ID
		$object = $this->objectService->getObject('listing', $id);

		$url = $object['directory'];

//		$this->fetchFromExternalDirectory(url: $url, update: true);

		return $object;
	}

	/**
	 * Copy or update a publication type from an external URL
	 *
	 * @param string $url The URL of the publication type to copy or update
	 * @return array The copied or updated publication type
	 * @throws GuzzleException
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws \InvalidArgumentException If the URL is invalid
	 */
	public function syncPublicationType(string $url): array
	{
		// Validate the URL
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			throw new \InvalidArgumentException('Invalid URL provided');
		}

		// Fetch the publication type data from the external URL
		try {
			$response = $this->client->get($url);
		} catch (GuzzleException $e) {
			throw new \InvalidArgumentException('Unable to fetch data from the provided URL: ' . $e->getMessage());
		}

		$publicationType = json_decode($response->getBody()->getContents(), true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new \InvalidArgumentException('Invalid JSON data received from the URL');
		}
		
		// Set the source to the URL
		$publicationType['source'] = $url;

		// Prevent against malicious input
		unset($publicationType['id']);
		unset($publicationType['uuid']);

		// Check if a publication type with the same name already exists
		/*
		$existingPublicationType = $this->objectService->getObjects(
			objectType: 'publicationType',
			limit: 1,
			filters: [
				['source' => $url]
			]
		);
		*/

		// TODO: THis is a hacky workaround for failing filters: PRIORITY: High
		$existingPublicationTypes = $this->objectService->getObjects(
			objectType: 'publicationType',
		);
		// Filter publication types to only include those with a matching source
		$existingPublicationTypes = array_filter($existingPublicationTypes, function($publicationType) use ($source) {
			// Check if the publication type has a 'source' property and if it matches the given source
			return isset($publicationType['source']) && $publicationType['source'] === $source;
		});


		if (!empty($existingPublicationTypes)) {
			// Update existing publication types
			$updatedPublicationTypes = [];
			foreach ($existingPublicationTypes as $existingType) {
				$updatedType = $this->objectService->updateObject('publicationType', $existingType['id'], $publicationType);
				$updatedPublicationTypes[] = $updatedType->jsonSerialize();
			}
			return $updatedPublicationTypes;
		} else {
			// Save the new publication type
			$newPublicationType = $this->objectService->saveObject('publicationType', $publicationType);
			return [$newPublicationType->jsonSerialize()];
		}
	}
}

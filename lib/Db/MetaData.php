<?php

namespace OCA\OpenCatalogi\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class MetaData extends Entity implements JsonSerializable
{

	protected ?string $title 	   = null;
	protected ?string $version     = null;
	protected ?string $description = null;
	protected ?string $summary     = null;
	protected ?array  $required    = [];
	protected ?array  $properties  = [];
	protected ?array  $archive     = [];
	protected ?string $source      = null;

	public function __construct() {
		$this->addType(fieldName: 'archive', type: 'json');
		$this->addType(fieldName: 'title', type: 'string');
		$this->addType(fieldName: 'version', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'summary', type: 'string');
		$this->addType(fieldName: 'required', type: 'json');
		$this->addType(fieldName: 'properties', type: 'json');
		$this->addType(fieldName: 'source', type: 'string');

		// Set the source URL using the id if available
		$this->setSourceUrl();
	}

	// Override setId method to update the source URL when id is set
	public function setId(int $id): void {
		parent::setId($id);
		$this->setSourceUrl();
	}

	// Method to set the source URL dynamically based on the entity id and Nextcloud's domain
	public function setSourceUrl(): void {
		// Get the current URL of the Nextcloud installation
		$baseUrl = $this->getBaseUrl();

		// Set the source dynamically if the id is available
		if ($this->id !== null) {
			$this->setSource($baseUrl . '/index.php/apps/opencatalogi/api/metadata/' . $this->id);
		}
	}

	// Method to get the base URL of the Nextcloud installation
	private function getBaseUrl(): string {
		// Determine the scheme (http or https)
		$scheme = $this->isHttps() ? 'https' : 'http';
		// Get the server host and port using $_SERVER
		$host = $_SERVER['SERVER_NAME'] ?? 'localhost';
		$port = $_SERVER['SERVER_PORT'] ?? 80;

		// Construct the base URL (including port if it's not default HTTP/HTTPS ports)
		$baseUrl = $scheme . '://' . $host;
		if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
			$baseUrl .= ':' . $port;
		}

		return $baseUrl;
	}

	// Check if the current connection is HTTPS
	private function isHttps(): bool {
		return (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
	}

	public function setSource(?string $source): self {
		if ($source === null) {
			$this->setSourceUrl();
		}
		$this->source = $source;

		return $this;
	}

	public function getJsonFields(): array
	{
		return array_keys(
			array_filter($this->getFieldTypes(), function ($field) {
				return $field === 'json';
			})
		);
	}

	public function hydrate(array $object): self
	{
		$jsonFields = $this->getJsonFields();

		foreach ($object as $key => $value) {
			if (in_array($key, $jsonFields) === true && $value === []) {
				$value = null;
			}

			$method = 'set' . ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Exception $exception) {
			}
		}

		return $this;
	}

	public function jsonSerialize(): array
	{
        $properties = [];
        foreach ($this->properties as $key => $property) {
            $properties[$key] = $property;
            if (isset($property['type']) === false) {
                $properties[$key] = $property;
                continue;
            }
            switch ($property['format']) {
                case 'string':
                // For now array as string
                case 'array':
                    $properties[$key]['default'] = (string) $property;
                    break;
                case 'int':
                case 'integer':
                case 'number':
                    $properties[$key]['default'] = (int) $property;
                    break;
                case 'bool':
                    $properties[$key]['default'] = (bool) $property;
                    break;

            }
        }

		if (empty($this->source) === true) {
			$this->setSourceUrl();
		}

		$array = [
			'id'          => $this->id,
			'title'       => $this->title,
			'version'     => $this->version,
			'description' => $this->description,
			'summary'     => $this->summary,
			'required'    => $this->required,
			'properties'  => $properties,
			'archive'     => $this->archive,
			'source'	  => $this->source,
		];

		$jsonFields = $this->getJsonFields();

		foreach ($array as $key => $value) {
			if (in_array($key, $jsonFields) === true && $value === null) {
				$array[$key] = [];
			}
		}

		return $array;
	}
}

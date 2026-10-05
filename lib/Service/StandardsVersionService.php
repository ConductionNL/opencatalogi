<?php

/**
 * Service that declares the standard versions this app emits, and checks them
 * against the versions their publishers currently publish.
 *
 * The declared versions used to live as literals inside the renderers, and the
 * conformance checks validated our output against the version our own renderer
 * had chosen. A check built that way cannot report that the version it validates
 * against has been superseded: it compares our output with our own choice. This
 * service separates the two. The declared version is a constant here, and the
 * published version is read from the publisher's own version index, so being
 * behind is a distinct, reportable failure.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use OCP\Http\Client\IClientService;

/**
 * Declares the emitted standard versions and compares them with the published ones.
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */
class StandardsVersionService {

	/**
	 * The status returned when the declared version matches the published one.
	 *
	 * @var string
	 */
	public const STATUS_CURRENT = 'current';

	/**
	 * The status returned when the publisher has moved past the declared version.
	 *
	 * @var string
	 */
	public const STATUS_BEHIND = 'behind';

	/**
	 * The status returned when the published version could not be read.
	 *
	 * Never treated as a pass: a check that could not reach the authority has
	 * not checked anything.
	 *
	 * @var string
	 */
	public const STATUS_UNKNOWN = 'unknown';

	/**
	 * The DiWoo metadata version this app emits.
	 *
	 * @var string
	 */
	public const DIWOO_VERSION = '0.9.8';

	/**
	 * The DiWoo metadata XML namespace, which carries no version.
	 *
	 * @var string
	 */
	public const DIWOO_NAMESPACE = 'https://standaarden.overheid.nl/diwoo/metadata/';

	/**
	 * The DiWoo metadata XSD for DIWOO_VERSION.
	 *
	 * @var string
	 */
	public const DIWOO_METADATA_XSD = 'https://standaarden.overheid.nl/diwoo/metadata/0.9.8/xsd/diwoo/diwoo-metadata.xsd';

	/**
	 * The DiWoo value-list XSD for DIWOO_VERSION.
	 *
	 * DIWOO_METADATA_XSD includes this file under the same target namespace, so a
	 * validator that follows the schema location picks it up on its own. It is
	 * declared here because an offline validation run needs both paths.
	 *
	 * @var string
	 */
	public const DIWOO_LISTS_XSD = 'https://standaarden.overheid.nl/diwoo/metadata/0.9.8/xsd/diwoo/diwoo-metadata-lijsten.xsd';

	/**
	 * The publisher's own DiWoo metadata version index.
	 *
	 * @var string
	 */
	public const DIWOO_VERSION_INDEX = 'https://standaarden.overheid.nl/diwoo/metadata';

	/**
	 * The sitemaps namespace the DiWoo document shares its root with.
	 *
	 * @var string
	 */
	public const SITEMAP_NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

	/**
	 * The sitemaps XSD.
	 *
	 * @var string
	 */
	public const SITEMAP_XSD = 'http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd';

	/**
	 * The DCAT-AP-NL profile version this app targets.
	 *
	 * @var string
	 */
	public const DCAT_AP_NL_VERSION = '3.0';

	/**
	 * The profile IRI the DCAT catalog declares conformance to.
	 *
	 * @var string
	 */
	public const DCAT_AP_NL_PROFILE = 'https://docs.geostandaarden.nl/dcat/dcat-ap-nl30/';

	/**
	 * The publisher's own DCAT-AP-NL release index.
	 *
	 * @var string
	 */
	public const DCAT_AP_NL_VERSION_INDEX = 'https://docs.geostandaarden.nl/dcat/';

	/**
	 * Per-request outbound timeout in seconds, matching the readiness self-check.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 10;

	/**
	 * Memoised fetch bodies, keyed by URL, so one request never fetches an index twice.
	 *
	 * @var array<string, string|null>
	 */
	private array $fetched = [];

	/**
	 * Constructor for StandardsVersionService.
	 *
	 * @param IClientService $clientService The Nextcloud HTTP client factory.
	 * @param DirectoryService $directoryService The directory service, reused for its SSRF outbound-URL guard.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly DirectoryService $directoryService,
	) {

	}//end __construct()

	/**
	 * The `xsi:schemaLocation` value for a DiWoo documents response.
	 *
	 * @return string The namespace/location pairs, space separated.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public static function diwooSchemaLocation(): string {
		return implode(
			' ',
			[
				self::SITEMAP_NAMESPACE,
				self::SITEMAP_XSD,
				self::DIWOO_NAMESPACE,
				self::DIWOO_METADATA_XSD,
			]
		);

	}//end diwooSchemaLocation()

	/**
	 * Read the DiWoo version the publisher currently calls current.
	 *
	 * Anchored on the publisher's own sentence ("De huidige versie van standaard
	 * en publicatievoorwaarden is versie v0.9.8"), not on the highest version
	 * number on the page: the index also links every superseded release.
	 *
	 * @param string $html The fetched version index.
	 *
	 * @return string|null The published version, or null when the page did not state one.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public static function parseDiwooVersion(string $html): ?string {
		$anchor = mb_strpos($html, 'De huidige versie');
		if ($anchor === false) {
			return null;
		}

		$tail = mb_substr($html, $anchor, 600);
		$matched = preg_match('/\bv(\d+\.\d+(?:\.\d+)?)\b/', $tail, $matches);
		if ($matched !== 1) {
			return null;
		}

		return $matches[1];

	}//end parseDiwooVersion()

	/**
	 * Read the newest adopted DCAT-AP-NL version from the publisher's release index.
	 *
	 * The index lists one directory per release. Only `def-st-` entries are adopted
	 * standards; `vv-st-` and `cv-st-` are consultation rounds and must not count.
	 * A directory is named `def-st-dcat-ap-nl<digits>-<yyyymmdd>`, where the first
	 * digit is the major version and the rest is the minor.
	 *
	 * @param string $html The fetched release index.
	 *
	 * @return string|null The newest adopted version, or null when the index listed none.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public static function parseDcatApNlVersion(string $html): ?string {
		$matched = preg_match_all('/def-st-dcat-ap-nl(\d{2,3})-(\d{8})/', $html, $matches, PREG_SET_ORDER);
		if ($matched === false || $matched === 0) {
			return null;
		}

		$newestDate = '';
		$version = null;
		foreach ($matches as $match) {
			if ($match[2] <= $newestDate) {
				continue;
			}

			$newestDate = $match[2];
			$version = mb_substr($match[1], 0, 1) . '.' . mb_substr($match[1], 1);
		}

		return $version;

	}//end parseDcatApNlVersion()

	/**
	 * Compare the declared DiWoo version with the published one.
	 *
	 * @return array{declared: string, published: string|null, status: string} The comparison.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function diwooVersion(): array {
		return $this->compare(
			declared: self::DIWOO_VERSION,
			published: $this->publishedDiwooVersion()
		);

	}//end diwooVersion()

	/**
	 * Compare the targeted DCAT-AP-NL profile version with the published one.
	 *
	 * @return array{declared: string, published: string|null, status: string, profile: string} The comparison.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public function dcatApNlVersion(): array {
		$comparison = $this->compare(
			declared: self::DCAT_AP_NL_VERSION,
			published: $this->publishedDcatApNlVersion()
		);
		$comparison['profile'] = self::DCAT_AP_NL_PROFILE;

		return $comparison;

	}//end dcatApNlVersion()

	/**
	 * The DiWoo version the publisher currently calls current.
	 *
	 * @return string|null The published version, or null when it could not be read.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function publishedDiwooVersion(): ?string {
		$body = $this->fetch(url: self::DIWOO_VERSION_INDEX);
		if ($body === null) {
			return null;
		}

		return self::parseDiwooVersion(html: $body);

	}//end publishedDiwooVersion()

	/**
	 * The newest adopted DCAT-AP-NL version the publisher lists.
	 *
	 * @return string|null The published version, or null when it could not be read.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public function publishedDcatApNlVersion(): ?string {
		$body = $this->fetch(url: self::DCAT_AP_NL_VERSION_INDEX);
		if ($body === null) {
			return null;
		}

		return self::parseDcatApNlVersion(html: $body);

	}//end publishedDcatApNlVersion()

	/**
	 * Build the comparison result for one standard.
	 *
	 * An unreadable published version yields `unknown`, never `current`: the point
	 * of this service is that a check which could not reach the authority must say
	 * so instead of reporting a pass.
	 *
	 * @param string $declared The version this app declares.
	 * @param string|null $published The version the publisher publishes, or null.
	 *
	 * @return array{declared: string, published: string|null, status: string} The comparison.
	 *
	 * @spec exclude Pure comparison over two already-resolved version strings.
	 */
	private function compare(string $declared, ?string $published): array {
		$status = self::STATUS_UNKNOWN;
		if ($published !== null) {
			$status = self::STATUS_CURRENT;
			if (version_compare($published, $declared, '>') === true) {
				$status = self::STATUS_BEHIND;
			}
		}

		return [
			'declared' => $declared,
			'published' => $published,
			'status' => $status,
		];

	}//end compare()

	/**
	 * Fetch a version index through the SSRF guard, memoised per request.
	 *
	 * Reuses {@see DirectoryService::validateOutboundUrl()} rather than guarding
	 * outbound requests a second way, and caches the body so a request that checks
	 * both standards still performs at most one fetch per index.
	 *
	 * @param string $url The version index to fetch.
	 *
	 * @return string|null The response body, or null on any failure.
	 *
	 * @spec exclude Outbound-fetch plumbing; the SSRF guard it delegates to carries the behavior.
	 */
	private function fetch(string $url): ?string {
		if (array_key_exists($url, $this->fetched) === true) {
			return $this->fetched[$url];
		}

		$this->fetched[$url] = null;

		try {
			$this->directoryService->validateOutboundUrl($url);
		} catch (\InvalidArgumentException $e) {
			return null;
		}

		try {
			$response = $this->clientService->newClient()->get(
				$url,
				[
					'timeout' => self::TIMEOUT_SECONDS,
					'http_errors' => false,
					'allow_redirects' => false,
				]
			);
		} catch (\Throwable $e) {
			return null;
		}

		if ($response->getStatusCode() !== 200) {
			return null;
		}

		$body = $response->getBody();
		if (is_resource($body) === true) {
			$body = stream_get_contents($body);
		}

		$this->fetched[$url] = (string)$body;

		return $this->fetched[$url];

	}//end fetch()
}//end class

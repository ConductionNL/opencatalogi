<?php

/**
 * OpenCatalogi Document Reference URL Policy.
 *
 * Decides whether a document reference may point at a URL: only https, and
 * never a host that is or resolves to a loopback, private, link-local or other
 * internal address. The same address ranges as the federation guard, without
 * its development allowance: a reference is meant for the public.
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
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-an-editor-adds-a-document-by-its-public-url-req-pbr-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\Federation\OutboundUrlGuard;

/**
 * The reason a reference URL is refused, or none.
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-an-editor-adds-a-document-by-its-public-url-req-pbr-001
 */
class DocumentReferenceUrlPolicy {

	/**
	 * The refusal for a URL that is not https.
	 */
	public const NOT_HTTPS = 'A document reference needs a public https address.';

	/**
	 * The refusal for an address that is not public.
	 */
	public const NOT_PUBLIC = 'The address of the document is not public, so readers cannot open it.';

	/**
	 * Constructor.
	 *
	 * @param OutboundUrlGuard $guard Classifies hosts and addresses.
	 */
	public function __construct(
		private readonly OutboundUrlGuard $guard = new OutboundUrlGuard(),
	) {
	}//end __construct()

	/**
	 * Why this URL may not be referenced, or null when it may.
	 *
	 * @param string $url The URL.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-an-editor-adds-a-document-by-its-public-url-req-pbr-001
	 */
	public function refusal(string $url): ?string {
		if (str_starts_with(strtolower(trim($url)), 'https://') === false) {
			return self::NOT_HTTPS;
		}

		try {
			$host = $this->guard->hostOf(url: trim($url), label: 'Document');
		} catch (InvalidArgumentException $e) {
			return self::NOT_HTTPS;
		}

		if ($this->isLocalName(host: $host) === true) {
			return self::NOT_PUBLIC;
		}

		try {
			$addresses = $this->guard->addressesOf(host: $host, label: 'Document');
		} catch (InvalidArgumentException $e) {
			// A name that does not resolve now is not shown to be private; the
			// daily check records it as unreachable.
			return null;
		}

		foreach ($addresses as $address) {
			if ($this->guard->isNeverAllowed(ipAddress: $address) === true || $this->guard->isInternal(ipAddress: $address) === true) {
				return self::NOT_PUBLIC;
			}
		}

		return null;
	}//end refusal()

	/**
	 * Whether a host name only ever means this machine or its local network.
	 *
	 * @param string $host The lower-case host.
	 *
	 * @return bool True for localhost and the .local and .localhost names.
	 */
	private function isLocalName(string $host): bool {
		return $host === 'localhost' || str_ends_with($host, '.local') === true || str_ends_with($host, '.localhost') === true;
	}//end isLocalName()
}//end class

<?php

/**
 * One place that decides whether a federation URL is routable on the open internet.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Federation
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Federation;

use OCA\OpenCatalogi\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Whether a federation URL is reachable by a peer on the open internet.
 *
 * WHY THIS IS ITS OWN CLASS. The same question — "is this host local?" — is asked
 * on two paths that must never disagree: DirectoryService suppresses its automatic
 * broadcast when our own URL is local, and BroadcastService refuses to advertise
 * one. A validator and an executor each owning a private copy of the same grammar
 * drift silently, and the drift is invisible until an instance advertises an
 * address no peer can resolve. `isAllowlistedFederationHost()` was already
 * duplicated byte-for-byte between the two services before this class existed.
 *
 * @psalm-suppress UnusedClass Resolved from the DI container by both services.
 */
class FederationHostPolicy {
	/**
	 * Hosts that are never reachable from another instance.
	 *
	 * @var array<string>
	 */
	private const LOCAL_HOSTS = [
		'localhost',
		'127.0.0.1',
		'::1',
		'0.0.0.0',
	];

	/**
	 * The app-config key holding the dev-only federation allowlist.
	 */
	private const CONFIG_ALLOWLIST = 'local_federation_hosts';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App configuration, for the local-federation allowlist.
	 */
	public function __construct(
		private readonly IAppConfig $config,
	) {
	}//end __construct()


	/**
	 * Whether a host is explicitly allowlisted for local federation.
	 *
	 * Dev-only escape hatch: a comma-separated `local_federation_hosts` lets two
	 * instances on a private docker network federate with each other. Empty by
	 * default, so production keeps the full protection.
	 *
	 * @param string $host The lower-cased hostname, without port.
	 *
	 * @return boolean True when the host is allowlisted.
	 */
	public function isAllowlistedFederationHost(string $host): bool {
		$allowlist = $this->config->getValueString(Application::APP_ID, self::CONFIG_ALLOWLIST, '');
		if ($allowlist === '') {
			return false;
		}

		$allowedHosts = array_filter(array_map('trim', explode(',', strtolower($allowlist))));

		return in_array($host, $allowedHosts, true);
	}//end isAllowlistedFederationHost()


	/**
	 * Whether a URL names an address no other instance could reach.
	 *
	 * An unparseable URL counts as local: a value we cannot reason about must not
	 * be handed to a peer as our address.
	 *
	 * @param string $url The URL to classify.
	 *
	 * @return boolean True when the URL is local, private, or unparseable.
	 */
	public function isLocalUrl(string $url): bool {
		$parsedUrl = parse_url($url);
		if ($parsedUrl === false || isset($parsedUrl['host']) === false) {
			return true;
		}

		$host = strtolower($parsedUrl['host']);

		// Allowlisted hosts are treated as remote so a docker-network rig federates.
		if ($this->isAllowlistedFederationHost(host: $host) === true) {
			return false;
		}

		if (in_array($host, self::LOCAL_HOSTS, true) === true) {
			return true;
		}

		// Private, loopback and reserved IP ranges.
		if (filter_var(value: $host, filter: FILTER_VALIDATE_IP) !== false) {
			$isPublicIp = filter_var(
				value: $host,
				filter: FILTER_VALIDATE_IP,
				options: (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
			);
			if ($isPublicIp === false) {
				return true;
			}
		}

		if (str_ends_with($host, '.local') === true) {
			return true;
		}

		return false;
	}//end isLocalUrl()
}//end class

<?php

/**
 * One place that decides whether an outbound URL points at an internal address.
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

use InvalidArgumentException;

/**
 * Parses an outbound URL, resolves its host and classifies the addresses.
 *
 * Every address is normalised before it is classified: an IPv4 address embedded
 * in IPv6 (mapped `::ffff:a.b.c.d`, compatible `::a.b.c.d`, NAT64 `64:ff9b::/96`
 * and 6to4 `2002::/16`) is checked as the IPv4 address it reaches.
 *
 * @spec exclude Outbound address guard shared by the directory and broadcast services; plumbing.
 */
class OutboundUrlGuard {
	/**
	 * Ranges that are never a federation peer: not routable on the open internet.
	 *
	 * The documentation ranges (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24,
	 * 2001:db8::/32) are not listed: nothing internal lives there.
	 *
	 * @var array<string>
	 */
	private const INTERNAL_RANGES = [
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'::/128',
		'::1/128',
		'64:ff9b:1::/48',
		'100::/64',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'ff00::/8',
	];

	/**
	 * Ranges no configuration may open: the unspecified network, the cloud-metadata
	 * endpoint and IPv6 unique-local and link-local space.
	 *
	 * @var array<string>
	 */
	private const NEVER_ALLOWED_RANGES = [
		'0.0.0.0/8',
		'169.254.169.254/32',
		'fc00::/7',
		'fe80::/10',
	];

	/**
	 * Hosts that are refused by name before any lookup.
	 *
	 * @var array<string>
	 */
	private const LOCAL_SUFFIXES = [
		'.local',
		'.localhost',
	];


	/**
	 * Parse a URL and return its lower-cased host.
	 *
	 * @param string $url   The URL to parse.
	 * @param string $label The capitalised URL kind used in messages, e.g. `Directory`.
	 *
	 * @return string The lower-cased host; an IPv6 literal keeps its brackets.
	 *
	 * @throws InvalidArgumentException When the URL has no host or is not http(s).
	 *
	 * @spec exclude Outbound address guard plumbing shared by the directory and broadcast services.
	 */
	public function hostOf(string $url, string $label): string {
		$parsed = parse_url(url: $url);
		if ($parsed === false || isset($parsed['scheme']) === false || isset($parsed['host']) === false) {
			throw new InvalidArgumentException('Invalid ' . strtolower(string: $label) . ' URL provided');
		}

		if (in_array(needle: strtolower(string: $parsed['scheme']), haystack: ['http', 'https'], strict: true) === false) {
			throw new InvalidArgumentException($label . ' URL scheme must be http or https');
		}

		return strtolower(string: $parsed['host']);
	}//end hostOf()


	/**
	 * Resolve a host to the normalised addresses it reaches.
	 *
	 * @param string $host  The lower-cased host from {@see hostOf()}.
	 * @param string $label The capitalised URL kind used in messages, e.g. `Directory`.
	 *
	 * @return array<string> The literal address, or every A/AAAA answer, normalised.
	 *
	 * @throws InvalidArgumentException When the host is a local name or does not resolve.
	 *
	 * @spec exclude Outbound address guard plumbing shared by the directory and broadcast services.
	 */
	public function addressesOf(string $host, string $label): array {
		if ($host === 'localhost' || $this->hasLocalSuffix(host: $host) === true) {
			throw new InvalidArgumentException($label . ' URL host is not allowed');
		}

		$lookupHost = trim(string: $host, characters: '[]');
		$addresses  = [$lookupHost];
		if (filter_var(value: $lookupHost, filter: FILTER_VALIDATE_IP) === false) {
			$addresses = $this->resolve(host: $lookupHost);
		}

		if (empty($addresses) === true) {
			throw new InvalidArgumentException($label . ' URL host could not be resolved');
		}

		return array_map(
			callback: fn (string $address): string => $this->normalise(ipAddress: $address),
			array: $addresses
		);
	}//end addressesOf()


	/**
	 * Whether an address is not routable on the open internet.
	 *
	 * @param string $ipAddress The address to classify.
	 *
	 * @return boolean True for private, loopback, link-local, reserved and unparseable addresses.
	 *
	 * @spec exclude Outbound address guard plumbing shared by the directory and broadcast services.
	 */
	public function isInternal(string $ipAddress): bool {
		$address = $this->normalise(ipAddress: $ipAddress);
		$isPublic = filter_var(
			value: $address,
			filter: FILTER_VALIDATE_IP,
			options: (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
		);
		if ($isPublic === false) {
			return true;
		}

		return $this->inAnyRange(ipAddress: $address, ranges: self::INTERNAL_RANGES);
	}//end isInternal()


	/**
	 * Whether an address must stay refused whatever the configuration says.
	 *
	 * @param string $ipAddress The address to classify.
	 *
	 * @return boolean True for the never-allowed ranges and unparseable addresses.
	 *
	 * @spec exclude Outbound address guard plumbing shared by the directory and broadcast services.
	 */
	public function isNeverAllowed(string $ipAddress): bool {
		$address = $this->normalise(ipAddress: $ipAddress);
		if (filter_var(value: $address, filter: FILTER_VALIDATE_IP) === false) {
			return true;
		}

		return $this->inAnyRange(ipAddress: $address, ranges: self::NEVER_ALLOWED_RANGES);
	}//end isNeverAllowed()


	/**
	 * Rewrite an IPv6 address that embeds an IPv4 address to that IPv4 address.
	 *
	 * Covers IPv4-mapped (`::ffff:0:0/96`), IPv4-compatible (`::/96`), NAT64
	 * (`64:ff9b::/96`) and 6to4 (`2002::/16`). Other addresses come back in their
	 * canonical form; an unparseable value comes back unchanged.
	 *
	 * @param string $ipAddress The address to normalise.
	 *
	 * @return string The address to classify.
	 *
	 * @spec exclude Outbound address guard plumbing shared by the directory and broadcast services.
	 */
	public function normalise(string $ipAddress): string {
		if (filter_var(value: $ipAddress, filter: FILTER_VALIDATE_IP, options: FILTER_FLAG_IPV6) === false) {
			return $ipAddress;
		}

		$packed = (string) inet_pton(ip: $ipAddress);
		$prefix = substr(string: $packed, offset: 0, length: 12);
		if ($prefix === str_repeat(string: "\x00", times: 10) . "\xff\xff"
			|| $prefix === str_repeat(string: "\x00", times: 12)
			|| $prefix === "\x00\x64\xff\x9b" . str_repeat(string: "\x00", times: 8)
		) {
			return (string) inet_ntop(ip: substr(string: $packed, offset: 12));
		}

		if (str_starts_with(haystack: $packed, needle: "\x20\x02") === true) {
			return (string) inet_ntop(ip: substr(string: $packed, offset: 2, length: 4));
		}

		return (string) inet_ntop(ip: $packed);
	}//end normalise()


	/**
	 * Whether a host ends in a local-only suffix.
	 *
	 * @param string $host The lower-cased host.
	 *
	 * @return boolean True for `.local` and `.localhost` names.
	 */
	private function hasLocalSuffix(string $host): bool {
		foreach (self::LOCAL_SUFFIXES as $suffix) {
			if (str_ends_with(haystack: $host, needle: $suffix) === true) {
				return true;
			}
		}

		return false;
	}//end hasLocalSuffix()


	/**
	 * Resolve a host name to its A and AAAA addresses.
	 *
	 * @param string $host The host name, without brackets.
	 *
	 * @return array<string> Every address found; empty when the name does not resolve.
	 */
	private function resolve(string $host): array {
		$addresses = [];
		$records   = dns_get_record(hostname: $host, type: (DNS_A | DNS_AAAA));
		if ($records === false) {
			$records = [];
		}

		foreach ($records as $record) {
			if (isset($record['ip']) === true) {
				$addresses[] = $record['ip'];
			}

			if (isset($record['ipv6']) === true) {
				$addresses[] = $record['ipv6'];
			}
		}

		if (empty($addresses) === true) {
			$resolved = gethostbyname(hostname: $host);
			if ($resolved !== $host && filter_var(value: $resolved, filter: FILTER_VALIDATE_IP) !== false) {
				$addresses[] = $resolved;
			}
		}

		return $addresses;
	}//end resolve()


	/**
	 * Whether an address falls in any of the given CIDR ranges.
	 *
	 * @param string        $ipAddress A normalised, valid address.
	 * @param array<string> $ranges    CIDR ranges of either family.
	 *
	 * @return boolean True when one range contains the address.
	 */
	private function inAnyRange(string $ipAddress, array $ranges): bool {
		$packed = (string) inet_pton(ip: $ipAddress);
		foreach ($ranges as $range) {
			[$subnet, $bits] = explode(separator: '/', string: $range);
			$subnetPacked    = (string) inet_pton(ip: $subnet);
			if (strlen(string: $subnetPacked) !== strlen(string: $packed)) {
				continue;
			}

			$mask = str_pad(
				string: str_repeat(string: "\xff", times: intdiv(num1: (int) $bits, num2: 8)),
				length: strlen(string: $packed),
				pad_string: "\x00"
			);
			if (((int) $bits % 8) !== 0) {
				$mask[intdiv(num1: (int) $bits, num2: 8)] = chr(codepoint: 0xff & (0xff << (8 - ((int) $bits % 8))));
			}

			if (($packed & $mask) === ($subnetPacked & $mask)) {
				return true;
			}
		}//end foreach

		return false;
	}//end inAnyRange()
}//end class

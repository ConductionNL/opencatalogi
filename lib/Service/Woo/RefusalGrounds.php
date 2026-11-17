<?php

/**
 * OpenCatalogi Refusal Grounds.
 *
 * The Woo refusal grounds are dossiq's (decision D3). With dossiq installed
 * this answers `OCA\Dossiq\Woo\WooRefusalGrounds::list()` unchanged, and a
 * failure there propagates: dossiq is the authority when it is present, so the
 * snapshot is never used then. Without dossiq it answers the entries of the
 * vendored copy of dossiq's release snapshot, marked `source: snapshot`, for
 * one use only: citing a ground on a redacted passage of a publication batch
 * (decision D12). A Woo request path asks through {@see self::forRequestPath()},
 * which refuses the snapshot.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Reads the Woo refusal grounds from dossiq, or from the vendored snapshot.
 *
 * @spec openspec/specs/woo-transparency/spec.md#requirement-weigeringsgronden-refusal-grounds
 */
class RefusalGrounds {

	/**
	 * The dossiq class that owns the grounds (dossiq REQ-WRG-007).
	 */
	public const DOSSIQ_CLASS = 'OCA\\Dossiq\\Woo\\WooRefusalGrounds';

	/**
	 * Where the source of an entry is recorded.
	 */
	public const SOURCE_DOSSIQ = 'dossiq';

	/**
	 * The source of an entry read from the vendored snapshot.
	 */
	public const SOURCE_SNAPSHOT = 'snapshot';

	/**
	 * The vendored copy of dossiq's snapshot (dossiq REQ-WRG-008).
	 */
	public const SNAPSHOT_PATH = __DIR__ . '/../../Settings/woo-refusal-grounds.snapshot.json';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface|null $container    Resolves dossiq's list at call time.
	 * @param IAppManager|null        $appManager   Tells whether dossiq is enabled.
	 * @param string                  $snapshotPath The vendored snapshot to read without dossiq.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?ContainerInterface $container = null,
		private readonly ?IAppManager $appManager = null,
		private readonly string $snapshotPath = self::SNAPSHOT_PATH,
	) {
	}//end __construct()

	/**
	 * Whether dossiq is enabled, so its list is the list.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
	 */
	public function fromDossiq(): bool {
		if ($this->appManager === null || $this->container === null) {
			return false;
		}

		return $this->appManager->isEnabledForAnyone('dossiq') === true;
	}//end fromDossiq()

	/**
	 * The active grounds, in dossiq's shape, each with its `source`.
	 *
	 * @return array<int, array<string, mixed>> The grounds.
	 *
	 * @throws RefusalGroundsUnavailableException When dossiq's list throws, or the snapshot is unreadable.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
	 */
	public function list(): array {
		if ($this->fromDossiq() === true) {
			return $this->dossiqList();
		}

		return $this->snapshotList();
	}//end list()

	/**
	 * The grounds an officer can cite on an assessment: active and citable.
	 *
	 * The list carries the article headings (5.1, 5.1.1, 5.2) as
	 * non-citable parents; a ground is cited by its leaf code.
	 *
	 * @return array<int, array<string, mixed>> The citable grounds.
	 *
	 * @throws RefusalGroundsUnavailableException When the grounds cannot be read.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
	 */
	public function citable(): array {
		return array_values(
			array_filter(
				$this->list(),
				static fn (array $ground): bool => ($ground['status'] ?? '') === 'active'
					&& (($ground['citable'] ?? true) !== false)
			)
		);
	}//end citable()

	/**
	 * The codes an assessment may cite.
	 *
	 * @return array<int, string> The codes.
	 *
	 * @throws RefusalGroundsUnavailableException When the grounds cannot be read.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
	 */
	public function citableCodes(): array {
		return array_map(static fn (array $ground): string => (string)$ground['code'], $this->citable());
	}//end citableCodes()

	/**
	 * The grounds for a Woo request path, which has no fallback (decision D12).
	 *
	 * @return array<int, array<string, mixed>> dossiq's citable grounds.
	 *
	 * @throws RefusalGroundsUnavailableException When dossiq is not installed or its list throws.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
	 */
	public function forRequestPath(): array {
		if ($this->fromDossiq() === false) {
			throw new RefusalGroundsUnavailableException(
				'The refusal grounds for a Woo request come from dossiq, which is not installed.'
			);
		}

		return $this->citable();
	}//end forRequestPath()

	/**
	 * The version of the vendored snapshot.
	 *
	 * @return string The version, or empty when the snapshot carries none.
	 *
	 * @throws RefusalGroundsUnavailableException When the snapshot is unreadable.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-refusal-grounds-come-from-dossiq-with-a-read-only-snapshot-for-redaction-only-req-wvc-003
	 */
	public function snapshotVersion(): string {
		return (string)($this->readSnapshot()['version'] ?? '');
	}//end snapshotVersion()

	/**
	 * dossiq's active grounds, each marked with its source.
	 *
	 * @return array<int, array<string, mixed>> The grounds.
	 *
	 * @throws RefusalGroundsUnavailableException When dossiq's list throws.
	 */
	private function dossiqList(): array {
		try {
			$grounds = $this->container->get(self::DOSSIQ_CLASS)->list();
		} catch (Throwable $e) {
			// Dossiq is the authority when present: its failure is the answer,
			// and the snapshot is not used in its place.
			throw new RefusalGroundsUnavailableException($e->getMessage(), 0, $e);
		}

		if (is_array($grounds) === false) {
			throw new RefusalGroundsUnavailableException('dossiq answered no list of refusal grounds.');
		}

		return array_map(
			static fn (array $ground): array => array_merge($ground, ['source' => self::SOURCE_DOSSIQ]),
			array_values(array_filter($grounds, 'is_array'))
		);
	}//end dossiqList()

	/**
	 * The snapshot's active grounds, each marked `source: snapshot`.
	 *
	 * @return array<int, array<string, mixed>> The grounds.
	 *
	 * @throws RefusalGroundsUnavailableException When the snapshot is unreadable.
	 */
	private function snapshotList(): array {
		$grounds = $this->readSnapshot()['grounds'] ?? null;
		if (is_array($grounds) === false) {
			throw new RefusalGroundsUnavailableException('The vendored refusal grounds snapshot holds no grounds.');
		}

		$active = array_filter(
			$grounds,
			static fn ($ground): bool => is_array($ground) === true
				&& trim((string)($ground['code'] ?? '')) !== ''
				&& ($ground['status'] ?? '') === 'active'
		);

		return array_map(
			static fn (array $ground): array => array_merge($ground, ['source' => self::SOURCE_SNAPSHOT]),
			array_values($active)
		);
	}//end snapshotList()

	/**
	 * The decoded snapshot file.
	 *
	 * @return array<string, mixed> The snapshot.
	 *
	 * @throws RefusalGroundsUnavailableException When it is missing or not JSON.
	 */
	private function readSnapshot(): array {
		$raw = false;
		if (is_readable($this->snapshotPath) === true) {
			$raw = file_get_contents($this->snapshotPath);
		}

		$decoded = null;
		if ($raw !== false) {
			$decoded = json_decode($raw, true);
		}

		if (is_array($decoded) === false) {
			throw new RefusalGroundsUnavailableException('The vendored refusal grounds snapshot cannot be read.');
		}

		return $decoded;
	}//end readSnapshot()
}//end class

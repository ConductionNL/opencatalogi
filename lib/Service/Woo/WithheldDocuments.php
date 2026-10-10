<?php

/**
 * Records which documents of a Woo decision were withheld, and on which grounds.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The one door dossiq records a decision's withheld documents through.
 *
 * Replace semantics: a call states the whole list for a publication, so the
 * stored entries are removed and the accepted ones written. A call with no
 * entries clears the list (a withdrawal). Each entry is exactly
 * `{position, grounds}`; every other key, a title first of all, is dropped
 * before anything is stored, so this path cannot leak what was withheld.
 *
 * The grounds are looked up in dossiq's own list (`WooRefusalGrounds::byCode()`)
 * and stored as dossiq answered, so the public read never guesses a label.
 * When that list cannot be reached, nothing is stored or removed and the
 * vendored snapshot is NOT read: a label from a stale copy is worse than none.
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
 */
class WithheldDocuments {

	/**
	 * dossiq's class that owns the refusal grounds list.
	 *
	 * @var string
	 */
	public const GROUNDS_CLASS = 'OCA\Dossiq\Woo\WooRefusalGrounds';

	/**
	 * The exception dossiq throws when its list cannot be read.
	 *
	 * @var string
	 */
	public const GROUNDS_UNAVAILABLE_CLASS = 'OCA\Dossiq\Woo\WooRefusalGroundsUnavailable';

	/**
	 * The keys an entry is reduced to before it is read.
	 *
	 * @var list<string>
	 */
	public const ENTRY_KEYS = ['position', 'grounds'];

	/**
	 * The keys stored per ground, read from dossiq's `byCode()` answer.
	 *
	 * @var list<string>
	 */
	public const GROUND_KEYS = ['code', 'article', 'label'];

	/**
	 * The keys of the answer.
	 *
	 * @var list<string>
	 */
	public const ANSWER_KEYS = ['recorded', 'refused'];

	/**
	 * The keys of one refused item.
	 *
	 * @var list<string>
	 */
	public const REFUSED_KEYS = ['position', 'code', 'reason'];

	public const REASON_UNKNOWN_GROUND = 'unknown-ground';

	public const REASON_GROUNDS_UNAVAILABLE = 'grounds-unavailable';

	public const REASON_NO_PUBLICATION = 'no-publication';

	public const REASON_INVALID_ENTRY = 'invalid-entry';

	/**
	 * Constructor.
	 *
	 * @param WithheldDocumentStore $store      Reads and writes the withheldDocument objects.
	 * @param ContainerInterface    $container  Resolves dossiq's grounds list.
	 * @param IAppManager           $appManager Says whether dossiq is there.
	 */
	public function __construct(
		private readonly WithheldDocumentStore $store,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Replace the withheld documents recorded for a publication.
	 *
	 * @param string                           $publicationId The publication of the Woo decision.
	 * @param array<int, array<string, mixed>> $entries       One `{position, grounds}` per withheld document.
	 * @param string                           $source        The recording app, such as `dossiq`.
	 *
	 * @return array{recorded: int, refused: list<array{position: int, code: string, reason: string}>}
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	public function record(string $publicationId, array $entries, string $source): array {
		$entries = array_map(fn (mixed $entry): array => $this->reduce(entry: $entry), array_values($entries));

		if ($this->store->publicationExists(publicationId: $publicationId) === false) {
			return $this->refuseAll(entries: $entries, reason: self::REASON_NO_PUBLICATION);
		}

		$grounds = $this->grounds();
		if ($grounds === null && $entries !== []) {
			return $this->refuseAll(entries: $entries, reason: self::REASON_GROUNDS_UNAVAILABLE);
		}

		$accepted = [];
		$refused = [];
		try {
			foreach ($entries as $entry) {
				$resolved = $this->resolve(grounds: $grounds, entry: $entry);
				if (isset($resolved['refused']) === true) {
					$refused[] = $resolved['refused'];
					continue;
				}

				$accepted[] = $resolved['entry'];
			}
		} catch (Throwable $e) {
			if ($this->isUnavailable(error: $e) === false) {
				throw $e;
			}

			return $this->refuseAll(entries: $entries, reason: self::REASON_GROUNDS_UNAVAILABLE);
		}

		$this->store->replace(publicationId: $publicationId, accepted: $accepted, source: $source);

		return ['recorded' => count($accepted), 'refused' => $refused];
	}//end record()

	/**
	 * Reduce an entry to `{position, grounds}`; a title or anything else is dropped here.
	 *
	 * @param mixed $entry The raw entry.
	 *
	 * @return array{position: int, grounds: list<string>}
	 */
	private function reduce(mixed $entry): array {
		if (is_array($entry) === false) {
			return ['position' => 0, 'grounds' => []];
		}

		$grounds = [];
		foreach ((array)($entry['grounds'] ?? []) as $code) {
			if (is_string($code) === true && trim($code) !== '') {
				$grounds[] = trim($code);
			}
		}

		$position = 0;
		if (is_numeric($entry['position'] ?? null) === true) {
			$position = (int)$entry['position'];
		}

		return ['position' => $position, 'grounds' => $grounds];
	}//end reduce()

	/**
	 * Resolve one entry's codes through dossiq's list.
	 *
	 * @param object|null                                 $grounds dossiq's WooRefusalGrounds.
	 * @param array{position: int, grounds: list<string>} $entry   The reduced entry.
	 *
	 * @return array<string, mixed> `['entry' => ...]` or `['refused' => ...]`.
	 */
	private function resolve(?object $grounds, array $entry): array {
		if ($entry['position'] < 1 || $entry['grounds'] === [] || $grounds === null) {
			return ['refused' => ['position' => $entry['position'], 'code' => (string)($entry['grounds'][0] ?? ''), 'reason' => self::REASON_INVALID_ENTRY]];
		}

		$stored = [];
		foreach ($entry['grounds'] as $code) {
			$ground = $grounds->byCode($code);
			if (is_array($ground) === false) {
				return ['refused' => ['position' => $entry['position'], 'code' => $code, 'reason' => self::REASON_UNKNOWN_GROUND]];
			}

			$stored[] = [
				'code' => (string)($ground['code'] ?? $code),
				'article' => (string)($ground['article'] ?? ''),
				'label' => (string)($ground['label'] ?? ''),
			];
		}

		return ['entry' => ['position' => $entry['position'], 'grounds' => $stored]];
	}//end resolve()

	/**
	 * dossiq's grounds list, or null when dossiq or the class is not there.
	 *
	 * @return object|null
	 */
	private function grounds(): ?object {
		if ($this->appManager->isInstalled('dossiq') === false || class_exists(self::GROUNDS_CLASS) === false) {
			return null;
		}

		try {
			$grounds = $this->container->get(self::GROUNDS_CLASS);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($grounds) === false || method_exists($grounds, 'byCode') === false) {
			return null;
		}

		return $grounds;
	}//end grounds()

	/**
	 * Whether an error is dossiq saying its list cannot be read.
	 *
	 * @param Throwable $error The error.
	 *
	 * @return bool
	 */
	private function isUnavailable(Throwable $error): bool {
		$class = self::GROUNDS_UNAVAILABLE_CLASS;

		return class_exists($class) === true && $error instanceof $class;
	}//end isUnavailable()

	/**
	 * Refuse every entry with one reason.
	 *
	 * @param list<array{position: int, grounds: list<string>}> $entries The reduced entries.
	 * @param string                                            $reason  The reason.
	 *
	 * @return array{recorded: int, refused: list<array{position: int, code: string, reason: string}>}
	 */
	private function refuseAll(array $entries, string $reason): array {
		$refused = [];
		foreach ($entries as $entry) {
			$refused[] = ['position' => $entry['position'], 'code' => (string)($entry['grounds'][0] ?? ''), 'reason' => $reason];
		}

		return ['recorded' => 0, 'refused' => $refused];
	}//end refuseAll()

}//end class

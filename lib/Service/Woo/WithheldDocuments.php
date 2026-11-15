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
 * The grounds are looked up in dossiq's own list, read once per call through
 * `WooRefusalGrounds::listWithRetired()` (or `byCode()` per code on a dossiq
 * without it), and stored as dossiq answered, so the public read never guesses
 * a label and one call never mixes two versions of the list.
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
	 * dossiq's method that answers the whole list, retired grounds included.
	 *
	 * @var string
	 */
	public const GROUNDS_LIST_METHOD = 'listWithRetired';

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

	public const REASON_UNAVAILABLE = 'unavailable';

	/**
	 * Constructor.
	 *
	 * @param WithheldDocumentStore $store Reads and writes the withheldDocument objects.
	 * @param ContainerInterface $container Resolves dossiq's grounds list.
	 * @param IAppManager $appManager Says whether dossiq is there.
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
	 * @param string $publicationId The publication of the Woo decision.
	 * @param array<int, array<string, mixed>> $entries One `{position, grounds}` per withheld document.
	 * @param string $source The recording app, such as `dossiq`.
	 *
	 * @return array{recorded: int, refused: list<array{position: int, code: string, reason: string}>}
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	public function record(string $publicationId, array $entries, string $source): array {
		$entries = array_map(fn (mixed $entry): array => $this->reduce(entry: $entry), array_values($entries));

		$refusal = $this->publicationRefusal(publicationId: $publicationId, entries: $entries);
		if ($refusal !== null) {
			return $refusal;
		}

		$resolved = $this->resolvedWithGrounds(entries: $entries);
		if ($resolved === null) {
			return $this->refuseAll(entries: $entries, reason: self::REASON_GROUNDS_UNAVAILABLE);
		}

		[$accepted, $refused] = $resolved;
		$this->store->replace(publicationId: $publicationId, accepted: $accepted, source: $source);

		return ['recorded' => count($accepted), 'refused' => $refused];
	}//end record()

	/**
	 * Every entry refused when the publication cannot be read or does not exist, else null.
	 *
	 * @param string $publicationId The publication of the Woo decision.
	 * @param list<array{position: int, grounds: list<string>}> $entries The reduced entries.
	 *
	 * @return array{recorded: int, refused: list<array{position: int, code: string, reason: string}>}|null
	 */
	private function publicationRefusal(string $publicationId, array $entries): ?array {
		$exists = $this->store->publicationExists(publicationId: $publicationId);
		if ($exists === null) {
			return $this->refuseAll(entries: $entries, reason: self::REASON_UNAVAILABLE);
		}

		if ($exists === false) {
			return $this->refuseAll(entries: $entries, reason: self::REASON_NO_PUBLICATION);
		}

		return null;
	}//end publicationRefusal()

	/**
	 * The entries resolved through dossiq's list, or null when that list is not
	 * there (for a call with entries) or says it cannot be read.
	 *
	 * @param list<array{position: int, grounds: list<string>}> $entries The reduced entries.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: list<array{position: int, code: string, reason: string}>}|null Accepted and refused.
	 *
	 * @throws Throwable When reading the list fails for another reason than dossiq's own.
	 */
	private function resolvedWithGrounds(array $entries): ?array {
		$grounds = $this->grounds();
		if ($grounds === null && $entries !== []) {
			return null;
		}

		return $this->resolveUnlessUnavailable(grounds: $grounds, entries: $entries);
	}//end resolvedWithGrounds()

	/**
	 * The entries resolved, or null when dossiq says its list cannot be read.
	 *
	 * @param object|null $grounds dossiq's WooRefusalGrounds.
	 * @param list<array{position: int, grounds: list<string>}> $entries The reduced entries.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: list<array{position: int, code: string, reason: string}>}|null Accepted and refused.
	 *
	 * @throws Throwable When reading the list fails for another reason than dossiq's own.
	 */
	private function resolveUnlessUnavailable(?object $grounds, array $entries): ?array {
		try {
			return $this->resolveAll(grounds: $grounds, entries: $entries);
		} catch (Throwable $e) {
			if ($this->isUnavailable(error: $e) === false) {
				throw $e;
			}

			return null;
		}
	}//end resolveUnlessUnavailable()

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

		$grounds = $this->groundCodes(codes: ($entry['grounds'] ?? []));

		return ['position' => $this->position(position: ($entry['position'] ?? null)), 'grounds' => $grounds];
	}//end reduce()

	/**
	 * The trimmed, non-blank text codes of an entry's grounds.
	 *
	 * @param mixed $codes The entry's grounds.
	 *
	 * @return list<string>
	 */
	private function groundCodes(mixed $codes): array {
		$grounds = [];
		foreach ((array)$codes as $code) {
			if (is_string($code) === true && trim($code) !== '') {
				$grounds[] = trim($code);
			}
		}

		return $grounds;
	}//end groundCodes()

	/**
	 * An entry's position as a number, or 0 when it is not numeric.
	 *
	 * @param mixed $position The entry's position.
	 *
	 * @return int
	 */
	private function position(mixed $position): int {
		if (is_numeric($position) === true) {
			return (int)$position;
		}

		return 0;
	}//end position()

	/**
	 * Resolve every entry, accepting each position once.
	 *
	 * @param object|null $grounds dossiq's WooRefusalGrounds.
	 * @param list<array{position: int, grounds: list<string>}> $entries The reduced entries.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: list<array{position: int, code: string, reason: string}>} Accepted and refused.
	 *
	 * @throws Throwable When dossiq's list cannot be read.
	 */
	private function resolveAll(?object $grounds, array $entries): array {
		$lookup = $this->lookup(grounds: $grounds, entries: $entries);
		$resolution = new WithheldResolution();
		foreach ($entries as $entry) {
			$resolution->add(entry: $entry, resolved: $this->resolve(lookup: $lookup, entry: $entry));
		}

		return $resolution->result();
	}//end resolveAll()

	/**
	 * dossiq's list read once and keyed by code, or null to ask `byCode()` per code.
	 *
	 * One read per call keeps a decision with hundreds of withheld documents from
	 * reading the grounds register hundreds of times, and keeps every label of one
	 * call from the same version of the list. A dossiq without `listWithRetired()`
	 * is still asked per code.
	 *
	 * @param object|null $grounds dossiq's WooRefusalGrounds.
	 * @param list<array{position: int, grounds: list<string>}> $entries The reduced entries.
	 *
	 * @return array<string, array<string, mixed>>|null
	 *
	 * @throws Throwable When dossiq's list cannot be read; record() turns its WooRefusalGroundsUnavailable into a refusal.
	 */
	private function index(?object $grounds, array $entries): ?array {
		if ($grounds === null || $this->listsAtOnce(grounds: $grounds, entries: $entries) === false) {
			return null;
		}

		return $this->keyedByCode(list: (array)$grounds->{self::GROUNDS_LIST_METHOD}());
	}//end index()

	/**
	 * Whether a call with entries can read dossiq's whole list at once.
	 *
	 * @param object $grounds dossiq's WooRefusalGrounds.
	 * @param list<array{position: int, grounds: list<string>}> $entries The reduced entries.
	 *
	 * @return bool
	 */
	private function listsAtOnce(object $grounds, array $entries): bool {
		return $entries !== [] && method_exists($grounds, self::GROUNDS_LIST_METHOD) === true;
	}//end listsAtOnce()

	/**
	 * dossiq's list keyed by code: rows without a text code are skipped, and the first row of a code wins.
	 *
	 * @param array<int|string, mixed> $list dossiq's list.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function keyedByCode(array $list): array {
		$byCode = [];
		foreach ($list as $ground) {
			if (is_array($ground) === false || is_string($ground['code'] ?? null) === false) {
				continue;
			}

			$byCode[$ground['code']] ??= $ground;
		}

		return $byCode;
	}//end keyedByCode()

	/**
	 * How one code is looked up: in dossiq's list read once, or through
	 * `byCode()` when that list cannot be read in one go. Null without dossiq.
	 *
	 * @param object|null $grounds dossiq's WooRefusalGrounds.
	 * @param list<array{position: int, grounds: list<string>}> $entries The reduced entries.
	 *
	 * @return \Closure(string): mixed|null
	 *
	 * @throws Throwable When dossiq's list cannot be read.
	 */
	private function lookup(?object $grounds, array $entries): ?\Closure {
		if ($grounds === null) {
			return null;
		}

		$byCode = $this->index(grounds: $grounds, entries: $entries);
		if ($byCode === null) {
			return static fn (string $code): mixed => $grounds->byCode($code);
		}

		return static fn (string $code): mixed => ($byCode[$code] ?? null);
	}//end lookup()

	/**
	 * Resolve one entry's codes through dossiq's list.
	 *
	 * @param (\Closure(string): mixed)|null $lookup How a code is looked up, or null without dossiq.
	 * @param array{position: int, grounds: list<string>} $entry The reduced entry.
	 *
	 * @return array<string, mixed> `['entry' => ...]` or `['refused' => ...]`.
	 */
	private function resolve(?\Closure $lookup, array $entry): array {
		if ($this->isIncomplete(entry: $entry) === true || $lookup === null) {
			return ['refused' => ['position' => $entry['position'], 'code' => (string)($entry['grounds'][0] ?? ''), 'reason' => self::REASON_INVALID_ENTRY]];
		}

		return $this->resolveCodes(lookup: $lookup, entry: $entry);
	}//end resolve()

	/**
	 * Whether an entry lacks a position or any ground.
	 *
	 * @param array{position: int, grounds: list<string>} $entry The reduced entry.
	 *
	 * @return bool
	 */
	private function isIncomplete(array $entry): bool {
		return $entry['position'] < 1 || $entry['grounds'] === [];
	}//end isIncomplete()

	/**
	 * Resolve each code of a complete entry; the first unknown code refuses the entry.
	 *
	 * @param \Closure(string): mixed $lookup How a code is looked up.
	 * @param array{position: int, grounds: list<string>} $entry The reduced entry.
	 *
	 * @return array<string, mixed> `['entry' => ...]` or `['refused' => ...]`.
	 */
	private function resolveCodes(\Closure $lookup, array $entry): array {
		$stored = [];
		foreach ($entry['grounds'] as $code) {
			$ground = $lookup($code);
			if (is_array($ground) === false) {
				return ['refused' => ['position' => $entry['position'], 'code' => $code, 'reason' => self::REASON_UNKNOWN_GROUND]];
			}

			$stored[] = $this->storedGround(ground: $ground, code: $code);
		}

		return ['entry' => ['position' => $entry['position'], 'grounds' => $stored]];
	}//end resolveCodes()

	/**
	 * What is stored of one ground: its code (else the asked code), article and label.
	 *
	 * @param array<string, mixed> $ground dossiq's ground.
	 * @param string $code The asked code.
	 *
	 * @return array{code: string, article: string, label: string}
	 */
	private function storedGround(array $ground, string $code): array {
		return [
			'code' => (string)($ground['code'] ?? $code),
			'article' => (string)($ground['article'] ?? ''),
			'label' => (string)($ground['label'] ?? ''),
		];
	}//end storedGround()

	/**
	 * dossiq's grounds list, or null when dossiq or the class is not there.
	 *
	 * @return object|null
	 */
	private function grounds(): ?object {
		if ($this->hasGroundsClass() === false) {
			return null;
		}

		$grounds = $this->builtGrounds();
		if (is_object($grounds) === false || method_exists($grounds, 'byCode') === false) {
			return null;
		}

		return $grounds;
	}//end grounds()

	/**
	 * dossiq's grounds list as the container builds it, or null when it cannot.
	 *
	 * @return mixed
	 */
	private function builtGrounds(): mixed {
		try {
			return $this->container->get(self::GROUNDS_CLASS);
		} catch (Throwable $e) {
			return null;
		}
	}//end builtGrounds()

	/**
	 * Whether dossiq is installed and its grounds class is there.
	 *
	 * @return bool
	 */
	private function hasGroundsClass(): bool {
		return $this->appManager->isInstalled('dossiq') === true && class_exists(self::GROUNDS_CLASS) === true;
	}//end hasGroundsClass()

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
	 * @param string $reason The reason.
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

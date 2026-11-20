<?php

/**
 * The Woo-index fields of a publication, from integriq's editable mapping.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The four publication-level fields the national Woo-index reads (`publisher`,
 * `officieleTitel`, `informatiecategorie`, `soortHandeling`) can come from
 * integriq's mapping `woo-index-publication`, which an administrator edits on
 * integriq's Mappings page. The mapper asks integriq through its typed event
 * {@see \OCA\Integriq\Event\MappingExecutionRequestedEvent} (integriq
 * `docs/developers/run-a-mapping-from-another-app.md`), once per publication per
 * request, and answers which fields the mapping filled.
 *
 * It never decides a value on its own: a field the mapping leaves empty, or
 * every field when integriq is absent, refuses (`not-found`, `not-allowed`,
 * `failed`) or a listener throws, keeps the sitemap's built-in read. The
 * sitemap therefore never depends on the mapping answering (REQ-WIM-002).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/woo-index-fields-from-the-mapping/specs/woo-compliance/spec.md#requirement-woo-index-fields-come-from-the-editable-mapping-when-integriq-answers-req-wim-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use OCP\EventDispatcher\IEventDispatcher;
use Throwable;

/**
 * Runs integriq's Woo-index mapping for a publication.
 *
 * @spec openspec/changes/woo-index-fields-from-the-mapping/specs/woo-compliance/spec.md#requirement-woo-index-fields-come-from-the-editable-mapping-when-integriq-answers-req-wim-001
 */
class WooIndexFieldMapper {

	/**
	 * integriq's event class. Named here, not imported: integriq is optional.
	 *
	 * @var string
	 */
	public const EVENT_CLASS = 'OCA\\Integriq\\Event\\MappingExecutionRequestedEvent';

	/**
	 * The mapping integriq seeds for the Woo-index fields.
	 *
	 * @var string
	 */
	public const MAPPING_SLUG = 'woo-index-publication';

	/**
	 * The app id integriq checks against the mapping's callableBy.
	 *
	 * @var string
	 */
	public const SOURCE_APP = 'opencatalogi';

	/**
	 * The fields the mapping may fill, in sitemap order.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = ['publisher', 'officieleTitel', 'informatiecategorie', 'soortHandeling'];

	/**
	 * The report entry when the fields are the built-in reads.
	 *
	 * @var array{source: string, refusal: null, fieldsFromMapping: array<int, string>}
	 */
	public const BUILT_IN_SOURCE = ['source' => 'built-in', 'refusal' => null, 'fieldsFromMapping' => []];

	/**
	 * Answers already given this request, by publication uuid.
	 *
	 * @var array<string, array{fields: array<string, string>, origin: array<string, string>, refusal: array{code: string, reason: string}|null}>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 * @param string           $eventClass The event class (integriq's by default; a stand-in in tests).
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly string $eventClass = self::EVENT_CLASS,
	) {

	}//end __construct()

	/**
	 * The fields the mapping filled for one publication, where each field came
	 * from, and integriq's refusal when there was one.
	 *
	 * @param array<string, mixed>      $publication  The publication.
	 * @param array<string, mixed>|null $organisation The owning organisation (`name`, `tooiIdentifier`), when known.
	 *
	 * @return array{fields: array<string, string>, origin: array<string, string>, refusal: array{code: string, reason: string}|null}
	 *
	 * @spec openspec/changes/woo-index-fields-from-the-mapping/specs/woo-compliance/spec.md#requirement-the-sitemap-never-depends-on-the-mapping-answering-req-wim-002
	 */
	public function map(array $publication, ?array $organisation = null): array {
		$uuid = (string)($publication['@self']['id'] ?? ($publication['id'] ?? ''));
		if ($uuid !== '' && isset($this->cache[$uuid]) === true) {
			return $this->cache[$uuid];
		}

		$answer = $this->ask(input: self::inputOf(publication: $publication, organisation: $organisation ?? []));
		if ($uuid !== '') {
			$this->cache[$uuid] = $answer;
		}

		return $answer;
	}//end map()

	/**
	 * Whether integriq is installed, so the mapping can be asked at all.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-index-fields-from-the-mapping/specs/woo-compliance/spec.md#requirement-the-administrator-sees-where-the-fields-come-from-req-wim-004
	 */
	public function isAvailable(): bool {
		return class_exists($this->eventClass) === true;
	}//end isAvailable()

	/**
	 * Where the Woo-index fields come from, asked of the mapping with one
	 * sample publication (REQ-WIM-004): `integriq` when the mapping filled at
	 * least one field, else `built-in`, with integriq's refusal when it refused.
	 *
	 * @return array{source: string, refusal: array{code: string, reason: string}|null, fieldsFromMapping: array<int, string>}
	 *
	 * @spec openspec/changes/woo-index-fields-from-the-mapping/specs/woo-compliance/spec.md#requirement-the-administrator-sees-where-the-fields-come-from-req-wim-004
	 */
	public function source(): array {
		if ($this->isAvailable() === false) {
			return self::BUILT_IN_SOURCE;
		}

		$answer = $this->ask(input: self::inputOf(
			publication: ['title' => 'Woo readiness check', 'summary' => 'Woo readiness check', 'soortHandeling' => 'ontvangst'],
			organisation: []
		));
		$fromMapping = array_keys(array_filter($answer['origin'], static fn (string $origin): bool => $origin === 'mapping'));
		$source = 'integriq';
		if ($fromMapping === []) {
			$source = 'built-in';
		}

		return ['source' => $source, 'refusal' => $answer['refusal'], 'fieldsFromMapping' => $fromMapping];
	}//end source()

	/**
	 * The mapping's input (design D2): a missing value is an empty string.
	 *
	 * @param array<string, mixed> $publication  The publication.
	 * @param array<string, mixed> $organisation The organisation.
	 *
	 * @return array<string, string>
	 */
	private static function inputOf(array $publication, array $organisation): array {
		$text = static function (mixed $value): string {
			if (is_scalar($value) === false) {
				return '';
			}

			return trim((string)$value);
		};

		return [
			'title' => $text($publication['title'] ?? null),
			'summary' => $text($publication['summary'] ?? null),
			'name' => $text($organisation['name'] ?? null),
			'tooiIdentifier' => $text($publication['tooiIdentifier'] ?? ($organisation['tooiIdentifier'] ?? null)),
			'tooiCategorieUri' => $text($publication['tooiCategorieUri'] ?? null),
			'category' => $text($publication['wooCategory'] ?? ($publication['category'] ?? null)),
			'soortHandeling' => $text($publication['soortHandeling'] ?? null),
		];
	}//end inputOf()

	/**
	 * Dispatch the event and read its answer.
	 *
	 * @param array<string, string> $input The mapping input.
	 *
	 * @return array{fields: array<string, string>, origin: array<string, string>, refusal: array{code: string, reason: string}|null}
	 */
	private function ask(array $input): array {
		$builtIn = ['fields' => [], 'origin' => array_fill_keys(self::FIELDS, 'built-in'), 'refusal' => null];
		if ($this->isAvailable() === false) {
			return $builtIn;
		}

		try {
			$event = new ($this->eventClass)(self::MAPPING_SLUG, $input, self::SOURCE_APP);
			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			return ['refusal' => ['code' => 'failed', 'reason' => $e->getMessage()]] + $builtIn;
		}

		$refusal = $event->getRefusal();
		if ($refusal !== null) {
			return ['refusal' => ['code' => (string)($refusal['code'] ?? ''), 'reason' => (string)($refusal['reason'] ?? '')]] + $builtIn;
		}

		if ($event->isHandled() === false) {
			return $builtIn;
		}

		return self::fieldsOf(output: (array)$event->getOutput());
	}//end ask()

	/**
	 * The non-empty string fields of an output, each marked as from the mapping.
	 *
	 * @param array<string, mixed> $output The mapping output.
	 *
	 * @return array{fields: array<string, string>, origin: array<string, string>, refusal: null}
	 */
	private static function fieldsOf(array $output): array {
		$fields = [];
		$origin = [];
		foreach (self::FIELDS as $field) {
			$value = ($output[$field] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				$fields[$field] = trim($value);
				$origin[$field] = 'mapping';
				continue;
			}

			$origin[$field] = 'built-in';
		}

		return ['fields' => $fields, 'origin' => $origin, 'refusal' => null];
	}//end fieldsOf()

}//end class

<?php

/**
 * OpenCatalogi Plain Summary Check.
 *
 * A plain language summary is only worth something with the reading level it
 * was written at, so a save that stores one with an empty level is refused
 * before it is written. The summary's provenance is the server's to write: a
 * save that changes the summary gets a fresh provenance naming the person who
 * saved it, and a save that tries to write the provenance itself gets the
 * stored one back.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
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
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-publication-carries-a-plain-language-summary-with-its-level-req-ppl-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Publication\PlainSummaryProvenance;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;

/**
 * Refuses a plain summary without a level, and keeps its provenance the server's.
 *
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-publication-carries-a-plain-language-summary-with-its-level-req-ppl-001
 *
 * @template-implements IEventListener<Event>
 */
class PlainSummaryCheck implements IEventListener {

	/**
	 * The reading levels a plain summary can be written at.
	 */
	public const LEVELS = ['A2', 'B1', 'B2'];

	/**
	 * The level a summary saved without naming one is written at.
	 */
	public const DEFAULT_LEVEL = 'B1';

	/**
	 * Constructor.
	 *
	 * @param PlainSummaryProvenance $provenance Builds the provenance, and holds an accepted one.
	 * @param IL10N $l10n Localisation.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly PlainSummaryProvenance $provenance,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Check the plain summary a save writes, before it is written.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-publication-carries-a-plain-language-summary-with-its-level-req-ppl-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->check(event: $event, new: $event->getObject(), old: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->check(event: $event, new: $event->getNewObject(), old: $event->getOldObject());
		}

	}//end handle()

	/**
	 * Refuse a summary without a level, or stamp the provenance of this save.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 * @param ObjectEntity $new The object as it would be written.
	 * @param ObjectEntity|null $old The object as stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-publication-carries-a-plain-language-summary-with-its-level-req-ppl-001
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $new, ?ObjectEntity $old): void {
		$data = ($new->getObject() ?? []);
		$stored = [];
		if ($old !== null) {
			$stored = ($old->getObject() ?? []);
		}

		if ($this->carriesPlainSummary(data: $data) === false && $this->carriesPlainSummary(data: $stored) === false) {
			return;
		}

		$text = $this->text(data: $data);
		$modified = [];
		if ($text !== '') {
			if (array_key_exists('plainSummaryLevel', $data) === false) {
				$modified['plainSummaryLevel'] = self::DEFAULT_LEVEL;
			} else if (in_array($data['plainSummaryLevel'], self::LEVELS, true) === false) {
				$this->refuse(event: $event);
				return;
			}
		}

		$modified['plainSummaryProvenance'] = $this->provenanceOf(text: $text, data: $data, stored: $stored);
		if (($data['plainSummaryProvenance'] ?? null) === $modified['plainSummaryProvenance'] && count($modified) === 1) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $modified));

	}//end check()

	/**
	 * The provenance this save stores.
	 *
	 * Held by the accept route for this text, else a fresh person provenance
	 * when the text changed, else what is stored: a caller never writes it.
	 *
	 * @param string $text The summary this save writes.
	 * @param array<string, mixed> $data The object as it would be written.
	 * @param array<string, mixed> $stored The object as stored.
	 *
	 * @return array<string, mixed>|null The provenance, or null without a summary.
	 */
	private function provenanceOf(string $text, array $data, array $stored): ?array {
		if ($text === '') {
			return null;
		}

		$accepted = $this->provenance->take(text: $text);
		if ($accepted !== null) {
			return $accepted;
		}

		$storedProvenance = ($stored['plainSummaryProvenance'] ?? null);
		if ($text === $this->text(data: $stored) && is_array($storedProvenance) === true) {
			return $storedProvenance;
		}

		return $this->provenance->acceptedNow(generatedBy: 'person');

	}//end provenanceOf()

	/**
	 * Whether an object carries any of the plain summary fields.
	 *
	 * @param array<string, mixed> $data The object.
	 *
	 * @return bool True when it does.
	 */
	private function carriesPlainSummary(array $data): bool {
		return array_key_exists('plainSummary', $data) === true
			|| array_key_exists('plainSummaryProvenance', $data) === true;

	}//end carriesPlainSummary()

	/**
	 * The plain summary of an object, trimmed.
	 *
	 * @param array<string, mixed> $data The object.
	 *
	 * @return string The summary, empty when there is none.
	 */
	private function text(array $data): string {
		$text = ($data['plainSummary'] ?? '');
		if (is_string($text) === false) {
			return '';
		}

		return trim($text);

	}//end text()

	/**
	 * Refuse the save, naming the level.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event): void {
		$event->setErrors(
			[
				'message' => $this->l10n->t('A plain language summary needs its reading level (plainSummaryLevel): A2, B1 or B2.'),
				'property' => 'plainSummaryLevel',
			]
		);
		$event->stopPropagation();

	}//end refuse()
}//end class

<?php

/**
 * The provenance of a plain language summary, held for the one save that stores it.
 *
 * The provenance is read-only to callers: PlainSummaryCheck stamps it on every
 * save. A save through the accept route knows more than the listener can see
 * (that the text came from a text assistant, and which one), so it leaves the
 * provenance here for the listener to take, keyed on the text it stores.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;

/**
 * Builds the provenance of a plain summary, and holds an accepted one for its save.
 *
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-publication-carries-a-plain-language-summary-with-its-level-req-ppl-001
 */
class PlainSummaryProvenance {

	/**
	 * Provenance waiting for its save, keyed on the text it belongs to.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $expected = [];

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Names the person who accepts.
	 * @param ITimeFactory $time The clock.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $time,
	) {

	}//end __construct()

	/**
	 * The provenance of a summary the current user accepts now.
	 *
	 * @param string $generatedBy person, or ai for a text assistant's draft.
	 * @param string|null $provider The text assistant, for an ai draft; left out for a person's text.
	 *
	 * @return array{generatedBy: string, provider?: string, at: string, acceptedBy: string} The provenance.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function acceptedNow(string $generatedBy, ?string $provider = null): array {
		$user = $this->userSession->getUser();
		$acceptedBy = 'system';
		if ($user !== null) {
			$acceptedBy = $user->getUID();
		}

		$provenance = ['generatedBy' => $generatedBy];
		if ($generatedBy === 'ai' && $provider !== null && $provider !== '') {
			$provenance['provider'] = $provider;
		}

		$provenance['at'] = $this->time->getDateTime()->format(DateTimeInterface::ATOM);
		$provenance['acceptedBy'] = $acceptedBy;

		return $provenance;

	}//end acceptedNow()

	/**
	 * Hold the provenance for the save that stores this text.
	 *
	 * @param string $text The plain summary about to be saved.
	 * @param array<string, mixed> $provenance Its provenance.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function expect(string $text, array $provenance): void {
		$this->expected[trim($text)] = $provenance;

	}//end expect()

	/**
	 * Take the provenance held for this text, once.
	 *
	 * @param string $text The plain summary being saved.
	 *
	 * @return array<string, mixed>|null The provenance, or null when none was held for it.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function take(string $text): ?array {
		$key = trim($text);
		if (array_key_exists($key, $this->expected) === false) {
			return null;
		}

		$provenance = $this->expected[$key];
		unset($this->expected[$key]);

		return $provenance;

	}//end take()

	/**
	 * Forget a held provenance whose save did not happen.
	 *
	 * @param string $text The plain summary that was not saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function forget(string $text): void {
		unset($this->expected[trim($text)]);

	}//end forget()
}//end class

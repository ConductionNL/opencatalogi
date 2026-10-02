<?php

/**
 * OpenCatalogi Saved Search Notice Writer.
 *
 * Writes the message a resident gets when new publications match their saved
 * search (hydra `woo-citizen-journey`, C3): one message in portaliq's inbox,
 * naming the search and the publication, with the rule key
 * `opencatalogi.savedSearch.matched` so portaliq also sends the e-mail by the
 * resident's preferences, and a link to the saved search, whose detail lists
 * the matches.
 *
 * The message is Dutch: a Woo portal speaks Dutch, and opencatalogi does not
 * know the resident's language. It is never Dutch and English in one string.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Portal
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-notice-reaches-the-resident-through-portaliq-and-a-crash-sends-late-not-never-req-ssa-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Portal;

use OCA\OpenCatalogi\Portal\PortalContributionProvider;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * Writes one saved-search match message into the resident's portal inbox.
 *
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-notice-reaches-the-resident-through-portaliq-and-a-crash-sends-late-not-never-req-ssa-004
 */
class SavedSearchNoticeWriter {

	/**
	 * The language of the message.
	 */
	private const LANGUAGE = 'nl';

	/**
	 * The portal collection that shows the resident's saved searches.
	 */
	private const COLLECTION = 'mySavedSearches';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectStore $store       Writes the message as the system.
	 * @param IFactory          $l10nFactory The Dutch text, whatever the request's language.
	 */
	public function __construct(
		private readonly PortalObjectStore $store,
		private readonly IFactory $l10nFactory,
	) {

	}//end __construct()

	/**
	 * Write the message for one notice.
	 *
	 * @param array<string, mixed>             $saved The saved search.
	 * @param array<int, array<string, mixed>> $batch The publications in this notice (one, or at most twenty).
	 * @param int                              $count How many publications matched in all.
	 *
	 * @return bool Whether a message was written; false when the search has no owner to tell.
	 *
	 * @throws \RuntimeException When the message could not be stored.
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-notice-reaches-the-resident-through-portaliq-and-a-crash-sends-late-not-never-req-ssa-004
	 */
	public function write(array $saved, array $batch, int $count): bool {
		$owner = ($saved['owner'] ?? null);
		if (is_string($owner) === false || $owner === '' || $batch === []) {
			return false;
		}

		$l10n = $this->l10nFactory->get('opencatalogi', self::LANGUAGE);
		$search = (string)($saved['title'] ?? '');

		$this->store->writePortalMessage(
			message: [
				'subjectRef' => $owner,
				'subject' => $this->subject(l10n: $l10n, search: $search, batch: $batch, count: $count),
				'body' => $this->body(l10n: $l10n, search: $search, batch: $batch, count: $count),
				'read' => false,
				'receivedAt' => gmdate('c'),
				'ruleKey' => PortalContributionProvider::RULE_SAVED_SEARCH_MATCHED,
				'recordLink' => ['app' => 'opencatalogi', 'collection' => self::COLLECTION, 'id' => (string)($saved['id'] ?? '')],
			]
		);

		return true;

	}//end write()

	/**
	 * The subject: the publication by name when there is one, else the count.
	 *
	 * @param IL10N                            $l10n   The Dutch text.
	 * @param string                           $search The search's title.
	 * @param array<int, array<string, mixed>> $batch  The publications in this notice.
	 * @param int                              $count  How many matched in all.
	 *
	 * @return string
	 */
	private function subject(IL10N $l10n, string $search, array $batch, int $count): string {
		if ($count === 1) {
			return $l10n->t('New publication for your search "%1$s": %2$s', [$search, (string)($batch[0]['title'] ?? '')]);
		}

		return $l10n->t('%1$d new publications for your search "%2$s"', [$count, $search]);

	}//end subject()

	/**
	 * The body: one line per publication with its link, and how many more.
	 *
	 * @param IL10N                            $l10n   The Dutch text.
	 * @param string                           $search The search's title.
	 * @param array<int, array<string, mixed>> $batch  The publications in this notice.
	 * @param int                              $count  How many matched in all.
	 *
	 * @return string
	 */
	private function body(IL10N $l10n, string $search, array $batch, int $count): string {
		$opening = $l10n->t('These publications match your search "%1$s".', [$search]);
		if ($count === 1) {
			$opening = $l10n->t('A new publication matches your search "%1$s".', [$search]);
		}

		$lines = [];
		foreach ($batch as $match) {
			$lines[] = '- '.(string)($match['title'] ?? '').': '.(string)($match['url'] ?? '');
		}

		$body = $opening."\n\n".implode("\n", $lines);
		if ($count > count($batch)) {
			$body .= "\n\n".$l10n->t('And %1$d more. Search again to see them all.', [$count - count($batch)]);
		}

		return $body;

	}//end body()
}//end class

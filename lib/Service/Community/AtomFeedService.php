<?php

/**
 * OpenCatalogi Atom Feed Service.
 *
 * A catalogue's activity as an Atom feed: its published and updated records
 * plus its notices, readable without an account by a ketenpartner who wants to
 * watch without a webhook.
 *
 * The feed carries exactly what an anonymous reader may read and no more, and
 * the check runs per entry against the publication rather than as a separate
 * rule of its own. A feed with its own idea of what is public is a second
 * access decision, and two access decisions disagree eventually.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Community
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Community;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;

/**
 * Builds a catalogue's Atom feed from what an anonymous reader may read.
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
 */
class AtomFeedService {

	/**
	 * Constructor.
	 *
	 * @param PublicationRuleService $ruleService The publication rules, which own the access decision.
	 * @param NoticeBoardService $noticeService The notice board rules.
	 */
	public function __construct(
		private readonly PublicationRuleService $ruleService,
		private readonly NoticeBoardService $noticeService,
	) {

	}//end __construct()

	/**
	 * The entries a catalogue's feed carries.
	 *
	 * The publication rules decide which records appear and which of their
	 * properties do, per entry. A record with no rule is absent: a record
	 * nobody decided to publish is not published.
	 *
	 * @param array<int, array<string, mixed>> $records The catalogue's records.
	 * @param array<int, array<string, mixed>> $notices The catalogue's current notices.
	 * @param array<int, array<string, mixed>> $rules The publication rules.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function entries(array $records, array $notices, array $rules, ?DateTimeInterface $now = null): array {
		$rulesByType = $this->ruleService->indexByRecordType(rules: $rules);
		$entries = [];

		foreach ($this->ruleService->projectList(records: $records, rulesByType: $rulesByType) as $view) {
			$entries[] = [
				'kind' => 'record',
				'id' => (string)($view['id'] ?? ''),
				'title' => (string)($view['title'] ?? ''),
				'updated' => (string)($view['updated'] ?? ($view['publicationDate'] ?? '')),
				'summary' => (string)($view['summary'] ?? ($view['description'] ?? '')),
			];
		}

		foreach ($notices as $notice) {
			if (is_array($notice) === false) {
				continue;
			}

			if ($this->noticeService->isCurrent(notice: $notice, now: $now) === false) {
				continue;
			}

			$entries[] = [
				'kind' => 'notice',
				'id' => (string)($notice['id'] ?? ''),
				'title' => (string)($notice['title'] ?? ''),
				'updated' => (string)($notice['startDate'] ?? ''),
				'summary' => (string)($notice['body'] ?? ''),
			];
		}

		return $entries;

	}//end entries()

	/**
	 * The feed as Atom.
	 *
	 * Everything that came from an entry is escaped. The feed is assembled from
	 * projections that already dropped every withheld property, so the escaping
	 * is about markup and not about access.
	 *
	 * @param string $catalogTitle The catalogue's title.
	 * @param string $selfUrl The feed's own URL.
	 * @param array<int, array<string, mixed>> $entries The entries.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return string The Atom document.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function toAtom(string $catalogTitle, string $selfUrl, array $entries, ?DateTimeInterface $now = null): string {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		$escape = static fn (string $value): string => htmlspecialchars(
			$value,
			(ENT_XML1 | ENT_QUOTES),
			'UTF-8'
		);

		$lines = [
			'<?xml version="1.0" encoding="UTF-8"?>',
			'<feed xmlns="http://www.w3.org/2005/Atom">',
			'  <title>' . $escape($catalogTitle) . '</title>',
			'  <id>' . $escape($selfUrl) . '</id>',
			'  <link rel="self" href="' . $escape($selfUrl) . '"/>',
			'  <updated>' . $moment->format(DateTimeInterface::ATOM) . '</updated>',
		];

		foreach ($entries as $entry) {
			$updated = trim((string)($entry['updated'] ?? ''));
			if ($updated !== '') {
				try {
					$updated = (new DateTimeImmutable($updated))->format(DateTimeInterface::ATOM);
				} catch (\Throwable $e) {
					$updated = $moment->format(DateTimeInterface::ATOM);
				}
			}

			if ($updated === '') {
				$updated = $moment->format(DateTimeInterface::ATOM);
			}

			$lines[] = '  <entry>';
			$lines[] = '    <title>' . $escape((string)($entry['title'] ?? '')) . '</title>';
			$lines[] = '    <id>' . $escape($selfUrl . '#' . (string)($entry['id'] ?? '')) . '</id>';
			$lines[] = '    <updated>' . $updated . '</updated>';
			$lines[] = '    <category term="' . $escape((string)($entry['kind'] ?? 'record')) . '"/>';
			$lines[] = '    <summary>' . $escape((string)($entry['summary'] ?? '')) . '</summary>';
			$lines[] = '  </entry>';
		}

		$lines[] = '</feed>';

		return implode("\n", $lines) . "\n";

	}//end toAtom()
}//end class

<?php

/**
 * OpenCatalogi Knowledge Article Service.
 *
 * A knowledge article is a published record, so it is searchable and public by
 * the rules that already govern publications. A reader says whether it helped,
 * once, and the count is shown on the article without the reader being
 * identifiable from it. An answer on a case becomes a draft article in one
 * action, and the case keeps its answer.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Counts what readers thought of an article, and turns an answer into one.
 *
 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md
 */
class KnowledgeArticleService {

	/**
	 * Constructor.
	 *
	 * @param string $salt The salt the reader token is hashed with.
	 */
	public function __construct(
		private readonly string $salt = '',
	) {

	}//end __construct()

	/**
	 * The stored form of a reader token.
	 *
	 * A verdict has to be attributable enough to be counted once, and no more
	 * than that. The salted hash is what is stored; the token itself never is.
	 *
	 * @param string $readerToken The reader's token.
	 *
	 * @return string The salted hash.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-reader-says-whether-an-article-helped-and-the-count-is-visible-req-psc-104
	 */
	public function readerHash(string $readerToken): string {
		return hash('sha256', $this->salt . '|' . $readerToken);

	}//end readerHash()

	/**
	 * Record a reader's verdict on an article.
	 *
	 * The second verdict from the same reader changes nothing, including when
	 * it disagrees with the first: a reader counts once.
	 *
	 * @param array<string, mixed> $article The article.
	 * @param array<int, array<string, mixed>> $existingVerdicts The verdicts already recorded on it.
	 * @param string $readerToken The reader's token.
	 * @param boolean $helpful Whether the article helped.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array{counted: boolean, article: array<string, mixed>, verdict: array<string, mixed>|null}
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-reader-says-whether-an-article-helped-and-the-count-is-visible-req-psc-104
	 */
	public function recordVerdict(
		array $article,
		array $existingVerdicts,
		string $readerToken,
		bool $helpful,
		?DateTimeInterface $now = null,
	): array {
		$hash = $this->readerHash(readerToken: $readerToken);

		foreach ($existingVerdicts as $verdict) {
			if ((string)($verdict['readerHash'] ?? '') === $hash) {
				return [
					'counted' => false,
					'article' => $article,
					'verdict' => null,
				];
			}
		}

		$field = ($helpful === true ? 'helpfulCount' : 'notHelpfulCount');
		$article[$field] = ((int)($article[$field] ?? 0) + 1);

		$moment = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

		return [
			'counted' => true,
			'article' => $article,
			'verdict' => [
				'article' => (string)($article['id'] ?? ''),
				'readerHash' => $hash,
				'helpful' => $helpful,
				'recordedAt' => $moment->format(DateTimeInterface::ATOM),
			],
		];

	}//end recordVerdict()

	/**
	 * The counts an anonymous reader may see on an article.
	 *
	 * The verdicts themselves never leave this app: what is published is two
	 * numbers.
	 *
	 * @param array<string, mixed> $article The article.
	 *
	 * @return array{helpful: integer, notHelpful: integer}
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-reader-says-whether-an-article-helped-and-the-count-is-visible-req-psc-104
	 */
	public function publicCounts(array $article): array {
		return [
			'helpful' => (int)($article['helpfulCount'] ?? 0),
			'notHelpful' => (int)($article['notHelpfulCount'] ?? 0),
		];

	}//end publicCounts()

	/**
	 * Turn an answer on a case into a draft article.
	 *
	 * The draft links back to the case and is not public. An answer written to
	 * one applicant is rarely the wording that belongs in public, so nothing
	 * here publishes: a person reads the draft and decides.
	 *
	 * @param array<string, mixed> $case The case, as the calling app holds it.
	 * @param string $answerProperty The property of the case that holds the answer.
	 * @param string|null $title The title for the draft; defaults to the case's title.
	 *
	 * @return array<string, mixed> The draft article.
	 *
	 * @throws \InvalidArgumentException When the case carries no answer to extract.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-the-answer-on-a-case-becomes-an-article-in-one-action-req-psc-105
	 */
	public function extractDraft(array $case, string $answerProperty = 'answer', ?string $title = null): array {
		$answer = trim((string)($case[$answerProperty] ?? ''));
		if ($answer === '') {
			throw new \InvalidArgumentException(
				message: 'The case carries no answer under "' . $answerProperty . '" to extract.'
			);
		}

		$draftTitle = trim((string)($title ?? ($case['title'] ?? '')));
		if ($draftTitle === '') {
			$draftTitle = 'Draft from case ' . (string)($case['id'] ?? '');
		}

		return [
			'title' => $draftTitle,
			'body' => $answer,
			'draft' => true,
			'sourceCase' => (string)($case['id'] ?? ''),
			'helpfulCount' => 0,
			'notHelpfulCount' => 0,
		];

	}//end extractDraft()

	/**
	 * Whether an anonymous reader may read this article.
	 *
	 * The check is here, beside the read, rather than in the surface that
	 * happens to be in front of the reader: a draft that is public in the API
	 * and hidden in the page is public.
	 *
	 * @param array<string, mixed> $article The article.
	 *
	 * @return boolean True when the article is published.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-the-answer-on-a-case-becomes-an-article-in-one-action-req-psc-105
	 */
	public function isPublic(array $article): bool {
		return (bool)($article['draft'] ?? true) === false;

	}//end isPublic()
}//end class

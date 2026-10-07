<?php

/**
 * OpenCatalogi comment period service.
 *
 * Terinzagelegging: a draft decision goes out for public comment for a bounded
 * statutory period, with one named legal remedy and the reaction form that
 * belongs to it.
 *
 * This is a SIBLING of InspectionService, not an extension of it, and the
 * difference is deliberate. An inspection is an unguessable link to a set of
 * documents chosen per case, checked at the read, for a named party. A comment
 * period is public, announced on the official announcement platform, carries a
 * legal remedy, and its close can withdraw the publication. Folding the second
 * into the first would force a token onto a public thing and give `endDate` two
 * meanings.
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
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-publication-can-carry-one-bounded-public-comment-period-req-pcp-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * Opens comment periods, derives their reaction form, and answers their state.
 *
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-publication-can-carry-one-bounded-public-comment-period-req-pcp-001
 */
class CommentPeriodService {

	/**
	 * The two legal remedies a comment period can carry.
	 *
	 * A zienswijze is a view offered on a draft; a bezwaar is an objection to a
	 * decision already taken. They are not interchangeable, and a reader told the
	 * wrong one loses a right, so the list is closed and a third value is
	 * refused rather than stored.
	 *
	 * @var array<int, string>
	 */
	public const REMEDIES = ['zienswijze', 'bezwaar'];

	/**
	 * The three states the portal shows, in order.
	 *
	 * @var array<int, string>
	 */
	public const STATES = ['upcoming', 'open', 'closed'];

	/**
	 * The host every official announcement of a Dutch public body appears on.
	 *
	 * @var string
	 */
	public const ANNOUNCEMENT_HOST = 'officielebekendmakingen.nl';

	/**
	 * The in-app path a reaction form lives under, per remedy.
	 *
	 * @var array<string, string>
	 */
	private const FORM_PATHS = [
		'zienswijze' => '/index.php/apps/opencatalogi/comment-periods/%s/zienswijze',
		'bezwaar' => '/index.php/apps/opencatalogi/comment-periods/%s/bezwaar',
	];

	/**
	 * Constructor.
	 *
	 * @param TermRoll $roll Rolls the end date off a non-working day, through OpenRegister.
	 * @param IAppConfig $appConfig Holds the reaction-form template, when an organisation set one.
	 * @param IURLGenerator $urlGenerator Makes a path absolute.
	 */
	public function __construct(
		private readonly TermRoll $roll,
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * Open a comment period on a publication.
	 *
	 * @param array<string, mixed> $publication The publication going out for comment.
	 * @param int $termDays How many days the period runs.
	 * @param string $legalRemedy The remedy open to a reader, one of {@see REMEDIES}.
	 * @param string $announcementUrl The official announcement of the period.
	 * @param bool $automaticWithdrawal Whether the close withdraws the publication.
	 * @param string $openedBy Who opened it.
	 * @param DateTimeInterface|null $start When it opens; defaults to now.
	 *
	 * @return array<string, mixed> The period to save.
	 *
	 * @throws DomainException On an unknown remedy, a term of no days, or an announcement that is not one.
	 * @throws TermRollUnavailableException When the end date cannot be rolled.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-publication-can-carry-one-bounded-public-comment-period-req-pcp-001
	 */
	public function open(
		array $publication,
		int $termDays,
		string $legalRemedy,
		string $announcementUrl,
		bool $automaticWithdrawal,
		string $openedBy,
		?DateTimeInterface $start = null,
	): array {
		$publicationId = trim((string)($publication['id'] ?? ''));
		if ($publicationId === '') {
			throw new DomainException(
				message: 'A comment period belongs to a publication, and none was named.'
			);
		}

		if (in_array($legalRemedy, self::REMEDIES, true) === false) {
			throw new DomainException(
				message: 'The legal remedy "' . $legalRemedy . '" is not one of ' . implode(', ', self::REMEDIES)
					. '. A reader told the wrong remedy loses a right, so an unknown one is refused.'
			);
		}

		if ($termDays < 1) {
			throw new DomainException(
				message: 'A comment period runs for at least one day. A period of no days is a publication with no '
					. 'comment period, which is a different thing and should be recorded as one.'
			);
		}

		$announcement = $this->validateAnnouncement(url: $announcementUrl);

		$startsAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($start !== null) {
			$startsAt = new DateTimeImmutable($start->format('Y-m-d\\TH:i:s.uP'));
		}

		$end = $this->roll->endDate(start: $startsAt, days: $termDays);
		if ($end['at'] <= $startsAt) {
			// The engine cannot normally land here, and the constraint is
			// asserted anyway: a period whose end is not after its start would
			// read as closed from the moment it opened.
			throw new DomainException(
				message: 'A comment period has to end after it starts.'
			);
		}

		$period = [
			'publication' => $publicationId,
			'startDate' => $startsAt->format(DateTimeInterface::ATOM),
			'endDate' => $end['at']->format(DateTimeInterface::ATOM),
			'termDays' => $termDays,
			'legalRemedy' => $legalRemedy,
			'reactionFormUrl' => $this->reactionFormUrl(publicationId: $publicationId, legalRemedy: $legalRemedy),
			'announcementUrl' => $announcement,
			'automaticWithdrawal' => $automaticWithdrawal,
			'openedBy' => $openedBy,
		];

		// Both carried only when the roll actually moved the date. A handler
		// defending a period that closes on Tuesday has to be able to read that
		// Monday was Tweede Paasdag.
		if ($end['unrolledAt'] !== null) {
			$period['unrolledEndDate'] = $end['unrolledAt']->format(DateTimeInterface::ATOM);
		}

		if ($end['rolledBy'] !== null) {
			$period['rolledBy'] = $end['rolledBy'];
		}

		return $period;

	}//end open()

	/**
	 * The reaction form that belongs to a remedy.
	 *
	 * Derived, never pasted. An officer who pastes the form by hand pastes the
	 * zienswijze form onto a bezwaar period sooner or later, and the reader who
	 * follows it files the wrong thing.
	 *
	 * @param string $publicationId The publication the period is on.
	 * @param string $legalRemedy The remedy, one of {@see REMEDIES}.
	 *
	 * @return string The absolute form url.
	 *
	 * @throws DomainException On an unknown remedy.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-the-reaction-form-follows-from-the-legal-remedy-req-pcp-003
	 */
	public function reactionFormUrl(string $publicationId, string $legalRemedy): string {
		if (in_array($legalRemedy, self::REMEDIES, true) === false) {
			throw new DomainException(
				message: 'No reaction form can be derived for the remedy "' . $legalRemedy . '".'
			);
		}

		$template = trim($this->appConfig->getValueString('opencatalogi', 'comment_period_form_template', ''));
		if ($template !== '' && str_contains($template, '{remedy}') === true) {
			return str_replace(
				['{remedy}', '{id}'],
				[rawurlencode($legalRemedy), rawurlencode($publicationId)],
				$template
			);
		}

		return $this->urlGenerator->getAbsoluteURL(
			sprintf(self::FORM_PATHS[$legalRemedy], rawurlencode($publicationId))
		);

	}//end reactionFormUrl()

	/**
	 * Which of the three states a period is in.
	 *
	 * Derived from the clock, never stored. A stored state is one a job has to
	 * keep true, and a job that has not run yet leaves a closed period reading
	 * as open.
	 *
	 * @param array<string, mixed> $period The period.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return string One of {@see STATES}.
	 *
	 * @throws DomainException When the period's dates cannot be read.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-the-period-reports-one-of-three-states-req-pcp-004
	 */
	public function state(array $period, ?DateTimeInterface $now = null): string {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		try {
			$start = new DateTimeImmutable((string)($period['startDate'] ?? ''));
			$end = new DateTimeImmutable((string)($period['endDate'] ?? ''));
		} catch (\Throwable $e) {
			// Read as open it invites comment on a closed draft; read as closed
			// it withholds a right that is owed. Neither default is worth
			// having, so the caller is told the period cannot be read.
			throw new DomainException(
				message: 'This comment period has dates that cannot be read, so its state is refused rather than guessed.',
				code: 0,
				previous: $e
			);
		}

		if ($moment < $start) {
			return 'upcoming';
		}

		if ($moment > $end) {
			return 'closed';
		}

		return 'open';

	}//end state()

	/**
	 * What the portal is told about a period: the three states, the remedy, the
	 * form and the announcement. The portal states themselves are portaliq's.
	 *
	 * @param array<string, mixed> $period The period.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The period as a reader's surface sees it.
	 *
	 * @throws DomainException When the period's dates cannot be read.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-the-period-reports-one-of-three-states-req-pcp-004
	 */
	public function publicView(array $period, ?DateTimeInterface $now = null): array {
		$state = $this->state(period: $period, now: $now);

		// Only while the period is open. A form offered on a closed period
		// collects a reaction nobody is obliged to read.
		$reactionFormUrl = null;
		if ($state === 'open') {
			$reactionFormUrl = (string)($period['reactionFormUrl'] ?? '');
		}

		return [
			'publication' => (string)($period['publication'] ?? ''),
			'state' => $state,
			'startDate' => ($period['startDate'] ?? null),
			'endDate' => ($period['endDate'] ?? null),
			'termDays' => (int)($period['termDays'] ?? 0),
			'legalRemedy' => (string)($period['legalRemedy'] ?? ''),
			'reactionFormUrl' => $reactionFormUrl,
			'announcementUrl' => (string)($period['announcementUrl'] ?? ''),
			'unrolledEndDate' => ($period['unrolledEndDate'] ?? null),
			'rolledBy' => ($period['rolledBy'] ?? null),
			'withdrawnAt' => ($period['withdrawnAt'] ?? null),
		];

	}//end publicView()

	/**
	 * Whether the close of this period still owes a withdrawal.
	 *
	 * @param array<string, mixed> $period The period.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return boolean True when the publication should be withdrawn now.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-closed-period-can-withdraw-its-publication-req-pcp-005
	 */
	public function withdrawalDue(array $period, ?DateTimeInterface $now = null): bool {
		if ((bool)($period['automaticWithdrawal'] ?? false) === false) {
			return false;
		}

		$withdrawnAt = (string)($period['withdrawnAt'] ?? '');
		if (trim($withdrawnAt) !== '') {
			return false;
		}

		try {
			return ($this->state(period: $period, now: $now) === 'closed');
		} catch (DomainException $e) {
			// An unreadable period is never withdrawn on a guess: withdrawing a
			// publication that should still be up is the harder mistake to undo.
			return false;
		}

	}//end withdrawalDue()

	/**
	 * Validate the announcement url.
	 *
	 * A period is public because it was announced, so the announcement is not
	 * decoration. It has to be an absolute https url, and it is checked against
	 * the official announcement host so a link to an internal page cannot stand
	 * in for one.
	 *
	 * @param string $url The announcement url.
	 *
	 * @return string The url.
	 *
	 * @throws DomainException When it is not an announcement.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-the-period-points-at-its-official-announcement-req-pcp-006
	 */
	private function validateAnnouncement(string $url): string {
		$trimmed = trim($url);
		if ($trimmed === '') {
			throw new DomainException(
				message: 'A comment period needs the official announcement it was published with. A period nobody was '
					. 'told about is not a public comment period.'
			);
		}

		$host = (string)parse_url($trimmed, PHP_URL_HOST);
		$scheme = strtolower((string)parse_url($trimmed, PHP_URL_SCHEME));
		if ($scheme !== 'https' || $host === '') {
			throw new DomainException(
				message: 'The announcement has to be an absolute https url.'
			);
		}

		$host = strtolower($host);
		if ($host !== self::ANNOUNCEMENT_HOST
			&& str_ends_with($host, '.' . self::ANNOUNCEMENT_HOST) === false
		) {
			throw new DomainException(
				message: 'The announcement has to point at ' . self::ANNOUNCEMENT_HOST
					. ', which is where an official announcement of a Dutch public body appears. A link to an '
					. 'internal page does not make a period publicly announced.'
			);
		}

		return $trimmed;

	}//end validateAnnouncement()
}//end class

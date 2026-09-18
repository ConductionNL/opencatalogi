<?php

/**
 * OpenCatalogi Zienswijze Service.
 *
 * Before information about an interested party is published, that party is
 * asked. The ask goes out over a channel that identifies the recipient, the
 * answer is recorded against the publication, and the publication cannot
 * advance past the round while an ask is open and unanswered inside its term.
 *
 * The portal identity is portaliq's. This raises the ask and reads the answer,
 * and builds no second login.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-interested-parties-are-consulted-before-information-about-them-is-published-req-pin-105
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;

/**
 * Raises the zienswijze ask and records the answer.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-interested-parties-are-consulted-before-information-about-them-is-published-req-pin-105
 */
class ZienswijzeService {

	/**
	 * The channels that identify their recipient.
	 *
	 * A channel outside this list is refused rather than sent over. Asking an
	 * interested party over a channel that cannot say who answered produces an
	 * answer nobody can rely on, and the answer is the thing that permits a
	 * publication to go ahead.
	 *
	 * @var array<int, string>
	 */
	public const IDENTIFYING_CHANNELS = ['mijn-overheid-berichtenbox', 'portal-message', 'eherkenning', 'digid', 'registered-post'];

	/**
	 * Raise an ask to one interested party.
	 *
	 * @param string $publicationId The publication the ask is about.
	 * @param string $party The interested party.
	 * @param string $channel The channel the ask goes out over.
	 * @param integer $termDays The term the party has to answer in.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The ask to save.
	 *
	 * @throws DomainException When the channel does not identify its recipient, or the term is not positive.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-interested-parties-are-consulted-before-information-about-them-is-published-req-pin-105
	 */
	public function raise(
		string $publicationId,
		string $party,
		string $channel,
		int $termDays,
		?DateTimeInterface $now = null,
	): array {
		if ($this->identifies(channel: $channel) === false) {
			throw new DomainException(
				message: 'A zienswijze cannot be asked over "' . $channel
					. '": that channel does not identify the recipient, so no answer over it can be relied on.'
			);
		}

		if ($termDays <= 0) {
			throw new DomainException(message: 'A zienswijze ask needs a term the party can answer within.');
		}

		if (trim($party) === '') {
			throw new DomainException(message: 'A zienswijze ask needs the party it is addressed to.');
		}

		$sentAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$sentAt = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		return [
			'publication' => $publicationId,
			'party' => $party,
			'channel' => $channel,
			'sentAt' => $sentAt->format(DateTimeInterface::ATOM),
			'termEndsAt' => $sentAt->add(new DateInterval('P' . $termDays . 'D'))->format(DateTimeInterface::ATOM),
			'answeredAt' => null,
			'answer' => null,
			'answeredBy' => null,
		];

	}//end raise()

	/**
	 * Whether a channel identifies its recipient.
	 *
	 * @param string $channel The channel.
	 *
	 * @return boolean True when it identifies.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-interested-parties-are-consulted-before-information-about-them-is-published-req-pin-105
	 */
	public function identifies(string $channel): bool {
		return in_array(trim($channel), self::IDENTIFYING_CHANNELS, true);

	}//end identifies()

	/**
	 * Record the answer against the ask.
	 *
	 * @param array<string, mixed> $ask The ask.
	 * @param string $answer What the party said.
	 * @param string $answeredBy Who said it.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The answered ask.
	 *
	 * @throws DomainException When the ask was already answered, or the answer names nobody.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-interested-parties-are-consulted-before-information-about-them-is-published-req-pin-105
	 */
	public function answer(array $ask, string $answer, string $answeredBy, ?DateTimeInterface $now = null): array {
		if (trim((string)($ask['answeredAt'] ?? '')) !== '') {
			throw new DomainException(message: 'This ask was already answered, and an answer is not overwritten.');
		}

		if (trim($answeredBy) === '') {
			throw new DomainException(
				message: 'An answer has to say who gave it. An unattributed answer cannot permit a publication.'
			);
		}

		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		$ask['answer'] = $answer;
		$ask['answeredBy'] = $answeredBy;
		$ask['answeredAt'] = $moment->format(DateTimeInterface::ATOM);

		return $ask;

	}//end answer()
}//end class

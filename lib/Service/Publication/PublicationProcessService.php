<?php

/**
 * OpenCatalogi Publication Process Service.
 *
 * Publish as a button hides four decisions: which documents, whether a
 * zienswijze round is needed, who approves, and which channels receive it. So
 * publication is a small process with those four steps, each recorded. A
 * municipality that wants one action configures the steps away, and a skipped
 * step is recorded as configured off rather than as done, so a later reading
 * can tell an approval that happened from one that was never asked for.
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
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;

/**
 * Walks a publication through its four steps and holds it when it must wait.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md
 */
class PublicationProcessService {

	/**
	 * The four steps, in the order they are walked.
	 *
	 * @var array<int, string>
	 */
	public const STEPS = ['documents', 'zienswijze', 'approval', 'channels'];

	/**
	 * A step that has not been taken yet.
	 *
	 * @var string
	 */
	public const PENDING = 'pending';

	/**
	 * A step somebody completed.
	 *
	 * @var string
	 */
	public const COMPLETE = 'complete';

	/**
	 * A step a configuration turned off. Not the same as done.
	 *
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * Start the process for one publication, honouring the configuration.
	 *
	 * @param string $publicationId The publication.
	 * @param array<string, boolean> $enabledSteps Which steps this instance walks, keyed by step name.
	 *
	 * @return array<string, mixed> The process to save.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-publication-runs-as-a-walked-process-req-pin-104
	 */
	public function start(string $publicationId, array $enabledSteps = []): array {
		$steps = [];
		foreach (self::STEPS as $step) {
			$enabled = (bool)($enabledSteps[$step] ?? true);
			$steps[] = [
				'step' => $step,
				'status' => ($enabled === true ? self::PENDING : self::SKIPPED),
				'completedBy' => null,
				'completedAt' => null,
				'note' => ($enabled === true ? null : 'Configured off on this instance.'),
			];
		}

		return [
			'publication' => $publicationId,
			'state' => 'open',
			'steps' => $steps,
		];

	}//end start()

	/**
	 * Record that somebody completed a step.
	 *
	 * @param array<string, mixed> $process The process.
	 * @param string $step The step.
	 * @param string $completedBy Who completed it.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The process.
	 *
	 * @throws DomainException When the step is not one of the four, or is configured off.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-publication-runs-as-a-walked-process-req-pin-104
	 */
	public function complete(
		array $process,
		string $step,
		string $completedBy,
		?DateTimeInterface $now = null,
	): array {
		if (in_array($step, self::STEPS, true) === false) {
			throw new DomainException(message: 'A publication has no step called "' . $step . '".');
		}

		$moment = ($now === null)
			? new DateTimeImmutable('now', new DateTimeZone('UTC'))
			: DateTimeImmutable::createFromInterface($now);

		$found = false;
		foreach (($process['steps'] ?? []) as $index => $recorded) {
			if ((string)($recorded['step'] ?? '') !== $step) {
				continue;
			}

			if ((string)($recorded['status'] ?? '') === self::SKIPPED) {
				throw new DomainException(
					message: 'The step "' . $step . '" is configured off on this instance, so it cannot be completed.'
				);
			}

			$process['steps'][$index]['status'] = self::COMPLETE;
			$process['steps'][$index]['completedBy'] = $completedBy;
			$process['steps'][$index]['completedAt'] = $moment->format(DateTimeInterface::ATOM);
			$found = true;
			break;
		}

		if ($found === false) {
			throw new DomainException(message: 'This process does not carry the step "' . $step . '".');
		}

		$process['state'] = ($this->isComplete(process: $process) === true ? 'complete' : 'open');

		return $process;

	}//end complete()

	/**
	 * Whether every step is either completed or configured off.
	 *
	 * @param array<string, mixed> $process The process.
	 *
	 * @return boolean True when nothing is pending.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-publication-runs-as-a-walked-process-req-pin-104
	 */
	public function isComplete(array $process): bool {
		foreach (($process['steps'] ?? []) as $recorded) {
			if ((string)($recorded['status'] ?? '') === self::PENDING) {
				return false;
			}
		}

		return true;

	}//end isComplete()

	/**
	 * Whether the publication may advance, and what holds it if not.
	 *
	 * An unanswered ask inside its term holds the publication and is named. An
	 * ask whose term has passed does not hold it: the party was asked and did
	 * not answer, which is an answer the law accepts.
	 *
	 * @param array<string, mixed> $process The process.
	 * @param array<int, array<string, mixed>> $asks The zienswijze asks on this publication.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array{mayAdvance: boolean, heldBy: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-interested-parties-are-consulted-before-information-about-them-is-published-req-pin-105
	 */
	public function mayAdvance(array $process, array $asks, ?DateTimeInterface $now = null): array {
		$moment = ($now === null)
			? new DateTimeImmutable('now', new DateTimeZone('UTC'))
			: DateTimeImmutable::createFromInterface($now);

		$held = [];
		foreach ($asks as $ask) {
			if (trim((string)($ask['answeredAt'] ?? '')) !== '') {
				continue;
			}

			try {
				$termEnds = new DateTimeImmutable((string)($ask['termEndsAt'] ?? ''));
			} catch (\Throwable $e) {
				// An ask whose term cannot be read holds the publication. The
				// safe reading on a publication surface is the one that does
				// not publish.
				$held[] = ['party' => (string)($ask['party'] ?? ''), 'reason' => 'unreadable-term'];
				continue;
			}

			if ($moment <= $termEnds) {
				$held[] = [
					'party' => (string)($ask['party'] ?? ''),
					'reason' => 'open-ask',
					'termEndsAt' => $termEnds->format(DateTimeInterface::ATOM),
				];
			}
		}

		return [
			'mayAdvance' => ($held === []),
			'heldBy' => $held,
		];

	}//end mayAdvance()
}//end class

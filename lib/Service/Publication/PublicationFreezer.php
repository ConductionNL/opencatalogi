<?php

/**
 * Freezes a withdrawn publication through OpenRegister.
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
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/archive/2026-11-25-publication-withdrawal-aftercare/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Freezes one publication with a reason, through OpenRegister's archive
 * handler, and says whether it worked.
 *
 * The handler is resolved from the container on use (ADR-083), so an instance
 * without it answers "not frozen" with the reason instead of failing the
 * withdrawal that asked for the freeze.
 *
 * @spec openspec/changes/archive/2026-11-25-publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-is-frozen-with-the-withdrawal-reason-req-pwa-003
 */
class PublicationFreezer {

	/**
	 * OpenRegister's handler for archive and freeze.
	 *
	 * @var string
	 */
	public const ARCHIVE_HANDLER = 'OCA\OpenRegister\Service\Object\ArchiveHandler';

	/**
	 * OpenRegister's request naming the object to freeze and why.
	 */
	public const STATE_REQUEST = 'OCA\OpenRegister\Service\Object\ObjectStateRequest';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's archive handler.
	 * @param LoggerInterface $logger Logs a freeze that failed.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Freeze a publication with the reason it was withdrawn.
	 *
	 * @param string $publicationId The publication's uuid.
	 * @param string $reason The internal reason of the withdrawal.
	 *
	 * @return array{frozen: bool, frozenError?: string} Whether it is frozen, and why not.
	 *
	 * @spec openspec/changes/archive/2026-11-25-publication-withdrawal-aftercare/tasks.md#task-3.2
	 */
	public function freeze(string $publicationId, string $reason): array {
		if ($publicationId === '') {
			return ['frozen' => false, 'frozenError' => 'The withdrawal names no publication to freeze.'];
		}

		try {
			$answer = $this->container->get(self::ARCHIVE_HANDLER)->freeze(self::stateRequest(publicationId: $publicationId, reason: $reason));
		} catch (Throwable $e) {
			// The withdrawal stands either way: the publication is already off
			// the public side. Only the freeze is reported as not done.
			$this->logger->warning(
				'[PublicationFreezer] Publication "' . $publicationId . '" was withdrawn but not frozen: ' . $e->getMessage()
			);

			return ['frozen' => false, 'frozenError' => $e->getMessage()];
		}

		if (is_array($answer['frozen'] ?? null) === false) {
			return ['frozen' => false, 'frozenError' => 'OpenRegister did not report the publication as frozen.'];
		}

		return ['frozen' => true];
	}//end freeze()

	/**
	 * How long after the withdrawal date a freeze still counts as the
	 * withdrawal's own: the withdrawal saves the date, sends its withdrawals and
	 * then freezes, all in one request.
	 *
	 * @var int
	 */
	public const WITHDRAWAL_FREEZE_WINDOW_SECONDS = 600;

	/**
	 * Lift the freeze a withdrawal put on a publication, before it is published
	 * again (decision 25 Nov 2026: Publish again unfreezes, audited with the
	 * editor and the reason, then publishes).
	 *
	 * Only the withdrawal's own freeze is lifted: one set at or shortly after the
	 * publication's `depublicationDate`. A freeze set later or for another
	 * reason (a legal hold) stays, and the answer says so.
	 *
	 * @param array<string, mixed> $publication The publication as stored, with its `@self`.
	 * @param string               $reason      Why it is unfrozen; OpenRegister's audit trail keeps it with the acting user.
	 *
	 * @return array{unfrozen: bool, unfrozenError?: string} Whether it is (now) not frozen, and why not.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-a-withdrawn-publication-can-be-published-again-req-ppw-003
	 */
	public function liftWithdrawalFreeze(array $publication, string $reason): array {
		$frozen = $publication['@self']['frozen'] ?? null;
		if (is_array($frozen) === false || $frozen === []) {
			return ['unfrozen' => true];
		}

		$publicationId = (string)($publication['@self']['id'] ?? ($publication['id'] ?? ''));
		if ($publicationId === '' || self::isWithdrawalFreeze(frozen: $frozen, depublicationDate: $publication['depublicationDate'] ?? null) === false) {
			return [
				'unfrozen' => false,
				'unfrozenError' => 'The publication is frozen for another reason than its withdrawal; only that freeze is lifted by publishing again.',
			];
		}

		try {
			$answer = $this->container->get(self::ARCHIVE_HANDLER)->unfreezeWith(self::stateRequest(publicationId: $publicationId, reason: $reason));
		} catch (Throwable $e) {
			$this->logger->warning(
				'[PublicationFreezer] Publication "' . $publicationId . '" could not be unfrozen to publish it again: ' . $e->getMessage()
			);

			return ['unfrozen' => false, 'unfrozenError' => $e->getMessage()];
		}

		if (($answer['frozen'] ?? null) !== null) {
			return ['unfrozen' => false, 'unfrozenError' => 'OpenRegister still reports the publication as frozen.'];
		}

		return ['unfrozen' => true];
	}//end liftWithdrawalFreeze()

	/**
	 * Whether a freeze marker is the one the withdrawal set: stamped at or within
	 * the window after the withdrawal date.
	 *
	 * @param array<string, mixed> $frozen            OpenRegister's marker (`by`, `at`, `reason`).
	 * @param mixed                $depublicationDate The publication's withdrawal date.
	 *
	 * @return bool True when the withdrawal set it.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-a-withdrawn-publication-can-be-published-again-req-ppw-003
	 */
	private static function isWithdrawalFreeze(array $frozen, mixed $depublicationDate): bool {
		$frozenAt = strtotime((string)($frozen['at'] ?? ''));
		$withdrawnAt = strtotime((string)($depublicationDate ?? ''));
		if ($frozenAt === false || $withdrawnAt === false) {
			return false;
		}

		$after = ($frozenAt - $withdrawnAt);

		return $after >= -5 && $after <= self::WITHDRAWAL_FREEZE_WINDOW_SECONDS;
	}//end isWithdrawalFreeze()

	/**
	 * The request OpenRegister's freeze takes: the publication and the reason,
	 * with no register or schema scope (the handler resolves the object itself).
	 *
	 * @param string $publicationId The publication's uuid.
	 * @param string $reason        The internal reason of the withdrawal.
	 *
	 * @return object The ObjectStateRequest.
	 *
	 * @throws RuntimeException When the installed OpenRegister has no such request.
	 *
	 * @spec openspec/changes/archive/2026-11-25-publication-withdrawal-aftercare/tasks.md#task-3.2
	 */
	private static function stateRequest(string $publicationId, string $reason): object {
		$class = self::STATE_REQUEST;
		if (class_exists($class) === false) {
			throw new RuntimeException('The installed OpenRegister offers no object freeze request.');
		}

		return new $class(identifier: $publicationId, reason: $reason, register: null, schema: null);
	}//end stateRequest()
}//end class

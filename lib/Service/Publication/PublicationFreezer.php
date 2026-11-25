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

<?php

/**
 * RecordWooBatchRejectionAction
 *
 * The action OpenRegister runs on the `reject` transition of a Woo batch: it
 * records the reviewer's reason with who rejected the batch and when, and
 * assigns the batch back to its author (woo-review-surface REQ-WRV-002).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Lifecycle
 * @package  OCA\OpenCatalogi\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-review-surface/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Lifecycle;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCP\IUserSession;
use RuntimeException;

/**
 * Records a batch rejection and hands the batch back to its author.
 *
 * The `reject` transition declares the input `reason` (required), so the
 * engine has merged it into the payload before this action runs. The reason
 * moves into `rejections` as `{reason, by, at}`, `lastRejectionReason` keeps
 * it for the author's notification, and `assignee` becomes `createdBy`.
 *
 * @spec openspec/changes/woo-review-surface/tasks.md#task-1.1
 */
class RecordWooBatchRejectionAction implements LifecycleActionInterface {

	/**
	 * Who `by` names when no user is signed in (a task decided by the system).
	 *
	 * @var string
	 */
	public const SYSTEM = 'system';

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The reviewer who rejects.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Record the rejection on the batch payload.
	 *
	 * @param array<string, mixed> $objectData   The batch after `status` moved to `in_progress`, with `reason`.
	 * @param array<string, mixed> $previousData The batch before the transition.
	 * @param array<string, mixed> $parameters   Not read.
	 * @param string               $actionName   Not read.
	 *
	 * @return array<string, mixed> The batch with the rejection recorded.
	 *
	 * @throws RuntimeException When the reason is empty: a rejection always says why.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is OpenRegister's.
	 *
	 * @spec openspec/changes/woo-review-surface/tasks.md#task-1.1
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$reason = trim((string)($objectData['reason'] ?? ''));
		if ($reason === '') {
			throw new RuntimeException('A rejected Woo batch needs a reason.');
		}

		$rejections = [];
		if (is_array($objectData['rejections'] ?? null) === true) {
			$rejections = array_values($objectData['rejections']);
		}

		$rejections[] = [
			'reason' => $reason,
			'by' => $this->actor(),
			'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
		];

		unset($objectData['reason']);
		$objectData['rejections']          = $rejections;
		$objectData['lastRejectionReason'] = $reason;
		$objectData['assignee']            = (string)($objectData['createdBy'] ?? ($previousData['createdBy'] ?? ''));

		return $objectData;
	}//end execute()

	/**
	 * The signed-in reviewer, or `system`.
	 *
	 * @return string
	 */
	private function actor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return self::SYSTEM;
		}

		return $user->getUID();
	}//end actor()
}//end class

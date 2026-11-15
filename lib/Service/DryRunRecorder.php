<?php

/**
 * Dry-run recorder: holds an outbound hand-over in staging and keeps what it would have sent.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/instance-staging-mode
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCP\IAppConfig;
use OCP\IUserSession;
use Throwable;

/**
 * An outbound channel asks `hold()` before it sends (REQ-STG-002). When the
 * gate allows the channel, `hold()` answers null and the channel sends. When
 * it does not, `hold()` stores a `dryRunDelivery` (channel, payload, at,
 * user, reason) and answers the dry run, and the channel sends nothing.
 *
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-nothing-leaves-the-instance-in-staging-req-stg-002
 */
class DryRunRecorder {

	/**
	 * The schema a dry run is stored in.
	 */
	public const SCHEMA = 'dryRunDelivery';

	/**
	 * Constructor.
	 *
	 * @param OutboundGate                $gate         Whether a channel may send.
	 * @param OpenRegisterLookupInterface $openRegister Reaches OpenRegister's object service.
	 * @param IAppConfig                  $config       The publication register the record is stored in.
	 * @param IUserSession                $userSession  Who made the hand-over.
	 */
	public function __construct(
		private readonly OutboundGate $gate,
		private readonly OpenRegisterLookupInterface $openRegister,
		private readonly IAppConfig $config,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Hold a hand-over: null when the channel may send, else the stored dry run.
	 *
	 * @param string               $channel One of OutboundGate::CHANNELS.
	 * @param array<string, mixed> $payload What the channel would send.
	 *
	 * @return array{dryRun: true, channel: string, reason: string, stored: bool}|null
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-nothing-leaves-the-instance-in-staging-req-stg-002
	 */
	public function hold(string $channel, array $payload): ?array {
		$reason = $this->gate->reason(channel: $channel);
		if ($reason === null) {
			return null;
		}

		$record = [
			'channel' => $channel,
			'payload' => $payload,
			'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
			'user' => ($this->userSession->getUser()?->getUID() ?? ''),
			'reason' => $reason,
		];

		return ['dryRun' => true, 'channel' => $channel, 'reason' => $reason, 'stored' => $this->store(record: $record)];
	}//end hold()

	/**
	 * Store the dry run; false when OpenRegister cannot take it. Nothing is sent either way.
	 *
	 * @param array<string, mixed> $record The dryRunDelivery.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/instance-staging-mode/tasks.md#task-2.2
	 */
	private function store(array $record): bool {
		try {
			$this->openRegister->installedObjectService()->saveObject(
				object: $record,
				register: $this->config->getValueString(AppIdentity::APP_ID, 'publication_register', 'publication'),
				schema: self::SCHEMA,
			);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end store()
}//end class

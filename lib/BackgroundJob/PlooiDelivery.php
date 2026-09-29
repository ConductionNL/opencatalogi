<?php

/**
 * OpenCatalogi PLOOI delivery job.
 *
 * Runs one PLOOI delivery queued by PlooiDeliveryListener, outside the request
 * that published the publication, so PLOOI's latency and outages never reach
 * the editor's save.
 *
 * @category BackgroundJob
 * @package  OCA\OpenCatalogi\BackgroundJob
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
 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Service\Publication\PlooiDeliveryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Delivers one publication to PLOOI.
 *
 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
 */
class PlooiDelivery extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param PlooiDeliveryService $deliveryService The PLOOI delivery.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PlooiDeliveryService $deliveryService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Deliver the queued publication.
	 *
	 * @param mixed $argument `{uuid, register, schema}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
	 */
	protected function run($argument): void {
		if (is_array($argument) === false || (string)($argument['uuid'] ?? '') === '') {
			return;
		}

		try {
			$this->deliveryService->deliver(
				uuid: (string)$argument['uuid'],
				register: (string)($argument['register'] ?? ''),
				schema: (string)($argument['schema'] ?? '')
			);
		} catch (\Throwable $e) {
			$this->logger->error('[PlooiDelivery] Delivery of ' . (string)$argument['uuid'] . ' failed: ' . $e->getMessage());
		}

	}//end run()
}//end class

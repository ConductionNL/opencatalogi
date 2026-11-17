<?php

/**
 * Records a failure of the Woo publication pipeline as an incident a named group receives.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenCatalogi\Service\SchemaObjects;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates one `wooPipelineIncident` per failure. The schema's own notification rule
 * (declared, not dispatched here) tells the incident group by Nextcloud
 * notification and e-mail. An identical incident that is still open and was
 * raised in the last 24 hours is not created again, so a job that fails every
 * run does not flood the group.
 *
 * Raising never throws: a pipeline failure must not become a second failure in
 * the caller. When the incident cannot be stored, that is logged as an error.
 *
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
 */
class PipelineIncidents {
	use ReadsObjectProperties;

	/**
	 * How long an open incident absorbs identical ones, in seconds.
	 *
	 * @var integer
	 */
	public const COALESCE_SECONDS = 86400;

	/**
	 * The register config key.
	 *
	 * @var string
	 */
	public const REGISTER_KEY = 'woo_register';

	/**
	 * The schema config key.
	 *
	 * @var string
	 */
	public const SCHEMA_KEY = 'woo_pipeline_incident_schema';

	/**
	 * The incident kind of a failed PLOOI delivery or withdrawal.
	 *
	 * @var string
	 */
	public const KIND_PLOOI = 'plooi-delivery';

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface $openRegister OpenRegister's ObjectService and the register configuration.
	 * @param ITimeFactory                $time         The clock.
	 * @param LoggerInterface             $logger       Records an incident that could not be stored.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $openRegister,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The message of a failed PLOOI delivery or withdrawal, naming the publication and the reason.
	 *
	 * Both the delivery service and the national channel raise with it, so one
	 * failure coalesces into one incident.
	 *
	 * @param string $title      The publication's title.
	 * @param string $reason     The platform's or the transport's reason.
	 * @param bool   $withdrawal True for a withdrawal.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
	 */
	public static function plooiFailure(string $title, string $reason, bool $withdrawal = false): string {
		$what = 'PLOOI delivery';
		if ($withdrawal === true) {
			$what = 'PLOOI withdrawal';
		}

		return $what . " of '" . $title . "' failed: " . $reason;
	}//end plooiFailure()

	/**
	 * Raise an incident, unless an identical one is open and recent.
	 *
	 * @param string $kind    What failed, such as `plooi-delivery` or `diwoo-version`.
	 * @param string $subject What it failed for, such as a publication id or a catalogue slug.
	 * @param string $message The reason, as a person reads it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
	 */
	public function raise(string $kind, string $subject, string $message): void {
		try {
			$target = $this->target();
			if ($this->coalesced(target: $target, kind: $kind, subject: $subject, message: $message) === true) {
				return;
			}

			$target->save(
				object: [
					'kind' => $kind,
					'subject' => $subject,
					'message' => $message,
					'at' => $this->now()->format(DateTimeInterface::ATOM),
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[PipelineIncidents] a pipeline incident could not be stored: ' . $kind . ' ' . $subject . ': ' . $message,
				['exception' => $e->getMessage()]
			);
		}
	}//end raise()

	/**
	 * Whether an identical incident is open and was raised within the coalescing window.
	 *
	 * @param SchemaObjects $target  The incident objects.
	 * @param string        $kind    The kind.
	 * @param string        $subject The subject.
	 * @param string        $message The message.
	 *
	 * @return bool
	 */
	private function coalesced(SchemaObjects $target, string $kind, string $subject, string $message): bool {
		$found = $target->search(filters: ['kind' => $kind, 'subject' => $subject, '_limit' => 100]);
		$since = $this->now()->getTimestamp() - self::COALESCE_SECONDS;

		foreach ((array)($this->asRecord(object: $found)['results'] ?? []) as $row) {
			$incident = $this->asRecord(object: $row);
			if ((string)($incident['kind'] ?? '') !== $kind
				|| (string)($incident['subject'] ?? '') !== $subject
				|| (string)($incident['message'] ?? '') !== $message
				|| ($incident['resolvedAt'] ?? null) !== null
			) {
				continue;
			}

			$at = strtotime((string)($incident['at'] ?? ''));
			if ($at !== false && $at >= $since) {
				return true;
			}
		}

		return false;
	}//end coalesced()

	/**
	 * The incident objects in the configured register and schema.
	 *
	 * @return SchemaObjects
	 */
	private function target(): SchemaObjects {
		$ids = $this->openRegister->registerConfiguration(registerKey: self::REGISTER_KEY, schemaKey: self::SCHEMA_KEY);

		return new SchemaObjects(
			objects: $this->openRegister->installedObjectService(),
			target: [(string)$ids['register'], (string)$ids['schema']]
		);
	}//end target()

	/**
	 * The current moment.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('@' . $this->time->getTime());
	}//end now()
}//end class

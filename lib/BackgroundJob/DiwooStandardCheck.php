<?php

/**
 * Daily check of the DiWoo metadata version against the standard's authority.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCA\OpenCatalogi\Service\Woo\PipelineIncidents;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Asks the DiWoo version index which metadata version is current and stores the
 * answer as a readiness result. A newer version fails readiness, names both
 * versions and raises a pipeline incident; an authority that cannot be read is
 * "could not verify", never a pass.
 *
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-the-diwoo-output-is-validated-as-it-is-generated-against-the-current-standard-req-wno-002
 */
class DiwooStandardCheck extends TimedJob {

	/**
	 * The app config key that holds the last result.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'diwoo_standard_check';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory            $time      The clock.
	 * @param StandardsVersionService $standards Reads the published DiWoo version.
	 * @param IAppConfig              $config    Stores the result.
	 * @param PipelineIncidents       $incidents Tells the incident group about a superseded version.
	 * @param LoggerInterface         $logger    The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly StandardsVersionService $standards,
		private readonly IAppConfig $config,
		private readonly PipelineIncidents $incidents,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Read the current version and store the readiness result.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-the-diwoo-output-is-validated-as-it-is-generated-against-the-current-standard-req-wno-002
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The job signature; the job takes no argument.
	 */
	protected function run($argument): void {
		$result = $this->check();
		if ($result['ready'] === false) {
			$this->logger->warning('[DiwooStandardCheck] ' . $result['message']);
		}

		if ($result['status'] === StandardsVersionService::STATUS_BEHIND) {
			$this->incidents->raise(kind: 'diwoo-version', subject: 'diwoo', message: $result['message']);
		}
	}//end run()

	/**
	 * Compare the emitted version with the authority's and store the result.
	 *
	 * @return array{declared: string, published: string|null, status: string, ready: bool, message: string, checkedAt: int} The stored result.
	 *
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-the-diwoo-output-is-validated-as-it-is-generated-against-the-current-standard-req-wno-002
	 */
	public function check(): array {
		$version = $this->standards->diwooVersion();
		$declared = (string)$version['declared'];
		$published = $version['published'];

		$ready = false;
		$message = 'could not verify';
		if ($version['status'] === StandardsVersionService::STATUS_BEHIND) {
			$message = 'DiWoo ' . (string)$published . ' is current; this instance emits ' . $declared;
		}

		if ($version['status'] === StandardsVersionService::STATUS_CURRENT) {
			$ready = true;
			$message = 'DiWoo ' . $declared . ' is current';
		}

		$result = [
			'declared' => $declared,
			'published' => $published,
			'status' => (string)$version['status'],
			'ready' => $ready,
			'message' => $message,
			'checkedAt' => $this->time->getTime(),
		];

		$this->config->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, (string)json_encode($result));

		return $result;
	}//end check()
}//end class

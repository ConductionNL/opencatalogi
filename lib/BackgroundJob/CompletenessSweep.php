<?php

/**
 * Every fifteen minutes, re-evaluates the publications whose dates passed.
 *
 * A publication that becomes public by date is not written, so nothing else
 * evaluates its completeness then. This job does, for the publications whose
 * publication or depublication date passed since its previous run
 * (publication-tells-its-source, REQ-PTS-002).
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
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenCatalogi\Service\Publication\CompletenessSweepService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Sweeps the window since its previous run and remembers where it got to.
 *
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
 */
class CompletenessSweep extends TimedJob {

	/**
	 * The app config key holding the moment of the previous sweep.
	 *
	 * @var string
	 */
	public const LAST_RUN_KEY = 'completeness_sweep_at';

	/**
	 * How far back the first sweep looks, in seconds.
	 *
	 * @var integer
	 */
	public const FIRST_WINDOW = 900;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time   The clock.
	 * @param CompletenessSweepService $sweeps The sweep.
	 * @param IAppConfig               $config Remembers the previous sweep.
	 * @param LoggerInterface          $logger Records the outcome.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CompletenessSweepService $sweeps,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 900);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Sweep the window since the previous run, then move the mark to now.
	 *
	 * A failed sweep leaves the mark where it was, so the next run covers the window again.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
	 */
	protected function run($argument): void {
		$now   = DateTimeImmutable::createFromInterface($this->time->getDateTime());
		$since = $now->modify('-' . self::FIRST_WINDOW . ' seconds');
		$mark  = $this->config->getValueString('opencatalogi', self::LAST_RUN_KEY, '');
		if ($mark !== '') {
			try {
				$since = new DateTimeImmutable($mark);
			} catch (\Exception) {
				// An unreadable mark: sweep the first window again.
			}
		}

		try {
			$counts = $this->sweeps->sweep(since: $since, now: $now);
		} catch (\Throwable $e) {
			$this->logger->error('[CompletenessSweep] The sweep failed: ' . $e->getMessage(), ['exception' => $e]);
			return;
		}

		$this->config->setValueString('opencatalogi', self::LAST_RUN_KEY, $now->format(DateTimeInterface::ATOM));
		$this->logger->info('[CompletenessSweep] The sweep is complete', $counts);
	}//end run()
}//end class

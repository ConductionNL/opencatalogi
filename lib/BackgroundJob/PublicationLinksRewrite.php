<?php

/**
 * Rewrites every publication's public and officer pages once, after a link setting changed.
 *
 * Queued by SettingsController when `publication_url_template` or
 * `publication_url_catalog` changes (publication-tells-its-source, REQ-PTS-001).
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
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\BackgroundJob;

use OCA\OpenCatalogi\Service\Publication\PublicationLinksRewriteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One rewrite pass over every publication.
 *
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
 */
class PublicationLinksRewrite extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                   $time     The clock.
	 * @param PublicationLinksRewriteService $rewrites Runs the pass.
	 * @param LoggerInterface                $logger   Records the outcome.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PublicationLinksRewriteService $rewrites,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the pass.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
	 */
	protected function run($argument): void {
		try {
			$counts = $this->rewrites->rewrite();
		} catch (\Throwable $e) {
			$this->logger->error('[PublicationLinksRewrite] The rewrite failed: ' . $e->getMessage(), ['exception' => $e]);
			return;
		}

		$this->logger->info('[PublicationLinksRewrite] The publication pages are rewritten', $counts);
	}//end run()
}//end class

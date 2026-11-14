<?php

/**
 * The comment period a reader sees on a publication.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeInterface;
use DomainException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Adds `commentPeriod` to a publication a reader already may read.
 *
 * Comment periods are admin-only objects, so the period is read without RBAC,
 * and only for a publication the caller has already been given. What is added
 * is `CommentPeriodService::publicView()`, which carries the reaction form only
 * while the period is open. Dates that cannot be read give
 * `commentPeriodError: "unreadable-dates"` instead of a state. A period that
 * cannot be looked up adds nothing.
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-the-publication-carries-its-comment-period-s-state-req-pdp-003
 */
class PublicationCommentPeriod {

	/**
	 * Constructor.
	 *
	 * @param CommentPeriodService $periods   The period states and public view.
	 * @param IAppConfig           $config    Holds the register and the comment period schema.
	 * @param ContainerInterface   $container Server container, for the consumed OR ObjectService.
	 * @param LoggerInterface      $logger    Logger.
	 */
	public function __construct(
		private readonly CommentPeriodService $periods,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The publication with its comment period, when it has one.
	 *
	 * @param array<string, mixed>   $publication The publication as answered to the reader.
	 * @param DateTimeInterface|null $now         The moment; defaults to now.
	 *
	 * @return array<string, mixed> The publication, with `commentPeriod` or `commentPeriodError` when it has a period.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#3.1
	 */
	public function addTo(array $publication, ?DateTimeInterface $now = null): array {
		$id = self::publicationId(publication: $publication);
		if ($id === '') {
			return $publication;
		}

		$period = $this->find(publicationId: $id);
		if ($period === null) {
			return $publication;
		}

		try {
			$publication['commentPeriod'] = $this->periods->publicView(period: $period, now: $now);
		} catch (DomainException $e) {
			$publication['commentPeriodError'] = 'unreadable-dates';
		}

		return $publication;

	}//end addTo()

	/**
	 * The id of a rendered publication: its `id`, else `@self.id`.
	 *
	 * @param array<string, mixed> $publication The publication.
	 *
	 * @return string The id, or '' when it has none.
	 */
	private static function publicationId(array $publication): string {
		$id = ($publication['id'] ?? null);
		if (is_string($id) === false || $id === '') {
			$id = ($publication['@self']['id'] ?? null);
		}

		if (is_string($id) === false && is_int($id) === false) {
			return '';
		}

		return (string) $id;

	}//end publicationId()

	/**
	 * The period of a publication, as an array.
	 *
	 * @param string $publicationId The publication id.
	 *
	 * @return array<string, mixed>|null The period, or null when it has none or none could be read.
	 */
	private function find(string $publicationId): ?array {
		$register = trim($this->config->getValueString('opencatalogi', 'publication_register', ''));
		$schema = trim($this->config->getValueString('opencatalogi', 'comment_period_schema', ''));
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			// The period is admin-only; the publication it belongs to was read with the caller's rights.
			$result = $objectService->searchObjectsPaginated(
				query: [
					'@self' => ['register' => $register, 'schema' => $schema],
					'publication' => $publicationId,
					'_limit' => 1,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning('[PublicationCommentPeriod] The comment period could not be read: ' . $e->getMessage());

			return null;
		}

		$first = (((array) ($result['results'] ?? []))[0] ?? null);
		if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {
			$first = $first->jsonSerialize();
		}

		if (is_array($first) === false || (string) ($first['publication'] ?? '') !== $publicationId) {
			return null;
		}

		return $first;

	}//end find()
}//end class

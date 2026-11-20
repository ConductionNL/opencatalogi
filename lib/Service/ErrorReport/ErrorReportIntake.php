<?php

/**
 * Takes a visitor's error report on a publication of a catalogue that accepts them.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\ErrorReport
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
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-take-error-reports-from-anyone-throttled-and-moderated-req-pdp-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\ErrorReport;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\ErrorReportIntakeInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Publication\DocumentFacts;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stores an `errorReport` for a publicly readable publication.
 *
 * The publication is looked up inside OpenRegister's anonymous scope, through
 * each catalogue's read-rule guard. Only a catalogue with `acceptErrorReports`
 * true and at least one group in `errorReportModerators` takes a report;
 * everything else answers 404 before the input is even read, so the route
 * tells a visitor nothing about catalogues that did not opt in. The report
 * holds the moderator groups so the notification rule can reach them, and
 * never an IP address.
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-take-error-reports-from-anyone-throttled-and-moderated-req-pdp-005
 *
 * @SuppressWarnings(PHPMD.StaticAccess) DocumentFacts::isLive() is a pure function.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Reads catalogues and publications and writes one OpenRegister object.
 */
class ErrorReportIntake implements ErrorReportIntakeInterface {

	/**
	 * The longest message taken, in characters.
	 */
	public const MAX_MESSAGE = 2000;

	/**
	 * Constructor.
	 *
	 * @param CatalogiServiceInterface         $catalogs     The catalogues.
	 * @param PublicationQueryServiceInterface $query        The catalogue read-rule guard and the scoped object read.
	 * @param OpenRegisterLookup               $openRegister Reaches OpenRegister's object service.
	 * @param IAppConfig                       $config       Holds the catalogue and error report registers and schemas.
	 * @param LoggerInterface                  $logger       Logger.
	 */
	public function __construct(
		private readonly CatalogiServiceInterface $catalogs,
		private readonly PublicationQueryServiceInterface $query,
		private readonly OpenRegisterLookup $openRegister,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Store a visitor's error report on a publication.
	 *
	 * @param string $publicationId The publication.
	 * @param mixed  $message       The report text as posted.
	 * @param mixed  $contact       The optional e-mail address as posted.
	 *
	 * @return array{status: int, error?: string, reference?: string} The outcome.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#5.1
	 */
	public function submit(string $publicationId, mixed $message, mixed $contact): array {
		try {
			$objects = $this->openRegister->installedObjectService();
			$target = $this->acceptingCatalog(objects: $objects, publicationId: $publicationId);
		} catch (Throwable $e) {
			// Fail closed: a publication that cannot be read takes no report.
			$this->logger->warning('OpenCatalogi: no error report taken', ['publication' => $publicationId, 'error' => $e->getMessage()]);
			return ['status' => 404, 'error' => 'unknown-publication'];
		}

		if ($target === null) {
			return ['status' => 404, 'error' => 'unknown-publication'];
		}

		$refusal = self::refusal(message: $message, contact: $contact);
		if ($refusal !== null) {
			return ['status' => 400, 'error' => $refusal];
		}

		$register = trim($this->config->getValueString('opencatalogi', 'publication_register', ''));
		$schema = trim($this->config->getValueString('opencatalogi', 'error_report_schema', ''));
		if ($register === '' || $schema === '') {
			return ['status' => 503, 'error' => 'not-configured'];
		}

		$report = [
			'publication' => $publicationId,
			'catalog' => $target['catalog'],
			'message' => trim((string) $message),
			'status' => 'new',
			'createdAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'moderators' => $target['moderators'],
		];
		if (is_string($contact) === true && trim($contact) !== '') {
			$report['contact'] = trim($contact);
		}

		$saved = $objects->saveObject(
			object: $report,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema)),
			_rbac: false,
			_multitenancy: false
		);

		return ['status' => 201, 'reference' => self::referenceOf(saved: $saved)];

	}//end submit()

	/**
	 * Why a posted report is refused, or null when it is taken.
	 *
	 * @param mixed $message The report text.
	 * @param mixed $contact The optional e-mail address.
	 *
	 * @return string|null The error code.
	 */
	private static function refusal(mixed $message, mixed $contact): ?string {
		if (is_string($message) === false || trim($message) === '') {
			return 'message-required';
		}

		if (mb_strlen(trim($message)) > self::MAX_MESSAGE) {
			return 'message-too-long';
		}

		if ($contact === null || $contact === '') {
			return null;
		}

		if (is_string($contact) === false || filter_var(trim($contact), FILTER_VALIDATE_EMAIL) === false) {
			return 'invalid-contact';
		}

		return null;

	}//end refusal()

	/**
	 * The first catalogue that holds the live publication and takes reports on it.
	 *
	 * @param object $objects       OpenRegister's ObjectService.
	 * @param string $publicationId The publication.
	 *
	 * @return array{catalog: string, moderators: list<string>}|null The catalogue id and its moderator groups, or null.
	 */
	private function acceptingCatalog(object $objects, string $publicationId): ?array {
		foreach ($this->catalogs->getCatalogSlugs() as $slug) {
			$catalog = $this->catalogs->getCatalogBySlug((string) $slug);
			if ($catalog === null || ($catalog['acceptErrorReports'] ?? false) !== true) {
				continue;
			}

			$catalogId = (string) ($catalog['id'] ?? $catalog['@self']['id'] ?? '');
			$moderators = $this->moderatorsOf(objects: $objects, catalogId: $catalogId);
			if ($moderators === []) {
				continue;
			}

			if ($this->isPublicIn(objects: $objects, catalog: $catalog, publicationId: $publicationId) === true) {
				return ['catalog' => $catalogId, 'moderators' => $moderators];
			}
		}

		return null;

	}//end acceptingCatalog()

	/**
	 * Whether an anonymous reader reads the publication, published, through the catalogue.
	 *
	 * @param object               $objects       OpenRegister's ObjectService.
	 * @param array<string, mixed> $catalog       The catalogue.
	 * @param string               $publicationId The publication.
	 *
	 * @return bool True when it does.
	 */
	private function isPublicIn(object $objects, array $catalog, string $publicationId): bool {
		$read = fn (): ?object => $this->query->findObjectInCatalog(
			catalog: $this->query->applyCatalogReadRuleGuard(catalog: $catalog),
			id: $publicationId,
			objectService: $objects
		);
		$entity = null;
		if (method_exists($objects, 'runAsAnonymous') === true) {
			$entity = $objects->runAsAnonymous($read);
		}

		if (method_exists($objects, 'runAsAnonymous') === false) {
			$entity = $read();
		}

		if (is_object($entity) === false || method_exists($entity, 'jsonSerialize') === false) {
			return false;
		}

		$publication = $entity->jsonSerialize();

		return is_array($publication) === true && DocumentFacts::isLive(publication: $publication, now: new DateTimeImmutable());

	}//end isPublicIn()

	/**
	 * The catalogue's moderator groups, read past the property's read rule.
	 *
	 * The groups are hidden from anonymous readers of the catalogue, so they are
	 * read here with the catalogue register and schema and without RBAC.
	 *
	 * @param object $objects   OpenRegister's ObjectService.
	 * @param string $catalogId The catalogue.
	 *
	 * @return list<string> The group ids; empty when none is named or the catalogue cannot be read.
	 */
	private function moderatorsOf(object $objects, string $catalogId): array {
		$register = trim($this->config->getValueString('opencatalogi', 'catalog_register', ''));
		$schema = trim($this->config->getValueString('opencatalogi', 'catalog_schema', ''));
		if ($catalogId === '' || $register === '' || $schema === '') {
			return [];
		}

		$entity = $objects->find(
			id: $catalogId,
			options: new ObjectFindOptions(register: $register, schema: $schema),
			_rbac: false,
			_multitenancy: false
		);
		$data = [];
		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			$data = $entity->getObject();
		}

		$groups = [];
		foreach ((array) ($data['errorReportModerators'] ?? []) as $group) {
			if (is_string($group) === true && trim($group) !== '') {
				$groups[] = trim($group);
			}
		}

		return array_values(array_unique($groups));

	}//end moderatorsOf()

	/**
	 * The reference of a stored report: its uuid.
	 *
	 * @param mixed $saved What OpenRegister answered.
	 *
	 * @return string The reference.
	 */
	private static function referenceOf(mixed $saved): string {
		$data = [];
		if (is_array($saved) === true) {
			$data = $saved;
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			$data = $saved->jsonSerialize();
		}

		return (string) ($data['@self']['id'] ?? $data['id'] ?? $data['uuid'] ?? '');

	}//end referenceOf()
}//end class

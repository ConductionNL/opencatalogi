<?php

/**
 * OpenCatalogi repair step: move a document type out of the summary.
 *
 * Before the publication had a document type field, a hand-off wrote the DiWoo
 * documentsoort into `summary`, so the summary of such a publication reads
 * `besluit` or similar and the type was lost to the sitemap. This step moves a
 * summary that is exactly a member of the national list of document types into
 * `documentsoort` and empties the summary. Any other summary, and any
 * publication that already has a document type, is left as it is. It also
 * records, once, the moment the DiWoo fields were introduced: the sitemap falls
 * back to the old derivation only for records created before it.
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
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
 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-documentsoort-written-into-the-summary-is-moved-to-its-field-req-dwp-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Contract\DocumentTypeVocabularyInterface;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Moves a documentsoort written into the summary to its own field (REQ-DWP-005).
 *
 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-documentsoort-written-into-the-summary-is-moved-to-its-field-req-dwp-005
 */
class MoveDocumentsoortOutOfSummary implements IRepairStep {

	/**
	 * The slug of the publication schema in every register.
	 */
	private const PUBLICATION_SLUG = 'publication';

	/**
	 * Publications read per page.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * The app-config key the sitemap reads ({@see \OCA\OpenCatalogi\Service\SitemapDocumentMapper::FIELDS_INTRODUCED_AT}).
	 */
	private const FIELDS_INTRODUCED_AT = 'diwoo_fields_introduced_at';

	/**
	 * OpenRegister's object service, for the run in progress.
	 *
	 * @var object|null
	 */
	private ?object $objects = null;

	/**
	 * The running counts of the run in progress.
	 *
	 * @var array{moved: int, left: int, failed: int}
	 */
	private array $counts = ['moved' => 0, 'left' => 0, 'failed' => 0];

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settings Reaches OpenRegister's registers and object service.
	 * @param DocumentTypeVocabularyInterface $vocabulary The national list of document types.
	 * @param IAppConfig $appConfig Holds the moment the DiWoo fields were introduced.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settings,
		private readonly DocumentTypeVocabularyInterface $vocabulary,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-documentsoort-written-into-the-summary-is-moved-to-its-field-req-dwp-005
	 */
	public function getName(): string {
		return 'Move a document type written into the summary of a publication to its own field';
	}//end getName()

	/**
	 * Record when the DiWoo fields were introduced, then move every summary that is a document type.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-documentsoort-written-into-the-summary-is-moved-to-its-field-req-dwp-005
	 */
	public function run(IOutput $output): void {
		$this->recordIntroduction();

		$objects = $this->settings->getObjectService();
		if ($objects === null) {
			$output->info('MoveDocumentsoortOutOfSummary: OpenRegister is not available; nothing to do.');
			return;
		}

		$this->objects = $objects;
		$this->counts = ['moved' => 0, 'left' => 0, 'failed' => 0];
		foreach ($this->publicationScopes() as [$register, $schema]) {
			$this->moveInScope(scope: ['register' => $register, 'schema' => $schema]);
		}

		$counts = $this->counts;
		$output->info(
			'MoveDocumentsoortOutOfSummary: ' . $counts['moved'] . ' publication(s) moved, '
			. $counts['left'] . ' summary(ies) left, ' . $counts['failed'] . ' could not be saved.'
		);

	}//end run()

	/**
	 * Write the introduction moment once; a later run never moves it.
	 *
	 * @return void
	 */
	private function recordIntroduction(): void {
		$key = self::FIELDS_INTRODUCED_AT;
		if ($this->appConfig->getValueString('opencatalogi', $key, '') !== '') {
			return;
		}

		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
		$this->appConfig->setValueString('opencatalogi', $key, $now);
	}//end recordIntroduction()

	/**
	 * Every register and schema pair whose schema is the publication schema.
	 *
	 * @return array<int, array{0: string, 1: string}> The pairs.
	 */
	private function publicationScopes(): array {
		$scopes = [];
		foreach (($this->settings->getSettings()['availableRegisters'] ?? []) as $register) {
			foreach ((array)($register['schemas'] ?? []) as $schema) {
				if (self::isPublicationSchemaRow(schema: $schema) === true) {
					$scopes[] = [(string)($register['id'] ?? ''), (string)($schema['id'] ?? '')];
				}
			}
		}

		return array_values(array_filter($scopes, static fn (array $scope): bool => $scope[0] !== '' && $scope[1] !== ''));
	}//end publicationScopes()

	/**
	 * Whether a register's schema entry is the publication schema.
	 *
	 * @param mixed $schema The schema entry.
	 *
	 * @return bool True for the publication schema.
	 */
	private static function isPublicationSchemaRow(mixed $schema): bool {
		return is_array($schema) === true && strtolower((string)($schema['slug'] ?? '')) === self::PUBLICATION_SLUG;
	}//end isPublicationSchemaRow()

	/**
	 * Move the summaries of every publication in one register and schema, page by page.
	 *
	 * @param array{register: string, schema: string} $scope The register and schema.
	 *
	 * @return void
	 */
	private function moveInScope(array $scope): void {
		$page = 1;
		do {
			$rows = (array)($this->objects->searchObjectsPaginated(
				query: ['@self' => $scope, '_limit' => self::PAGE_SIZE, '_page' => $page],
				_rbac: false,
				_multitenancy: false
			)['results'] ?? []);
			foreach ($rows as $row) {
				$this->moveOne(publication: self::asArray(row: $row), scope: $scope);
			}

			$page++;
		} while (count($rows) === self::PAGE_SIZE);

	}//end moveInScope()

	/**
	 * Move one publication's summary when it is exactly a document type and no type is stored.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param array{register: string, schema: string} $scope The register and schema.
	 *
	 * @return void
	 */
	private function moveOne(array $publication, array $scope): void {
		$summary = trim((string)($publication['summary'] ?? ''));
		$stored = trim((string)($publication['documentsoort'] ?? ''));
		if ($summary === '') {
			return;
		}

		$member = $this->vocabulary->resolveDocumentsoort($summary);
		if ($member === null || $stored !== '') {
			$this->counts['left']++;
			return;
		}

		$uuid = (string)($publication['id'] ?? ($publication['@self']['id'] ?? ''));
		unset($publication['@self']);
		$publication['documentsoort'] = $member['uri'];
		$publication['summary'] = '';
		try {
			$this->objects->saveObject(
				object: $publication,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $scope['register'], schema: $scope['schema']),
					uuid: $uuid
				),
				_rbac: false,
				_multitenancy: false
			);
			$this->counts['moved']++;
		} catch (\Throwable $e) {
			$this->counts['failed']++;
			$this->logger->warning('MoveDocumentsoortOutOfSummary: publication ' . $uuid . ' could not be saved: ' . $e->getMessage());
		}

	}//end moveOne()

	/**
	 * A search row as an array.
	 *
	 * @param mixed $row An array or a serialisable entity.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function asArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return (array)$row;
	}//end asArray()
}//end class

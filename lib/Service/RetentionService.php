<?php

/**
 * Service for the publication retention lifecycle.
 *
 * Implements the temporal/retention policy layer described in
 * openspec/changes/publication-retention-lifecycle. Per hydra ADR-022 this
 * service builds NO scheduler, NO state machine and NO audit store: scheduling
 * is the OpenRegister published-predicate over publicationDate/depublicationDate,
 * the published -> archived transition is declared in the schema's
 * x-openregister-lifecycle, notifications are schema-declared
 * (x-openregister-notifications), and every mutation is audited by OpenRegister's
 * immutable audit trail. The only in-app moving part is the daily evaluation pass
 * (RetentionEvaluation cron), which this service drives as a dumb evaluator of
 * stored fields and configured policy.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenCatalogi\Contract\RetentionServiceInterface;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drives the publication retention lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods) The lifecycle steps are small private methods; the public surface is the retention contract.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) Lifecycle steps are split into small documented
 *   private methods; the docblocks of those steps push the class past the line threshold.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md
 */
class RetentionService implements RetentionServiceInterface {
	use ReadsObjectProperties;

	/**
	 * Config key holding the per-catalog retention defaults (JSON).
	 *
	 * @var string
	 */
	public const CONFIG_DEFAULTS = 'retention_defaults';

	/**
	 * Config key holding the warning window in days.
	 *
	 * @var string
	 */
	public const CONFIG_WARNING_WINDOW = 'retention_warning_window_days';

	/**
	 * Default warning window in days (catalog-configurable).
	 *
	 * @var int
	 */
	public const DEFAULT_WARNING_WINDOW = 30;

	/**
	 * Valid retention actions.
	 *
	 * @var array<int, string>
	 */
	public const ACTIONS = ['review', 'depublish', 'archive'];

	/**
	 * The columns of the retention report CSV, in order.
	 *
	 * @var array<int, string>
	 */
	private const REPORT_COLUMNS = [
		'id',
		'title',
		'publicationDate',
		'retentionCategory',
		'retentionTermMonths',
		'retentionExpiresAt',
		'status',
		'actionTaken',
		'decisionBy',
		'decisionAt',
		'decisionNote',
	];

	/**
	 * Cached OpenRegister ObjectService instance.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * Hands archived publications to the e-depot and reads back how it went.
	 *
	 * @var EdepotHandover
	 */
	private EdepotHandover $edepot;

	/**
	 * Cached OpenRegister DMN DecisionTableEvaluator instance.
	 *
	 * @var object|null
	 */
	private ?object $dmnEvaluator = null;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App configuration.
	 * @param ContainerInterface $container Server container for resolving OpenRegister.
	 * @param IUserSession $userSession Current user session (decision attribution).
	 * @param LoggerInterface $logger Logger.
	 * @param RetentionPolicyTable $policyTable Translates stored defaults into the DMN table shape.
	 * @param EdepotHandover|null $edepot Hands archived publications to the e-depot; built from the container when null.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly RetentionPolicyTable $policyTable = new RetentionPolicyTable(),
		?EdepotHandover $edepot = null,
	) {
		$this->edepot = ($edepot ?? new EdepotHandover(container: $container, logger: $logger));

	}//end __construct()

	/**
	 * Resolve the OpenRegister ObjectService from the container.
	 *
	 * @return object|null The ObjectService, or null when OpenRegister is unavailable.
	 *
	 * @spec exclude pure framework plumbing — resolves the consumed OR ObjectService.
	 */
	public function getObjectService(): ?object {
		if ($this->objectService === null) {
			try {
				$this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			} catch (\Throwable $e) {
				$this->logger->warning('[RetentionService] OpenRegister ObjectService unavailable: ' . $e->getMessage());
				return null;
			}
		}

		return $this->objectService;
	}//end getObjectService()

	/**
	 * Resolve the shared OpenRegister DMN decision-table evaluator.
	 *
	 * The RET-004 category match is delegated to this evaluator (hydra
	 * ADR-065, One Engine): opencatalogi translates its stored defaults into
	 * a decision table and never matches rules itself. Same availability
	 * guard as {@see getObjectService()}: unresolvable means "no defaults
	 * configured", logged, never guessed around with an app-local matcher.
	 *
	 * @return object|null The DecisionTableEvaluator, or null when OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/retention-defaults-on-shared-decision-tables/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	public function getDecisionTableEvaluator(): ?object {
		if ($this->dmnEvaluator === null) {
			try {
				$this->dmnEvaluator = $this->container->get('OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator');
			} catch (\Throwable $e) {
				$this->logger->warning('[RetentionService] OpenRegister DecisionTableEvaluator unavailable: ' . $e->getMessage());
				return null;
			}
		}

		return $this->dmnEvaluator;
	}//end getDecisionTableEvaluator()

	/**
	 * Resolve the configured publication register identifier.
	 *
	 * @return string|null The register id/uuid, or null when unconfigured.
	 *
	 * @spec exclude configuration plumbing.
	 */
	private function getRegister(): ?string {
		$value = (string)$this->config->getValueString('opencatalogi', 'publication_register', '');
		if ($value === '') {
			return null;
		}

		return $value;
	}//end getRegister()

	/**
	 * Resolve the configured publication schema identifier.
	 *
	 * @return string|null The schema id/uuid, or null when unconfigured.
	 *
	 * @spec exclude configuration plumbing.
	 */
	private function getSchema(): ?string {
		$value = (string)$this->config->getValueString('opencatalogi', 'publication_schema', '');
		if ($value === '') {
			return null;
		}

		return $value;
	}//end getSchema()

	/**
	 * Get the configured warning window in days (default 30).
	 *
	 * @return int The warning window in days.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	public function getWarningWindowDays(): int {
		$value = (int)$this->config->getValueString('opencatalogi', self::CONFIG_WARNING_WINDOW, (string)self::DEFAULT_WARNING_WINDOW);
		if ($value <= 0) {
			return self::DEFAULT_WARNING_WINDOW;
		}

		return $value;
	}//end getWarningWindowDays()

	/**
	 * Get the per-catalog retention defaults configuration.
	 *
	 * Shape: { "<catalogSlug>": { "<woo-category>": { "termMonths": int,
	 * "action": string }, "_fallback": { ... } }, ... }.
	 *
	 * @return array<string, mixed> The decoded defaults map (possibly empty).
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	public function getRetentionDefaults(): array {
		$raw = (string)$this->config->getValueString('opencatalogi', self::CONFIG_DEFAULTS, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end getRetentionDefaults()

	/**
	 * Store the per-catalog retention defaults configuration.
	 *
	 * @param array<string, mixed> $defaults The defaults map to persist.
	 *
	 * @return array<string, mixed> The stored defaults.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	public function setRetentionDefaults(array $defaults): array {
		(new RetentionRules())->assertValidDefaults(defaults: $defaults);
		$this->config->setValueString('opencatalogi', self::CONFIG_DEFAULTS, json_encode($defaults));
		return $this->getRetentionDefaults();
	}//end setRetentionDefaults()

	/**
	 * Compute the retention expiry from a publication date and term.
	 *
	 * @param string|null $publicationDate The ISO-8601 publication date.
	 * @param int|null $termMonths The retention term in months.
	 *
	 * @return string|null The computed expiry as ISO-8601, or null when not computable.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-metadata-on-the-publication-schema-ret-003
	 */
	public function computeExpiry(?string $publicationDate, ?int $termMonths): ?string {
		return (new RetentionRules())->expiry(publicationDate: $publicationDate, termMonths: $termMonths);
	}//end computeExpiry()

	/**
	 * Apply per-catalog retention defaults to a publication that lacks them.
	 *
	 * Already-set retention values are never overwritten (RET-004). Returns the
	 * publication array with any newly-applied retention fields and a recomputed
	 * retentionExpiresAt; the caller persists it. The category-to-default match
	 * is delegated to OpenRegister's shared DMN decision-table evaluator (hydra
	 * ADR-065, One Engine): the stored defaults become a FIRST-policy table and
	 * this service never matches rules itself.
	 *
	 * @param array<string, mixed> $publication The publication object data.
	 * @param string $catalogSlug The catalog slug the publication belongs to.
	 *
	 * @return array<string, mixed> The publication with defaults applied.
	 *
	 * @spec openspec/changes/retention-defaults-on-shared-decision-tables/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	public function applyDefaults(array $publication, string $catalogSlug): array {
		$decisionTable = $this->catalogDecisionTable(catalogSlug: $catalogSlug);
		if ($decisionTable === null) {
			return $publication;
		}

		$outputs = $this->evaluateDefaults(decisionTable: $decisionTable, category: (string)($publication['retentionCategory'] ?? ''));
		if ($outputs === null) {
			return $publication;
		}

		return $this->withComputedExpiry(publication: self::withDefaultOutputs(publication: $publication, outputs: $outputs));
	}//end applyDefaults()

	/**
	 * The catalog's retention defaults as a FIRST-policy decision table.
	 *
	 * @param string $catalogSlug The catalog slug.
	 *
	 * @return array<string, mixed>|null The decision table, or null when nothing usable is configured.
	 *
	 * @spec exclude Internal table step of applyDefaults().
	 */
	private function catalogDecisionTable(string $catalogSlug): ?array {
		$catalogDefaults = ($this->getRetentionDefaults()[$catalogSlug] ?? []);
		if (is_array($catalogDefaults) === false) {
			return null;
		}

		return $this->policyTable->fromDefaults(catalogDefaults: $catalogDefaults);
	}//end catalogDecisionTable()

	/**
	 * The winning row's outputs for a category, from OpenRegister's evaluator.
	 *
	 * No matching row, an unavailable evaluator or a failed evaluation all mean
	 * no defaults; only the failure is logged.
	 *
	 * @param array<string, mixed> $decisionTable The decision table.
	 * @param string $category The publication's retention category.
	 *
	 * @return array<string, mixed>|null The outputs, or null for no defaults.
	 *
	 * @spec exclude Internal evaluation step of applyDefaults().
	 */
	private function evaluateDefaults(array $decisionTable, string $category): ?array {
		$evaluation = $this->evaluation(decisionTable: $decisionTable, category: $category);
		if ($evaluation === null) {
			return null;
		}

		$outputs = ($evaluation['result']['outputs'] ?? []);
		if (is_array($outputs) === false) {
			return null;
		}

		return $outputs;
	}//end evaluateDefaults()

	/**
	 * Evaluate the decision table for a category.
	 *
	 * @param array<string, mixed> $decisionTable The decision table.
	 * @param string $category The publication's retention category.
	 *
	 * @return array{result: mixed}|null The evaluator's answer under `result`, or null when it is unavailable or fails.
	 *
	 * @spec exclude Internal evaluation step of evaluateDefaults().
	 */
	private function evaluation(array $decisionTable, string $category): ?array {
		$evaluator = $this->getDecisionTableEvaluator();
		if ($evaluator === null) {
			return null;
		}

		try {
			return ['result' => $evaluator->evaluate($decisionTable, ['category' => $category])];
		} catch (\Throwable $e) {
			$this->logEvaluationFailure(error: $e);
			return null;
		}
	}//end evaluation()

	/**
	 * Log a failed evaluation; no matching row is expected and not logged.
	 *
	 * @param \Throwable $error The evaluator's error.
	 *
	 * @return void
	 *
	 * @spec exclude Internal logging step of evaluation().
	 */
	private function logEvaluationFailure(\Throwable $error): void {
		if (method_exists($error, 'getErrorCode') === true && $error->getErrorCode() === 'no_rule_matched') {
			return;
		}

		$this->logger->warning('[RetentionService] retention decision-table evaluation failed: ' . $error->getMessage());
	}//end logEvaluationFailure()

	/**
	 * Fill the empty retention term and action from the winning row.
	 *
	 * An officer's choice is never overwritten; a null output means the row
	 * does not configure that key, and an unknown action is ignored.
	 *
	 * @param array<string, mixed> $publication The publication object data.
	 * @param array<string, mixed> $outputs The winning row's outputs.
	 *
	 * @return array<string, mixed> The publication.
	 *
	 * @spec exclude Internal fill step of applyDefaults().
	 */
	private static function withDefaultOutputs(array $publication, array $outputs): array {
		if (empty($publication['retentionTermMonths']) === true && isset($outputs['termMonths']) === true) {
			$publication['retentionTermMonths'] = (int)$outputs['termMonths'];
		}

		if (empty($publication['retentionAction']) === true && self::hasKnownAction(outputs: $outputs) === true) {
			$publication['retentionAction'] = (string)$outputs['action'];
		}

		return $publication;
	}//end withDefaultOutputs()

	/**
	 * Whether the winning row names one of the known retention actions.
	 *
	 * @param array<string, mixed> $outputs The winning row's outputs.
	 *
	 * @return boolean True for a known action.
	 *
	 * @spec exclude Internal check of withDefaultOutputs().
	 */
	private static function hasKnownAction(array $outputs): bool {
		return isset($outputs['action']) === true && in_array($outputs['action'], self::ACTIONS, true) === true;
	}//end hasKnownAction()

	/**
	 * Recompute the expiry from the publication date and term, unless it is held.
	 *
	 * @param array<string, mixed> $publication The publication object data.
	 *
	 * @return array<string, mixed> The publication.
	 *
	 * @spec exclude Internal expiry step of applyDefaults().
	 */
	private function withComputedExpiry(array $publication): array {
		if (empty($publication['retentionExpiresAt']) === false) {
			return $publication;
		}

		$termMonths = null;
		if (isset($publication['retentionTermMonths']) === true) {
			$termMonths = (int)$publication['retentionTermMonths'];
		}

		$computed = $this->computeExpiry(publicationDate: ($publication['publicationDate'] ?? null), termMonths: $termMonths);
		if ($computed !== null) {
			$publication['retentionExpiresAt'] = $computed;
		}

		return $publication;
	}//end withComputedExpiry()

	/**
	 * Stamp retention defaults onto a publication at publication time (RET-004).
	 *
	 * Called from the OR object event listeners when an object first becomes
	 * published. Only publication objects (the configured publication
	 * register/schema) are touched; the matching per-catalog default fills any
	 * retention field the officer left empty, values already set are never
	 * overwritten, and when nothing changes nothing is saved (so the follow-up
	 * OR update event terminates instead of looping).
	 *
	 * @param array<string, mixed> $objectData The OR event object data (with @self envelope).
	 *
	 * @return array<string, mixed>|null The saved publication, or null when nothing was stamped.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	public function applyDefaultsAtPublication(array $objectData): ?array {
		$data = $this->publicationData(objectData: $objectData);
		$run = null;
		if ($data !== null) {
			$run = $this->publicationRun(self: $objectData['@self']);
		}

		if ($run === null) {
			return null;
		}

		$stamped = $this->applyDefaults(publication: $data, catalogSlug: $this->resolveCatalogSlug(register: $run->register, schema: $run->schema));
		if ($stamped === $data) {
			// Idempotent: already stamped (or no matching default) means no write.
			return null;
		}

		return $this->saveStamped(run: $run, data: (new RetentionRules())->withObjectId(self: $objectData['@self'], data: $stamped));
	}//end applyDefaultsAtPublication()

	/**
	 * The publication payload without its @self envelope, or null when the hook does not apply.
	 *
	 * Only a publication object (the configured register/schema) that has been
	 * published is stamped; RET-004 stamps at publication time only.
	 *
	 * @param array<string, mixed> $objectData The OR event object data (with @self envelope).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec exclude internal guard for the RET-004 publication-time hook.
	 */
	private function publicationData(array $objectData): ?array {
		$self = ($objectData['@self'] ?? []);
		if (is_array($self) === false || $this->isConfiguredPublication(self: $self) === false) {
			return null;
		}

		$data = $objectData;
		unset($data['@self']);
		if (empty($data['publicationDate']) === true) {
			return null;
		}

		return $data;
	}//end publicationData()

	/**
	 * A run over the register and schema the event's @self envelope names, or null without OpenRegister.
	 *
	 * @param array<string, mixed> $self The event object's @self envelope.
	 *
	 * @return RetentionRun|null
	 *
	 * @spec exclude internal step of the RET-004 publication-time hook.
	 */
	private function publicationRun(array $self): ?RetentionRun {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		return new RetentionRun(objectService: $objectService, register: (string)($self['register'] ?? ''), schema: (string)($self['schema'] ?? ''));
	}//end publicationRun()

	/**
	 * Save a stamped publication; a failing save is logged and yields null.
	 *
	 * @param RetentionRun $run The run over the publication's register and schema.
	 * @param array<string, mixed> $data The stamped publication.
	 *
	 * @return array<string, mixed>|null The saved publication.
	 *
	 * @spec exclude internal step of the RET-004 publication-time hook.
	 */
	private function saveStamped(RetentionRun $run, array $data): ?array {
		try {
			$saved = $run->save(data: $data);
		} catch (\Throwable $e) {
			$this->logger->error('[RetentionService] failed to stamp retention defaults at publication: ' . $e->getMessage());
			return null;
		}

		return $this->plainArray(object: $saved);
	}//end saveStamped()

	/**
	 * Whether an event's @self envelope names the configured publication register/schema.
	 *
	 * @param array<string, mixed> $self The event object's @self envelope.
	 *
	 * @return bool True when the object is a publication.
	 *
	 * @spec exclude internal guard for the RET-004 publication-time hook.
	 */
	private function isConfiguredPublication(array $self): bool {
		$configuredRegister = $this->getRegister();
		$configuredSchema = $this->getSchema();
		if ($configuredRegister === null || $configuredSchema === null) {
			return false;
		}

		return (string)($self['register'] ?? '') === $configuredRegister
			&& (string)($self['schema'] ?? '') === $configuredSchema;
	}//end isConfiguredPublication()

	/**
	 * Resolve the catalog slug a publication belongs to.
	 *
	 * Catalog membership is by register/schema: a catalog object lists the
	 * registers and schemas it contains. The first catalog whose lists include
	 * the publication's register and schema provides the slug that keys the
	 * per-catalog retention defaults (RET-004). Returns an empty string when no
	 * catalog matches, in which case no catalog default can apply. Ids are compared
	 * as text, so two uuid ids only match when they are the same uuid.
	 *
	 * @param string $register The publication's register id.
	 * @param string $schema The publication's schema id.
	 *
	 * @return string The catalog slug, or an empty string when unresolvable.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	private function resolveCatalogSlug(string $register, string $schema): string {
		foreach ($this->catalogObjects() as $catalog) {
			$catalogData = $this->plainArray(object: $catalog);
			if (in_array($register, array_map('strval', (array)($catalogData['registers'] ?? [])), true) === true
				&& in_array($schema, array_map('strval', (array)($catalogData['schemas'] ?? [])), true) === true
			) {
				return (string)($catalogData['slug'] ?? '');
			}
		}

		return '';
	}//end resolveCatalogSlug()

	/**
	 * Every catalog object, read outside RBAC and multitenancy; empty when the
	 * catalog register or schema is not configured or the search fails.
	 *
	 * @return iterable<mixed> The catalog rows, arrays or serialisable objects.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	private function catalogObjects(): iterable {
		$objectService = $this->getObjectService();
		$scope = [
			'register' => (string)$this->config->getValueString('opencatalogi', 'catalog_register', ''),
			'schema' => (string)$this->config->getValueString('opencatalogi', 'catalog_schema', ''),
		];
		if ($objectService === null || $scope['register'] === '' || $scope['schema'] === '') {
			return [];
		}

		try {
			$result = $objectService->searchObjectsPaginated(query: ['@self' => $scope, '_limit' => 1000], _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('[RetentionService] catalog lookup for retention defaults failed: ' . $e->getMessage());
			return [];
		}

		return ($result['results'] ?? []);
	}//end catalogObjects()

	/**
	 * Evaluate all publications for retention expiry and act per stored policy.
	 *
	 * Dumb evaluator (RET-005): queries publications whose retentionExpiresAt is
	 * within the warning window or in the past, then per retentionAction:
	 *  - review    -> flag only (humans decide in the queue);
	 *  - depublish -> set depublicationDate = now (standard PUB-017 path);
	 *  - archive   -> request the declared lifecycle transition (status=archived,
	 *                 depublicationDate = now), which OpenRegister validates.
	 * Idempotent via retentionLastEvaluatedAt: an object already actioned today is
	 * skipped. Every save is audited by OpenRegister's immutable audit trail.
	 *
	 * With `$dryRun` nothing is saved: the result also holds `planned`, one entry
	 * per publication with its id, title and the count it would add to
	 * (REQ-IMC-002). The decision is the same one the daily job takes.
	 *
	 * @param bool $dryRun Report the planned actions without saving anything.
	 *
	 * @return array<string, mixed> Counts: expiringSoon, reviewRequired, depublished, archived; plus `planned` on a dry run.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The dry run is the command's safe default; the job keeps the plain call.
	 */
	public function evaluate(bool $dryRun = false): array {
		$counts = ['expiringSoon' => 0, 'reviewRequired' => 0, 'depublished' => 0, 'archived' => 0];
		$planned = [];

		$run = $this->configuredRun();
		if ($run === null) {
			$this->logger->info('[RetentionService] register/schema unconfigured or OpenRegister unavailable; skipping evaluation');
			return $this->evaluationResult(counts: $counts, planned: $planned, dryRun: $dryRun);
		}

		foreach (($this->retentionCandidates(run: $run) ?? []) as $object) {
			$data = $this->plainArray(object: $object);
			$outcome = $this->plannedOutcome(run: $run, data: $data);
			if ($outcome === null) {
				continue;
			}

			$counts[$outcome]++;
			if ($dryRun === true) {
				$planned[] = self::plannedEntry(data: $data, outcome: $outcome);
				continue;
			}

			$this->applyOutcome(run: $run, data: $data, outcome: $outcome);
		}

		return $this->evaluationResult(counts: $counts, planned: $planned, dryRun: $dryRun);
	}//end evaluate()

	/**
	 * A dry run's planned action for one publication.
	 *
	 * @param array<string, mixed> $data    The publication.
	 * @param string               $outcome The action it would get.
	 *
	 * @return array{id: string, title: string, action: string}
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	private static function plannedEntry(array $data, string $outcome): array {
		return [
			'id' => (string)($data['id'] ?? ($data['uuid'] ?? '')),
			'title' => (string)($data['title'] ?? ''),
			'action' => $outcome,
		];
	}//end plannedEntry()

	/**
	 * The evaluation's answer: the counts, and on a dry run the planned actions.
	 *
	 * @param array<string, int> $counts The counts.
	 * @param array<int, array<string, string>> $planned The planned actions.
	 * @param bool $dryRun Whether this was a dry run.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	private function evaluationResult(array $counts, array $planned, bool $dryRun): array {
		if ($dryRun === true) {
			$counts['planned'] = $planned;
		}

		return $counts;
	}//end evaluationResult()

	/**
	 * A run over the configured publication register and schema, or null when either is unset or OpenRegister is missing.
	 *
	 * @return RetentionRun|null
	 *
	 * @spec exclude internal plumbing shared by the evaluation, decision and report steps.
	 */
	private function configuredRun(): ?RetentionRun {
		$objectService = $this->getObjectService();
		$register = $this->getRegister();
		$schema = $this->getSchema();
		if ($objectService === null || $register === null || $schema === null) {
			return null;
		}

		return new RetentionRun(objectService: $objectService, register: $register, schema: $schema);
	}//end configuredRun()

	/**
	 * The publications whose retentionExpiresAt lies at or before the end of the warning window.
	 *
	 * Covers both expiring-soon and already-expired publications, in one plain object search.
	 *
	 * @param RetentionRun $run The evaluation run.
	 *
	 * @return array<int, mixed>|null The rows, or null when the search failed (logged).
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	private function retentionCandidates(RetentionRun $run): ?array {
		$query = (new RetentionQueue(now: $run->now))->query(
			scope: ['register' => $run->register, 'schema' => $run->schema],
			windowDays: $this->getWarningWindowDays()
		);

		try {
			$result = $run->objectService->searchObjectsPaginated(query: $query, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->error('[RetentionService] retention search failed: ' . $e->getMessage());
			return null;
		}

		return ($result['results'] ?? []);
	}//end retentionCandidates()

	/**
	 * Decide, without saving, what the evaluation does with one publication: skip it,
	 * flag it as expiring soon or for review, or act on its expiry.
	 *
	 * A publication without a parseable expiry, or already evaluated today, is skipped.
	 *
	 * @param RetentionRun $run The evaluation run.
	 * @param array<string, mixed> $data The publication.
	 *
	 * @return string|null The count it adds to, or null when skipped.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	private function plannedOutcome(RetentionRun $run, array $data): ?string {
		if ($run->evaluatedToday(data: $data) === true) {
			return null;
		}

		$expiry = (new RetentionRules())->parseDate(value: ($data['retentionExpiresAt'] ?? null));
		if ($expiry === null) {
			return null;
		}

		if ($expiry > $run->now) {
			return 'expiringSoon';
		}

		$counted = ['depublish' => 'depublished', 'archive' => 'archived'];
		return ($counted[(string)($data['retentionAction'] ?? 'review')] ?? 'reviewRequired');
	}//end plannedOutcome()

	/**
	 * Carry out a planned outcome: stamp a flag-only outcome, act on an expiry.
	 *
	 * @param RetentionRun $run The evaluation run.
	 * @param array<string, mixed> $data The publication.
	 * @param string $outcome The outcome {@see plannedOutcome()} chose.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	private function applyOutcome(RetentionRun $run, array $data, string $outcome): void {
		if ($outcome === 'expiringSoon' || $outcome === 'reviewRequired') {
			$run->stampEvaluated(data: $data);
			return;
		}

		$this->actOnExpiry(run: $run, data: $data);
	}//end applyOutcome()

	/**
	 * Act on an expired publication per its retentionAction.
	 *
	 * `depublish` sets depublicationDate; `archive` also requests the archived
	 * lifecycle status, which removes it from public surfaces (RET-006). Both record
	 * the decision. Any other action only flags it for review: visibility unchanged.
	 *
	 * @param RetentionRun $run The evaluation run.
	 * @param array<string, mixed> $data The publication.
	 *
	 * @return string The count it adds to.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	private function actOnExpiry(RetentionRun $run, array $data): string {
		$action = (string)($data['retentionAction'] ?? 'review');
		$counted = ['depublish' => 'depublished', 'archive' => 'archived'];
		if (isset($counted[$action]) === false) {
			$run->stampEvaluated(data: $data);
			return 'reviewRequired';
		}

		$data = (new RetentionRules(now: $run->now))->expiredWithdrawal(data: $data, action: $action);
		$run->save(data: $this->recordDecision(data: $data, decision: 'auto-' . $action, note: 'retention term expired'));

		return $counted[$action];
	}//end actOnExpiry()

	/**
	 * Build a retention review-queue summary for the dashboard widget.
	 *
	 * @return array<string, int> Counts keyed expiringSoon, reviewRequired, archived.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	public function getQueueSummary(): array {
		$summary = ['expiringSoon' => 0, 'reviewRequired' => 0, 'archived' => 0];

		$queue = new RetentionQueue(now: new DateTimeImmutable('now', new DateTimeZone('UTC')));
		foreach (($this->queueObjects(queue: $queue) ?? []) as $object) {
			$bucket = $queue->bucket(data: $this->plainArray(object: $object));
			if ($bucket !== null) {
				$summary[$bucket]++;
			}
		}

		return $summary;
	}//end getQueueSummary()

	/**
	 * The publications whose retention term ends within the warning window.
	 *
	 * @param RetentionQueue $queue The review queue at the moment of the summary.
	 *
	 * @return array|null The objects, or null when OpenRegister or the scope is missing or the search fails.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	private function queueObjects(RetentionQueue $queue): ?array {
		$run = $this->configuredRun();
		if ($run === null) {
			return null;
		}

		$query = $queue->query(scope: ['register' => $run->register, 'schema' => $run->schema], windowDays: $this->getWarningWindowDays());

		try {
			$result = $run->objectService->searchObjectsPaginated(query: $query, _rbac: true, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('[RetentionService] queue summary failed: ' . $e->getMessage());
			return null;
		}

		return ($result['results'] ?? []);
	}//end queueObjects()

	/**
	 * Record a human disposal/extension decision (who/when/basis/rationale).
	 *
	 * The decision is appended to the publication and persisted via saveObject, so
	 * OpenRegister's immutable audit trail captures it (RET-006/RET-007); no
	 * bespoke audit store. A non-empty rationale is mandatory.
	 *
	 * @param string $publicationId The publication uuid.
	 * @param string $decision The decision verb (extend|depublish|archive|dispose).
	 * @param string $rationale The mandatory rationale.
	 * @param int $extendMonths Extra months to extend the term by (extend only).
	 *
	 * @return array<string, mixed> The updated publication object.
	 *
	 * @throws RuntimeException When inputs are invalid or OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	public function recordHumanDecision(string $publicationId, string $decision, string $rationale, int $extendMonths = 0): array {
		if (trim($rationale) === '') {
			throw new RuntimeException('A rationale note is mandatory for retention decisions');
		}

		$run = $this->configuredRun();
		if ($run === null) {
			throw new RuntimeException('OpenRegister publication register/schema unavailable');
		}

		$publication = $this->findPublication(run: $run, publicationId: $publicationId);
		$data = (new RetentionRules())->afterDecision(
			data: $this->plainArray(object: $publication),
			decision: ['decision' => $decision, 'note' => $rationale, 'months' => $extendMonths]
		);
		if ($decision === 'archive') {
			$data['edepotTransfer'] = $this->edepot->request(publication: $publication, now: $run->now);
		}

		return $this->plainArray(object: $run->save(data: $this->recordDecision(data: $data, decision: $decision, note: $rationale)));
	}//end recordHumanDecision()

	/**
	 * Bring every archived publication's pending e-depot transfer up to date with OpenRegister.
	 *
	 * A transfer that ended is saved on its publication: `transferred`, or
	 * `failed` with the e-depot's reason. The publication stays archived.
	 *
	 * @return int How many publications got a new transfer status.
	 *
	 * @spec openspec/changes/capability-rows-get-requirements/specs/publication-retention-lifecycle/spec.md#requirement-an-archive-decision-hands-the-publication-to-the-e-depot-req-crr-003
	 */
	public function refreshEdepotTransfers(): int {
		$run = $this->configuredRun();
		if ($run === null) {
			return 0;
		}

		return $this->edepot->refreshArchived(run: $run);
	}//end refreshEdepotTransfers()

	/**
	 * The publication a decision is about.
	 *
	 * @param RetentionRun $run The run over the publication register and schema.
	 * @param string $publicationId The publication id.
	 *
	 * @return mixed The object as OpenRegister returns it.
	 *
	 * @throws RuntimeException When it cannot be found.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	private function findPublication(RetentionRun $run, string $publicationId): mixed {
		try {
			return $run->objectService->find($publicationId);
		} catch (\Throwable $e) {
			throw new RuntimeException('Publication not found: ' . $publicationId);
		}
	}//end findPublication()

	/**
	 * Build a retention report (rows) for a catalog and date range.
	 *
	 * Derived purely from stored fields + the decision log captured on each object
	 * (which OpenRegister audits); no separate reporting store (RET-009).
	 *
	 * @param string|null $catalogSlug Optional catalog slug filter.
	 * @param string|null $from Optional ISO date lower bound (publication date).
	 * @param string|null $to Optional ISO date upper bound (publication date).
	 *
	 * @return array<int, array<string, mixed>> Report rows.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
	 */
	public function buildReport(?string $catalogSlug = null, ?string $from = null, ?string $to = null): array {
		$report = new RetentionReport(filter: ['catalog' => $catalogSlug, 'from' => $from, 'to' => $to]);
		$rows = [];
		foreach ($this->reportPublications() as $object) {
			$data = $this->plainArray(object: $object);
			if ($report->includes(data: $data) === true) {
				$rows[] = $report->row(data: $data);
			}
		}

		return $rows;
	}//end buildReport()

	/**
	 * Every publication the report reads, or none when OpenRegister or its config is missing.
	 *
	 * @return iterable<mixed> The stored publications.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
	 */
	private function reportPublications(): iterable {
		$run = $this->configuredRun();
		if ($run === null) {
			return [];
		}

		$query = ['@self' => ['register' => $run->register, 'schema' => $run->schema], '_limit' => 10000];

		try {
			$result = $run->objectService->searchObjectsPaginated(query: $query, _rbac: true, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('[RetentionService] report build failed: ' . $e->getMessage());
			return [];
		}

		return ($result['results'] ?? []);
	}//end reportPublications()

	/**
	 * Render report rows as CSV (UTF-8 with BOM).
	 *
	 * @param array<int, array<string, mixed>> $rows The report rows.
	 *
	 * @return string The CSV document.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
	 */
	public function renderReportCsv(array $rows): string {
		return (new BomCsv(columns: array_combine(self::REPORT_COLUMNS, self::REPORT_COLUMNS)))->render(rows: $rows);
	}//end renderReportCsv()

	/**
	 * Append a decision record to the publication's decision log.
	 *
	 * @param array<string, mixed> $data The publication data.
	 * @param string $decision The decision verb.
	 * @param string $note The rationale / reason.
	 *
	 * @return array<string, mixed> The publication with the decision logged.
	 *
	 * @spec exclude internal helper for the audited decision payload.
	 */
	private function recordDecision(array $data, string $decision, string $note): array {
		return (new RetentionRules())->withDecision(
			data: $data,
			entry: ['decision' => $decision, 'by' => ($this->userSession->getUser()?->getUID() ?? 'system'), 'note' => $note]
		);
	}//end recordDecision()

}//end class

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

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
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
	 * Cached OpenRegister ObjectService instance.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

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
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly RetentionPolicyTable $policyTable = new RetentionPolicyTable(),
	) {

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
		// Validate each action value to keep policy data sane (never hard-coded terms).
		foreach ($defaults as $catalog => $categories) {
			if (is_array($categories) === false) {
				throw new RuntimeException('Invalid retention defaults shape for catalog ' . (string)$catalog);
			}

			foreach ($categories as $category => $rule) {
				if (is_array($rule) === false) {
					continue;
				}

				if (isset($rule['action']) === true && in_array($rule['action'], self::ACTIONS, true) === false) {
					$message = 'Invalid retention action "' . (string)$rule['action'] . '" for '
						. (string)$catalog . '/' . (string)$category;
					throw new RuntimeException($message);
				}
			}
		}

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
		if ($publicationDate === null || $publicationDate === '' || $termMonths === null || $termMonths <= 0) {
			return null;
		}

		try {
			$date = new DateTimeImmutable($publicationDate);
		} catch (\Throwable $e) {
			return null;
		}

		$expiry = $date->add(new DateInterval('P' . $termMonths . 'M'));
		return $expiry->format(DateTimeInterface::ATOM);
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
		$defaults = $this->getRetentionDefaults();
		$catalogDefaults = ($defaults[$catalogSlug] ?? []);
		if (is_array($catalogDefaults) === false) {
			return $publication;
		}

		$decisionTable = $this->policyTable->fromDefaults(catalogDefaults: $catalogDefaults);
		if ($decisionTable === null) {
			// Nothing configured for this catalog.
			return $publication;
		}

		$evaluator = $this->getDecisionTableEvaluator();
		if ($evaluator === null) {
			// Already warned in the resolver; degrade to "no defaults configured".
			return $publication;
		}

		$category = (string)($publication['retentionCategory'] ?? '');
		try {
			$result = $evaluator->evaluate($decisionTable, ['category' => $category]);
		} catch (\Throwable $e) {
			if (method_exists($e, 'getErrorCode') === true && $e->getErrorCode() === 'no_rule_matched') {
				// Expected: no row for this category and no fallback configured.
				return $publication;
			}

			$this->logger->warning('[RetentionService] retention decision-table evaluation failed: ' . $e->getMessage());
			return $publication;
		}

		$outputs = ($result['outputs'] ?? []);
		if (is_array($outputs) === false) {
			return $publication;
		}

		// Only fill empties — never overwrite an officer's choice. Null outputs
		// mean the winning row does not configure that key; skip them.
		if (empty($publication['retentionTermMonths']) === true && isset($outputs['termMonths']) === true) {
			$publication['retentionTermMonths'] = (int)$outputs['termMonths'];
		}

		if (empty($publication['retentionAction']) === true && isset($outputs['action']) === true
			&& in_array($outputs['action'], self::ACTIONS, true) === true
		) {
			$publication['retentionAction'] = (string)$outputs['action'];
		}

		// Recompute expiry from publication date + term when not manually held.
		if (empty($publication['retentionExpiresAt']) === true) {
			$termMonths = null;
			if (isset($publication['retentionTermMonths']) === true) {
				$termMonths = (int)$publication['retentionTermMonths'];
			}

			$computed = $this->computeExpiry(
				publicationDate: ($publication['publicationDate'] ?? null),
				termMonths: $termMonths
			);
			if ($computed !== null) {
				$publication['retentionExpiresAt'] = $computed;
			}
		}

		return $publication;
	}//end applyDefaults()

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
		$self = ($objectData['@self'] ?? []);
		if (is_array($self) === false || $this->isConfiguredPublication(self: $self) === false) {
			// Not a publication object; retention defaults do not apply.
			return null;
		}

		$data = $objectData;
		unset($data['@self']);
		if (empty($data['publicationDate']) === true) {
			// Not published yet; RET-004 stamps at publication time only.
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)($self['register'] ?? '');
		$schema = (string)($self['schema'] ?? '');
		$stamped = $this->applyDefaults(
			publication: $data,
			catalogSlug: $this->resolveCatalogSlug(register: $register, schema: $schema)
		);
		if ($stamped === $data) {
			// Idempotent: already stamped (or no matching default) means no write.
			return null;
		}

		$stamped = $this->carryObjectId(self: $self, data: $stamped);

		try {
			$saved = $this->save(objectService: $objectService, register: $register, schema: $schema, data: $stamped);
		} catch (\Throwable $e) {
			$this->logger->error('[RetentionService] failed to stamp retention defaults at publication: ' . $e->getMessage());
			return null;
		}

		return $this->plainArray(object: $saved);
	}//end applyDefaultsAtPublication()

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
	 * Carry the object id from the @self envelope onto the payload so the save
	 * updates the existing object instead of creating a duplicate.
	 *
	 * @param array<string, mixed> $self The event object's @self envelope.
	 * @param array<string, mixed> $data The stamped publication payload.
	 *
	 * @return array<string, mixed> The payload with its id ensured.
	 *
	 * @spec exclude internal helper for the RET-004 publication-time hook.
	 */
	private function carryObjectId(array $self, array $data): array {
		$uuid = (string)($self['uuid'] ?? ($self['id'] ?? ($data['id'] ?? '')));
		if ($uuid !== '' && empty($data['id']) === true) {
			$data['id'] = $uuid;
		}

		return $data;
	}//end carryObjectId()

	/**
	 * Resolve the catalog slug a publication belongs to.
	 *
	 * Catalog membership is by register/schema: a catalog object lists the
	 * registers and schemas it contains. The first catalog whose lists include
	 * the publication's register and schema provides the slug that keys the
	 * per-catalog retention defaults (RET-004). Returns an empty string when no
	 * catalog matches, in which case no catalog default can apply.
	 *
	 * @param string $register The publication's register id.
	 * @param string $schema The publication's schema id.
	 *
	 * @return string The catalog slug, or an empty string when unresolvable.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	private function resolveCatalogSlug(string $register, string $schema): string {
		$objectService = $this->getObjectService();
		$catalogRegister = (string)$this->config->getValueString('opencatalogi', 'catalog_register', '');
		$catalogSchema = (string)$this->config->getValueString('opencatalogi', 'catalog_schema', '');
		if ($objectService === null || $catalogRegister === '' || $catalogSchema === '') {
			return '';
		}

		try {
			$result = $objectService->searchObjectsPaginated(
				query: [
					'@self' => ['register' => $catalogRegister, 'schema' => $catalogSchema],
					'_limit' => 1000,
				],
				_rbac: false,
				_multitenancy: false
			);
			$catalogs = ($result['results'] ?? []);
		} catch (\Throwable $e) {
			$this->logger->warning('[RetentionService] catalog lookup for retention defaults failed: ' . $e->getMessage());
			return '';
		}

		foreach ($catalogs as $catalog) {
			$catalogData = $this->plainArray(object: $catalog);
			$registers = array_map('intval', (array)($catalogData['registers'] ?? []));
			$schemas = array_map('intval', (array)($catalogData['schemas'] ?? []));
			if (in_array((int)$register, $registers, true) === true
				&& in_array((int)$schema, $schemas, true) === true
			) {
				return (string)($catalogData['slug'] ?? '');
			}
		}

		return '';
	}//end resolveCatalogSlug()

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
	 * @return array<string, int> Counts: expiringSoon, reviewRequired, depublished, archived.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	public function evaluate(): array {
		$counts = ['expiringSoon' => 0, 'reviewRequired' => 0, 'depublished' => 0, 'archived' => 0];

		$objectService = $this->getObjectService();
		$register = $this->getRegister();
		$schema = $this->getSchema();
		if ($objectService === null || $register === null || $schema === null) {
			$this->logger->info('[RetentionService] register/schema unconfigured or OpenRegister unavailable; skipping evaluation');
			return $counts;
		}

		$run = new RetentionRun(objectService: $objectService, register: $register, schema: $schema);
		$objects = $this->retentionCandidates(run: $run);
		if ($objects === null) {
			return $counts;
		}

		foreach ($objects as $object) {
			$outcome = $this->evaluatePublication(run: $run, data: $this->plainArray(object: $object));
			if ($outcome !== null) {
				$counts[$outcome]++;
			}
		}

		return $counts;
	}//end evaluate()

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
		$windowEnd = $run->now->add(new DateInterval('P' . $this->getWarningWindowDays() . 'D'));
		$query = [
			'@self' => ['register' => $run->register, 'schema' => $run->schema],
			'retentionExpiresAt' => ['$lte' => $windowEnd->format(DateTimeInterface::ATOM)],
			'_limit' => 1000,
		];

		try {
			$result = $run->objectService->searchObjectsPaginated(query: $query, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->error('[RetentionService] retention search failed: ' . $e->getMessage());
			return null;
		}

		return ($result['results'] ?? []);
	}//end retentionCandidates()

	/**
	 * Evaluate one publication: skip it, flag it as expiring soon, or act on its expiry.
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
	private function evaluatePublication(RetentionRun $run, array $data): ?string {
		$expiresAt = ($data['retentionExpiresAt'] ?? null);
		if ($expiresAt === null || $expiresAt === '') {
			return null;
		}

		$lastEvaluated = (string)($data['retentionLastEvaluatedAt'] ?? '');
		if ($lastEvaluated !== '' && str_starts_with($lastEvaluated, $run->today) === true) {
			return null;
		}

		try {
			$expiry = new DateTimeImmutable($expiresAt);
		} catch (\Throwable $e) {
			return null;
		}

		if ($expiry > $run->now) {
			$this->stampEvaluated(run: $run, data: $data);
			return 'expiringSoon';
		}

		return $this->actOnExpiry(run: $run, data: $data);
	}//end evaluatePublication()

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
			$this->stampEvaluated(run: $run, data: $data);
			return 'reviewRequired';
		}

		if ($action === 'archive') {
			$data['status'] = 'archived';
		}

		$data['depublicationDate'] = $run->now->format(DateTimeInterface::ATOM);
		$data['retentionLastEvaluatedAt'] = $run->now->format(DateTimeInterface::ATOM);
		$this->recordDecision(data: $data, decision: 'auto-' . $action, note: 'retention term expired');
		$this->save(objectService: $run->objectService, register: $run->register, schema: $run->schema, data: $data);

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

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		foreach (($this->queueObjects(now: $now) ?? []) as $object) {
			$bucket = $this->queueBucket(data: $this->plainArray(object: $object), now: $now);
			if ($bucket !== null) {
				$summary[$bucket]++;
			}
		}

		return $summary;
	}//end getQueueSummary()

	/**
	 * The publications whose retention term ends within the warning window.
	 *
	 * @param DateTimeImmutable $now The moment of the summary.
	 *
	 * @return array|null The objects, or null when OpenRegister or the scope is missing or the search fails.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	private function queueObjects(DateTimeImmutable $now): ?array {
		$objectService = $this->getObjectService();
		$scope = $this->publicationScope();
		if ($objectService === null || $scope === null) {
			return null;
		}

		$query = $this->queueQuery(scope: $scope, now: $now);

		try {
			$result = $objectService->searchObjectsPaginated(query: $query, _rbac: true, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('[RetentionService] queue summary failed: ' . $e->getMessage());
			return null;
		}

		return ($result['results'] ?? []);
	}//end queueObjects()

	/**
	 * The search for publications whose term ends within the warning window.
	 *
	 * @param array $scope The publication register and schema.
	 * @param DateTimeImmutable $now The moment of the summary.
	 *
	 * @return array<string, mixed> The query.
	 *
	 * @spec exclude Internal query step of queueObjects().
	 */
	private function queueQuery(array $scope, DateTimeImmutable $now): array {
		$windowEnd = $now->add(new DateInterval('P' . $this->getWarningWindowDays() . 'D'));

		return [
			'@self' => $scope,
			'retentionExpiresAt' => ['$lte' => $windowEnd->format(DateTimeInterface::ATOM)],
			'_limit' => 1000,
		];
	}//end queueQuery()

	/**
	 * The configured publication register and schema, or null when either is unset.
	 *
	 * @return array{register: string, schema: string}|null The scope.
	 *
	 * @spec exclude Internal scope step of queueObjects().
	 */
	private function publicationScope(): ?array {
		$register = $this->getRegister();
		$schema = $this->getSchema();
		if ($register === null || $schema === null) {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end publicationScope()

	/**
	 * The summary count a publication adds to, or null for none.
	 *
	 * An archived publication counts as archived; an expired one with the
	 * review action needs review; one that has not expired yet is expiring soon.
	 *
	 * @param array $data The publication.
	 * @param DateTimeImmutable $now The moment of the summary.
	 *
	 * @return string|null The summary key.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	private function queueBucket(array $data, DateTimeImmutable $now): ?string {
		if ((string)($data['status'] ?? '') === 'archived') {
			return 'archived';
		}

		$expiry = $this->retentionExpiry(data: $data);
		if ($expiry === null) {
			return null;
		}

		if ($expiry > $now) {
			return 'expiringSoon';
		}

		return self::reviewBucket(data: $data);
	}//end queueBucket()

	/**
	 * The review-required key for an expired publication whose action is review, the default.
	 *
	 * @param array $data The publication.
	 *
	 * @return string|null The summary key, or null for another action.
	 *
	 * @spec exclude Internal check of queueBucket().
	 */
	private static function reviewBucket(array $data): ?string {
		if ((string)($data['retentionAction'] ?? 'review') === 'review') {
			return 'reviewRequired';
		}

		return null;
	}//end reviewBucket()

	/**
	 * A publication's retention expiry, or null when it is unset or unreadable.
	 *
	 * @param array $data The publication.
	 *
	 * @return DateTimeImmutable|null The expiry.
	 *
	 * @spec exclude Internal parse step of queueBucket().
	 */
	private function retentionExpiry(array $data): ?DateTimeImmutable {
		$expiresAt = ($data['retentionExpiresAt'] ?? null);
		if ($expiresAt === null || $expiresAt === '') {
			return null;
		}

		try {
			return new DateTimeImmutable((string)$expiresAt);
		} catch (\Throwable $e) {
			return null;
		}
	}//end retentionExpiry()

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

		$run = $this->decisionRun();

		try {
			$object = $run->objectService->find($publicationId);
		} catch (\Throwable $e) {
			throw new RuntimeException('Publication not found: ' . $publicationId);
		}

		$data = $this->plainArray(object: $object);
		if ($decision !== 'extend') {
			$data = $this->withdrawn(data: $data, decision: $decision);
		}

		if ($decision === 'extend') {
			$data['retentionExpiresAt'] = $this->extendedExpiry(data: $data, months: $extendMonths);
			$data['retentionNote'] = $rationale;
			$data['retentionLastEvaluatedAt'] = '';
		}

		$this->recordDecision(data: $data, decision: $decision, note: $rationale);
		$saved = $this->save(objectService: $run->objectService, register: $run->register, schema: $run->schema, data: $data);

		return $this->plainArray(object: $saved);
	}//end recordHumanDecision()

	/**
	 * The object service, register and schema a human decision is saved through.
	 *
	 * @return RetentionRun The run.
	 *
	 * @throws RuntimeException When OpenRegister, the register or the schema is unavailable.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	private function decisionRun(): RetentionRun {
		$objectService = $this->getObjectService();
		$register = $this->getRegister();
		$schema = $this->getSchema();
		if ($objectService === null || $register === null || $schema === null) {
			throw new RuntimeException('OpenRegister publication register/schema unavailable');
		}

		return new RetentionRun(objectService: $objectService, register: $register, schema: $schema);
	}//end decisionRun()

	/**
	 * The retention expiry moved on by a number of months.
	 *
	 * Counted from the stored expiry, or from now when there is none or it cannot be read.
	 *
	 * @param array<string, mixed> $data The publication.
	 * @param int $months How many months to extend by.
	 *
	 * @return string The new expiry, as an ATOM date.
	 *
	 * @throws RuntimeException When the number of months is not positive.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	private function extendedExpiry(array $data, int $months): string {
		if ($months <= 0) {
			throw new RuntimeException('Extend requires a positive number of months');
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$base = ($data['retentionExpiresAt'] ?? $now->format(DateTimeInterface::ATOM));
		try {
			$newExpiry = (new DateTimeImmutable((string)$base))->add(new DateInterval('P' . $months . 'M'));
		} catch (\Throwable $e) {
			$newExpiry = $now->add(new DateInterval('P' . $months . 'M'));
		}

		return $newExpiry->format(DateTimeInterface::ATOM);
	}//end extendedExpiry()

	/**
	 * Apply a depublish, archive or dispose decision to a publication.
	 *
	 * @param array<string, mixed> $data The publication.
	 * @param string $decision The decision verb.
	 *
	 * @return array<string, mixed> The publication.
	 *
	 * @throws RuntimeException On a decision that is none of those.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
	 */
	private function withdrawn(array $data, string $decision): array {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		switch ($decision) {
			case 'depublish':
				$data['depublicationDate'] = $now->format(DateTimeInterface::ATOM);
				break;

			case 'archive':
			case 'dispose':
				$data['status'] = 'archived';
				$data['depublicationDate'] = $now->format(DateTimeInterface::ATOM);
				break;

			default:
				throw new RuntimeException('Unknown retention decision: ' . $decision);
		}//end switch

		return $data;
	}//end withdrawn()

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
		$objectService = $this->getObjectService();
		$register = $this->getRegister();
		$schema = $this->getSchema();
		if ($objectService === null || $register === null || $schema === null) {
			return [];
		}

		$query = [
			'@self' => ['register' => $register, 'schema' => $schema],
			'_limit' => 10000,
		];

		try {
			$result = $objectService->searchObjectsPaginated(query: $query, _rbac: true, _multitenancy: false);
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
		$headers = [
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

		return (new BomCsv(columns: array_combine($headers, $headers)))->render(rows: $rows);
	}//end renderReportCsv()

	/**
	 * Append a decision record to the publication's decision log.
	 *
	 * @param array<string, mixed> $data The publication data (mutated).
	 * @param string $decision The decision verb.
	 * @param string $note The rationale / reason.
	 *
	 * @return void
	 *
	 * @spec exclude internal helper for the audited decision payload.
	 */
	private function recordDecision(array &$data, string $decision, string $note): void {
		$user = $this->userSession->getUser();
		$decisionBy = 'system';
		if ($user !== null) {
			$decisionBy = $user->getUID();
		}

		$log = ($data['retentionDecisionLog'] ?? []);
		if (is_array($log) === false) {
			$log = [];
		}

		$log[] = [
			'decision' => $decision,
			'by' => $decisionBy,
			'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
			'note' => $note,
			'basis' => (string)($data['retentionCategory'] ?? ''),
		];

		$data['retentionDecisionLog'] = $log;

	}//end recordDecision()

	/**
	 * Stamp the idempotency marker without otherwise changing the object.
	 *
	 * @param RetentionRun $run The evaluation run.
	 * @param array<string, mixed> $data The publication data.
	 *
	 * @return void
	 *
	 * @spec exclude internal idempotency helper.
	 */
	private function stampEvaluated(RetentionRun $run, array $data): void {
		$data['retentionLastEvaluatedAt'] = $run->now->format(DateTimeInterface::ATOM);
		$this->save(objectService: $run->objectService, register: $run->register, schema: $run->schema, data: $data);

	}//end stampEvaluated()

	/**
	 * Persist a publication via the consumed OR ObjectService (auto-audited).
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param array<string, mixed> $data The publication data.
	 *
	 * @return mixed The saved ObjectEntity (OR returns an entity; normalise at the call site).
	 *
	 * @spec exclude thin pass-through to the consumed OR saveObject (ADR-022).
	 */
	private function save(object $objectService, string $register, string $schema, array $data): mixed {
		return $objectService->saveObject(
			object: $data,
			register: $register,
			schema: $schema,
			uuid: (string)($data['id'] ?? $data['uuid'] ?? ''),
			_rbac: false,
			_multitenancy: false,
		);

	}//end save()
}//end class

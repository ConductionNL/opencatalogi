<?php
/**
 * Service for the publication retention lifecycle.
 *
 * Implements the temporal/retention policy layer described in
 * openspec/changes/publication-retention-lifecycle. Per hydra ADR-022 this
 * service builds NO scheduler, NO state machine and NO audit store: scheduling
 * is the OpenRegister published-predicate over publicatiedatum/depublicatiedatum,
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

use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drives the publication retention lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RetentionService
{

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
     * Constructor.
     *
     * @param IAppConfig         $config      App configuration.
     * @param ContainerInterface $container   Server container for resolving OpenRegister.
     * @param IUserSession       $userSession Current user session (decision attribution).
     * @param LoggerInterface    $logger      Logger.
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Resolve the OpenRegister ObjectService from the container.
     *
     * @return object|null The ObjectService, or null when OpenRegister is unavailable.
     *
     * @spec exclude pure framework plumbing — resolves the consumed OR ObjectService.
     */
    public function getObjectService(): ?object
    {
        if ($this->objectService === null) {
            try {
                $this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            } catch (\Throwable $e) {
                $this->logger->warning('[RetentionService] OpenRegister ObjectService unavailable: '.$e->getMessage());
                return null;
            }
        }

        return $this->objectService;

    }//end getObjectService()

    /**
     * Resolve the configured publication register identifier.
     *
     * @return string|null The register id/uuid, or null when unconfigured.
     *
     * @spec exclude configuration plumbing.
     */
    private function getRegister(): ?string
    {
        $value = (string) $this->config->getValueString('opencatalogi', 'publication_register', '');
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
    private function getSchema(): ?string
    {
        $value = (string) $this->config->getValueString('opencatalogi', 'publication_schema', '');
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
    public function getWarningWindowDays(): int
    {
        $value = (int) $this->config->getValueString('opencatalogi', self::CONFIG_WARNING_WINDOW, (string) self::DEFAULT_WARNING_WINDOW);
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
    public function getRetentionDefaults(): array
    {
        $raw = (string) $this->config->getValueString('opencatalogi', self::CONFIG_DEFAULTS, '');
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
    public function setRetentionDefaults(array $defaults): array
    {
        // Validate each action value to keep policy data sane (never hard-coded terms).
        foreach ($defaults as $catalog => $categories) {
            if (is_array($categories) === false) {
                throw new RuntimeException('Invalid retention defaults shape for catalog '.(string) $catalog);
            }

            foreach ($categories as $category => $rule) {
                if (is_array($rule) === false) {
                    continue;
                }

                if (isset($rule['action']) === true && in_array($rule['action'], self::ACTIONS, true) === false) {
                    $message = 'Invalid retention action "'.(string) $rule['action'].'" for '
                        .(string) $catalog.'/'.(string) $category;
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
     * @param int|null    $termMonths      The retention term in months.
     *
     * @return string|null The computed expiry as ISO-8601, or null when not computable.
     *
     * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-metadata-on-the-publication-schema-ret-003
     */
    public function computeExpiry(?string $publicationDate, ?int $termMonths): ?string
    {
        if ($publicationDate === null || $publicationDate === '' || $termMonths === null || $termMonths <= 0) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($publicationDate);
        } catch (\Throwable $e) {
            return null;
        }

        $expiry = $date->add(new \DateInterval('P'.$termMonths.'M'));
        return $expiry->format(\DateTimeInterface::ATOM);

    }//end computeExpiry()

    /**
     * Apply per-catalog retention defaults to a publication that lacks them.
     *
     * Already-set retention values are never overwritten (RET-004). Returns the
     * publication array with any newly-applied retention fields and a recomputed
     * retentionExpiresAt; the caller persists it. Pure in-memory policy resolution.
     *
     * @param array<string, mixed> $publication The publication object data.
     * @param string               $catalogSlug The catalog slug the publication belongs to.
     *
     * @return array<string, mixed> The publication with defaults applied.
     *
     * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
     */
    public function applyDefaults(array $publication, string $catalogSlug): array
    {
        $defaults        = $this->getRetentionDefaults();
        $catalogDefaults = ($defaults[$catalogSlug] ?? []);
        if (is_array($catalogDefaults) === false) {
            return $publication;
        }

        $category = (string) ($publication['retentionCategory'] ?? '');
        $rule     = null;
        if ($category !== '' && isset($catalogDefaults[$category]) === true && is_array($catalogDefaults[$category]) === true) {
            $rule = $catalogDefaults[$category];
        } else if (isset($catalogDefaults['_fallback']) === true && is_array($catalogDefaults['_fallback']) === true) {
            $rule = $catalogDefaults['_fallback'];
        }

        if ($rule === null) {
            return $publication;
        }

        // Only fill empties — never overwrite an officer's choice.
        if (empty($publication['retentionTermMonths']) === true && isset($rule['termMonths']) === true) {
            $publication['retentionTermMonths'] = (int) $rule['termMonths'];
        }

        if (empty($publication['retentionAction']) === true && isset($rule['action']) === true
            && in_array($rule['action'], self::ACTIONS, true) === true
        ) {
            $publication['retentionAction'] = (string) $rule['action'];
        }

        // Recompute expiry from publication date + term when not manually held.
        if (empty($publication['retentionExpiresAt']) === true) {
            $termMonths = null;
            if (isset($publication['retentionTermMonths']) === true) {
                $termMonths = (int) $publication['retentionTermMonths'];
            }

            $computed = $this->computeExpiry(
                ($publication['publicatiedatum'] ?? null),
                $termMonths
            );
            if ($computed !== null) {
                $publication['retentionExpiresAt'] = $computed;
            }
        }

        return $publication;

    }//end applyDefaults()

    /**
     * Evaluate all publications for retention expiry and act per stored policy.
     *
     * Dumb evaluator (RET-005): queries publications whose retentionExpiresAt is
     * within the warning window or in the past, then per retentionAction:
     *  - review    -> flag only (humans decide in the queue);
     *  - depublish -> set depublicatiedatum = now (standard PUB-017 path);
     *  - archive   -> request the declared lifecycle transition (status=archived,
     *                 depublicatiedatum = now), which OpenRegister validates.
     * Idempotent via retentionLastEvaluatedAt: an object already actioned today is
     * skipped. Every save is audited by OpenRegister's immutable audit trail.
     *
     * @return array<string, int> Counts: expiringSoon, reviewRequired, depublished, archived.
     *
     * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
     */
    public function evaluate(): array
    {
        $counts = ['expiringSoon' => 0, 'reviewRequired' => 0, 'depublished' => 0, 'archived' => 0];

        $objectService = $this->getObjectService();
        $register      = $this->getRegister();
        $schema        = $this->getSchema();
        if ($objectService === null || $register === null || $schema === null) {
            $this->logger->info('[RetentionService] register/schema unconfigured or OpenRegister unavailable; skipping evaluation');
            return $counts;
        }

        $now       = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $today     = $now->format('Y-m-d');
        $windowEnd = $now->add(new \DateInterval('P'.$this->getWarningWindowDays().'D'));

        // Plain object search: publications with retentionExpiresAt at or before the
        // end of the warning window (covers both expiring-soon and already-expired).
        $query = [
            '@self'              => ['register' => $register, 'schema' => $schema],
            'retentionExpiresAt' => ['$lte' => $windowEnd->format(\DateTimeInterface::ATOM)],
            '_limit'             => 1000,
        ];

        try {
            $result  = $objectService->searchObjectsPaginated(query: $query, _rbac: false, _multitenancy: false);
            $objects = ($result['results'] ?? []);
        } catch (\Throwable $e) {
            $this->logger->error('[RetentionService] retention search failed: '.$e->getMessage());
            return $counts;
        }

        foreach ($objects as $object) {
            $data = $this->normalise($object);

            $expiresAt = ($data['retentionExpiresAt'] ?? null);
            if ($expiresAt === null || $expiresAt === '') {
                continue;
            }

            // Idempotency: skip objects already evaluated today.
            $lastEvaluated = (string) ($data['retentionLastEvaluatedAt'] ?? '');
            if ($lastEvaluated !== '' && str_starts_with($lastEvaluated, $today) === true) {
                continue;
            }

            try {
                $expiry = new \DateTimeImmutable($expiresAt);
            } catch (\Throwable $e) {
                continue;
            }

            $expired = ($expiry <= $now);
            $action  = (string) ($data['retentionAction'] ?? 'review');

            if ($expired === false) {
                // Within the warning window but not yet expired -> flag expiring-soon.
                $counts['expiringSoon']++;
                $this->stampEvaluated(objectService: $objectService, register: $register, schema: $schema, data: $data, now: $now);
                continue;
            }

            switch ($action) {
                case 'depublish':
                    $data['depublicatiedatum']        = $now->format(\DateTimeInterface::ATOM);
                    $data['retentionLastEvaluatedAt'] = $now->format(\DateTimeInterface::ATOM);
                    $this->recordDecision(data: $data, decision: 'auto-depublish', note: 'retention term expired');
                    $this->save(objectService: $objectService, register: $register, schema: $schema, data: $data);
                    $counts['depublished']++;
                    break;

                case 'archive':
                    // Request the declared lifecycle transition; setting depublicatiedatum
                    // removes it from public surfaces as part of archiving (RET-006).
                    $data['status']            = 'archived';
                    $data['depublicatiedatum'] = $now->format(\DateTimeInterface::ATOM);
                    $data['retentionLastEvaluatedAt'] = $now->format(\DateTimeInterface::ATOM);
                    $this->recordDecision(data: $data, decision: 'auto-archive', note: 'retention term expired');
                    $this->save(objectService: $objectService, register: $register, schema: $schema, data: $data);
                    $counts['archived']++;
                    break;

                case 'review':
                default:
                    // Flag only — visibility unchanged; humans decide in the queue.
                    $counts['reviewRequired']++;
                    $this->stampEvaluated(objectService: $objectService, register: $register, schema: $schema, data: $data, now: $now);
                    break;
            }//end switch
        }//end foreach

        return $counts;

    }//end evaluate()

    /**
     * Build a retention review-queue summary for the dashboard widget.
     *
     * @return array<string, int> Counts keyed expiringSoon, reviewRequired, archived.
     *
     * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
     */
    public function getQueueSummary(): array
    {
        $summary = ['expiringSoon' => 0, 'reviewRequired' => 0, 'archived' => 0];

        $objectService = $this->getObjectService();
        $register      = $this->getRegister();
        $schema        = $this->getSchema();
        if ($objectService === null || $register === null || $schema === null) {
            return $summary;
        }

        $now       = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $windowEnd = $now->add(new \DateInterval('P'.$this->getWarningWindowDays().'D'));

        try {
            $result  = $objectService->searchObjectsPaginated(
                query: [
                    '@self'              => ['register' => $register, 'schema' => $schema],
                    'retentionExpiresAt' => ['$lte' => $windowEnd->format(\DateTimeInterface::ATOM)],
                    '_limit'             => 1000,
                ],
                _rbac: true,
                _multitenancy: false
            );
            $objects = ($result['results'] ?? []);
        } catch (\Throwable $e) {
            $this->logger->warning('[RetentionService] queue summary failed: '.$e->getMessage());
            return $summary;
        }

        foreach ($objects as $object) {
            $data   = $this->normalise($object);
            $status = (string) ($data['status'] ?? '');
            if ($status === 'archived') {
                $summary['archived']++;
                continue;
            }

            $expiresAt = ($data['retentionExpiresAt'] ?? null);
            if ($expiresAt === null || $expiresAt === '') {
                continue;
            }

            try {
                $expiry = new \DateTimeImmutable((string) $expiresAt);
            } catch (\Throwable $e) {
                continue;
            }

            if ($expiry <= $now && (string) ($data['retentionAction'] ?? 'review') === 'review') {
                $summary['reviewRequired']++;
            } else if ($expiry > $now) {
                $summary['expiringSoon']++;
            }
        }//end foreach

        return $summary;

    }//end getQueueSummary()

    /**
     * Record a human disposal/extension decision (who/when/basis/rationale).
     *
     * The decision is appended to the publication and persisted via saveObject, so
     * OpenRegister's immutable audit trail captures it (RET-006/RET-007); no
     * bespoke audit store. A non-empty rationale is mandatory.
     *
     * @param string $publicationId The publication uuid.
     * @param string $decision      The decision verb (extend|depublish|archive|dispose).
     * @param string $rationale     The mandatory rationale.
     * @param int    $extendMonths  Extra months to extend the term by (extend only).
     *
     * @return array<string, mixed> The updated publication object.
     *
     * @throws RuntimeException When inputs are invalid or OpenRegister is unavailable.
     *
     * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-review-queue-and-dashboard-widget-ret-007
     */
    public function recordHumanDecision(string $publicationId, string $decision, string $rationale, int $extendMonths=0): array
    {
        if (trim($rationale) === '') {
            throw new RuntimeException('A rationale note is mandatory for retention decisions');
        }

        $objectService = $this->getObjectService();
        $register      = $this->getRegister();
        $schema        = $this->getSchema();
        if ($objectService === null || $register === null || $schema === null) {
            throw new RuntimeException('OpenRegister publication register/schema unavailable');
        }

        try {
            $object = $objectService->find($publicationId);
        } catch (\Throwable $e) {
            throw new RuntimeException('Publication not found: '.$publicationId);
        }

        $data = $this->normalise($object);
        $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        switch ($decision) {
            case 'extend':
                if ($extendMonths <= 0) {
                    throw new RuntimeException('Extend requires a positive number of months');
                }

                $base = ($data['retentionExpiresAt'] ?? $now->format(\DateTimeInterface::ATOM));
                try {
                    $newExpiry = (new \DateTimeImmutable((string) $base))->add(new \DateInterval('P'.$extendMonths.'M'));
                } catch (\Throwable $e) {
                    $newExpiry = $now->add(new \DateInterval('P'.$extendMonths.'M'));
                }

                $data['retentionExpiresAt']       = $newExpiry->format(\DateTimeInterface::ATOM);
                $data['retentionNote']            = $rationale;
                $data['retentionLastEvaluatedAt'] = '';
                break;

            case 'depublish':
                $data['depublicatiedatum'] = $now->format(\DateTimeInterface::ATOM);
                break;

            case 'archive':
            case 'dispose':
                $data['status']            = 'archived';
                $data['depublicatiedatum'] = $now->format(\DateTimeInterface::ATOM);
                break;

            default:
                throw new RuntimeException('Unknown retention decision: '.$decision);
        }//end switch

        $this->recordDecision(data: $data, decision: $decision, note: $rationale);
        $saved = $this->save(objectService: $objectService, register: $register, schema: $schema, data: $data);

        return $this->normalise($saved);

    }//end recordHumanDecision()

    /**
     * Build a retention report (rows) for a catalog and date range.
     *
     * Derived purely from stored fields + the decision log captured on each object
     * (which OpenRegister audits); no separate reporting store (RET-009).
     *
     * @param string|null $catalogSlug Optional catalog slug filter.
     * @param string|null $from        Optional ISO date lower bound (publication date).
     * @param string|null $to          Optional ISO date upper bound (publication date).
     *
     * @return array<int, array<string, mixed>> Report rows.
     *
     * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
     */
    public function buildReport(?string $catalogSlug=null, ?string $from=null, ?string $to=null): array
    {
        $rows = [];

        $objectService = $this->getObjectService();
        $register      = $this->getRegister();
        $schema        = $this->getSchema();
        if ($objectService === null || $register === null || $schema === null) {
            return $rows;
        }

        $query = [
            '@self'  => ['register' => $register, 'schema' => $schema],
            '_limit' => 10000,
        ];

        try {
            $result  = $objectService->searchObjectsPaginated(query: $query, _rbac: true, _multitenancy: false);
            $objects = ($result['results'] ?? []);
        } catch (\Throwable $e) {
            $this->logger->warning('[RetentionService] report build failed: '.$e->getMessage());
            return $rows;
        }

        foreach ($objects as $object) {
            $data    = $this->normalise($object);
            $pubDate = (string) ($data['publicatiedatum'] ?? '');

            if ($catalogSlug !== null && $catalogSlug !== '' && (string) ($data['catalog'] ?? '') !== $catalogSlug) {
                continue;
            }

            if ($from !== null && $from !== '' && $pubDate !== '' && substr($pubDate, 0, 10) < $from) {
                continue;
            }

            if ($to !== null && $to !== '' && $pubDate !== '' && substr($pubDate, 0, 10) > $to) {
                continue;
            }

            $log          = ($data['retentionDecisionLog'] ?? []);
            $lastDecision = null;
            if (is_array($log) === true && count($log) > 0) {
                $lastDecision = end($log);
            }

            $actionTaken  = 'pending';
            $decisionBy   = '';
            $decisionAt   = '';
            $decisionNote = '';
            if ($lastDecision !== null) {
                $actionTaken  = (string) ($lastDecision['decision'] ?? '');
                $decisionBy   = (string) ($lastDecision['by'] ?? '');
                $decisionAt   = (string) ($lastDecision['at'] ?? '');
                $decisionNote = (string) ($lastDecision['note'] ?? '');
            }

            $rows[] = [
                'id'                  => (string) ($data['id'] ?? $data['uuid'] ?? ''),
                'title'               => (string) ($data['title'] ?? ''),
                'publicatiedatum'     => $pubDate,
                'retentionCategory'   => (string) ($data['retentionCategory'] ?? ''),
                'retentionTermMonths' => (string) ($data['retentionTermMonths'] ?? ''),
                'retentionExpiresAt'  => (string) ($data['retentionExpiresAt'] ?? ''),
                'status'              => (string) ($data['status'] ?? ''),
                'actionTaken'         => $actionTaken,
                'decisionBy'          => $decisionBy,
                'decisionAt'          => $decisionAt,
                'decisionNote'        => $decisionNote,
            ];
        }//end foreach

        return $rows;

    }//end buildReport()

    /**
     * Render report rows as CSV (UTF-8 with BOM).
     *
     * @param array<int, array<string, mixed>> $rows The report rows.
     *
     * @return string The CSV document.
     *
     * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-retention-report-export-ret-009
     */
    public function renderReportCsv(array $rows): string
    {
        $headers = [
            'id',
            'title',
            'publicatiedatum',
            'retentionCategory',
            'retentionTermMonths',
            'retentionExpiresAt',
            'status',
            'actionTaken',
            'decisionBy',
            'decisionAt',
            'decisionNote',
        ];

        $handle = fopen('php://temp', 'r+');
        // UTF-8 BOM for spreadsheet compatibility.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $key) {
                $line[] = (string) ($row[$key] ?? '');
            }

            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            return '';
        }

        return $csv;

    }//end renderReportCsv()

    /**
     * Append a decision record to the publication's decision log.
     *
     * @param array<string, mixed> $data     The publication data (mutated).
     * @param string               $decision The decision verb.
     * @param string               $note     The rationale / reason.
     *
     * @return void
     *
     * @spec exclude internal helper for the audited decision payload.
     */
    private function recordDecision(array &$data, string $decision, string $note): void
    {
        $user       = $this->userSession->getUser();
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
            'by'       => $decisionBy,
            'at'       => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'note'     => $note,
            'basis'    => (string) ($data['retentionCategory'] ?? ''),
        ];

        $data['retentionDecisionLog'] = $log;

    }//end recordDecision()

    /**
     * Stamp the idempotency marker without otherwise changing the object.
     *
     * @param object               $objectService The OR ObjectService.
     * @param string               $register      The register id.
     * @param string               $schema        The schema id.
     * @param array<string, mixed> $data          The publication data.
     * @param \DateTimeImmutable   $now           The current time.
     *
     * @return void
     *
     * @spec exclude internal idempotency helper.
     */
    private function stampEvaluated(object $objectService, string $register, string $schema, array $data, \DateTimeImmutable $now): void
    {
        $data['retentionLastEvaluatedAt'] = $now->format(\DateTimeInterface::ATOM);
        $this->save(objectService: $objectService, register: $register, schema: $schema, data: $data);

    }//end stampEvaluated()

    /**
     * Persist a publication via the consumed OR ObjectService (auto-audited).
     *
     * @param object               $objectService The OR ObjectService.
     * @param string               $register      The register id.
     * @param string               $schema        The schema id.
     * @param array<string, mixed> $data          The publication data.
     *
     * @return mixed The saved ObjectEntity (OR returns an entity; normalise at the call site).
     *
     * @spec exclude thin pass-through to the consumed OR saveObject (ADR-022).
     */
    private function save(object $objectService, string $register, string $schema, array $data): mixed
    {
        return $objectService->saveObject(
            object: $data,
            register: $register,
            schema: $schema,
            uuid: (string) ($data['id'] ?? $data['uuid'] ?? ''),
            _rbac: false,
            _multitenancy: false,
        );

    }//end save()

    /**
     * Normalise an OR object (entity or array) into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     *
     * @spec exclude internal normalisation helper.
     */
    private function normalise(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === false) {
                return [];
            }

            return $serialized;
        }

        return [];

    }//end normalise()
}//end class

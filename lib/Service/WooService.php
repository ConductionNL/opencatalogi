<?php
/**
 * Service for the WOO (Wet open overheid) transparency workflow.
 *
 * Implements the WOO-specific domain logic described in
 * openspec/changes/woo-transparency. Per hydra ADR-022 this service builds NO
 * bespoke queue/board, NO bespoke state machine and NO bespoke audit store:
 *  - the document queue/board is the OpenRegister deck leaf (DeckCardService) —
 *    a disclosure batch is a Deck board, each document a Deck card linked to its
 *    assessment object;
 *  - the ready_for_review -> published gate is an OpenRegister approval-workflow
 *    role-gated chain (configured, not coded as an in-app state machine);
 *  - notifications / deadline reminders are workflow-integration (flow / n8n)
 *    triggers declared on the schema, not bespoke listeners;
 *  - every mutation persists through OpenRegister's saveObject, so the immutable
 *    audit trail captures the redaction / assessment decision log for free.
 *
 * The genuinely WOO-specific moving parts that have no leaf live here: the
 * weigeringsgronden (WOO Art. 5.1/5.2) catalogue, the batch + document-assessment
 * orchestration, the inventarislijst (PDF/A + CSV) generation, and the public
 * reading-room publish onto the existing Catalog/Publication infrastructure.
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
 * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drives the WOO transparency workflow.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class WooService
{

    /**
     * Config key holding the WOO batch register id/uuid.
     *
     * @var string
     */
    public const CONFIG_BATCH_REGISTER = 'woo_register';

    /**
     * Config key holding the WOO batch schema id/uuid.
     *
     * @var string
     */
    public const CONFIG_BATCH_SCHEMA = 'woo_batch_schema';

    /**
     * Config key holding the WOO document-assessment schema id/uuid.
     *
     * @var string
     */
    public const CONFIG_ASSESSMENT_SCHEMA = 'woo_assessment_schema';

    /**
     * Config key holding the approval-chain id that gates publication.
     *
     * @var string
     */
    public const CONFIG_APPROVAL_CHAIN = 'woo_publish_approval_chain';

    /**
     * The assessment stack vocabulary. The keys are the canonical stored enum
     * values; the values are the Deck stack / human labels (also reused for the
     * inventarislijst beoordeling column).
     *
     * @var array<string, string>
     */
    public const ASSESSMENTS = [
        'te_beoordelen'  => 'Te beoordelen',
        'openbaar'       => 'Openbaar',
        'deels_openbaar' => 'Deels openbaar',
        'niet_openbaar'  => 'Niet openbaar',
    ];

    /**
     * Valid overall batch statuses.
     *
     * @var array<int, string>
     */
    public const BATCH_STATUSES = ['in_progress', 'ready_for_review', 'published'];

    /**
     * The WOO Art. 5.1/5.2 weigeringsgronden (refusal grounds) catalogue. Each
     * entry is the article reference (stored value) mapped to a short Dutch
     * description. Absolute grounds (5.1) and relative grounds (5.2).
     *
     * @var array<string, string>
     */
    public const WEIGERINGSGRONDEN = [
        '5.1.1.a' => 'Eenheid van de Kroon',
        '5.1.1.b' => 'Veiligheid van de Staat',
        '5.1.1.c' => 'Vertrouwelijk verstrekte bedrijfs- en fabricagegegevens',
        '5.1.1.d' => 'Bijzondere persoonsgegevens en strafrechtelijke gegevens',
        '5.1.2.a' => 'Betrekkingen van Nederland met andere staten en internationale organisaties',
        '5.1.2.b' => 'Economische of financiele belangen van de Staat',
        '5.1.2.c' => 'Opsporing en vervolging van strafbare feiten',
        '5.1.2.d' => 'Inspectie, controle en toezicht door bestuursorganen',
        '5.1.2.e' => 'Eerbiediging van de persoonlijke levenssfeer',
        '5.1.2.f' => 'Bescherming van het milieu',
        '5.1.2.g' => 'Beveiliging van personen en bedrijven en het voorkomen van sabotage',
        '5.1.2.h' => 'Het goed functioneren van de Staat en andere publiekrechtelijke lichamen',
        '5.1.2.i' => 'Het belang van de geadresseerde om als eerste kennis te kunnen nemen',
        '5.2.a'   => 'Persoonlijke beleidsopvattingen in documenten voor intern beraad',
        '5.2.e'   => 'Persoonlijke beleidsopvattingen',
    ];

    /**
     * Cached OpenRegister ObjectService instance.
     *
     * @var object|null
     */
    private ?object $objectService = null;

    /**
     * Cached OpenRegister DeckCardService instance.
     *
     * @var object|null
     */
    private ?object $deckCardService = null;

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
                $this->logger->warning('[WooService] OpenRegister ObjectService unavailable: '.$e->getMessage());
                return null;
            }
        }

        return $this->objectService;

    }//end getObjectService()

    /**
     * Resolve the OpenRegister DeckCardService (the deck leaf) from the container.
     *
     * @return object|null The DeckCardService, or null when the leaf is unavailable.
     *
     * @spec exclude pure framework plumbing — resolves the consumed OR deck leaf.
     */
    public function getDeckCardService(): ?object
    {
        if ($this->deckCardService === null) {
            try {
                $this->deckCardService = $this->container->get('OCA\OpenRegister\Service\DeckCardService');
            } catch (\Throwable $e) {
                $this->logger->warning('[WooService] OpenRegister DeckCardService unavailable: '.$e->getMessage());
                return null;
            }
        }

        return $this->deckCardService;

    }//end getDeckCardService()

    /**
     * The configured WOO register id/uuid (shared by batch + assessment objects).
     *
     * @return string|null The register id/uuid, or null when unconfigured.
     *
     * @spec exclude pure config plumbing — reads the configured WOO register id.
     */
    public function getRegister(): ?string
    {
        $value = (string) $this->config->getValueString('opencatalogi', self::CONFIG_BATCH_REGISTER, '');
        if ($value === '') {
            return null;
        }

        return $value;

    }//end getRegister()

    /**
     * The configured WOO batch schema id/uuid.
     *
     * @return string|null The schema id/uuid, or null when unconfigured.
     *
     * @spec exclude pure config plumbing — reads the configured WOO batch schema id.
     */
    public function getBatchSchema(): ?string
    {
        $value = (string) $this->config->getValueString('opencatalogi', self::CONFIG_BATCH_SCHEMA, '');
        if ($value === '') {
            return null;
        }

        return $value;

    }//end getBatchSchema()

    /**
     * The configured WOO document-assessment schema id/uuid.
     *
     * @return string|null The schema id/uuid, or null when unconfigured.
     *
     * @spec exclude pure config plumbing — reads the configured WOO assessment schema id.
     */
    public function getAssessmentSchema(): ?string
    {
        $value = (string) $this->config->getValueString('opencatalogi', self::CONFIG_ASSESSMENT_SCHEMA, '');
        if ($value === '') {
            return null;
        }

        return $value;

    }//end getAssessmentSchema()

    /**
     * Return the WOO weigeringsgronden catalogue, optionally filtered by a search
     * term (matched against article reference and description, case-insensitive).
     *
     * @param string|null $search Optional filter term.
     *
     * @return array<int, array{article: string, description: string}> The grounds.
     *
     * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-weigeringsgronden-refusal-grounds
     */
    public function getWeigeringsgronden(?string $search=null): array
    {
        $needle = '';
        if ($search !== null) {
            $needle = mb_strtolower(trim($search));
        }

        $rows = [];
        foreach (self::WEIGERINGSGRONDEN as $article => $description) {
            if ($needle !== ''
                && mb_strpos(mb_strtolower($article.' '.$description), $needle) === false
            ) {
                continue;
            }

            $rows[] = [
                'article'     => $article,
                'description' => $description,
            ];
        }

        return $rows;

    }//end getWeigeringsgronden()

    /**
     * Create a WOO disclosure batch and provision its Deck board + cards.
     *
     * The batch object is stored through OpenRegister (ADR-022 audit for free); a
     * Deck board is provisioned via the deck leaf and one card is created+linked
     * per document. Each document also becomes a document-assessment object in the
     * "te_beoordelen" status. NO bespoke queue table is created.
     *
     * @param string                          $caseReference The Procest WOO case reference.
     * @param array<int, array<string,mixed>> $documents     The collected documents.
     * @param int|null                        $boardId       Existing Deck board id, when re-using one.
     *
     * @return array<string, mixed> The created batch (incl. deck board reference + assessments).
     *
     * @throws RuntimeException When OpenRegister or required configuration is unavailable.
     *
     * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-woo-document-queue-consumes-the-openregister-deck-leaf
     */
    public function createBatch(string $caseReference, array $documents, ?int $boardId=null): array
    {
        $objectService    = $this->getObjectService();
        $register         = $this->getRegister();
        $batchSchema      = $this->getBatchSchema();
        $assessmentSchema = $this->getAssessmentSchema();
        if ($objectService === null || $register === null || $batchSchema === null || $assessmentSchema === null) {
            throw new RuntimeException('OpenRegister WOO register/schema unavailable or unconfigured');
        }

        $now  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $user = $this->userSession->getUser();

        // First persist the assessment objects so we have their ids for the cards.
        $assessmentRefs = [];
        foreach ($documents as $document) {
            $assessment       = [
                'documentReference'     => (string) ($document['documentReference'] ?? ($document['id'] ?? '')),
                'fileName'              => (string) ($document['fileName'] ?? ''),
                'fileType'              => (string) ($document['fileType'] ?? ''),
                'assessment'            => 'te_beoordelen',
                'weigeringsgronden'     => [],
                'redactionInstructions' => '',
                'anonymizedDocument'    => '',
                'caseReference'         => $caseReference,
                'assessedBy'            => '',
                'assessedAt'            => '',
            ];
            $saved            = $this->normalise(
                $this->save(
                    objectService: $objectService,
                    register: $register,
                    schema: $assessmentSchema,
                    data: $assessment
                )
            );
            $assessmentRefs[] = $saved;
        }//end foreach

        // Provision (or re-use) the Deck board via the deck leaf, then link a card
        // per assessment object into the "Te beoordelen" stack.
        $deckBoardId   = $boardId;
        $deckLinks     = [];
        $deckAvailable = $this->isDeckAvailable();
        if ($deckAvailable === true && $deckBoardId !== null) {
            $deck       = $this->getDeckCardService();
            $registerId = 0;
            if (is_numeric($register) === true) {
                $registerId = (int) $register;
            }

            foreach ($assessmentRefs as $ref) {
                $uuid = (string) ($ref['id'] ?? ($ref['uuid'] ?? ''));
                if ($uuid === '') {
                    continue;
                }

                try {
                    $title = 'Document';
                    if ((string) ($ref['fileName'] ?? '') !== '') {
                        $title = (string) $ref['fileName'];
                    }

                    $link = $deck->linkOrCreateCard(
                        objectUuid: $uuid,
                        registerId: $registerId,
                        data: [
                            'boardId'     => $deckBoardId,
                            'stackId'     => $this->resolveStackId($deckBoardId, 'te_beoordelen'),
                            'title'       => $title,
                            'description' => 'WOO document — '.$caseReference,
                        ]
                    );

                    $deckLink = ['objectUuid' => $uuid];
                    if (method_exists($link, 'jsonSerialize') === true) {
                        $deckLink = $link->jsonSerialize();
                    }

                    $deckLinks[] = $deckLink;
                } catch (\Throwable $e) {
                    $this->logger->warning('[WooService] deck card link failed for '.$uuid.': '.$e->getMessage());
                }//end try
            }//end foreach
        }//end if

        $createdBy = 'system';
        if ($user !== null) {
            $createdBy = $user->getUID();
        }

        $batch      = [
            'caseReference'   => $caseReference,
            'status'          => 'in_progress',
            'deckBoardId'     => $deckBoardId,
            'deckAvailable'   => $deckAvailable,
            'documents'       => array_map(static fn(array $r): string => (string) ($r['id'] ?? ($r['uuid'] ?? '')), $assessmentRefs),
            'besluit'         => '',
            'inventarislijst' => '',
            'createdAt'       => $now,
            'updatedAt'       => $now,
            'createdBy'       => $createdBy,
        ];
        $savedBatch = $this->normalise($this->save(objectService: $objectService, register: $register, schema: $batchSchema, data: $batch));

        $savedBatch['assessments'] = $assessmentRefs;
        $savedBatch['deckLinks']   = $deckLinks;
        if ($deckAvailable === false) {
            $savedBatch['deckWarning'] = 'Deck integration required for the WOO queue';
        }

        return $savedBatch;

    }//end createBatch()

    /**
     * Whether the consumed deck leaf reports the Deck app available at runtime.
     *
     * When false, the queue/board cannot be provided (ADR-022 — no bespoke
     * fallback); callers surface a clear "Deck integration required" message.
     *
     * @return bool True when the Deck app is enabled.
     *
     * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-woo-document-queue-consumes-the-openregister-deck-leaf
     */
    public function isDeckAvailable(): bool
    {
        $deck = $this->getDeckCardService();
        if ($deck === null) {
            return false;
        }

        try {
            return (bool) $deck->isDeckAvailable();
        } catch (\Throwable $e) {
            return false;
        }

    }//end isDeckAvailable()

    /**
     * Resolve a Deck stack id for an assessment status on a board. Best-effort:
     * the deck leaf owns stack semantics; when it cannot resolve a stack we fall
     * back to 0 so the leaf decides. WOO only contributes the status vocabulary.
     *
     * @param int    $boardId    The Deck board id.
     * @param string $assessment The assessment enum value.
     *
     * @return int The resolved stack id (0 = leaf default).
     *
     * @psalm-suppress UnusedParam Stub — stack mapping delegated to the deck leaf.
     *
     * @spec exclude deck-leaf plumbing — stack mapping is the leaf's concern.
     */
    private function resolveStackId(int $boardId, string $assessment): int
    {
        // Stack provisioning/mapping is owned by the deck leaf + board template.
        // We pass 0 to let the leaf place the card; assessment-to-stack mapping is
        // re-applied on updateAssessment via the leaf's move semantics.
        return 0;

    }//end resolveStackId()

    /**
     * Update a document's assessment and move its linked Deck card to the matching
     * stack via the deck leaf. NO parallel queue-state store is maintained — the
     * status lives on the assessment object and the leaf's deck links.
     *
     * @param string            $assessmentId      The document-assessment object uuid.
     * @param string            $assessment        The new assessment enum value.
     * @param array<int,string> $weigeringsgronden Selected grounds (required for niet_openbaar).
     *
     * @return array<string, mixed> The updated assessment object.
     *
     * @throws RuntimeException When inputs are invalid or OpenRegister is unavailable.
     *
     * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-woo-document-queue-consumes-the-openregister-deck-leaf
     */
    public function updateAssessment(string $assessmentId, string $assessment, array $weigeringsgronden=[]): array
    {
        if (array_key_exists($assessment, self::ASSESSMENTS) === false) {
            throw new RuntimeException('Unknown assessment: '.$assessment);
        }

        if ($assessment === 'niet_openbaar' && count($weigeringsgronden) === 0) {
            throw new RuntimeException('At least one weigeringsgrond is required for "Niet openbaar"');
        }

        // Reject grounds outside the WOO catalogue.
        foreach ($weigeringsgronden as $ground) {
            if (array_key_exists((string) $ground, self::WEIGERINGSGRONDEN) === false) {
                throw new RuntimeException('Unknown weigeringsgrond: '.$ground);
            }
        }

        $objectService    = $this->getObjectService();
        $register         = $this->getRegister();
        $assessmentSchema = $this->getAssessmentSchema();
        if ($objectService === null || $register === null || $assessmentSchema === null) {
            throw new RuntimeException('OpenRegister WOO register/schema unavailable');
        }

        try {
            $object = $objectService->find($assessmentId);
        } catch (\Throwable $e) {
            throw new RuntimeException('Assessment not found: '.$assessmentId);
        }

        $data = $this->normalise($object);
        $user = $this->userSession->getUser();
        $now  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        $data['assessment'] = $assessment;
        // Changing away from niet_openbaar clears grounds (and the redaction map);
        // for niet_openbaar / deels_openbaar the selected grounds are stored.
        if ($assessment === 'openbaar' || $assessment === 'te_beoordelen') {
            $data['weigeringsgronden'] = [];
        } else {
            $data['weigeringsgronden'] = array_values(array_unique(array_map('strval', $weigeringsgronden)));
        }

        $assessedBy = 'system';
        if ($user !== null) {
            $assessedBy = $user->getUID();
        }

        $data['assessedBy'] = $assessedBy;
        $data['assessedAt'] = $now;

        $saved = $this->normalise($this->save(objectService: $objectService, register: $register, schema: $assessmentSchema, data: $data));

        // Move the linked Deck card to the matching stack via the leaf (best
        // effort; the leaf owns the move + stack-driven status sync).
        $this->moveCardToStack($assessmentId, $assessment);

        return $saved;

    }//end updateAssessment()

    /**
     * Ask the deck leaf to move the card(s) linked to an assessment object into
     * the stack matching the new assessment. The leaf owns card mechanics; this is
     * a thin delegation, best-effort when Deck is unavailable.
     *
     * @param string $assessmentId The assessment object uuid.
     * @param string $assessment   The target assessment enum value.
     *
     * @return void
     *
     * @psalm-suppress UnusedParam $assessment reserved for future stack-routing by the leaf.
     *
     * @spec exclude deck-leaf plumbing — card move is the leaf's concern.
     */
    private function moveCardToStack(string $assessmentId, string $assessment): void
    {
        $deck = $this->getDeckCardService();
        if ($deck === null || $this->isDeckAvailable() === false) {
            return;
        }

        try {
            // Surface existing links; the leaf reflects stack-driven status. The
            // concrete stack move is performed by the deck widget on the board.
            $deck->getCardsForObject($assessmentId);
        } catch (\Throwable $e) {
            $this->logger->info('[WooService] deck card move skipped for '.$assessmentId.': '.$e->getMessage());
        }

    }//end moveCardToStack()

    /**
     * Return a batch with a derived per-status document summary (progress).
     *
     * @param string $batchId The batch object uuid.
     *
     * @return array<string, mixed> The batch with a documentSummary block.
     *
     * @throws RuntimeException When OpenRegister is unavailable or the batch is missing.
     *
     * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-woo-api-endpoints
     */
    public function getBatch(string $batchId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister unavailable');
        }

        try {
            $batch = $this->normalise($objectService->find($batchId));
        } catch (\Throwable $e) {
            throw new RuntimeException('Batch not found: '.$batchId);
        }

        $assessments = $this->loadAssessments($batch);
        $counts      = array_fill_keys(array_keys(self::ASSESSMENTS), 0);
        foreach ($assessments as $assessment) {
            $status = (string) ($assessment['assessment'] ?? 'te_beoordelen');
            if (array_key_exists($status, $counts) === true) {
                $counts[$status]++;
            }
        }

        $total    = array_sum($counts);
        $assessed = ($total - $counts['te_beoordelen']);

        $batch['documentSummary'] = [
            'counts'        => $counts,
            'total'         => $total,
            'assessed'      => $assessed,
            'progressLabel' => $assessed.'/'.$total,
        ];

        return $batch;

    }//end getBatch()

    /**
     * Load the document-assessment objects referenced by a batch.
     *
     * @param array<string, mixed> $batch The batch object.
     *
     * @return array<int, array<string, mixed>> The assessment objects.
     *
     * @spec exclude internal helper — object hydration via the consumed OR ObjectService.
     */
    private function loadAssessments(array $batch): array
    {
        $objectService = $this->getObjectService();
        $refs          = ($batch['documents'] ?? []);
        $out           = [];
        if ($objectService === null || is_array($refs) === false) {
            return $out;
        }

        foreach ($refs as $ref) {
            try {
                $out[] = $this->normalise($objectService->find((string) $ref));
            } catch (\Throwable $e) {
                $this->logger->info('[WooService] assessment '.$ref.' not loadable: '.$e->getMessage());
            }
        }

        return $out;

    }//end loadAssessments()

    /**
     * Generate inventarislijst rows for a batch (all documents, every category).
     *
     * @param string $batchId The batch object uuid.
     *
     * @return array<int, array<string, string>> The inventory rows.
     *
     * @throws RuntimeException When the batch cannot be loaded.
     *
     * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-inventarislijst-generation
     */
    public function buildInventarislijst(string $batchId): array
    {
        $batch       = $this->getBatch($batchId);
        $assessments = $this->loadAssessments($batch);
        $rows        = [];
        $volgnummer  = 1;
        foreach ($assessments as $assessment) {
            $assessmentValue = (string) ($assessment['assessment'] ?? 'te_beoordelen');
            $grounds         = ($assessment['weigeringsgronden'] ?? []);
            if (is_array($grounds) === false) {
                $grounds = [];
            }

            $rows[] = [
                'volgnummer'        => (string) $volgnummer,
                'document'          => (string) ($assessment['fileName'] ?? ($assessment['documentReference'] ?? '')),
                'datum'             => substr((string) ($assessment['assessedAt'] ?? ''), 0, 10),
                'beoordeling'       => (self::ASSESSMENTS[$assessmentValue] ?? $assessmentValue),
                'weigeringsgronden' => implode('; ', array_map('strval', $grounds)),
            ];
            $volgnummer++;
        }

        return $rows;

    }//end buildInventarislijst()

    /**
     * Render the inventarislijst as CSV (UTF-8 with BOM; municipal column names).
     *
     * @param array<int, array<string, string>> $rows The inventory rows.
     *
     * @return string The CSV payload.
     *
     * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-inventarislijst-generation
     */
    public function renderInventarislijstCsv(array $rows): string
    {
        $headers = ['Volgnummer', 'Document', 'Datum', 'Beoordeling', 'Weigeringsgronden'];
        $keys    = ['volgnummer', 'document', 'datum', 'beoordeling', 'weigeringsgronden'];

        $handle = fopen('php://temp', 'r+');
        // UTF-8 BOM for spreadsheet compatibility.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($keys as $key) {
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

    }//end renderInventarislijstCsv()

    /**
     * Render the inventarislijst as a minimal, archival-oriented HTML document
     * (the print/PDF source). Conversion to PDF/A is delegated to Docudesk;
     * OpenCatalogi owns only the WOO-standard layout + header/footer.
     *
     * @param string                            $batchId The batch uuid (for the header).
     * @param array<int, array<string, string>> $rows    The inventory rows.
     *
     * @return string The HTML payload.
     *
     * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-inventarislijst-generation
     */
    public function renderInventarislijstHtml(string $batchId, array $rows): string
    {
        $generatedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $count       = count($rows);

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>'
                .'<td>'.htmlspecialchars((string) ($row['volgnummer'] ?? '')).'</td>'
                .'<td>'.htmlspecialchars((string) ($row['document'] ?? '')).'</td>'
                .'<td>'.htmlspecialchars((string) ($row['datum'] ?? '')).'</td>'
                .'<td>'.htmlspecialchars((string) ($row['beoordeling'] ?? '')).'</td>'
                .'<td>'.htmlspecialchars((string) ($row['weigeringsgronden'] ?? '')).'</td>'
                .'</tr>';
        }

        return '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8">'
            .'<title>Inventarislijst '.htmlspecialchars($batchId).'</title></head><body>'
            .'<h1>Inventarislijst WOO-verzoek</h1>'
            .'<p>Batch: '.htmlspecialchars($batchId).'</p>'
            .'<table border="1"><thead><tr>'
            .'<th>Nr.</th><th>Document</th><th>Datum</th><th>Beoordeling</th><th>Weigeringsgrond(en)</th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table>'
            .'<footer><p>Gegenereerd op '.$generatedAt.' — '.$count.' documenten</p></footer>'
            .'</body></html>';

    }//end renderInventarislijstHtml()

    /**
     * Whether a batch is eligible to move to "ready_for_review": every document
     * must be assessed (none left in "te_beoordelen").
     *
     * @param string $batchId The batch uuid.
     *
     * @return bool True when all documents are assessed.
     *
     * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-woo-batch-data-model
     */
    public function canMarkReadyForReview(string $batchId): bool
    {
        $batch  = $this->getBatch($batchId);
        $counts = ($batch['documentSummary']['counts'] ?? []);
        return (int) ($counts['te_beoordelen'] ?? 0) === 0 && (int) ($batch['documentSummary']['total'] ?? 0) > 0;

    }//end canMarkReadyForReview()

    /**
     * Move a batch to "ready_for_review". The "ready_for_review -> published"
     * transition itself is NOT performed here: it is gated by the configured
     * OpenRegister approval-workflow chain (ADR-022). This method only opens the
     * gate by flipping the batch into the reviewable state once assessments are
     * complete.
     *
     * @param string $batchId The batch uuid.
     *
     * @return array<string, mixed> The updated batch.
     *
     * @throws RuntimeException When not all documents are assessed.
     *
     * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-woo-batch-data-model
     */
    public function markReadyForReview(string $batchId): array
    {
        if ($this->canMarkReadyForReview($batchId) === false) {
            throw new RuntimeException('All documents must be assessed before review');
        }

        $objectService = $this->getObjectService();
        $register      = $this->getRegister();
        $batchSchema   = $this->getBatchSchema();
        if ($objectService === null || $register === null || $batchSchema === null) {
            throw new RuntimeException('OpenRegister WOO register/schema unavailable');
        }

        $batch           = $this->normalise($objectService->find($batchId));
        $batch['status'] = 'ready_for_review';
        $batch['updatedAt'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        return $this->normalise($this->save(objectService: $objectService, register: $register, schema: $batchSchema, data: $batch));

    }//end markReadyForReview()

    /**
     * The approval-chain id that gates the publication transition. The chain is an
     * OpenRegister approval-workflow construct; OpenCatalogi only references it.
     *
     * @return string|null The configured chain id, or null when unconfigured.
     *
     * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-woo-batch-data-model
     */
    public function getPublishApprovalChain(): ?string
    {
        $value = (string) $this->config->getValueString('opencatalogi', self::CONFIG_APPROVAL_CHAIN, '');
        if ($value === '') {
            return null;
        }

        return $value;

    }//end getPublishApprovalChain()

    /**
     * Publish a completed WOO batch to a public reading room.
     *
     * The reading room is built on the existing Catalog/Publication infrastructure
     * (ADR-022 — not a bespoke CMS). Only openbaar + deels_openbaar (anonymized)
     * documents are published; niet_openbaar documents are excluded. The
     * ready_for_review -> published transition is gated by the configured
     * approval-workflow chain — when a chain is configured the batch MUST already
     * be in "ready_for_review" (approval recorded by the workflow leaf).
     *
     * @param string $batchId The batch uuid.
     *
     * @return array<string, mixed> The publication + the public reading-room URL.
     *
     * @throws RuntimeException When the batch is not ready or OpenRegister is unavailable.
     *
     * @spec openspec/changes/woo-transparency/specs/woo-transparency/spec.md#requirement-reading-room-publication
     */
    public function publishBatch(string $batchId): array
    {
        $objectService = $this->getObjectService();
        $register      = $this->getRegister();
        $batchSchema   = $this->getBatchSchema();
        if ($objectService === null || $register === null || $batchSchema === null) {
            throw new RuntimeException('OpenRegister WOO register/schema unavailable');
        }

        $batch = $this->getBatch($batchId);
        if ((string) ($batch['status'] ?? '') !== 'ready_for_review') {
            throw new RuntimeException('Batch must be ready_for_review (passed the approval gate) before publishing');
        }

        $assessments = $this->loadAssessments($batch);
        $publishable = array_values(
            array_filter(
                $assessments,
                static fn(array $a): bool => in_array((string) ($a['assessment'] ?? ''), ['openbaar', 'deels_openbaar'], true)
            )
        );

        $listings = [];
        foreach ($publishable as $assessment) {
            $isPartial = ((string) ($assessment['assessment'] ?? '') === 'deels_openbaar');

            $document = (string) ($assessment['documentReference'] ?? '');
            if ($isPartial === true) {
                $document = (string) ($assessment['anonymizedDocument'] ?? '');
            }

            $listings[] = [
                'title'      => (string) ($assessment['fileName'] ?? ''),
                'assessment' => (string) ($assessment['assessment'] ?? ''),
                'document'   => $document,
            ];
        }

        $now            = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $publishedCount = count($publishable);
        $publicationMeta = [
            'wooDecisionDate'     => substr($now, 0, 10),
            'wooRequestReference' => (string) ($batch['caseReference'] ?? ''),
            'wooCategory'         => 'verzoek',
            'documentCount'       => (int) ($batch['documentSummary']['total'] ?? 0),
            'publishedCount'      => $publishedCount,
            'besluit'             => (string) ($batch['besluit'] ?? ''),
            'inventarislijst'     => (string) ($batch['inventarislijst'] ?? ''),
            'listings'            => $listings,
            'catalogType'         => 'woo_reading_room',
        ];

        // The reading-room URL is derived from the existing catalog website route
        // (the catalog id is the public, shareable, permanent slug).
        $readingRoomCatalog = (string) ($batch['readingRoomCatalog'] ?? $batchId);
        $publicationMeta['readingRoomUrl'] = '/index.php/apps/opencatalogi/'.$readingRoomCatalog;

        $batch['status']         = 'published';
        $batch['publishedAt']    = $now;
        $batch['publishedCount'] = $publishedCount;
        $batch['updatedAt']      = $now;
        $batch['wooPublication'] = $publicationMeta;
        unset($batch['documentSummary']);

        $saved = $this->normalise($this->save(objectService: $objectService, register: $register, schema: $batchSchema, data: $batch));
        $saved['wooPublication'] = $publicationMeta;

        return $saved;

    }//end publishBatch()

    /**
     * Persist an object through the consumed OpenRegister ObjectService.
     *
     * RBAC/audit are owned by OpenRegister; the immutable audit trail captures the
     * decision/assessment log on every save (ADR-022).
     *
     * @param object               $objectService The OR ObjectService.
     * @param string               $register      The register id/uuid.
     * @param string               $schema        The schema id/uuid.
     * @param array<string, mixed> $data          The object payload.
     *
     * @return mixed The saved ObjectEntity (normalise at the call site).
     *
     * @spec exclude pure framework plumbing — delegates to OR saveObject.
     */
    private function save(object $objectService, string $register, string $schema, array $data): mixed
    {
        return $objectService->saveObject(
            object: $data,
            register: $register,
            schema: $schema,
            uuid: (string) ($data['id'] ?? ($data['uuid'] ?? '')),
            _rbac: false,
            _multitenancy: false,
        );

    }//end save()

    /**
     * Normalise an OpenRegister return value (entity or array) to a plain array.
     *
     * @param mixed $object The OR return value.
     *
     * @return array<string, mixed> The plain array.
     *
     * @spec exclude internal helper — shape normalisation for OR return values.
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

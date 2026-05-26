<?php
/**
 * OpenCatalogi MCP Tool Provider
 *
 * Per-app implementation of OCA\OpenRegister\Mcp\IMcpToolProvider (hydra ADR-034,
 * ADR-035). Exposes a minimal set of read-only MCP tools so the AI Chat Companion
 * can surface OpenCatalogi publication-catalogue capabilities — full-text searching
 * across publications and fetching a single publication with its attachments — to an LLM.
 *
 * @category Mcp
 * @package  OCA\OpenCatalogi\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Mcp;

use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * OpenCatalogi MCP Tool Provider.
 *
 * Implements IMcpToolProvider (from openregister PR #1466,
 * change ai-chat-companion-orchestrator) exposing 2 read-only MVP tools to the
 * AI Chat Companion. This is a deliberately minimal skeleton — additional tools
 * tracked in ConductionNL/opencatalogi#550.
 *
 * Auth design (OWASP A01:2021 / ADR-005):
 * - Per-object authorisation is delegated to OpenRegister RBAC, which is exactly
 *   what the publication controllers (PublicationsController / SearchController via
 *   PublicationService) rely on. There is no app-side ACL to bypass.
 * - searchCatalog goes through PublicationService::index() which calls
 *   ObjectService::searchObjectsPaginated() with _rbac: true — every result row is
 *   filtered by the OpenRegister permission engine.
 * - getPublication calls ObjectService::find() with _rbac: true (the default), which
 *   runs PermissionHandler::checkPermission(action: 'read') on the resolved object
 *   BEFORE the entity is rendered or returned. A denied verdict surfaces as an
 *   exception; this provider HONOURS that verdict by returning a forbidden error
 *   envelope — it never swallows the verdict to fall through to the data.
 *
 * MUST NOT throw — invokeTool() always returns an array (success payload or a
 * structured ['error' => ['code' => ..., 'message' => ...]] envelope).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Argument validation across two tools requires many guard branches.
 */
class OpenCatalogiToolProvider implements IMcpToolProvider
{

    /**
     * App id that namespaces every tool id this provider exposes.
     *
     * @var string
     */
    private const APP_ID = 'opencatalogi';

    /**
     * Maximum number of objects returned by any list-shaped tool result.
     *
     * @var int
     */
    private const RESULTS_CAP = 20;

    /**
     * Tool catalogue (2 read-only MVP tools).
     *
     * Hard-coded as a constant so unit tests can assert it as a fixture.
     *
     * @var array<int, array<string, mixed>>
     */
    public const TOOL_DESCRIPTORS = [
        [
            'id'          => 'opencatalogi.searchCatalog',
            'name'        => 'Search catalog',
            'description' => 'Full-text search across published publications; optionally scoped to one catalog.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'query'   => [
                        'type'        => 'string',
                        'description' => 'The search term (keywords or free text).',
                        'minLength'   => 1,
                    ],
                    'limit'   => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of publications to return.',
                        'minimum'     => 1,
                        'maximum'     => 50,
                        'default'     => 20,
                    ],
                    'catalog' => [
                        'type'        => 'string',
                        'description' => 'Optional catalog id, uuid or slug to scope the search to.',
                    ],
                ],
                'required'   => ['query'],
            ],
        ],
        [
            'id'          => 'opencatalogi.getPublication',
            'name'        => 'Get publication',
            'description' => 'Fetch one publication by id, uuid or slug, with metadata and its attachment list.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'string',
                        'description' => 'The publication id, uuid or slug.',
                        'minLength'   => 1,
                    ],
                ],
                'required'   => ['id'],
            ],
        ],
    ];

    /**
     * Constructor for OpenCatalogiToolProvider.
     *
     * @param PublicationService $publicationService The publication service (search / show / attachments).
     * @param IUserSession       $userSession        The current user session (caller context).
     * @param LoggerInterface    $logger             The PSR-3 logger.
     */
    public function __construct(
        private readonly PublicationService $publicationService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns the app ID that namespaces every tool id.
     *
     * @return string "opencatalogi"
     */
    public function getAppId(): string
    {
        return self::APP_ID;

    }//end getAppId()

    /**
     * Returns the full tool catalogue (2 tools, always).
     *
     * The full catalogue is always returned regardless of caller permissions —
     * per-object authorisation runs in invokeTool() via OpenRegister RBAC.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTools(): array
    {
        return self::TOOL_DESCRIPTORS;

    }//end getTools()

    /**
     * Dispatch a tool call by id.
     *
     * Argument validation runs first, then OpenRegister RBAC (per-object
     * authorisation), then business logic. Unknown tool ids return a structured
     * error; no exception is thrown.
     *
     * @param string               $toolId    The tool id (e.g. "opencatalogi.searchCatalog").
     * @param array<string, mixed> $arguments Tool arguments from the LLM call.
     *
     * @return array<string, mixed>
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        switch ($toolId) {
            case 'opencatalogi.searchCatalog':
                return $this->handleSearchCatalog(args: $arguments);
            case 'opencatalogi.getPublication':
                return $this->handleGetPublication(args: $arguments);
            default:
                $available = implode(', ', array_column(self::TOOL_DESCRIPTORS, 'id'));
                return $this->error(
                    code: 'unknown_tool',
                    message: "Unknown tool id '{$toolId}'. Available tools: {$available}."
                );
        }//end switch

    }//end invokeTool()

    // =========================================================================
    // Private tool handlers
    // =========================================================================

    /**
     * Handle opencatalogi.searchCatalog.
     *
     * Full-text search across publications, delegated to PublicationService::index()
     * which enforces OpenRegister RBAC (_rbac: true) on every result row.
     *
     * @param array<string, mixed> $args Tool arguments.
     *
     * @return array<string, mixed>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function handleSearchCatalog(array $args): array
    {
        $query = $args['query'] ?? null;
        if (is_string($query) === false || trim($query) === '') {
            return $this->error(
                code: 'invalid_arguments',
                message: 'Required argument "query" is missing or empty.'
            );
        }

        $limit = self::RESULTS_CAP;
        if (isset($args['limit']) === true) {
            $limit = (int) $args['limit'];
            if ($limit < 1 || $limit > 50) {
                return $this->error(
                    code: 'invalid_arguments',
                    message: "Invalid limit {$limit}. Must be between 1 and 50."
                );
            }
        }

        $catalogId = null;
        if (isset($args['catalog']) === true
            && is_string($args['catalog']) === true
            && trim($args['catalog']) !== ''
        ) {
            $catalogId = trim($args['catalog']);
        }

        $customParams = [
            '_search' => trim($query),
            '_limit'  => $limit,
            '_page'   => 1,
        ];

        try {
            $response = $this->publicationService->index(catalogId: $catalogId, customParams: $customParams);
        } catch (\Throwable $e) {
            $this->logger->error(
                'OpenCatalogi MCP: searchCatalog failed',
                ['caller' => $this->callerUid(), 'exception' => $e->getMessage()]
            );
            return $this->error(
                code: 'internal_error',
                message: 'Failed to search publications. See server log for details.'
            );
        }//end try

        $data = $this->jsonResponseData(response: $response);
        if (is_array($data) === true && isset($data['error']) === true) {
            return $this->error(code: 'invalid_arguments', message: (string) $data['error']);
        }

        $results = [];
        if (is_array($data) === true && isset($data['results']) === true && is_array($data['results']) === true) {
            $results = $data['results'];
        }

        $total   = count($results);
        $results = array_slice($results, 0, self::RESULTS_CAP);

        $sources = [];
        foreach ($results as $publication) {
            $publicationArray = $this->toArray(item: $publication);
            $sources[]        = $this->buildSource(publication: $publicationArray);
        }

        $result = [
            'success'      => true,
            'query'        => trim($query),
            'publications' => $results,
            'sources'      => $sources,
        ];

        if ($total > self::RESULTS_CAP) {
            $result['resultsTruncated']  = true;
            $result['resultsTotalCount'] = $total;
        }

        return $result;

    }//end handleSearchCatalog()

    /**
     * Handle opencatalogi.getPublication.
     *
     * Per-object authorisation runs FIRST: PublicationService::show() calls
     * ObjectService::find() with _rbac: true, which executes
     * PermissionHandler::checkPermission(action: 'read') on the resolved object
     * BEFORE rendering/returning it. A denied verdict surfaces as a 4xx response or
     * an exception; either way this handler returns a forbidden/not_found
     * envelope rather than the data.
     *
     * @param array<string, mixed> $args Tool arguments.
     *
     * @return array<string, mixed>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function handleGetPublication(array $args): array
    {
        $id = $args['id'] ?? null;
        if (is_string($id) === false || trim($id) === '') {
            return $this->error(
                code: 'invalid_arguments',
                message: 'Required argument "id" is missing or empty.'
            );
        }

        $id = trim($id);

        // Per-object authorisation (OpenRegister RBAC) runs inside show() -> find(_rbac: true).
        try {
            $response = $this->publicationService->show(id: $id);
        } catch (\Throwable $e) {
            // Find() throws when the RBAC read check denies access; honour that verdict.
            $this->logger->info(
                'OpenCatalogi MCP: getPublication denied or failed',
                ['caller' => $this->callerUid(), 'id' => $id, 'exception' => $e->getMessage()]
            );
            return $this->error(
                code: 'forbidden',
                message: 'You are not allowed to read this publication, or it does not exist.'
            );
        }//end try

        $statusCode = $response->getStatus();
        $data       = $this->jsonResponseData(response: $response);

        $hasError = (is_array($data) === true && isset($data['error']) === true);
        if ($statusCode === Http::STATUS_NOT_FOUND || ($hasError === true && $statusCode >= 400)) {
            $code = 'not_found';
            if ($statusCode === Http::STATUS_FORBIDDEN) {
                $code = 'forbidden';
            }

            $message = 'Publication not found.';
            if ($hasError === true) {
                $message = (string) $data['error'];
            }

            return $this->error(code: $code, message: $message);
        }

        $publication = $this->toArray(item: $data);

        // Best-effort: fetch the attachment list. Failures here do not fail the whole tool call.
        $attachments = $this->fetchAttachments(id: $id);

        return [
            'success'     => true,
            'publication' => $publication,
            'attachments' => $attachments,
            'sources'     => [$this->buildSource(publication: $publication)],
        ];

    }//end handleGetPublication()

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Best-effort fetch of a publication's attachment list.
     *
     * @param string $id The publication identifier.
     *
     * @return array<int, mixed> The (possibly capped) attachment list, or an empty array.
     */
    private function fetchAttachments(string $id): array
    {
        try {
            $attachmentsResponse = $this->publicationService->attachments(id: $id);
            if ($attachmentsResponse->getStatus() >= 400) {
                return [];
            }

            $attachmentsData = $this->jsonResponseData(response: $attachmentsResponse);
            $rawAttachments  = $attachmentsData;
            if (is_array($attachmentsData) === true && isset($attachmentsData['results']) === true) {
                $rawAttachments = $attachmentsData['results'];
            } else if (is_array($attachmentsData) === true && isset($attachmentsData['files']) === true) {
                $rawAttachments = $attachmentsData['files'];
            }

            if (is_array($rawAttachments) === false) {
                return [];
            }

            return array_slice(array_values($rawAttachments), 0, self::RESULTS_CAP);
        } catch (\Throwable $e) {
            $this->logger->info(
                'OpenCatalogi MCP: getPublication attachments lookup failed',
                ['caller' => $this->callerUid(), 'id' => $id, 'exception' => $e->getMessage()]
            );
            return [];
        }//end try

    }//end fetchAttachments()

    /**
     * Build a structured error envelope.
     *
     * @param string $code    A short machine-readable error code.
     * @param string $message A human-readable message.
     *
     * @return array{error: array{code: string, message: string}}
     */
    private function error(string $code, string $message): array
    {
        return ['error' => ['code' => $code, 'message' => $message]];

    }//end error()

    /**
     * Best-effort resolution of the calling user's id (for log context only).
     *
     * @return string The user id, or "anonymous" when there is no session user.
     */
    private function callerUid(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return 'anonymous';
        }

        return $user->getUID();

    }//end callerUid()

    /**
     * Extract the decoded payload from a JSONResponse.
     *
     * @param JSONResponse $response The response to read.
     *
     * @return mixed The decoded data (array, scalar or null).
     */
    private function jsonResponseData(JSONResponse $response): mixed
    {
        return $response->getData();

    }//end jsonResponseData()

    /**
     * Build a deep link path for an OpenCatalogi publication.
     *
     * @param string $uuid The publication identifier.
     *
     * @return string The deep link path, e.g. /apps/opencatalogi/publications/<uuid>.
     */
    private function buildDeepLink(string $uuid): string
    {
        return "/apps/opencatalogi/publications/{$uuid}";

    }//end buildDeepLink()

    /**
     * Build a source descriptor for a normalised publication array.
     *
     * @param array<string, mixed> $publication The normalised publication array.
     *
     * @return array{type: string, uuid: string, url: string, label: string}
     */
    private function buildSource(array $publication): array
    {
        $uuid  = $this->extractIdentifier(item: $publication);
        $label = (string) ($publication['title'] ?? $publication['name'] ?? 'Publication');

        return [
            'type'  => 'opencatalogi.publication',
            'uuid'  => $uuid,
            'url'   => $this->buildDeepLink(uuid: $uuid),
            'label' => $label,
        ];

    }//end buildSource()

    /**
     * Normalise an OpenRegister object / entity to a plain PHP array.
     *
     * @param mixed $item Raw item from a service call.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $item): array
    {
        if (is_array($item) === true) {
            return $item;
        }

        if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
            $serialised = $item->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        if (is_object($item) === true && method_exists($item, 'getObject') === true) {
            $object = $item->getObject();
            if (is_array($object) === true) {
                return $object;
            }
        }

        if (is_object($item) === true) {
            return (array) $item;
        }

        return [];

    }//end toArray()

    /**
     * Extract a publication identifier (uuid / id / slug) from a normalised array.
     *
     * @param array<string, mixed> $item The normalised publication array.
     *
     * @return string The identifier, or empty string when not found.
     */
    private function extractIdentifier(array $item): string
    {
        $self = $item['@self'] ?? [];
        if (is_array($self) === false) {
            $self = [];
        }

        $identifier = ($item['uuid'] ?? $item['id'] ?? $item['slug'] ?? null);
        if ($identifier === null) {
            $identifier = ($self['uuid'] ?? $self['id'] ?? $self['slug'] ?? '');
        }

        return (string) $identifier;

    }//end extractIdentifier()
}//end class

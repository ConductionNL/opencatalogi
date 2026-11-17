<?php

/**
 * The answers of OpenCatalogi's two curated agent tools.
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
 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCP\AppFramework\Http\JSONResponse;

/**
 * Checks the arguments of the curated agent tools and shapes their answers.
 *
 * The tools themselves live on PublicationService (`searchCatalogPublications`,
 * `listPublicationFiles`) and delegate to its index() and attachments() paths,
 * which keep the catalog-scope gate and the RBAC read check. This class only
 * turns their JSONResponse into the plain array an agent tool answers, and an
 * argument or lookup failure into a structured `error` envelope. An error
 * message is always a fixed text: nothing an exception carries reaches the agent.
 *
 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md
 */
class AgentToolAnswer {

	/**
	 * The most results one search answers.
	 *
	 * @var int
	 */
	public const MAX_LIMIT = 50;

	/**
	 * The trimmed text of an argument, or null when it is blank.
	 *
	 * @param string $value The argument.
	 *
	 * @return string|null The trimmed text.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public static function text(string $value): ?string {
		$trimmed = trim($value);
		if ($trimmed === '') {
			return null;
		}

		return $trimmed;
	}//end text()

	/**
	 * The refusal of a search without a catalog, pointing at the unscoped search tool.
	 *
	 * @return array{error: array{code: string, message: string}} The error envelope.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public static function missingCatalog(): array {
		return self::error(
			code: 'invalid_arguments',
			message: 'Required argument "catalog" is missing or empty. For a search that is not scoped to one catalog, use opencatalogi.publication.search.'
		);
	}//end missingCatalog()

	/**
	 * The refusal of a required argument that is missing or empty.
	 *
	 * @param string $name The argument's name.
	 *
	 * @return array{error: array{code: string, message: string}} The error envelope.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public static function missingArgument(string $name): array {
		return self::error(code: 'invalid_arguments', message: 'Required argument "' . $name . '" is missing or empty.');
	}//end missingArgument()

	/**
	 * The refusal of a limit outside 1 to MAX_LIMIT, or null when it is inside.
	 *
	 * @param int $limit The asked limit.
	 *
	 * @return array{error: array{code: string, message: string}}|null The error envelope, or null.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public static function limitRefusal(int $limit): ?array {
		if ($limit >= 1 && $limit <= self::MAX_LIMIT) {
			return null;
		}

		return self::error(code: 'invalid_arguments', message: 'Invalid limit ' . $limit . '. Must be between 1 and ' . self::MAX_LIMIT . '.');
	}//end limitRefusal()

	/**
	 * The checked arguments of one search, or the refusal of the first bad one.
	 *
	 * @param array{catalog: string, query: string, limit: int} $asked The arguments as the tool received them.
	 *
	 * @return array{search?: array{catalog: string, query: string, limit: int}, refusal?: array{error: array{code: string, message: string}}}
	 *         `search` with the trimmed catalog and query, or `refusal`.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public static function searchRequest(array $asked): array {
		$search = ['catalog' => self::text(value: $asked['catalog']), 'query' => self::text(value: $asked['query']), 'limit' => $asked['limit']];
		if ($search['catalog'] === null) {
			return ['refusal' => self::missingCatalog()];
		}

		if ($search['query'] === null) {
			return ['refusal' => self::missingArgument(name: 'query')];
		}

		$refusal = self::limitRefusal(limit: $search['limit']);
		if ($refusal !== null) {
			return ['refusal' => $refusal];
		}

		return ['search' => $search];
	}//end searchRequest()

	/**
	 * The index() parameters of one search: the query, the limit and the first page.
	 *
	 * @param array{catalog: string, query: string, limit: int} $search The checked search.
	 *
	 * @return array{_search: string, _limit: int, _page: int} The parameters.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public static function searchParams(array $search): array {
		return ['_search' => $search['query'], '_limit' => $search['limit'], '_page' => 1];
	}//end searchParams()

	/**
	 * The search answer: the RBAC-filtered rows of index(), at most the asked limit.
	 *
	 * A 4xx from index() (an invalid search parameter) becomes an
	 * `invalid_arguments` error; any other failure an `internal_error`.
	 *
	 * @param array{catalog: string, query: string, limit: int} $search   The checked search.
	 * @param JSONResponse                                   $response What index() answered.
	 *
	 * @return array<string, mixed> The answer, or the error envelope.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public static function searchAnswer(array $search, JSONResponse $response): array {
		$refusal = self::searchStatusRefusal(status: $response->getStatus());
		if ($refusal !== null) {
			return $refusal;
		}

		$data = $response->getData();
		$rows = self::rows(data: $data, key: 'results');

		return [
			'success' => true,
			'catalog' => $search['catalog'],
			'query' => $search['query'],
			'publications' => array_slice($rows, 0, $search['limit']),
			'total' => self::total(data: $data, rows: $rows),
		];
	}//end searchAnswer()

	/**
	 * The error envelope of a failed search status, or null for a success.
	 *
	 * @param int $status The status index() answered.
	 *
	 * @return array{error: array{code: string, message: string}}|null The envelope, or null.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	private static function searchStatusRefusal(int $status): ?array {
		if ($status >= 500) {
			return self::searchFailed();
		}

		if ($status >= 400) {
			return self::error(code: 'invalid_arguments', message: 'The search parameters were refused.');
		}

		return null;
	}//end searchStatusRefusal()

	/**
	 * The answer of a search that threw.
	 *
	 * @return array{error: array{code: string, message: string}} The error envelope.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public static function searchFailed(): array {
		return self::error(code: 'internal_error', message: 'Failed to search publications.');
	}//end searchFailed()

	/**
	 * The file answer: the files attachments() lists for a publication.
	 *
	 * A 404 (out of this instance's catalog scope, not readable, or gone)
	 * becomes `not_found` without a file list; any other failure an `internal_error`.
	 *
	 * @param string       $publication The publication's id.
	 * @param JSONResponse $response    What attachments() answered.
	 *
	 * @return array<string, mixed> The answer, or the error envelope.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-the-publication-file-attachment-list-survives-as-a-curated-service-tool-req-005
	 */
	public static function filesAnswer(string $publication, JSONResponse $response): array {
		$refusal = self::filesStatusRefusal(status: $response->getStatus());
		if ($refusal !== null) {
			return $refusal;
		}

		$data = $response->getData();
		$rows = self::rows(data: $data, key: 'results');

		return [
			'success' => true,
			'publication' => $publication,
			'files' => $rows,
			'total' => self::total(data: $data, rows: $rows),
		];
	}//end filesAnswer()

	/**
	 * The error envelope of a failed file lookup status, or null for a success.
	 *
	 * @param int $status The status attachments() answered.
	 *
	 * @return array{error: array{code: string, message: string}}|null The envelope, or null.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-the-publication-file-attachment-list-survives-as-a-curated-service-tool-req-005
	 */
	private static function filesStatusRefusal(int $status): ?array {
		if ($status === 404) {
			return self::error(code: 'not_found', message: 'Publication not found.');
		}

		if ($status >= 400) {
			return self::filesFailed();
		}

		return null;
	}//end filesStatusRefusal()

	/**
	 * The answer of a file lookup that threw.
	 *
	 * @return array{error: array{code: string, message: string}} The error envelope.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-the-publication-file-attachment-list-survives-as-a-curated-service-tool-req-005
	 */
	public static function filesFailed(): array {
		return self::error(code: 'internal_error', message: 'Failed to list the files of the publication.');
	}//end filesFailed()

	/**
	 * The list under a key of an answer, or an empty list.
	 *
	 * @param mixed  $data The response data.
	 * @param string $key  The key of the list.
	 *
	 * @return list<mixed> The rows.
	 */
	private static function rows(mixed $data, string $key): array {
		if (is_array($data) === false || isset($data[$key]) === false || is_array($data[$key]) === false) {
			return [];
		}

		return array_values($data[$key]);
	}//end rows()

	/**
	 * The answer's own `total`, or the number of rows when it has none.
	 *
	 * @param mixed       $data The response data.
	 * @param list<mixed> $rows The rows.
	 *
	 * @return int The total.
	 */
	private static function total(mixed $data, array $rows): int {
		if (is_array($data) === true && is_int($data['total'] ?? null) === true) {
			return $data['total'];
		}

		return count($rows);
	}//end total()

	/**
	 * A structured error envelope.
	 *
	 * @param string $code    The error code.
	 * @param string $message The fixed message.
	 *
	 * @return array{error: array{code: string, message: string}} The envelope.
	 */
	private static function error(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end error()
}//end class

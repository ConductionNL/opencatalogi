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
	 * The index() parameters of one search: the query, the limit and the first page.
	 *
	 * @param string $query The trimmed query.
	 * @param int    $limit The checked limit.
	 *
	 * @return array{_search: string, _limit: int, _page: int} The parameters.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public static function searchParams(string $query, int $limit): array {
		return ['_search' => $query, '_limit' => $limit, '_page' => 1];
	}//end searchParams()

	/**
	 * The search answer: the RBAC-filtered rows of index(), at most the asked limit.
	 *
	 * A 4xx from index() (an invalid search parameter) becomes an
	 * `invalid_arguments` error; any other failure an `internal_error`.
	 *
	 * @param string       $catalog  The catalog searched.
	 * @param string       $query    The query.
	 * @param int          $limit    The checked limit.
	 * @param JSONResponse $response What index() answered.
	 *
	 * @return array<string, mixed> The answer, or the error envelope.
	 *
	 * @spec openspec/changes/opencatalogi-mcp-adoption/specs/mcp-tool-surface/spec.md#requirement-catalog-scoped-search-survives-as-a-curated-service-tool-req-004
	 */
	public static function searchAnswer(string $catalog, string $query, int $limit, JSONResponse $response): array {
		$data = $response->getData();
		if ($response->getStatus() >= 500) {
			return self::searchFailed();
		}

		if ($response->getStatus() >= 400) {
			return self::error(code: 'invalid_arguments', message: 'The search parameters were refused.');
		}

		$rows = self::rows(data: $data, key: 'results');

		return [
			'success' => true,
			'catalog' => $catalog,
			'query' => $query,
			'publications' => array_slice($rows, 0, $limit),
			'total' => self::total(data: $data, rows: $rows),
		];
	}//end searchAnswer()

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
		if ($response->getStatus() === 404) {
			return self::error(code: 'not_found', message: 'Publication not found.');
		}

		if ($response->getStatus() >= 400) {
			return self::filesFailed();
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

<?php

/**
 * Checks the arguments of OpenCatalogi's governed agent write tools.
 *
 * @category Mcp
 * @package  OCA\OpenCatalogi\Mcp
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Mcp;

/**
 * The property allow-list of the draft tool, enforced before anything is saved.
 *
 * A publication is public once its publication date has passed, so a field that
 * sets a date, the lifecycle state or the retention of a publication is the
 * publish act itself. The draft tool takes only the five descriptive fields; a
 * forbidden or unknown field rejects the whole call, so nothing is half-saved.
 * Every message is a fixed text.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
 */
class McpArgumentValidator {

	/**
	 * The fields a draft may carry, with whether each is a list.
	 *
	 * @var array<string, bool>
	 */
	public const DRAFT_FIELDS = [
		'title'        => false,
		'summary'      => false,
		'description'  => false,
		'organization' => false,
		'themes'       => true,
	];

	/**
	 * The fields that publish, withdraw or change the lifecycle of a publication.
	 *
	 * @var list<string>
	 */
	public const PUBLISH_FIELDS = ['publicationDate', 'depublicationDate', 'status'];

	/**
	 * The fields of a draft, or the refusal of the whole call.
	 *
	 * @param array<array-key, mixed> $fields The draft the agent sent.
	 *
	 * @return array{fields: array<string, string|list<string>>}|array{error: array{code: string, message: string}}
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	public function draftFields(array $fields): array {
		$refusal = $this->namesRefusal(names: array_map('strval', array_keys($fields)));
		if ($refusal !== null) {
			return $refusal;
		}

		$draft = $this->collect(fields: $fields);
		if (isset($draft['error']) === true) {
			return $draft;
		}

		if (isset($draft['title']) === false) {
			return self::error(message: 'Field "title" is required.');
		}

		return ['fields' => $draft];
	}//end draftFields()

	/**
	 * The refusal of the first field name the draft may not carry, or null.
	 *
	 * @param list<string> $names The field names the agent sent.
	 *
	 * @return array{error: array{code: string, message: string}}|null The refusal.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	private function namesRefusal(array $names): ?array {
		foreach ($names as $name) {
			$refusal = $this->fieldRefusal(name: $name);
			if ($refusal !== null) {
				return $refusal;
			}
		}

		return null;
	}//end namesRefusal()

	/**
	 * The non-empty allow-listed fields of a draft, or the refusal of a mistyped one.
	 *
	 * @param array<array-key, mixed> $fields The draft the agent sent, already free of refused names.
	 *
	 * @return array<string, mixed> The fields, or an `error` envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	private function collect(array $fields): array {
		$draft = [];
		foreach (self::DRAFT_FIELDS as $name => $isList) {
			if (isset($fields[$name]) === false) {
				continue;
			}

			$value = $this->fieldValue(value: $fields[$name], isList: $isList);
			if ($value === null) {
				return self::typeRefusal(name: $name, isList: $isList);
			}

			if ($value !== '' && $value !== []) {
				$draft[$name] = $value;
			}
		}

		return $draft;
	}//end collect()

	/**
	 * The refusal of a field with the wrong type.
	 *
	 * @param string $name   The field name.
	 * @param bool   $isList Whether the field is a list of ids.
	 *
	 * @return array{error: array{code: string, message: string}} The refusal.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	private static function typeRefusal(string $name, bool $isList): array {
		if ($isList === true) {
			return self::error(message: 'Field "' . $name . '" must be a list of ids.');
		}

		return self::error(message: 'Field "' . $name . '" must be text.');
	}//end typeRefusal()

	/**
	 * The refusal of one field name, or null when the draft may carry it.
	 *
	 * @param string $name The field name.
	 *
	 * @return array{error: array{code: string, message: string}}|null The refusal.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	private function fieldRefusal(string $name): ?array {
		if (in_array($name, self::PUBLISH_FIELDS, true) === true || str_starts_with($name, 'retention') === true) {
			return self::error(
				message: 'Field "' . $name . '" cannot be set by this tool: a draft carries no publication date, '
					. 'withdrawal date, lifecycle state or retention. Publishing is a separate, approved step.'
			);
		}

		if (array_key_exists($name, self::DRAFT_FIELDS) === false) {
			return self::error(
				message: 'Field "' . $name . '" is not accepted. A draft takes only: ' . implode(', ', array_keys(self::DRAFT_FIELDS)) . '.'
			);
		}

		return null;
	}//end fieldRefusal()

	/**
	 * The trimmed value of one field, or null when its type is wrong.
	 *
	 * @param mixed $value  The value.
	 * @param bool  $isList Whether the field is a list of ids.
	 *
	 * @return string|list<string>|null The value.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	private function fieldValue(mixed $value, bool $isList): string|array|null {
		if ($isList === false) {
			if (is_string($value) === false) {
				return null;
			}

			return trim($value);
		}

		if (is_array($value) === false || array_is_list($value) === false) {
			return null;
		}

		$ids = [];
		foreach ($value as $id) {
			if (is_string($id) === false || trim($id) === '') {
				return null;
			}

			$ids[] = trim($id);
		}

		return array_values(array_unique($ids));
	}//end fieldValue()

	/**
	 * The invalid-arguments envelope.
	 *
	 * @param string $message The fixed message.
	 *
	 * @return array{error: array{code: string, message: string}} The envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	private static function error(string $message): array {
		return ['error' => ['code' => 'invalid_arguments', 'message' => $message]];
	}//end error()
}//end class

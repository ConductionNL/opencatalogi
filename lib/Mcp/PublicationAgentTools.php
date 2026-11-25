<?php

/**
 * OpenCatalogi's governed agent write tools.
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Mcp;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\AgentPublishingInterface;
use OCA\OpenCatalogi\Contract\AgentWithdrawalInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * The agent write tools OpenRegister's scanner serves as `opencatalogi.<name>`.
 *
 * Every write goes through OpenRegister's object save with RBAC and
 * multitenancy on, as the person whose session the agent acts in: an agent can
 * never write what that person could not write in the screen. This class owns
 * the argument allow-list and the answer shape, never a check of its own in
 * place of OpenRegister's.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-runs-the-existing-rbacd-path-as-the-granting-user-req-008
 */
class PublicationAgentTools {

	/**
	 * The OpenRegister object service.
	 *
	 * @var string
	 */
	private const OBJECTS = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * Build the tools.
	 *
	 * @param ContainerInterface   $container The container OpenRegister's object service comes from.
	 * @param IAppConfig           $config    Where the publication register and schema are configured.
	 * @param McpArgumentValidator $validator The draft allow-list and the publish ids.
	 * @param AgentPublishingInterface|null $publishing Publishes approved publications; without it the publish tool refuses.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $config,
		private readonly McpArgumentValidator $validator,
		private readonly ?AgentPublishingInterface $publishing = null,
	) {
	}//end __construct()

	/**
	 * Agent tool: create a draft publication from its descriptive fields.
	 *
	 * The draft carries no publication date, so the public never sees it; the
	 * lifecycle gives it its initial `draft` state. A field that would publish,
	 * withdraw, change the state or set retention rejects the whole call.
	 *
	 * @param array<array-key, mixed> $publication The draft: title (required), summary, description,
	 *                                             organization (uuid) and themes (list of uuids).
	 *
	 * @return array<string, mixed> The created draft, or an `error` envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-runs-the-existing-rbacd-path-as-the-granting-user-req-008
	 */
	#[McpTool(
		name: 'createDraftPublication',
		description: 'Create a draft publication. Pass one "publication" object with "title" (required) and, if known, '
			. '"summary", "description", "organization" (uuid) and "themes" (list of theme uuids). A draft has no '
			. 'publication date and is not visible to the public. This tool cannot publish: a publication date, '
			. 'withdrawal date, status or retention field is refused. It cannot edit an existing publication either.',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: false,
		scope: 'create',
		subject: 'publication',
		action: 'createDraft',
		reach: 'instance',
	)]
	public function createDraftPublication(array $publication): array {
		$checked = $this->validator->draftFields(fields: $publication);
		if (isset($checked['error']) === true) {
			return $checked;
		}

		$register = $this->config->getValueString(AppIdentity::APP_ID, 'publication_register', '');
		$schema   = $this->config->getValueString(AppIdentity::APP_ID, 'publication_schema', '');
		if ($register === '' || $schema === '') {
			return self::error(code: 'not_configured', message: 'The publication register is not configured on this instance.');
		}

		try {
			$saved = $this->container->get(self::OBJECTS)->saveObject(
				object: $checked['fields'],
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema)),
				_rbac: true,
				_multitenancy: true
			);
		} catch (\Throwable) {
			return self::error(
				code: 'not_saved',
				message: 'The draft was not saved. The account this agent acts for may not create publications here, '
					. 'or a field did not validate.'
			);
		}

		return self::draftAnswer(saved: $saved);
	}//end createDraftPublication()

	/**
	 * Agent tool: publish publications a human has approved.
	 *
	 * The approval is the publication's own review: only a publication a
	 * reviewer or publisher moved to `approved` in the screen is published; a
	 * draft or one still in review is refused, also where review is switched
	 * off. The publish runs the publish button's checks and lifecycle
	 * transition as the person the agent acts for, item by item.
	 *
	 * @param array<array-key, mixed> $ids The publication ids, at most AgentPublishingInterface::MAX_ITEMS.
	 *
	 * @return array<string, mixed> `published` and `refused` (each `{id, code}`), or an `error` envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-runs-the-existing-rbacd-path-as-the-granting-user-req-008
	 */
	#[McpTool(
		name: 'publishPublication',
		description: 'Publish publications that a person has already approved. Pass "ids", a list of publication '
			. 'uuids (at most 50). Only a publication in the state "approved" is published; a draft or a publication '
			. 'in review is refused with "not_approved" and must be approved by a reviewer first. Publishing makes '
			. 'the publication public and sends it to the national index and other harvesters: that distribution '
			. 'cannot be undone by withdrawing it later. Each id is answered as published or refused with a reason.',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: true,
		scope: 'update',
		subject: 'publication',
		action: 'publish',
		reach: 'external',
	)]
	public function publishPublication(array $ids): array {
		$checked = $this->validator->publicationIds(ids: $ids, max: AgentPublishingInterface::MAX_ITEMS);
		if (isset($checked['error']) === true) {
			return $checked;
		}

		if ($this->publishing === null) {
			return self::error(code: 'not_configured', message: 'Publishing is not available on this instance.');
		}

		$answer = $this->publishing->publishApproved(ids: $checked['ids']);
		if (isset($answer['error']) === true) {
			return self::publishError(code: (string)$answer['error']);
		}

		return ['success' => $answer['refused'] === [], 'published' => $answer['published'], 'refused' => $answer['refused']];
	}//end publishPublication()

	/**
	 * Agent tool: ask a publisher to withdraw publications from the public.
	 *
	 * Nothing is withdrawn by this call. Each publication gets an OpenRegister
	 * task for the publishers group, on the publication, carrying the reason;
	 * the publication stays public until a publisher completes the task.
	 *
	 * @param array<array-key, mixed> $ids    The publication ids, at most AgentWithdrawalInterface::MAX_ITEMS.
	 * @param string                  $reason Why they must come down.
	 *
	 * @return array<string, mixed> `staged` (each `{id, task}`) and `refused` (each `{id, code}`), or an `error` envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	#[McpTool(
		name: 'depublishPublication',
		description: 'Ask a publisher to withdraw publications from the public. Pass "ids", a list of publication '
			. 'uuids (at most 50), and "reason", why they must come down. This tool does not withdraw anything: it '
			. 'creates one task per publication for the publishers group, and the publication stays public until a '
			. 'publisher completes that task. A withdrawal does not recall copies that harvesters, the national index '
			. 'or federated catalogues already took. Each id is answered as staged (with its task id) or refused.',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: false,
		scope: 'update',
		subject: 'publication',
		action: 'depublish',
		reach: 'external',
	)]
	public function depublishPublication(array $ids, string $reason): array {
		return $this->stageThroughTask(action: AgentWithdrawalInterface::DEPUBLISH, ids: $ids, reason: $reason);
	}//end depublishPublication()

	/**
	 * Agent tool: ask a publisher to archive publications.
	 *
	 * Nothing is archived by this call. Each publication gets an OpenRegister
	 * task for the publishers group, on the publication, carrying the reason;
	 * only a publisher completing it archives the publication.
	 *
	 * @param array<array-key, mixed> $ids    The publication ids, at most AgentWithdrawalInterface::MAX_ITEMS.
	 * @param string                  $reason Why they should be archived.
	 *
	 * @return array<string, mixed> `staged` (each `{id, task}`) and `refused` (each `{id, code}`), or an `error` envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	#[McpTool(
		name: 'archivePublication',
		description: 'Ask a publisher to archive publications. Pass "ids", a list of publication uuids (at most 50), '
			. 'and "reason", why. This tool does not archive anything: it creates one task per publication for the '
			. 'publishers group, and only a publisher completing that task archives the publication. Each id is '
			. 'answered as staged (with its task id) or refused.',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: false,
		scope: 'update',
		subject: 'publication',
		action: 'archive',
		reach: 'instance',
	)]
	public function archivePublication(array $ids, string $reason): array {
		return $this->stageThroughTask(action: AgentWithdrawalInterface::ARCHIVE, ids: $ids, reason: $reason);
	}//end archivePublication()

	/**
	 * Validate the call and stage one publisher task per publication.
	 *
	 * @param string                  $action AgentWithdrawalInterface::DEPUBLISH or ::ARCHIVE.
	 * @param array<array-key, mixed> $ids    The publication ids.
	 * @param mixed                   $reason The reason argument.
	 *
	 * @return array<string, mixed> `staged` and `refused`, or an `error` envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	private function stageThroughTask(string $action, array $ids, mixed $reason): array {
		$checked = $this->validator->publicationIds(ids: $ids, max: AgentWithdrawalInterface::MAX_ITEMS);
		if (isset($checked['error']) === true) {
			return $checked;
		}

		$why = $this->validator->reason(reason: $reason);
		if (isset($why['error']) === true) {
			return $why;
		}

		$withdrawal = $this->withdrawal();
		if ($withdrawal === null) {
			return self::error(code: 'not_configured', message: 'Publisher tasks are not available on this instance.');
		}

		$answer = $withdrawal->stage(action: $action, ids: $checked['ids'], reason: $why['reason']);
		if (isset($answer['error']) === true) {
			return self::stageError(code: (string)$answer['error']);
		}

		return ['success' => $answer['refused'] === [], 'staged' => $answer['staged'], 'refused' => $answer['refused']];
	}//end stageThroughTask()

	/**
	 * The staging service, from the container; null when it cannot be built.
	 *
	 * @return AgentWithdrawalInterface|null The service.
	 */
	private function withdrawal(): ?AgentWithdrawalInterface {
		try {
			$withdrawal = $this->container->get(AgentWithdrawalInterface::class);
		} catch (\Throwable) {
			return null;
		}

		if ($withdrawal instanceof AgentWithdrawalInterface) {
			return $withdrawal;
		}

		return null;
	}//end withdrawal()

	/**
	 * The error envelope of a staging call refused as a whole.
	 *
	 * @param string $code `tasks_unavailable` or `not_configured`.
	 *
	 * @return array{error: array{code: string, message: string}} The envelope.
	 */
	private static function stageError(string $code): array {
		if ($code === 'tasks_unavailable') {
			return self::error(
				code: $code,
				message: 'OpenRegister tasks are not available, or the call has no signed-in user, so no publisher task was created.'
			);
		}

		return self::error(code: $code, message: 'The publication register is not configured on this instance.');
	}//end stageError()

	/**
	 * The error envelope of a publish call refused as a whole.
	 *
	 * @param string $code `not_a_publisher` or `not_configured`.
	 *
	 * @return array{error: array{code: string, message: string}} The envelope.
	 */
	private static function publishError(string $code): array {
		if ($code === 'not_a_publisher') {
			return self::error(
				code: $code,
				message: 'The account this agent acts for may not publish. Ask a member of the publishers group.'
			);
		}

		return self::error(code: $code, message: 'The publication register is not configured on this instance.');
	}//end publishError()

	/**
	 * The answer for a saved draft.
	 *
	 * @param mixed $saved What OpenRegister returned.
	 *
	 * @return array<string, mixed> The draft's id and fields, or an `error` envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	private static function draftAnswer(mixed $saved): array {
		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			$saved = $saved->jsonSerialize();
		}

		if (is_array($saved) === false) {
			return self::error(code: 'not_saved', message: 'The draft was not saved.');
		}

		$self = $saved['@self'] ?? [];
		if (is_array($self) === false) {
			$self = [];
		}

		$id = ($self['id'] ?? ($self['uuid'] ?? ($saved['id'] ?? ($saved['uuid'] ?? null))));
		if (is_string($id) === false || $id === '') {
			return self::error(code: 'not_saved', message: 'The draft was not saved.');
		}

		$draft = ['id' => $id];
		foreach (array_keys(McpArgumentValidator::DRAFT_FIELDS) as $name) {
			if (array_key_exists($name, $saved) === true) {
				$draft[$name] = $saved[$name];
			}
		}

		$draft['status'] = (string)($saved['status'] ?? 'draft');

		return ['success' => true, 'publication' => $draft];
	}//end draftAnswer()

	/**
	 * A structured error envelope.
	 *
	 * @param string $code    The error code.
	 * @param string $message The fixed message.
	 *
	 * @return array{error: array{code: string, message: string}} The envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	private static function error(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end error()
}//end class

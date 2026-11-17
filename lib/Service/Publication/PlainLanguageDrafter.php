<?php

/**
 * OpenCatalogi Plain Language Drafter.
 *
 * An officer can ask a text assistant for a draft of a publication's plain
 * language summary. The draft goes through Nextcloud's TaskProcessing API, so
 * any provider of text simplification serves it (hermiq or another), and
 * without one the officer writes the summary by hand. A draft is only ever a
 * suggestion: nothing is stored until the officer accepts a text, and the
 * stored provenance names who accepted it.
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
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PlainSummaryDrafterInterface;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

/**
 * Drafts, polls and accepts a publication's plain language summary.
 *
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
 */
class PlainLanguageDrafter implements PlainSummaryDrafterInterface {
	use ReadsObjectProperties;

	/**
	 * The TaskProcessing task type that writes the draft.
	 */
	public const TASK_TYPE = 'core:text2text:simplification';

	/**
	 * The prefix of the custom id that ties a task to its publication.
	 */
	private const CUSTOM_ID_PREFIX = 'opencatalogi-plain-summary:';

	/**
	 * The longest plain summary the schema stores.
	 */
	public const MAX_LENGTH = 1500;

	/**
	 * Constructor.
	 *
	 * @param IManager $tasks Nextcloud's TaskProcessing manager.
	 * @param OpenRegisterLookupInterface $openRegister The publication register and its ObjectService.
	 * @param PublicationRights $rights The update right on one publication.
	 * @param PlainSummaryProvenance $provenance Builds the provenance and holds it for the save.
	 * @param IUserSession $userSession The current session.
	 * @param IL10N $l10n Localisation.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IManager $tasks,
		private readonly OpenRegisterLookupInterface $openRegister,
		private readonly PublicationRights $rights,
		private readonly PlainSummaryProvenance $provenance,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether a text assistant for simplification is installed.
	 *
	 * @return bool True when a provider serves the task type.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function isAvailable(): bool {
		try {
			return array_key_exists(self::TASK_TYPE, $this->tasks->getAvailableTaskTypes());
		} catch (\Throwable $e) {
			$this->logger->warning('[PlainLanguageDrafter] The text assistants could not be listed: ' . $e->getMessage());
			return false;
		}

	}//end isAvailable()

	/**
	 * Ask the text assistant for a draft of the publication's plain summary.
	 *
	 * The caller needs the update right on the publication, so nothing leaves
	 * the instance that is not already the caller's to read and change.
	 *
	 * @param string $publicationId The publication id.
	 *
	 * @return array{taskId: int} The scheduled task.
	 *
	 * @throws PlainSummaryRefusal 401, 404 or 403 on access, 409 no-provider without a text assistant, 502 when scheduling fails.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function draft(string $publicationId): array {
		$publication = $this->asArray(object: $this->writable(publicationId: $publicationId));
		$this->assertAvailable();

		$task = new Task(
			taskTypeId: self::TASK_TYPE,
			input: ['input' => $this->sourceText(publication: $publication)],
			appId: AppIdentity::APP_ID,
			userId: $this->userId(),
			customId: self::CUSTOM_ID_PREFIX . $publicationId . ':' . $this->providerId()
		);

		$this->schedule(task: $task);

		return ['taskId' => (int)$task->getId()];

	}//end draft()

	/**
	 * Refuse a draft while no text assistant is installed.
	 *
	 * @return void
	 *
	 * @throws PlainSummaryRefusal 409 without a provider.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	private function assertAvailable(): void {
		if ($this->isAvailable() === false) {
			throw new PlainSummaryRefusal(
				reason: 'no-provider',
				status: 409,
				message: $this->l10n->t('No text assistant is installed. You can write the plain language summary yourself.')
			);
		}

	}//end assertAvailable()

	/**
	 * Hand the draft task to the task processing manager.
	 *
	 * @param Task $task The draft task.
	 *
	 * @return void
	 *
	 * @throws PlainSummaryRefusal 502 when the provider does not take it.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	private function schedule(Task $task): void {
		try {
			$this->tasks->scheduleTask(task: $task);
		} catch (\Throwable $e) {
			$this->logger->warning('[PlainLanguageDrafter] The draft could not be scheduled: ' . $e->getMessage());
			throw new PlainSummaryRefusal(
				reason: 'provider-failed',
				status: 502,
				message: $this->l10n->t('The text assistant could not take the request. Nothing was stored.')
			);
		}

	}//end schedule()

	/**
	 * The state of a draft: still running, its text, or why it failed.
	 *
	 * Polling never stores anything: the text is a suggestion until accepted.
	 *
	 * @param string $publicationId The publication id.
	 * @param int $taskId The task that writes the draft.
	 *
	 * @return array{status: string, text?: string, error?: string} The draft's state.
	 *
	 * @throws PlainSummaryRefusal 401, 404 or 403 on access, 404 for a task that is not this caller's draft of this publication.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function poll(string $publicationId, int $taskId): array {
		$this->writable(publicationId: $publicationId);
		$task = $this->ownTask(publicationId: $publicationId, taskId: $taskId);
		if ($task === null) {
			throw new PlainSummaryRefusal(reason: 'task-not-found', status: 404);
		}

		return $this->statusOf(task: $task);

	}//end poll()

	/**
	 * A draft task's answer to the poll: its text, its failure, or that it is still going.
	 *
	 * @param Task $task The draft task.
	 *
	 * @return array<string, mixed> `status`, with `text` or `error`.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	private function statusOf(Task $task): array {
		return match ($task->getStatus()) {
			Task::STATUS_SUCCESSFUL => $this->finished(task: $task),
			Task::STATUS_FAILED, Task::STATUS_CANCELLED => [
				'status' => 'failed',
				'error' => ($task->getErrorMessage() ?? $this->l10n->t('The text assistant could not write a draft.')),
			],
			Task::STATUS_RUNNING => ['status' => 'running'],
			default => ['status' => 'scheduled'],
		};

	}//end statusOf()

	/**
	 * Store the plain summary the officer accepted, with its provenance.
	 *
	 * The provenance is ai, with the text assistant, only when the text comes
	 * from a finished draft of this publication that this caller asked for;
	 * otherwise it is person. Either way the caller is the one who accepted.
	 *
	 * @param string $publicationId The publication id.
	 * @param string $text The accepted text.
	 * @param string $level The reading level: A2, B1 or B2.
	 * @param int|null $fromTaskId The draft the text came from, when it did.
	 *
	 * @return array{plainSummary: string, plainSummaryLevel: string, plainSummaryProvenance: array<string, mixed>} What was stored.
	 *
	 * @throws PlainSummaryRefusal 401, 404 or 403 on access, 400 for an empty or too long text or an unknown level, 503 when the save fails.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	public function accept(string $publicationId, string $text, string $level, ?int $fromTaskId = null): array {
		$publication = $this->writable(publicationId: $publicationId);
		$text = trim($text);
		$this->assertAcceptable(text: $text, level: $level);

		$provenance = $this->acceptedProvenance(publicationId: $publicationId, fromTaskId: $fromTaskId);
		$record = array_merge($this->asArray(object: $publication), ['plainSummary' => $text, 'plainSummaryLevel' => $level]);
		unset($record['id'], $record['@self']);

		$this->provenance->expect(text: $text, provenance: $provenance);
		try {
			$this->save(publicationId: $publicationId, record: $record);
		} finally {
			$this->provenance->forget(text: $text);
		}

		return ['plainSummary' => $text, 'plainSummaryLevel' => $level, 'plainSummaryProvenance' => $provenance];

	}//end accept()

	/**
	 * The provenance an accept stores: `ai` with its provider when it names this
	 * caller's finished draft of this publication, else `person`.
	 *
	 * @param string $publicationId The publication.
	 * @param int|null $fromTaskId The draft task the text came from, if any.
	 *
	 * @return array<string, mixed> The provenance.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	private function acceptedProvenance(string $publicationId, ?int $fromTaskId): array {
		$task = null;
		if ($fromTaskId !== null) {
			$task = $this->ownTask(publicationId: $publicationId, taskId: $fromTaskId);
		}

		if ($task === null || $task->getStatus() !== Task::STATUS_SUCCESSFUL) {
			return $this->provenance->acceptedNow(generatedBy: 'person');
		}

		return $this->provenance->acceptedNow(generatedBy: 'ai', provider: $this->providerOf(task: $task));

	}//end acceptedProvenance()

	/**
	 * Store the publication with its accepted plain summary, as the caller.
	 *
	 * @param string $publicationId The publication.
	 * @param array<string, mixed> $record The publication record to store.
	 *
	 * @return void
	 *
	 * @throws PlainSummaryRefusal 503 when it could not be stored.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	private function save(string $publicationId, array $record): void {
		try {
			$target = $this->publicationRegister();
			$objects = $this->openRegister->objectService();
			$objects->saveObject(
				object: $record,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $target['register'], schema: $target['schema']),
					uuid: $publicationId
				),
				_rbac: true
			);
		} catch (\Throwable $e) {
			$this->logger->warning('[PlainLanguageDrafter] The plain summary could not be stored: ' . $e->getMessage());
			$message = $this->l10n->t('The plain language summary could not be stored.');
			throw new PlainSummaryRefusal(reason: 'not-stored', status: 503, message: $message);
		}

	}//end save()

	/**
	 * Refuse an empty or too long text, or a level outside the list.
	 *
	 * @param string $text The trimmed text.
	 * @param string $level The level.
	 *
	 * @return void
	 *
	 * @throws PlainSummaryRefusal 400 naming what is wrong.
	 */
	private function assertAcceptable(string $text, string $level): void {
		if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
			$message = $this->l10n->t('A plain language summary has between 1 and 1500 characters.');
			throw new PlainSummaryRefusal(reason: 'invalid-text', status: 400, message: $message);
		}

		if (in_array($level, self::LEVELS, true) === false) {
			$message = $this->l10n->t('A plain language summary needs its reading level (plainSummaryLevel): A2, B1 or B2.');
			throw new PlainSummaryRefusal(reason: 'invalid-level', status: 400, message: $message);
		}

	}//end assertAcceptable()

	/**
	 * The publication, when the caller is signed in, may read it and may update it.
	 *
	 * @param string $publicationId The publication id.
	 *
	 * @return object The publication as OpenRegister returned it.
	 *
	 * @throws PlainSummaryRefusal 401 without a session, 404 when it cannot be read, 403 without the update right, 503 when the register fails.
	 */
	private function writable(string $publicationId): object {
		if ($this->userSession->getUser() === null) {
			throw new PlainSummaryRefusal(reason: 'not-logged-in', status: 401);
		}

		$publication = $this->findPublication(publicationId: $publicationId);
		if ($this->rights->mayUpdate(publication: $publication) === false) {
			throw new PlainSummaryRefusal(
				reason: 'forbidden',
				status: 403,
				message: $this->l10n->t('You may not change this publication.')
			);
		}

		return $publication;

	}//end writable()

	/**
	 * Read the publication as the caller.
	 *
	 * @param string $publicationId The publication.
	 *
	 * @return object The publication.
	 *
	 * @throws PlainSummaryRefusal 404 when it is not there or not readable, 503 when the register is unavailable.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	private function findPublication(string $publicationId): object {
		try {
			$target = $this->publicationRegister();
			$objects = $this->openRegister->objectService();
			$publication = $objects->find(
				id: $publicationId,
				options: new ObjectFindOptions(register: $target['register'], schema: $target['schema']),
				_rbac: true
			);
		} catch (\Throwable $e) {
			throw $this->readRefusal(exception: $e);
		}

		if (is_object($publication) === false) {
			throw new PlainSummaryRefusal(reason: 'not-found', status: 404);
		}

		return $publication;

	}//end findPublication()

	/**
	 * The refusal for a publication that could not be read.
	 *
	 * Not there, or not readable by this caller: both answer 404, so an id
	 * nobody may read does not confirm that it exists.
	 *
	 * @param \Throwable $exception What the read threw.
	 *
	 * @return PlainSummaryRefusal 404, or 503 when the register itself failed.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	private function readRefusal(\Throwable $exception): PlainSummaryRefusal {
		$class = get_class($exception);
		if (str_ends_with($class, 'DoesNotExistException') === true || str_ends_with($class, 'NotAuthorizedException') === true) {
			return new PlainSummaryRefusal(reason: 'not-found', status: 404);
		}

		$this->logger->warning('[PlainLanguageDrafter] The publication could not be read: ' . $exception->getMessage());
		return new PlainSummaryRefusal(reason: 'register-unavailable', status: 503);

	}//end readRefusal()

	/**
	 * The task, when it is this caller's draft of this publication.
	 *
	 * @param string $publicationId The publication id.
	 * @param int $taskId The task id.
	 *
	 * @return Task|null The task, or null when it is not there or not this caller's draft of this publication.
	 */
	private function ownTask(string $publicationId, int $taskId): ?Task {
		try {
			$task = $this->tasks->getTask(id: $taskId);
		} catch (\Throwable) {
			return null;
		}

		if ($this->isOwnDraft(task: $task, publicationId: $publicationId) === false) {
			return null;
		}

		return $task;

	}//end ownTask()

	/**
	 * Whether a task is a plain summary draft of this publication by this caller.
	 *
	 * @param Task $task The task.
	 * @param string $publicationId The publication.
	 *
	 * @return bool True when it is.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	private function isOwnDraft(Task $task, string $publicationId): bool {
		return $task->getTaskTypeId() === self::TASK_TYPE
			&& str_starts_with((string)$task->getCustomId(), self::CUSTOM_ID_PREFIX . $publicationId . ':') === true
			&& $task->getUserId() === $this->userId();

	}//end isOwnDraft()

	/**
	 * The answer for a finished draft.
	 *
	 * @param Task $task The successful task.
	 *
	 * @return array{status: string, text?: string, error?: string} The draft, or failed when it carries no text.
	 */
	private function finished(Task $task): array {
		$text = (($task->getOutput() ?? [])['output'] ?? null);
		if (is_string($text) === false || trim($text) === '') {
			return ['status' => 'failed', 'error' => $this->l10n->t('The text assistant returned no text.')];
		}

		return ['status' => 'successful', 'text' => trim($text)];

	}//end finished()

	/**
	 * The text the assistant simplifies: title, summary and description.
	 *
	 * @param array<string, mixed> $publication The publication's properties.
	 *
	 * @return string The source text, one part per paragraph.
	 */
	private function sourceText(array $publication): string {
		$parts = [];
		foreach (['title', 'summary', 'description'] as $field) {
			$value = ($publication[$field] ?? null);
			if (is_string($value) === false) {
				continue;
			}

			$value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));
			if ($value !== '') {
				$parts[] = $value;
			}
		}

		return implode("\n\n", $parts);

	}//end sourceText()

	/**
	 * The id of the text assistant that serves the task type now, empty when unknown.
	 *
	 * @return string The provider id.
	 */
	private function providerId(): string {
		try {
			$provider = $this->tasks->getPreferredProvider(taskTypeId: self::TASK_TYPE);
			return $provider->getId();
		} catch (\Throwable) {
			return '';
		}

	}//end providerId()

	/**
	 * The text assistant a draft was scheduled with, recorded in its custom id.
	 *
	 * @param Task $task The task.
	 *
	 * @return string|null The provider id, or null when none was recorded.
	 */
	private function providerOf(Task $task): ?string {
		$parts = explode(':', (string)$task->getCustomId(), 3);
		$provider = ($parts[2] ?? '');
		if ($provider === '') {
			return null;
		}

		return $provider;

	}//end providerOf()

	/**
	 * The current user's id.
	 *
	 * @return string|null The id, or null without a session.
	 */
	private function userId(): ?string {
		return $this->userSession->getUser()?->getUID();

	}//end userId()

	/**
	 * The configured publication register and schema.
	 *
	 * @return array<string, string> The register and schema identifiers.
	 */
	private function publicationRegister(): array {
		return $this->openRegister->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');

	}//end publicationRegister()
}//end class

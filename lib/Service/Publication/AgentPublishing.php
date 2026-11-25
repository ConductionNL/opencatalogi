<?php

/**
 * Agent publishing on the publication lifecycle
 *
 * An agent publishes only what a human moved to `approved`, through the same
 * checks and lifecycle transition as the publish button, as the person whose
 * session the agent acts in.
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Contract\AgentPublishingInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * Publishes approved publications for an agent, one item at a time, each refusal named.
 *
 * The human approval is the publication's own review: a publisher or reviewer
 * moved it to `approved` in the screen. The agent cannot approve, so a draft or
 * a publication still in review is refused even where review is switched off.
 * Every other check is the publish button's: the publish right, the update
 * right on the item, an embargo, and a publication without documents (the
 * agent cannot confirm that one). An embargo is judged with nobody allowed to
 * lift it: an agent never releases an embargoed publication early, even for a
 * person who may; that person lifts it in the screen.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
class AgentPublishing implements AgentPublishingInterface {

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface      $openRegister OpenRegister's object service and the publication register and schema.
	 * @param PublicationRightsInterface       $rights       The publish right, and the update right on one publication.
	 * @param PublicationStateServiceInterface $states       The record a publish now stores.
	 * @param PublishTransition                $publisher    The lifecycle transition, the embargo check and the document count.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $openRegister,
		private readonly PublicationRightsInterface $rights,
		private readonly PublicationStateServiceInterface $states,
		private readonly PublishTransition $publisher,
	) {
	}//end __construct()

	/**
	 * Publish each approved publication; every other one is refused with its reason.
	 *
	 * @param list<string> $ids The publication ids, at most MAX_ITEMS.
	 *
	 * @return array{published: list<string>, refused: list<array{id: string, code: string}>}|array{error: string}
	 *         Per item; `error` when the caller may not publish at all, or the register is not configured.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-runs-the-existing-rbacd-path-as-the-granting-user-req-008
	 */
	public function publishApproved(array $ids): array {
		if ($this->rights->mayPublish() === false) {
			return ['error' => 'not_a_publisher'];
		}

		try {
			$target = $this->openRegister->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');
		} catch (Throwable) {
			return ['error' => 'not_configured'];
		}

		$register = (string)($target['register'] ?? '');
		$schema   = (string)($target['schema'] ?? '');
		if ($register === '' || $schema === '') {
			return ['error' => 'not_configured'];
		}

		$answer = ['published' => [], 'refused' => []];
		foreach ($ids as $id) {
			$code = $this->publishOne(id: $id, options: new ObjectFindOptions(register: $register, schema: $schema));
			if ($code === null) {
				$answer['published'][] = $id;
				continue;
			}

			$answer['refused'][] = ['id' => $id, 'code' => $code];
		}

		return $answer;
	}//end publishApproved()

	/**
	 * Publish one publication, or say why not.
	 *
	 * @param string            $id      The publication id.
	 * @param ObjectFindOptions $options The publication register and schema.
	 *
	 * @return string|null Null when published; else `not_found`, `forbidden`, `not_approved`,
	 *                     `embargo_holds`, `no_documents` or `not_published`.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectProperties::of() is a pure read of the answer.
	 */
	private function publishOne(string $id, ObjectFindOptions $options): ?string {
		try {
			$publication = $this->openRegister->objectService()->find(id: $id, options: $options, _rbac: true);
		} catch (Throwable) {
			$publication = null;
		}

		if (is_object($publication) === false) {
			return 'not_found';
		}

		$refusal = $this->refusal(id: $id, publication: $publication);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$record = $this->states->publishedRecord(publication: ObjectProperties::of(object: $publication));
			$this->publisher->publish(id: $id, stored: 'approved', publicationDate: (string)$record['publicationDate']);
		} catch (Throwable) {
			return 'not_published';
		}

		return null;
	}//end publishOne()

	/**
	 * Why the publish button would refuse this publication for the caller, or null.
	 *
	 * @param string $id          The publication id.
	 * @param object $publication The publication as read with RBAC.
	 *
	 * @return string|null The refusal code, or null.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectProperties::of() is a pure read of the answer.
	 */
	private function refusal(string $id, object $publication): ?string {
		if ($this->rights->mayUpdate(publication: $publication) === false) {
			return 'forbidden';
		}

		$properties = ObjectProperties::of(object: $publication);
		if (trim((string)($properties['status'] ?? '')) !== 'approved') {
			return 'not_approved';
		}

		$veto = $this->publisher->embargoVeto(
			stored: $properties,
			record: $this->states->publishedRecord(publication: $properties),
			caller: null
		);
		if ($veto !== null) {
			return 'embargo_holds';
		}

		if ($this->publisher->documentCount(id: $id, publication: $publication) === 0) {
			return 'no_documents';
		}

		return null;
	}//end refusal()
}//end class

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

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\PublicationRightsInterface;
use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Publishes approved publications for an agent, one item at a time, each refusal named.
 *
 * The human approval is the publication's own review: a publisher or reviewer
 * moved it to `approved` in the screen. The agent cannot approve, so a draft or
 * a publication still in review is refused even where review is switched off.
 * Every other check is the publish button's: the publish right, the update
 * right on the item, an embargo the caller may not lift, and a publication
 * without documents (the agent cannot confirm that one).
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
 */
class AgentPublishing {

	/**
	 * The most publications one call publishes.
	 *
	 * @var int
	 */
	public const MAX_ITEMS = 50;

	/**
	 * The OpenRegister object service.
	 *
	 * @var string
	 */
	private const OBJECTS = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface               $container   OpenRegister's object service.
	 * @param IAppConfig                       $config      The publication register and schema.
	 * @param IUserSession                     $userSession The person the agent acts for.
	 * @param PublicationRightsInterface       $rights      The publish right, and the update right on one publication.
	 * @param PublicationStateServiceInterface $states      The record a publish now stores.
	 * @param PublishTransition                $publisher   The lifecycle transition, the embargo check and the document count.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $config,
		private readonly IUserSession $userSession,
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

		$register = $this->config->getValueString(AppIdentity::APP_ID, 'publication_register', '');
		$schema   = $this->config->getValueString(AppIdentity::APP_ID, 'publication_schema', '');
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
	 */
	private function publishOne(string $id, ObjectFindOptions $options): ?string {
		try {
			$publication = $this->container->get(self::OBJECTS)->find(id: $id, options: $options, _rbac: true);
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
			$record = $this->states->publishedRecord(publication: self::propertiesOf(publication: $publication));
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
	 */
	private function refusal(string $id, object $publication): ?string {
		if ($this->rights->mayUpdate(publication: $publication) === false) {
			return 'forbidden';
		}

		$properties = self::propertiesOf(publication: $publication);
		if (PublishTransition::storedState(stored: (string)($properties['status'] ?? '')) !== 'approved') {
			return 'not_approved';
		}

		$veto = $this->publisher->embargoVeto(
			stored: $properties,
			record: $this->states->publishedRecord(publication: $properties),
			caller: $this->userSession->getUser()
		);
		if ($veto !== null) {
			return 'embargo_holds';
		}

		if ($this->publisher->documentCount(id: $id, publication: $publication) === 0) {
			return 'no_documents';
		}

		return null;
	}//end refusal()

	/**
	 * The properties of a publication as OpenRegister answers it.
	 *
	 * @param object $publication The publication.
	 *
	 * @return array<string, mixed> Its properties.
	 */
	private static function propertiesOf(object $publication): array {
		if (method_exists($publication, 'jsonSerialize') === true) {
			$data = $publication->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return get_object_vars($publication);
	}//end propertiesOf()
}//end class

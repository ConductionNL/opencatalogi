<?php

/**
 * OpenCatalogi publication write guard.
 *
 * Loads a publication for a write by the signed-in user, or answers why the
 * write is refused: not signed in, not readable, or without the update right.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\Publication\PublicationRights;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The publication a write is about, or the answer that refuses the write.
 *
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */
class PublicationWriteGuard {

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface $registers   The OpenRegister ObjectService and register configuration.
	 * @param PublicationRights           $rights      Whether the user may update a publication.
	 * @param IUserSession                $userSession The signed-in user.
	 * @param IL10N                       $l10n        Translations.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $registers,
		private readonly PublicationRights $rights,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * The signed-in user, or null.
	 *
	 * @return IUser|null The user.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	public function user(): ?IUser {
		return $this->userSession->getUser();
	}//end user()

	/**
	 * Load the publication for a write, or the answer that refuses it.
	 *
	 * Not signed in is 401; missing or unreadable is 404, so an id nobody may
	 * read does not confirm that it exists; readable without the update right is 403.
	 *
	 * @param string $id The publication.
	 *
	 * @return array{0: object|null, 1: JSONResponse|null} The publication, or null and the refusal.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	public function guard(string $id): array {
		if ($this->userSession->getUser() === null) {
			return [null, new JSONResponse(data: ['error' => 'not-logged-in'], statusCode: Http::STATUS_UNAUTHORIZED)];
		}

		$publication = $this->load(id: $id);
		if ($publication === null) {
			return [null, $this->notFound()];
		}

		if ($this->rights->mayUpdate(publication: $publication) === false) {
			return [null, new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN)];
		}

		return [$publication, null];
	}//end guard()

	/**
	 * The publication as the signed-in user may read it.
	 *
	 * @param string $id The publication.
	 *
	 * @return object|null The publication, or null when it is missing or unreadable.
	 */
	private function load(string $id): ?object {
		try {
			$config = $this->registers->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');
			$options = new ObjectFindOptions(register: $config['register'], schema: $config['schema']);
			$publication = $this->registers->objectService()->find(id: $id, options: $options, _rbac: true);
		} catch (\Throwable $e) {
			return null;
		}

		if (is_object($publication) === false) {
			return null;
		}

		return $publication;
	}//end load()

	/**
	 * The 404 for a publication that is missing or not readable.
	 *
	 * @return JSONResponse The answer.
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'not-found', 'message' => $this->l10n->t('Publication not found')],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end notFound()
}//end class

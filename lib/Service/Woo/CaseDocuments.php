<?php

/**
 * The documents delivered for a Woo request, read from the files on its record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-transparency/spec.md#requirement-opening-a-batch-gathers-the-documents-of-the-woo-request-req-cvs-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use DomainException;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;

/**
 * Gathers the documents of one Woo request, named by its case reference.
 *
 * The documents delivered for a case are the files attached to its Woo
 * request record in OpenRegister. Each becomes one document of the batch:
 * its file id as the reference, its name and its type. An unknown reference
 * is refused, naming it, rather than opening an empty batch nobody asked for.
 *
 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-transparency/spec.md#requirement-opening-a-batch-gathers-the-documents-of-the-woo-request-req-cvs-004
 */
class CaseDocuments {

	/**
	 * Constructor.
	 *
	 * @param WooRequestStore    $requests     Where Woo requests are kept.
	 * @param OpenRegisterLookup $openRegister Resolves OpenRegister's file service.
	 */
	public function __construct(
		private readonly WooRequestStore $requests,
		private readonly OpenRegisterLookup $openRegister,
	) {
	}//end __construct()

	/**
	 * The documents delivered for a case, as batch documents.
	 *
	 * @param string $caseReference The Woo request's case reference.
	 *
	 * @return array<int, array{documentReference: string, fileName: string, fileType: string}> The documents.
	 *
	 * @throws DomainException When no Woo request carries the reference.
	 *
	 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-transparency/spec.md#scenario-a-batch-starts-with-the-case-s-documents
	 */
	public function forCase(string $caseReference): array {
		$request = $this->requests->byReference(reference: $caseReference);
		if ($request === null) {
			throw new DomainException(sprintf('No Woo request has the case reference %s.', $caseReference));
		}

		$recordId = (string)($request['id'] ?? ($request['@self']['uuid'] ?? ($request['uuid'] ?? '')));
		$fileService = $this->openRegister->installedService(
			'OCA\OpenRegister\Service\FileService',
			'OpenRegister FileService is not available, so the documents of the case cannot be read.'
		);
		$files = ($fileService->formatFiles($fileService->getFiles(object: $recordId))['results'] ?? []);

		$documents = [];
		foreach ((array)$files as $file) {
			if (is_array($file) === false) {
				continue;
			}

			$documents[] = [
				'documentReference' => (string)($file['id'] ?? ''),
				'fileName' => (string)($file['title'] ?? ($file['name'] ?? '')),
				'fileType' => (string)($file['mimetype'] ?? ($file['mimeType'] ?? '')),
			];
		}

		return $documents;
	}//end forCase()
}//end class

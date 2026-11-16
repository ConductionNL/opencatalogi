<?php

/**
 * Whether a file is the original of a redacted Woo document.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\AttachmentReading
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/tasks.md#1.4
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\AttachmentReading;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\RedactedOriginalGuardInterface;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenCatalogi\Service\WooService;
use OCP\IAppConfig;
use Throwable;

/**
 * The Woo redaction path marks the original of a partly public document by
 * the `wooAssessment` that names it as its `documentReference` and carries
 * the separate redacted file as its `anonymizedDocument`. Such an original is
 * never read into the text index: its withheld text would become findable.
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/tasks.md#1.4
 */
class RedactedOriginalGuard implements RedactedOriginalGuardInterface {
	use ReadsObjectProperties;

	/**
	 * How many assessments of one document are read.
	 */
	private const LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config       Where the Woo register and assessment schema are configured.
	 * @param OpenRegisterLookupInterface $openRegister OpenRegister's object service.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly OpenRegisterLookupInterface $openRegister,
	) {

	}//end __construct()

	/**
	 * Whether the file is the original of a redacted document.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return bool|null True for a redacted original, false when it is not
	 *                   one (or no Woo register is configured), null when it
	 *                   could not be checked.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/tasks.md#1.4
	 */
	public function isRedactedOriginal(int $fileId): ?bool {
		$target = $this->assessmentTarget();
		if ($target === null || $fileId <= 0) {
			return false;
		}

		$rows = $this->assessmentsOf(target: $target, fileId: $fileId);
		if ($rows === null) {
			return null;
		}

		foreach ($rows as $row) {
			if ($this->marksRedaction(assessment: $this->asRecord(object: $row), fileId: $fileId) === true) {
				return true;
			}
		}

		return false;

	}//end isRedactedOriginal()

	/**
	 * The configured Woo register and assessment schema, or null when either is not configured.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function assessmentTarget(): ?array {
		$register = trim($this->config->getValueString('opencatalogi', WooService::CONFIG_BATCH_REGISTER, ''));
		$schema = trim($this->config->getValueString('opencatalogi', WooService::CONFIG_ASSESSMENT_SCHEMA, ''));
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];

	}//end assessmentTarget()

	/**
	 * The assessments that reference the file, or null when they could not be read.
	 *
	 * @param array{register: string, schema: string} $target The register and schema of the assessments.
	 * @param int                                      $fileId The Nextcloud file id.
	 *
	 * @return array<int, mixed>|null
	 */
	private function assessmentsOf(array $target, int $fileId): ?array {
		try {
			if ($this->openRegister->isInstalled() === false) {
				return null;
			}

			$result = $this->openRegister->objectService()->searchObjectsPaginated(
				query: ['@self' => $target, 'documentReference' => (string)$fileId, '_limit' => self::LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}

		return array_values((array)($result['results'] ?? []));

	}//end assessmentsOf()

	/**
	 * Whether an assessment names this file as the original of a separate redacted file.
	 *
	 * @param array<string, mixed> $assessment The assessment.
	 * @param int                  $fileId     The file id.
	 *
	 * @return bool
	 */
	private function marksRedaction(array $assessment, int $fileId): bool {
		if (trim((string)($assessment['documentReference'] ?? '')) !== (string)$fileId) {
			return false;
		}

		$redacted = trim((string)($assessment['anonymizedDocument'] ?? ''));
		return $redacted !== '' && $redacted !== (string)$fileId;

	}//end marksRedaction()
}//end class

<?php

/**
 * Whether the Woo annual report can be generated on this instance.
 *
 * The report is OpenRegister's to render: the `woo-jaarverslag` template is a
 * seed object of its `report-templates` schema, and `POST /api/reports/generate`
 * renders it. Both arrive with a newer OpenRegister. This class answers whether
 * both are here, so the publications report offers the action only when it can
 * work and otherwise says why. It never produces a partial report.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
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
 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-an-officer-generates-the-annual-report-from-the-publications-report-req-war-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * Answers whether OpenRegister can generate the Woo annual report.
 */
class WooAnnualReport {
	/**
	 * The register and schema OpenRegister keeps report templates in.
	 *
	 * @var string
	 */
	public const TEMPLATE_REGISTER = 'report-templates';

	/**
	 * The slug of the template OpenCatalogi ships.
	 *
	 * @var string
	 */
	public const TEMPLATE_SLUG = 'woo-jaarverslag';

	/**
	 * The OpenRegister controller behind `POST /api/reports/generate`.
	 *
	 * @var string
	 */
	public const GENERATOR_CLASS = 'OCA\OpenRegister\Search\Controller\ReportTemplateController';

	/**
	 * The reason given when OpenRegister cannot generate the report.
	 *
	 * @var string
	 */
	public const NEEDS_NEWER_OPENREGISTER = 'needs-newer-openregister';

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterServicesInterface $openRegister Reaches OpenRegister's object service.
	 */
	public function __construct(
		private readonly OpenRegisterServicesInterface $openRegister,
	) {
	}//end __construct()

	/**
	 * Whether the report can be generated, with the template to generate it from.
	 *
	 * @return array{available: bool, templateId: string|null, reason: string|null, formats: list<string>}
	 *
	 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-an-officer-generates-the-annual-report-from-the-publications-report-req-war-002
	 */
	public function availability(): array {
		$templateId = null;
		if (class_exists(self::GENERATOR_CLASS) === true) {
			$templateId = $this->templateId();
		}

		if ($templateId === null) {
			return ['available' => false, 'templateId' => null, 'reason' => self::NEEDS_NEWER_OPENREGISTER, 'formats' => []];
		}

		return ['available' => true, 'templateId' => $templateId, 'reason' => null, 'formats' => ['pdf', 'ods', 'csv']];
	}//end availability()

	/**
	 * The shipped template's uuid, or null when OpenRegister does not hold it.
	 *
	 * @return string|null
	 */
	private function templateId(): ?string {
		try {
			$template = $this->openRegister->installedService(
				serviceId: 'OCA\OpenRegister\Service\ObjectService',
				unavailable: 'OpenRegister is not installed.'
			)->find(
				id: self::TEMPLATE_SLUG,
				options: new ObjectFindOptions(register: self::TEMPLATE_REGISTER, schema: self::TEMPLATE_REGISTER)
			);
		} catch (Throwable $e) {
			// No OpenRegister, no report-templates register, or no template: the report cannot be generated.
			return null;
		}

		if (is_object($template) === false || method_exists($template, 'getUuid') === false) {
			return null;
		}

		$uuid = (string)$template->getUuid();
		if ($uuid === '') {
			return null;
		}

		return $uuid;
	}//end templateId()
}//end class

<?php

/**
 * The Generate annual report action is offered only when OpenRegister can generate it.
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
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
 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-an-officer-generates-the-annual-report-from-the-publications-report-req-war-002
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters -- PHPUnit assertion and mock helpers take positional arguments.

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCA\OpenCatalogi\Controller\WooAnnualReportController;
use OCA\OpenCatalogi\Service\Woo\WooAnnualReport;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * WooAnnualReport::availability() and its endpoint.
 */
class WooAnnualReportCapabilityTest extends TestCase {

	/**
	 * Make the OpenRegister generator class resolvable, as a newer OpenRegister does.
	 *
	 * @return void
	 */
	private function withGenerator(): void {
		if (class_exists(WooAnnualReport::GENERATOR_CLASS) === false) {
			eval('namespace OCA\OpenRegister\Search\Controller; class ReportTemplateController {}');
		}
	}//end withGenerator()

	/**
	 * An OpenRegister double whose object service finds the given template, or throws.
	 *
	 * @param object|null    $template The template the find returns.
	 * @param Throwable|null $error    What the find throws instead.
	 * @param array|null     $seen     The find's arguments, written back.
	 *
	 * @return OpenRegisterServicesInterface
	 */
	private function openRegister(?object $template, ?\Throwable $error = null, ?array &$seen = null): OpenRegisterServicesInterface {
		$objects = new class($template, $error, $seen) {
			/**
			 * @param object|null     $template The template.
			 * @param \Throwable|null $error    The error.
			 * @param array|null      $seen     The arguments seen.
			 */
			public function __construct(private ?object $template, private ?\Throwable $error, public ?array &$seen) {
			}

			/**
			 * @param string            $id      The id.
			 * @param ObjectFindOptions $options The options.
			 * @return object|null
			 */
			public function find(string $id, ObjectFindOptions $options): ?object {
				$this->seen = ['id' => $id, 'register' => $options->register, 'schema' => $options->schema];
				if ($this->error !== null) {
					throw $this->error;
				}

				return $this->template;
			}
		};
		$openRegister = $this->createMock(OpenRegisterServicesInterface::class);
		$openRegister->method('installedService')->willReturn($objects);

		return $openRegister;
	}//end openRegister()

	/**
	 * A template entity double with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return object
	 */
	private function template(string $uuid): object {
		return new class($uuid) {
			/** @param string $uuid The uuid. */
			public function __construct(private string $uuid) {
			}

			/** @return string */
			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}//end template()

	/**
	 * Without OpenRegister's report templates the action is absent and the page is told why.
	 *
	 * @return void
	 */
	public function testWithoutReportGenerationTheActionIsAbsent(): void {
		$this->withGenerator();
		$report = new WooAnnualReport($this->openRegister(null, new RuntimeException('no register report-templates')));

		$this->assertSame(
			['available' => false, 'templateId' => null, 'reason' => 'needs-newer-openregister', 'formats' => []],
			$report->availability()
		);
	}//end testWithoutReportGenerationTheActionIsAbsent()

	/**
	 * With the generator but without the shipped template the action is absent too.
	 *
	 * @return void
	 */
	public function testWithoutTheTemplateTheActionIsAbsent(): void {
		$this->withGenerator();

		$this->assertFalse((new WooAnnualReport($this->openRegister(null)))->availability()['available']);
		$this->assertFalse((new WooAnnualReport($this->openRegister($this->template(''))))->availability()['available']);
	}//end testWithoutTheTemplateTheActionIsAbsent()

	/**
	 * With both, the action generates from the shipped template, looked up in OpenRegister's report-templates.
	 *
	 * @return void
	 */
	public function testWithReportGenerationTheActionNamesTheTemplate(): void {
		$this->withGenerator();
		$seen = null;
		$availability = (new WooAnnualReport($this->openRegister($this->template('t-1'), null, $seen)))->availability();

		$this->assertSame(['available' => true, 'templateId' => 't-1', 'reason' => null, 'formats' => ['pdf', 'ods', 'csv']], $availability);
		$this->assertSame(['id' => 'woo-jaarverslag', 'register' => 'report-templates', 'schema' => 'report-templates'], $seen);
	}//end testWithReportGenerationTheActionNamesTheTemplate()

	/**
	 * The endpoint answers the availability as it is.
	 *
	 * @return void
	 */
	public function testTheEndpointAnswersTheAvailability(): void {
		$this->withGenerator();
		$controller = new WooAnnualReportController(
			'opencatalogi',
			$this->createMock(IRequest::class),
			new WooAnnualReport($this->openRegister($this->template('t-2')))
		);

		$this->assertSame('t-2', $controller->availability()->getData()['templateId']);
	}//end testTheEndpointAnswersTheAvailability()
}//end class

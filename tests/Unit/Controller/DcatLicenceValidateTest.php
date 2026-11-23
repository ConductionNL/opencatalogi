<?php

/**
 * The DCAT validator reports a licence that is not on the controlled list.
 *
 * Drives `dcat#validate` through the controller with the real DcatService and
 * DcatMappingService, so the omitted licence and its report come from the same
 * mapping the feed uses.
 *
 * @category Test
 * @package  Unit\Controller
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
 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\DcatController;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\DcatAssurance;
use OCA\OpenCatalogi\Service\DcatDatasetSource;
use OCA\OpenCatalogi\Service\DcatMappingProfile;
use OCA\OpenCatalogi\Service\DcatMappingService;
use OCA\OpenCatalogi\Service\DcatSerializer;
use OCA\OpenCatalogi\Service\DcatService;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use OCA\OpenCatalogi\Service\InstanceSettings;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The validate route names each dataset whose licence did not resolve.
 */
class DcatLicenceValidateTest extends TestCase {

	/**
	 * A legacy free-text licence is left out of the feed and listed by the validator.
	 *
	 * @return void
	 */
	public function testAFreeTextLicenceIsReportedByValidate(): void {
		$response = $this->controllerFor(
			publications: [
				['id' => 'u1', 'title' => 'Vrij', 'license' => 'vrij te gebruiken'],
				['id' => 'u2', 'title' => 'Open', 'license' => 'CC0'],
			]
		)->validate('open');

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertFalse(condition: $data['valid']);

		$licences = array_values(array_filter($data['violations'], static fn (array $v): bool => ($v['axis'] ?? '') === 'dct:license'));
		$this->assertSame(
			expected: [[
				'iri' => 'https://host/api/open/u1',
				'axis' => 'dct:license',
				'value' => 'vrij te gebruiken',
				'reason' => 'licence is not on the EU licences authority list',
			]],
			actual: $licences
		);

	}//end testAFreeTextLicenceIsReportedByValidate()

	/**
	 * A theme violation stays out of the validate answer; only licences are added to it.
	 *
	 * @return void
	 */
	public function testOnlyLicenceViolationsJoinTheValidateAnswer(): void {
		$data = $this->controllerFor(
			publications: [['id' => 'u3', 'title' => 'Thema', 'license' => 'CC0', 'category' => 'geen-thema']]
		)->validate('open')->getData();

		$axes = array_values(array_filter($data['violations'], static fn (array $v): bool => isset($v['axis']) === true));
		$this->assertSame(expected: [], actual: $axes);

	}//end testOnlyLicenceViolationsJoinTheValidateAnswer()

	/**
	 * A controller over a DcatService whose catalogue page maps the given publications.
	 *
	 * @param array<int, array<string, mixed>> $publications The publications on the first page.
	 *
	 * @return DcatController
	 */
	private function controllerFor(array $publications): DcatController {
		$versions = $this->createMock(originalClassName: StandardsVersionService::class);
		$versions->method('dcatApNlVersion')->willReturn(
			[
				'declared' => '3.0',
				'published' => '3.0',
				'status' => StandardsVersionService::STATUS_CURRENT,
				'profile' => StandardsVersionService::DCAT_AP_NL_PROFILE,
			]
		);
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://host');
		$mapping = new DcatMappingService(new DcatVocabularyService());

		$lookup = new OpenRegisterLookup(
			$this->createMock(originalClassName: ContainerInterface::class),
			$this->createMock(originalClassName: IAppManager::class)
		);
		$instance = new InstanceSettings($urls, $this->createMock(originalClassName: IAppConfig::class));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$service = new class($lookup, $mapping, new DcatSerializer(), $instance, $logger, new DcatAssurance($versions)) extends DcatService {

			/**
			 * The mapping the page goes through.
			 *
			 * @var DcatMappingService|null
			 */
			public ?DcatMappingService $pageMapping = null;

			/**
			 * The publications on the page.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $pagePublications = [];

			/**
			 * Map the page's publications as the feed does, collecting their violations.
			 *
			 * @param array<string, mixed> $catalog The catalog.
			 * @param string $catalogSlug The slug.
			 * @param int $page The page.
			 * @param array $violations The violations, appended to.
			 *
			 * @return array<string, mixed> The document.
			 */
			public function buildCatalogDocument(array $catalog, string $catalogSlug, int $page = 1, array &$violations = []): array {
				$graph = [];
				foreach ($this->pagePublications as $publication) {
					$source = new DcatDatasetSource(publication: $publication, datasetIri: "https://host/api/$catalogSlug/" . $publication['id']);
					$profile = new DcatMappingProfile(mapping: DcatMappingService::DEFAULT_MAPPING, defaults: ['name' => 'Gemeente']);
					$graph[] = $this->pageMapping->mapDataset(source: $source, profile: $profile);
					array_push($violations, ...$source->violations);
				}

				return ['@context' => [], '@graph' => $graph];
			}
		};
		$service->pageMapping = $mapping;
		$service->pagePublications = $publications;

		$catalogi = $this->createMock(originalClassName: CatalogiService::class);
		$catalogi->method('getCatalogBySlug')->willReturn(['hasDcat' => true, 'slug' => 'open']);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DcatController(
			'opencatalogi',
			$this->createMock(originalClassName: IRequest::class),
			$service,
			new DcatSerializer(),
			$catalogi,
			$this->createMock(originalClassName: PublicationQueryService::class),
			$l10n,
			$logger,
			$versions,
			null
		);

	}//end controllerFor()
}//end class

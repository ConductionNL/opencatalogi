<?php

/**
 * Unit tests for the licence list the licence pickers read.
 *
 * @category Test
 * @package  Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\DcatController;
use OCA\OpenCatalogi\Service\DcatLicenceRule;
use OCA\OpenCatalogi\Service\DcatService;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\DcatSerializerInterface;
use OCA\OpenCatalogi\Contract\DcatServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\StandardsVersionServiceInterface;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * The licence pickers read the same list a save is held to.
 *
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */
class DcatLicencesTest extends TestCase {

	/**
	 * The service's answer: every bundled member, with its code, IRI and both labels, and the list's provenance.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 *
	 * @return void
	 */
	public function testTheServiceAnswersTheBundledListWithItsSource(): void {
		$answer = self::service()->licences();

		$this->assertSame(expected: count((new DcatVocabularyService())->licenceList()), actual: $answer['total']);
		$this->assertGreaterThan(expected: 0, actual: $answer['total']);
		$this->assertSame(expected: $answer['total'], actual: count($answer['results']));
		$this->assertNotSame(expected: '', actual: $answer['source']);
		$this->assertNotSame(expected: '', actual: $answer['version']);
		foreach ($answer['results'] as $row) {
			$this->assertSame(expected: ['code', 'iri', 'label', 'labelNl'], actual: array_keys($row));
		}
	}//end testTheServiceAnswersTheBundledListWithItsSource()

	/**
	 * Every IRI the picker offers is one the save rule accepts.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 *
	 * @return void
	 */
	public function testEveryOfferedLicencePassesTheSaveRule(): void {
		$rule = new DcatLicenceRule(vocabulary: new DcatVocabularyService());

		foreach (self::service()->licences()['results'] as $row) {
			$this->assertTrue(condition: $rule->isListed(value: $row['iri']), message: $row['code']);
		}
	}//end testEveryOfferedLicencePassesTheSaveRule()

	/**
	 * The route answers the service's list for a signed-in caller who is not an administrator.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 *
	 * @return void
	 */
	public function testTheRouteAnswersTheListToAnySignedInUser(): void {
		$list = ['results' => [['code' => 'CC0', 'iri' => 'http://x/CC0', 'label' => 'CC0', 'labelNl' => 'CC0']], 'total' => 1, 'source' => 's', 'version' => 'v'];
		$dcatService = $this->createMock(originalClassName: DcatServiceInterface::class);
		$dcatService->method('licences')->willReturn($list);

		$controller = new DcatController(
			appName: 'opencatalogi',
			request: $this->createMock(originalClassName: IRequest::class),
			dcatService: $dcatService,
			serializer: $this->createMock(originalClassName: DcatSerializerInterface::class),
			catalogiService: $this->createMock(originalClassName: CatalogiServiceInterface::class),
			queryService: $this->createMock(originalClassName: PublicationQueryServiceInterface::class),
			l10n: $this->createMock(originalClassName: IL10N::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			standardsVersions: $this->createMock(originalClassName: StandardsVersionServiceInterface::class)
		);

		$response = $controller->licences();
		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: $list, actual: $response->getData());

		$method = new ReflectionMethod(objectOrMethod: DcatController::class, method: 'licences');
		$this->assertNotEmpty(actual: $method->getAttributes(name: NoAdminRequired::class));
		$this->assertNotEmpty(actual: $method->getAttributes(name: NoCSRFRequired::class));
	}//end testTheRouteAnswersTheListToAnySignedInUser()

	/**
	 * The service without its collaborators: licences() reads only the bundled list.
	 *
	 * @return DcatService
	 */
	private static function service(): DcatService {
		return (new ReflectionClass(objectOrClass: DcatService::class))->newInstanceWithoutConstructor();
	}//end service()
}//end class

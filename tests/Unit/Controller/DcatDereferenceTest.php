<?php

/**
 * Content negotiation on a dataset IRI, through PublicationsController::show().
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Controller\PublicationsController;
use OCA\OpenCatalogi\Http\DcatResponse;
use OCA\OpenCatalogi\Service\DcatAssurance;
use OCA\OpenCatalogi\Service\DcatDereference;
use OCA\OpenCatalogi\Service\DcatMappingService;
use OCA\OpenCatalogi\Service\DcatSerializer;
use OCA\OpenCatalogi\Service\DcatService;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use OCA\OpenCatalogi\Service\InstanceSettings;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A dataset IRI answers Turtle, redirects HTML and refuses what the caller cannot read.
 *
 * @covers \OCA\OpenCatalogi\Service\DcatDereference
 * @covers \OCA\OpenCatalogi\Controller\PublicationsController
 * @covers \OCA\OpenCatalogi\Service\DcatService
 * @uses   \OCA\OpenCatalogi\Controller\CorsSettings
 * @uses   \OCA\OpenCatalogi\Http\DcatResponse
 * @uses   \OCA\OpenCatalogi\Service\CallerScope
 * @uses   \OCA\OpenCatalogi\Service\DcatAssurance
 * @uses   \OCA\OpenCatalogi\Service\DcatDatasetSource
 * @uses   \OCA\OpenCatalogi\Service\DcatDistributionMapper
 * @uses   \OCA\OpenCatalogi\Service\DcatMappingProfile
 * @uses   \OCA\OpenCatalogi\Service\DcatMappingService
 * @uses   \OCA\OpenCatalogi\Service\DcatSerializer
 * @uses   \OCA\OpenCatalogi\Service\DcatVocabularyService
 * @uses   \OCA\OpenCatalogi\Service\InstanceSettings
 * @uses   \OCA\OpenCatalogi\Service\OpenRegisterLookup
 * @uses   \OCA\OpenCatalogi\Service\StandardsVersionService
 */
class DcatDereferenceTest extends TestCase {

	private const UUID = '0b1e7a52-0000-4000-8000-000000000001';

	private const IRI = 'https://host/apps/opencatalogi/api/woo/' . self::UUID;

	/**
	 * The queries the ObjectService was asked.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The controller over a real DcatService whose search answers $results.
	 *
	 * @param string $accept The Accept header.
	 * @param list<array<string, mixed>> $results What the RBAC-on search answers.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return PublicationsController The controller.
	 */
	private function controller(string $accept, array $results, array $params = []): PublicationsController {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://host');
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $args): string => "/apps/opencatalogi/publications/{$args['catalogSlug']}/{$args['id']}#$route"
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn ($app, $key, $default = '') => $default);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query, bool $_rbac) use ($results): array {
				$this->queries[] = $query + ['rbac' => $_rbac];
				return ['results' => $results, 'next' => null];
			}
		);
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFiles')->willReturn([]);
		$fileService->method('formatFiles')->willReturn(['results' => []]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willThrowException(new \RuntimeException('not needed'));

		$orContainer = $this->createMock(ContainerInterface::class);
		$orContainer->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				'OCA\\OpenRegister\\Service\\ObjectService' => $objectService,
				'OCA\\OpenRegister\\Service\\FileService' => $fileService,
				'OCA\\OpenRegister\\Db\\SchemaMapper' => $schemaMapper,
			}
		);

		$versions = $this->createMock(StandardsVersionService::class);
		$dcat = new DcatService(
			new OpenRegisterLookup($orContainer, $appManager),
			new DcatMappingService(new DcatVocabularyService()),
			new DcatSerializer(),
			new InstanceSettings($urls, $appConfig),
			$this->createMock(LoggerInterface::class),
			new DcatAssurance($versions)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$dereference = new DcatDereference($dcat, new DcatSerializer(), $urls, $l10n);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				DcatDereference::class => $dereference,
				default => $orContainer->get($id),
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => strtolower($name) === 'accept' ? $accept : '');
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		$catalogi = $this->createMock(CatalogiServiceInterface::class);
		$catalogi->method('getCatalogBySlug')->willReturn(['id' => 'c1', 'title' => 'WOO', 'slug' => 'woo', 'hasDcat' => true, 'registers' => [20], 'schemas' => [28]]);
		$guard = $this->createMock(PublicationQueryServiceInterface::class);
		$guard->method('applyCatalogReadRuleGuard')->willReturnArgument(0);

		return new PublicationsController(
			'opencatalogi',
			$request,
			$this->createMock(PublicationServiceInterface::class),
			$catalogi,
			$guard,
			$container,
			$appManager,
			$this->createMock(LoggerInterface::class),
			$l10n,
			$this->createMock(UsageCounterServiceInterface::class),
			$appConfig
		);
	}//end controller()

	/**
	 * A public publication of the feed.
	 *
	 * @return array<string, mixed> The publication.
	 */
	private function publication(): array {
		return [
			'id' => self::UUID,
			'title' => 'Besluit',
			'@self' => ['uuid' => self::UUID, 'register' => 20, 'schema' => 28, 'updated' => '2026-01-01T00:00:00+00:00'],
		];
	}//end publication()

	/**
	 * Turtle on a dataset IRI answers that dataset's DCAT graph, read with RBAC on.
	 *
	 * @return void
	 */
	public function testADatasetIriAnswersTurtle(): void {
		$response = $this->controller(accept: 'text/turtle', results: [$this->publication()])->show('woo', self::UUID);

		$this->assertInstanceOf(DcatResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertStringStartsWith('text/turtle', $response->getHeaders()['Content-Type']);
		$body = $response->render();
		$this->assertStringContainsString('<' . self::IRI . '>', $body);
		$this->assertStringContainsString('dcat:Dataset', $body);
		$this->assertStringNotContainsString('dcat:Catalog', $body);

		$this->assertCount(1, $this->queries);
		$this->assertSame([self::UUID], $this->queries[0]['_ids']);
		$this->assertSame(28, $this->queries[0]['@self']['schema']);
		$this->assertTrue($this->queries[0]['rbac']);
	}//end testADatasetIriAnswersTurtle()

	/**
	 * A browser asking for HTML is redirected to the publication page, without a search.
	 *
	 * @return void
	 */
	public function testHtmlRedirectsToThePublicPage(): void {
		$accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
		$response = $this->controller(accept: $accept, results: [$this->publication()])->show('woo', self::UUID);

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame(
			'/apps/opencatalogi/publications/woo/' . self::UUID . '#opencatalogi.ui.publicationsPage',
			$response->getRedirectURL()
		);
		$this->assertSame([], $this->queries);
	}//end testHtmlRedirectsToThePublicPage()

	/**
	 * A publication the RBAC-on search does not return, such as a draft, is a 404.
	 *
	 * @return void
	 */
	public function testADraftIriIs404(): void {
		$response = $this->controller(accept: 'application/rdf+xml', results: [])->show('woo', self::UUID);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(404, $response->getStatus());
		$this->assertCount(1, $this->queries);
	}//end testADraftIriIs404()

	/**
	 * A row that is not the asked uuid is never answered as it.
	 *
	 * @return void
	 */
	public function testAnotherRowIsNotAnsweredAsTheIri(): void {
		$other = $this->publication();
		$other['id'] = 'other';
		$other['@self']['uuid'] = 'other';

		$response = $this->controller(accept: 'text/turtle', results: [$other])->show('woo', self::UUID);

		$this->assertSame(404, $response->getStatus());
	}//end testAnotherRowIsNotAnsweredAsTheIri()

	/**
	 * JSON, JSON-LD (schema.org) and an explicit format keep the publication answer.
	 *
	 * @return void
	 */
	public function testOtherRepresentationsAreNotDereferenced(): void {
		$this->assertNull(DcatDereference::preferred(accept: 'application/json'));
		$this->assertNull(DcatDereference::preferred(accept: 'application/ld+json'));
		$this->assertNull(DcatDereference::preferred(accept: '*/*'));
		$this->assertNull(DcatDereference::preferred(accept: null));
		$this->assertSame('rdfxml', DcatDereference::preferred(accept: 'text/turtle;q=0.5, application/rdf+xml'));
		$this->assertSame('turtle', DcatDereference::preferred(accept: 'application/json;q=0.2, text/turtle;q=0.9'));

		$response = $this->controller(accept: 'text/turtle', results: [$this->publication()], params: ['format' => 'json'])->show('woo', self::UUID);
		$this->assertNotInstanceOf(DcatResponse::class, $response);
	}//end testOtherRepresentationsAreNotDereferenced()

	/**
	 * A catalogue whose DCAT feed is off answers its IRIs with a 404.
	 *
	 * @return void
	 */
	public function testACatalogueWithoutDcatIs404(): void {
		$dcat = $this->createMock(DcatService::class);
		$dcat->method('isDcatEnabled')->willReturn(false);
		$dcat->expects($this->never())->method('buildDatasetDocument');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$response = (new DcatDereference($dcat, new DcatSerializer(), $this->createMock(IURLGenerator::class), $l10n))
			->answer(catalog: ['hasDcat' => false], catalogSlug: 'woo', id: self::UUID, kind: 'turtle');

		$this->assertSame(404, $response->getStatus());
	}//end testACatalogueWithoutDcatIs404()
}//end class

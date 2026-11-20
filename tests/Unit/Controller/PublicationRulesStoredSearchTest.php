<?php

/**
 * The public plain-word search over stored records (REQ-PIN-111).
 *
 * Without posted records the public search reads the stored records of every
 * configured published collection and matches the words against what each
 * collection's anonymous set allows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-public-searches-published-information-in-plain-words-req-pin-111
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\PublicationRuleChecks;
use OCA\OpenCatalogi\Controller\PublicationRulesController;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Publication\DecisionPublicationValidator;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService;
use OCA\OpenCatalogi\Service\Publication\PublishedRecordView;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for PublicationRulesController::publicSearch() over stored records.
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicationRulesController
 * @covers \OCA\OpenCatalogi\Controller\PublicationRuleChecks
 * @uses   \OCA\OpenCatalogi\Controller\AnswersCrossOriginRequests
 * @uses   \OCA\OpenCatalogi\Controller\CorsAllowlist
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublishedRecordView
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService
 * @uses   \OCA\OpenCatalogi\Service\Publication\PublicationRuleService
 * @uses   \OCA\OpenCatalogi\Service\Publication\DecisionPublicationValidator
 * @uses   \OCA\OpenCatalogi\Service\OpenRegisterLookup
 */
class PublicationRulesStoredSearchTest extends TestCase {

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The published-collection configuration.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * The ObjectService the stored search reaches.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The app config double.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

	/**
	 * Configure one decision collection and an OpenRegister holding one decision.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->stored = (string)json_encode(
			[
				[
					'recordType' => '12',
					'register' => '3',
					'anonymousProperties' => ['title'],
					'conditions' => [],
				],
			]
		);

		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $key === PublishedCollectionsService::CONFIG_KEY ? $this->stored : $default
		);

		$this->objects = $this->createMock(ObjectService::class);

	}//end setUp()

	/**
	 * The controller, with or without the stored-record view.
	 *
	 * @param boolean $withView Whether PublicationRuleChecks gets the view.
	 *
	 * @return PublicationRulesController The controller.
	 */
	private function controller(bool $withView = true): PublicationRulesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$rules = new PublicationRuleService();
		$view = null;
		if ($withView === true) {
			$view = new PublishedRecordView(
				new PublishedCollectionsService($this->config, $rules, 'opencatalogi'),
				$rules,
				new OpenRegisterLookup($container, $appManager)
			);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PublicationRulesController(
			'opencatalogi',
			$request,
			$this->config,
			$l10n,
			$this->createMock(IUserSession::class),
			new PublicationRuleChecks($rules, new DecisionPublicationValidator(), $view)
		);

	}//end controller()

	/**
	 * A stored decision.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function decision(): array {
		return [
			'id' => 'd-1',
			'title' => 'Besluit geluidsnormen',
			'notes' => 'Intern overleg met de wethouder',
			'dossier' => ['id' => 'dossier-9', 'url' => '/dossiers/dossier-9'],
			'@self' => ['register' => 3, 'schema' => 12],
		];

	}//end decision()

	/**
	 * Without posted records the search finds a stored document and names its dossier.
	 *
	 * @return void
	 */
	public function testTheSearchFindsAStoredDocumentWithItsDossier(): void {
		$this->params = ['q' => 'geluidsnormen'];
		$this->objects->expects($this->once())->method('searchObjects')->willReturn([$this->decision()]);

		$response = $this->controller()->publicSearch();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'results' => [
					[
						'id' => 'd-1',
						'@self' => ['register' => 3, 'schema' => 12],
						'title' => 'Besluit geluidsnormen',
						'dossier' => ['id' => 'dossier-9', 'url' => '/dossiers/dossier-9'],
					],
				],
				'total' => 1,
			],
			$response->getData()
		);

	}//end testTheSearchFindsAStoredDocumentWithItsDossier()

	/**
	 * A word only in a withheld property does not return the stored record.
	 *
	 * @return void
	 */
	public function testTheSearchRespectsTheAnonymousSet(): void {
		$this->params = ['q' => 'wethouder'];
		$this->objects->method('searchObjects')->willReturn([$this->decision()]);

		$this->assertSame(['results' => [], 'total' => 0], $this->controller()->publicSearch()->getData());

	}//end testTheSearchRespectsTheAnonymousSet()

	/**
	 * An unreadable configuration refuses with 503 rather than answering nothing found.
	 *
	 * @return void
	 */
	public function testAnUnreadableConfigurationRefusesWith503(): void {
		$this->params = ['q' => 'geluid'];
		$this->stored = '{not json';

		$response = $this->controller()->publicSearch();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('unreadable-rule', $response->getData()['error']);

	}//end testAnUnreadableConfigurationRefusesWith503()

	/**
	 * Posted records are searched as before, and nothing stored is read.
	 *
	 * @return void
	 */
	public function testPostedRecordsAreSearchedWithoutReadingTheStore(): void {
		$this->params = [
			'q' => 'een',
			'records' => [['@type' => 'besluit', 'title' => 'Een besluit', 'bsn' => '1']],
			'rules' => [['recordType' => 'besluit', 'enabled' => true, 'anonymousProperties' => ['title']]],
		];
		$this->objects->expects($this->never())->method('searchObjects');

		$this->assertSame(['results' => [['title' => 'Een besluit']], 'total' => 1], $this->controller()->publicSearch()->getData());

	}//end testPostedRecordsAreSearchedWithoutReadingTheStore()

	/**
	 * Without the stored-record view there is nothing stored to search.
	 *
	 * @return void
	 */
	public function testWithoutTheViewTheStoredSearchIsEmpty(): void {
		$this->params = ['q' => 'geluid'];
		$this->objects->expects($this->never())->method('searchObjects');

		$this->assertSame(['results' => [], 'total' => 0], $this->controller(withView: false)->publicSearch()->getData());

	}//end testWithoutTheViewTheStoredSearchIsEmpty()
}//end class

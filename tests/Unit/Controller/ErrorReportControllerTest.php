<?php

/**
 * A visitor's error report on a publication, through its public route.
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
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-take-error-reports-from-anyone-throttled-and-moderated-req-pdp-005
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Controller\ErrorReportController;
use OCA\OpenCatalogi\Service\ErrorReport\ErrorReportIntake;
use OCA\OpenCatalogi\Service\ErrorReport\ModeratorRecipients;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * `POST /api/publications/{id}/error-reports`.
 *
 * @covers \OCA\OpenCatalogi\Controller\ErrorReportController
 * @covers \OCA\OpenCatalogi\Service\ErrorReport\ErrorReportIntake
 * @covers \OCA\OpenCatalogi\Service\ErrorReport\ModeratorRecipients
 * @uses   \OCA\OpenCatalogi\Service\Publication\DocumentFacts
 * @uses   \OCA\OpenCatalogi\Controller\CorsAllowlist
 */
class ErrorReportControllerTest extends TestCase {

	private const PUBLICATION = [
		'id' => 'pub-1',
		'@self' => ['id' => 'pub-1', 'published' => '2026-10-02T09:00:00+00:00'],
	];

	/** @var CatalogiServiceInterface&MockObject */
	private CatalogiServiceInterface $catalogs;

	/** @var PublicationQueryServiceInterface&MockObject */
	private PublicationQueryServiceInterface $query;

	/** @var MockObject */
	private MockObject $objects;

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var array<int, array<string, mixed>> The objects saved. */
	private array $saved = [];

	/** @var array<string, mixed> The catalogue object as stored, past its read rules. */
	private array $storedCatalog = ['id' => 'cat-1', 'errorReportModerators' => ['woo-redactie']];

	/** @var array<string, mixed> The posted parameters. */
	private array $posted = [];

	private ErrorReportController $controller;

	/**
	 * A double that only configures the methods the class really has, adding the rest.
	 *
	 * Same helper as SitemapServiceTest::environmentAwareDouble().
	 *
	 * @param class-string $class The class to double.
	 * @param array<int, string> $methods The methods to configure.
	 *
	 * @return MockObject The double.
	 */
	private function environmentAwareDouble(string $class, array $methods): MockObject {
		$existing = [];
		$absent = [];
		foreach ($methods as $method) {
			if (method_exists($class, $method) === true) {
				$existing[] = $method;
			} else {
				$absent[] = $method;
			}
		}

		$builder = $this->getMockBuilder($class)->disableOriginalConstructor();
		if ($existing !== []) {
			$builder->onlyMethods($existing);
		}

		if ($absent !== []) {
			$builder->addMethods($absent);
		}

		return $builder->getMock();
	}//end environmentAwareDouble()

	/**
	 * A serialisable entity.
	 *
	 * @param array<string, mixed> $data The serialisation.
	 *
	 * @return object
	 */
	private static function entity(array $data): object {
		return new class($data) {
			/**
			 * @param array<string, mixed> $data The serialisation.
			 */
			public function __construct(private array $data) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end entity()

	/**
	 * Wire the controller to the real intake over doubled boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->catalogs = $this->createMock(CatalogiServiceInterface::class);
		$this->catalogs->method('getCatalogSlugs')->willReturn(['woo']);
		$this->query = $this->createMock(PublicationQueryServiceInterface::class);
		$this->query->method('applyCatalogReadRuleGuard')->willReturnArgument(0);
		$this->objects = $this->environmentAwareDouble(ObjectService::class, ['runAsAnonymous', 'find', 'saveObject']);
		$this->objects->method('runAsAnonymous')->willReturnCallback(static fn (callable $read): mixed => $read());
		$this->objects->method('find')->willReturnCallback(
			function (): ObjectEntity {
				$catalog = new ObjectEntity();
				$catalog->setObject($this->storedCatalog);
				return $catalog;
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				$report = new ObjectEntity();
				$report->setUuid('report-uuid-1');
				$report->setObject($object);
				return $report;
			}
		);
		$lookup = $this->createMock(OpenRegisterLookup::class);
		$lookup->method('installedObjectService')->willReturn($this->objects);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'publication_register' => '5',
				'error_report_schema' => '31',
				'catalog_register' => '5',
				'catalog_schema' => '1',
				default => $default,
			}
		);

		$intake = new ErrorReportIntake(
			catalogs: $this->catalogs,
			query: $this->query,
			openRegister: $lookup,
			config: $config,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('getParam')->willReturnCallback(fn (string $key): mixed => ($this->posted[$key] ?? null));
		$this->request->method('getRemoteAddress')->willReturn('203.0.113.7');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('*');

		$this->controller = new ErrorReportController(
			appName: 'opencatalogi',
			request: $this->request,
			intake: $intake,
			l10n: $l10n,
			config: $appConfig
		);
	}//end setUp()

	/**
	 * Catalogue `woo` with these settings holds the publication.
	 *
	 * @param array<string, mixed> $catalog     The catalogue as the public read answers it.
	 * @param array<string, mixed> $publication The publication.
	 *
	 * @return void
	 */
	private function catalogue(array $catalog, array $publication = self::PUBLICATION): void {
		$this->catalogs->method('getCatalogBySlug')->willReturn(['id' => 'cat-1', 'registers' => [5], 'schemas' => [9]] + $catalog);
		$this->query->method('findObjectInCatalog')->willReturnCallback(
			static fn (array $catalogue, string $id): ?object => ($id === 'pub-1' ? self::entity($publication) : null)
		);
	}//end catalogue()

	/**
	 * A report on an opted-in catalogue is stored as new and reaches the moderator group's members.
	 *
	 * @return void
	 */
	public function testAReportIsStoredAndTheModeratorsAreNotified(): void {
		$this->catalogue(['acceptErrorReports' => true]);
		$this->posted = ['message' => 'De bijlage hoort bij een ander besluit', 'contact' => 'inwoner@example.nl'];

		$response = $this->controller->create(id: 'pub-1');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['reference' => 'report-uuid-1'], $response->getData());
		$this->assertCount(1, $this->saved);
		$report = $this->saved[0];
		$this->assertSame('pub-1', $report['publication']);
		$this->assertSame('cat-1', $report['catalog']);
		$this->assertSame('De bijlage hoort bij een ander besluit', $report['message']);
		$this->assertSame('inwoner@example.nl', $report['contact']);
		$this->assertSame('new', $report['status']);
		$this->assertSame(['woo-redactie'], $report['moderators']);

		// The schema's rule on `created` reaches the members of the stored groups.
		$fragment = json_decode(
			(string) file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/woo-publication-detail-for-the-portal.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$rule = $fragment['components']['schemas']['errorReport']['x-openregister-notifications']['error-report-created'];
		$this->assertSame('created', $rule['trigger']['type']);
		$this->assertSame([['kind' => 'expression', 'resolver' => ModeratorRecipients::class]], $rule['recipients']);

		$member = $this->createMock(IUser::class);
		$member->method('getUID')->willReturn('redacteur');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$member]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => ($gid === 'woo-redactie' ? $group : null));
		$entity = new ObjectEntity();
		$entity->setObject($report);

		$this->assertSame(['redacteur'], (new ModeratorRecipients(groups: $groups))->resolve($entity, []));
	}//end testAReportIsStoredAndTheModeratorsAreNotified()

	/**
	 * A catalogue that did not opt in takes nothing.
	 *
	 * @return void
	 */
	public function testWithoutOptInTheRouteIs404(): void {
		$this->catalogue(['acceptErrorReports' => false]);
		$this->posted = ['message' => 'Fout'];

		$this->assertSame(404, $this->controller->create(id: 'pub-1')->getStatus());
		$this->assertSame([], $this->saved);
	}//end testWithoutOptInTheRouteIs404()

	/**
	 * An opted-in catalogue without a moderator takes nothing.
	 *
	 * @return void
	 */
	public function testWithoutAModeratorTheRouteIs404(): void {
		$this->catalogue(['acceptErrorReports' => true]);
		$this->storedCatalog = ['id' => 'cat-1', 'errorReportModerators' => ['', 7]];
		$this->posted = ['message' => 'Fout'];

		$this->assertSame(404, $this->controller->create(id: 'pub-1')->getStatus());
		$this->assertSame([], $this->saved);
	}//end testWithoutAModeratorTheRouteIs404()

	/**
	 * A draft, or a publication the catalogue does not hold, takes nothing.
	 *
	 * @return void
	 */
	public function testADraftOrAnUnknownPublicationIs404(): void {
		$this->catalogue(['acceptErrorReports' => true], ['id' => 'pub-1', '@self' => ['published' => null]]);
		$this->posted = ['message' => 'Fout'];

		$this->assertSame(404, $this->controller->create(id: 'pub-1')->getStatus());
		$this->assertSame(404, $this->controller->create(id: 'pub-2')->getStatus());
		$this->assertSame([], $this->saved);
	}//end testADraftOrAnUnknownPublicationIs404()

	/**
	 * A message over 2,000 characters, an empty one and a bad address are refused; 2,000 is taken.
	 *
	 * @return void
	 */
	public function testALongMessageIsRefused(): void {
		$this->catalogue(['acceptErrorReports' => true]);

		$this->posted = ['message' => str_repeat('é', 2001)];
		$long = $this->controller->create(id: 'pub-1');
		$this->posted = ['message' => '   '];
		$empty = $this->controller->create(id: 'pub-1');
		$this->posted = ['message' => 'Fout', 'contact' => 'geen adres'];
		$badContact = $this->controller->create(id: 'pub-1');

		$this->assertSame(400, $long->getStatus());
		$this->assertSame('message-too-long', $long->getData()['error']);
		$this->assertSame('message-required', $empty->getData()['error']);
		$this->assertSame('invalid-contact', $badContact->getData()['error']);
		$this->assertSame([], $this->saved);

		$this->posted = ['message' => str_repeat('é', 2000)];
		$this->assertSame(201, $this->controller->create(id: 'pub-1')->getStatus());
	}//end testALongMessageIsRefused()

	/**
	 * The stored report holds exactly the report fields: no address of the sender.
	 *
	 * @return void
	 */
	public function testNoAddressIsStored(): void {
		$this->catalogue(['acceptErrorReports' => true]);
		$this->posted = ['message' => 'Fout', 'ip' => '203.0.113.7', 'remoteAddress' => '203.0.113.7'];

		$this->controller->create(id: 'pub-1');

		$this->assertSame(['publication', 'catalog', 'message', 'status', 'createdAt', 'moderators'], array_keys($this->saved[0]));
		$this->assertStringNotContainsString('203.0.113.7', json_encode($this->saved[0], JSON_THROW_ON_ERROR));
	}//end testNoAddressIsStored()

	/**
	 * Five reports an hour per address, anonymous or signed in.
	 *
	 * @return void
	 */
	public function testTheRouteIsRateLimited(): void {
		$method = new ReflectionMethod(ErrorReportController::class, 'create');
		$anon = $method->getAttributes(AnonRateLimit::class)[0]->getArguments();
		$user = $method->getAttributes(UserRateLimit::class)[0]->getArguments();

		$this->assertSame(['limit' => 5, 'period' => 3600], $anon);
		$this->assertSame(['limit' => 5, 'period' => 3600], $user);
	}//end testTheRouteIsRateLimited()

	/**
	 * The preflight allows a cross-origin post.
	 *
	 * @return void
	 */
	public function testThePreflightAllowsThePost(): void {
		$headers = $this->controller->preflightedCors()->getHeaders();

		$this->assertSame('GET, POST, OPTIONS', $headers['Access-Control-Allow-Methods']);
	}//end testThePreflightAllowsThePost()
}//end class

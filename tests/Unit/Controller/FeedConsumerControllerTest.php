<?php

/**
 * Unit tests for FeedConsumerController.
 *
 * Covers the admin endpoints of a catalogue's feed consumers: the listing with
 * the instance-wide account list, adding a consumer, generating a key (no-store,
 * 201), revoking and disabling, and the 404 / 400 / 500 answers.
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

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\FeedConsumerServiceInterface;
use OCA\OpenCatalogi\Controller\FeedConsumerController;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for FeedConsumerController.
 */
#[CoversClass(FeedConsumerController::class)]
class FeedConsumerControllerTest extends TestCase {

	private const CATALOG = ['id' => 'cat-1', 'slug' => 'hva-onderwijs'];

	private IRequest|MockObject $request;
	private FeedConsumerServiceInterface|MockObject $consumers;
	private CatalogiServiceInterface|MockObject $catalogs;
	private IAppConfig|MockObject $appConfig;
	private FeedConsumerController $controller;

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => $this->params[$key] ?? $default
		);
		$this->consumers = $this->createMock(FeedConsumerServiceInterface::class);
		$this->catalogs = $this->createMock(CatalogiServiceInterface::class);
		$this->catalogs->method('getCatalogBySlug')->willReturnCallback(
			static fn (string $slug): ?array => $slug === 'hva-onderwijs' ? self::CATALOG : null
		);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new FeedConsumerController(
			'opencatalogi',
			$this->request,
			$this->consumers,
			$this->catalogs,
			$this->appConfig,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testIndexListsConsumersAndTheInstanceAccountList(): void {
		$this->appConfig->method('getValueString')->with('opencatalogi', 'ooapi_consumers', '')->willReturn(' surf , ,edu ');
		$this->consumers->method('consumersOf')->with('cat-1')->willReturn([['id' => 'c1']]);

		$response = $this->controller->index('hva-onderwijs');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'results' => [['id' => 'c1']],
				'instanceAccounts' => ['accounts' => ['surf', 'edu'], 'everyAccount' => false, 'setting' => 'ooapi_consumers'],
			],
			$response->getData()
		);
	}

	public function testIndexSaysEveryAccountReadsWhenTheListIsEmpty(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->consumers->method('consumersOf')->willReturn([]);

		$data = $this->controller->index('hva-onderwijs')->getData();

		$this->assertSame(['accounts' => [], 'everyAccount' => true, 'setting' => 'ooapi_consumers'], $data['instanceAccounts']);
	}

	public function testAnUnknownCatalogueAnswers404(): void {
		$this->consumers->expects($this->never())->method('addConsumer');

		$this->assertSame(404, $this->controller->create('nope')->getStatus());
		$this->assertSame(404, $this->controller->index('nope')->getStatus());
	}

	public function testCreateAddsTheConsumerToTheCatalogue(): void {
		$this->params = ['name' => 'Studiekeuzeportaal', 'contact' => 'api@example.org'];
		$this->consumers->expects($this->once())->method('addConsumer')
			->with(self::CATALOG, ['name' => 'Studiekeuzeportaal', 'contact' => 'api@example.org'])
			->willReturn(['id' => 'c1']);

		$response = $this->controller->create('hva-onderwijs');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 'c1'], $response->getData());
	}

	public function testCreateAnswers400ForRefusedInput(): void {
		$this->consumers->method('addConsumer')->willThrowException(new InvalidArgumentException('name needs between 1 and 200 characters'));

		$response = $this->controller->create('hva-onderwijs');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'name needs between 1 and 200 characters'], $response->getData());
	}

	public function testCreateAnswers500WhenTheAccountFails(): void {
		$this->consumers->method('addConsumer')->willThrowException(new RuntimeException('The consumer account could not be created'));

		$response = $this->controller->create('hva-onderwijs');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Internal server error'], $response->getData());
	}

	public function testGenerateKeyReturnsTheKeyOnceAndIsNotCached(): void {
		$this->params = ['label' => 'production', 'expiresAt' => '2030-01-01T00:00:00Z'];
		$this->consumers->expects($this->once())->method('consumerOf')->with('cat-1', 'c1')->willReturn(['id' => 'c1']);
		$this->consumers->expects($this->once())->method('generateKey')
			->with('c1', ['label' => 'production', 'expiresAt' => '2030-01-01T00:00:00Z'])
			->willReturn(['key' => 'oc_c1.k1.secret', 'consumer' => ['id' => 'c1']]);

		$response = $this->controller->generateKey('hva-onderwijs', 'c1');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('oc_c1.k1.secret', $response->getData()['key']);
		$this->assertSame('no-store', $response->getHeaders()['Cache-Control']);
	}

	public function testGenerateKeyRefusesAConsumerOfAnotherCatalogue(): void {
		$this->consumers->method('consumerOf')->willThrowException(new OutOfBoundsException('Feed consumer not found'));
		$this->consumers->expects($this->never())->method('generateKey');

		$this->assertSame(404, $this->controller->generateKey('hva-onderwijs', 'other')->getStatus());
	}

	public function testRevokeKeyRevokesTheKeyOfTheCataloguesConsumer(): void {
		$this->consumers->expects($this->once())->method('consumerOf')->with('cat-1', 'c1');
		$this->consumers->expects($this->once())->method('revokeKey')->with('c1', 'k1')->willReturn(['id' => 'c1']);

		$response = $this->controller->revokeKey('hva-onderwijs', 'c1', 'k1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['id' => 'c1'], $response->getData());
	}

	public function testRevokeKeyAnswers404ForAnUnknownKey(): void {
		$this->consumers->method('revokeKey')->willThrowException(new OutOfBoundsException('Key not found'));

		$this->assertSame(404, $this->controller->revokeKey('hva-onderwijs', 'c1', 'nope')->getStatus());
	}

	public function testDisableDisablesTheCataloguesConsumer(): void {
		$this->consumers->expects($this->once())->method('consumerOf')->with('cat-1', 'c1');
		$this->consumers->expects($this->once())->method('disableConsumer')->with('c1')->willReturn(['id' => 'c1', 'enabled' => false]);

		$this->assertSame(['id' => 'c1', 'enabled' => false], $this->controller->disable('hva-onderwijs', 'c1')->getData());
	}
}

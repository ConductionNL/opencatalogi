<?php

/**
 * Tests for the portal endpoint actions and the shared dossier read.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
 */

declare(strict_types=1);

namespace Unit\Controller;

require_once __DIR__.'/../Service/Portal/FakePortalObjectStore.php';
require_once __DIR__.'/../Portal/PortalAssertionVerifierTest.php';

use OCA\OpenCatalogi\Controller\PortalCollectionController;
use OCA\OpenCatalogi\Controller\PortalSavedSearchController;
use OCA\OpenCatalogi\Controller\SharedCollectionController;
use OCA\OpenCatalogi\Portal\PortalAssertionVerifier;
use OCA\OpenCatalogi\Service\Portal\CitizenCollectionService;
use OCA\OpenCatalogi\Service\Portal\CollectionShareService;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenCatalogi\Service\Portal\SavedSearchService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Unit\Portal\PortalAssertionVerifierTest;
use Unit\Service\Portal\FakePortalObjectStore;

/**
 * Tests for PortalCollectionController and SharedCollectionController.
 */
class PortalCollectionControllerTest extends TestCase {

	private const SECRET = 'a-dedicated-secret-of-enough-length';

	private const PUB = 'aaaaaaaa-0000-4000-8000-000000000001';

	private FakePortalObjectStore $store;

	private CitizenCollectionService $collections;

	private CollectionShareService $shares;

	protected function setUp(): void {
		$this->store = new FakePortalObjectStore();
		$this->store->publication(self::PUB, 'Besluit windpark');
		$linker = $this->createMock(PublicationLinker::class);
		$linker->method('url')->willReturn('https://example.org/p');
		$this->collections = new CitizenCollectionService(store: $this->store, linker: $linker, logger: new NullLogger());
		$this->shares = new CollectionShareService(store: $this->store, linker: $linker);
	}

	/**
	 * A controller for a request with these params and assertion header.
	 *
	 * @param array<string, mixed> $params The params.
	 * @param string|null          $header The X-Portal-Subject header.
	 *
	 * @return PortalCollectionController
	 */
	private function controller(array $params, ?string $header): PortalCollectionController {
		return new PortalCollectionController(
			appName: 'opencatalogi',
			request: $this->request(params: $params, header: $header),
			verifier: new PortalAssertionVerifier(secretOverride: self::SECRET),
			collections: $this->collections,
			shares: $this->shares,
			logger: new NullLogger()
		);
	}

	/**
	 * A saved-search controller for a request.
	 *
	 * @param array<string, mixed> $params The params.
	 * @param string|null          $header The X-Portal-Subject header.
	 *
	 * @return PortalSavedSearchController
	 */
	private function searches(array $params, ?string $header): PortalSavedSearchController {
		return new PortalSavedSearchController(
			appName: 'opencatalogi',
			request: $this->request(params: $params, header: $header),
			verifier: new PortalAssertionVerifier(secretOverride: self::SECRET),
			searches: new SavedSearchService(store: $this->store),
			logger: new NullLogger()
		);
	}

	/**
	 * A request with params and the assertion header.
	 *
	 * @param array<string, mixed> $params The params.
	 * @param string|null          $header The X-Portal-Subject header.
	 *
	 * @return IRequest
	 */
	private function request(array $params, ?string $header): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $name, $default=null) => ($params[$name] ?? $default));
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'X-Portal-Subject' ? (string)$header : ''));

		return $request;
	}

	public function testWithoutAValidAssertionTheAnswerIs401(): void {
		foreach ([null, 'garbage', PortalAssertionVerifierTest::mint(secret: 'another-secret-long-enough-here')] as $header) {
			$response = $this->controller(['title' => 'x', 'publication' => self::PUB], $header)->addItem();
			$this->assertSame(401, $response->getStatus());
		}

		$this->assertSame([], ($this->store->objects['collection'] ?? []));
	}

	public function testTheAssertedSubjectOwnsTheNewDossier(): void {
		$response = $this->controller(['title' => 'Windpark', 'publication' => self::PUB, 'owner' => 'intruder'], PortalAssertionVerifierTest::mint(['sub' => 'subject-9']))->addItem();

		$this->assertSame(201, $response->getStatus());
		$id = $response->getData()['id'];
		$this->assertSame('subject-9', $this->store->objects['collection'][$id]['owner']);
	}

	public function testAnotherSubjectGets404AndBadInputGets422(): void {
		$made = $this->controller(['title' => 'Windpark', 'publication' => self::PUB], PortalAssertionVerifierTest::mint(['sub' => 'subject-1']))->addItem()->getData();

		$foreign = $this->controller(['collection' => $made['id']], PortalAssertionVerifierTest::mint(['sub' => 'subject-2']));
		$this->assertSame(404, $foreign->view()->getStatus());
		$this->assertSame(404, $foreign->share()->getStatus());
		$this->assertSame(404, $foreign->delete()->getStatus());

		$bad = $this->controller(['publication' => self::PUB], PortalAssertionVerifierTest::mint());
		$this->assertSame(422, $bad->addItem()->getStatus());
	}

	/**
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 */
	public function testTheSharedLinkWorksWithoutSignInAndStopsWhenRevoked(): void {
		$owner = PortalAssertionVerifierTest::mint(['sub' => 'subject-1']);
		$made = $this->controller(['title' => 'Windpark', 'publication' => self::PUB], $owner)->addItem()->getData();
		$share = $this->controller(['collection' => $made['id']], $owner)->share()->getData();
		$this->assertSame('/index.php/apps/opencatalogi/api/collections/shared/'.$share['token'], $share['link']);

		$removed = $this->controller(['collection' => $made['id'], 'itemId' => $made['items'][0]['id']], PortalAssertionVerifierTest::mint(['sub' => 'subject-2']))->removeItem();
		$this->assertSame(404, $removed->getStatus());

		$shared = new SharedCollectionController(appName: 'opencatalogi', request: $this->createMock(IRequest::class), shares: $this->shares, logger: new NullLogger());
		$response = $shared->show(token: $share['token']);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('no-store', $response->getHeaders()['Cache-Control']);
		$this->assertCount(1, $response->getData()['items']);

		$this->controller(['collection' => $made['id']], $owner)->unshare();
		$this->assertSame(404, $shared->show(token: $share['token'])->getStatus());
	}

	public function testSaveAndPauseASearch(): void {
		$owner = PortalAssertionVerifierTest::mint(['sub' => 'subject-1']);
		$saved = $this->searches(['title' => 'Windpark', 'query' => ['text' => 'windpark']], $owner)->save();
		$this->assertSame(201, $saved->getStatus());

		$id = $saved->getData()['id'];
		$this->assertSame(404, $this->searches(['savedSearch' => $id], PortalAssertionVerifierTest::mint(['sub' => 'subject-2']))->pause()->getStatus());
		$this->assertSame(200, $this->searches(['savedSearch' => $id], $owner)->pause()->getStatus());
		$this->assertFalse($this->store->objects['savedSearch'][$id]['active']);

		$this->assertSame(404, $this->searches(['savedSearch' => $id], PortalAssertionVerifierTest::mint(['sub' => 'subject-2']))->delete()->getStatus());
		$this->assertSame(200, $this->searches(['savedSearch' => $id], $owner)->delete()->getStatus());
		$this->assertSame([], $this->store->objects['savedSearch']);
	}
}

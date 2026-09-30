<?php

/**
 * Tests for a resident's dossiers (citizen-collections).
 *
 * Every written dossier is also validated against the shipped `collection`
 * schema, so the service can never write what the register refuses.
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

namespace Unit\Service\Portal;

require_once __DIR__.'/FakePortalObjectStore.php';

use OCA\OpenCatalogi\Exception\PortalInputException;
use OCA\OpenCatalogi\Exception\PortalNotFoundException;
use OCA\OpenCatalogi\Service\Portal\CitizenCollectionService;
use OCA\OpenCatalogi\Service\Portal\CollectionShareService;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for CitizenCollectionService.
 */
class CitizenCollectionServiceTest extends TestCase {

	private const PUB_A = 'aaaaaaaa-0000-4000-8000-000000000001';
	private const PUB_B = 'aaaaaaaa-0000-4000-8000-000000000002';
	private const PUB_LATER = 'aaaaaaaa-0000-4000-8000-000000000003';

	private FakePortalObjectStore $store;

	private CitizenCollectionService $service;

	private CollectionShareService $shares;

	protected function setUp(): void {
		$this->store = new FakePortalObjectStore();
		$this->store->publication(self::PUB_A, 'Besluit windpark');
		$this->store->publication(self::PUB_B, 'Advies windpark');
		$this->store->publication(self::PUB_LATER, 'Nog niet openbaar', '+1 day');

		$linker = $this->createMock(PublicationLinker::class);
		$linker->method('url')->willReturnCallback(static fn (string $id): string => 'https://example.org/p/'.$id);

		$this->service = new CitizenCollectionService(store: $this->store, linker: $linker, logger: new NullLogger());
		$this->shares = new CollectionShareService(store: $this->store, linker: $linker);
	}

	/**
	 * Assert the stored dossier passes the shipped schema.
	 *
	 * @param string $id The dossier id.
	 *
	 * @return void
	 */
	private function assertStoredDossierIsValid(string $id): void {
		$root = dirname(__DIR__, 4);
		$fragment = json_decode((string)file_get_contents($root.'/lib/Settings/register.d/citizen-collections.json'), true)['components']['schemas']['collection'];
		$properties = $fragment['properties'];
		$properties['share']['type'] = ['object', 'null'];
		$data = $this->store->objects['collection'][$id];
		unset($data['id']);
		$result = (new Validator())->validate(
			json_decode((string)json_encode($data)),
			(string)json_encode(['type' => 'object', 'properties' => $properties, 'required' => $fragment['required']])
		);
		$this->assertTrue($result->isValid(), 'The stored dossier does not pass the collection schema');
	}

	/**
	 * Make a dossier for a subject with the given publications.
	 *
	 * @param string             $owner        The owner.
	 * @param array<int, string> $publications The publication ids.
	 *
	 * @return array<string, mixed> The owner view.
	 */
	private function dossierOf(string $owner, array $publications): array {
		$view = $this->service->addItem(owner: $owner, input: ['title' => 'Windpark', 'publication' => array_shift($publications), 'note' => 'eerste']);
		foreach ($publications as $publication) {
			$view = $this->service->addItem(owner: $owner, input: ['collection' => $view['id'], 'publication' => $publication]);
		}

		return $view;
	}

	public function testAddingToANewDossierMakesOneOwnedByTheResident(): void {
		$view = $this->service->addItem(owner: 'subject-1', input: ['title' => 'Windpark', 'publication' => self::PUB_A, 'note' => 'Lees paragraaf 3', 'owner' => 'someone-else']);

		$stored = $this->store->objects['collection'][$view['id']];
		$this->assertSame('subject-1', $stored['owner']);
		$this->assertSame('Windpark', $stored['title']);
		$this->assertCount(1, $stored['items']);
		$this->assertSame(self::PUB_A, $stored['items'][0]['publication']);
		$this->assertSame('resident', $stored['items'][0]['addedBy']);
		$this->assertSame('Besluit windpark', $stored['items'][0]['title']);
		$this->assertNull($stored['items'][0]['attachment']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $stored['items'][0]['id']);
		$this->assertStoredDossierIsValid($view['id']);
	}

	public function testAddingTheSameDocumentTwiceKeepsOneItem(): void {
		$view = $this->service->addItem(owner: 'subject-1', input: ['title' => 'Windpark', 'publication' => self::PUB_A, 'attachment' => '4711']);
		$this->service->addItem(owner: 'subject-1', input: ['collection' => $view['id'], 'publication' => self::PUB_A, 'attachment' => '4711']);
		$this->service->addItem(owner: 'subject-1', input: ['collection' => $view['id'], 'publication' => self::PUB_A]);

		$items = $this->store->objects['collection'][$view['id']]['items'];
		$this->assertCount(2, $items);
		$this->assertSame('4711', $items[0]['attachment']);
		$this->assertStoredDossierIsValid($view['id']);
	}

	/**
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-adds-a-public-publication-or-one-of-its-documents-to-a-dossier-req-ccol-002
	 */
	public function testAPublicationThatIsNotPublicYetCannotBeAdded(): void {
		$this->expectException(PortalNotFoundException::class);
		try {
			$this->service->addItem(owner: 'subject-1', input: ['title' => 'Windpark', 'publication' => self::PUB_LATER]);
		} finally {
			$this->assertSame([], ($this->store->objects['collection'] ?? []));
		}
	}

	public function testAnotherResidentsDossierAnswersNotFound(): void {
		$view = $this->dossierOf('subject-1', [self::PUB_A]);

		foreach ([
			fn () => $this->service->view(owner: 'subject-2', collectionId: $view['id']),
			fn () => $this->service->addItem(owner: 'subject-2', input: ['collection' => $view['id'], 'publication' => self::PUB_B]),
			fn () => $this->service->removeItem(owner: 'subject-2', collectionId: $view['id'], itemId: $view['items'][0]['id']),
			fn () => $this->shares->share(owner: 'subject-2', collectionId: $view['id']),
			fn () => $this->service->delete(owner: 'subject-2', collectionId: $view['id']),
			fn () => $this->service->view(owner: 'subject-1', collectionId: 'does-not-exist'),
		] as $call) {
			try {
				$call();
				$this->fail('Expected a not-found answer');
			} catch (PortalNotFoundException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->assertCount(1, $this->store->objects['collection'][$view['id']]['items']);
	}

	public function testTheOwnerSeesBothItemsWithTheirNotes(): void {
		$view = $this->dossierOf('subject-1', [self::PUB_A, self::PUB_B]);
		$view = $this->service->view(owner: 'subject-1', collectionId: $view['id']);

		$this->assertCount(2, $view['items']);
		$this->assertSame('eerste', $view['items'][0]['note']);
		$this->assertTrue($view['items'][0]['public']);
		$this->assertSame('https://example.org/p/'.self::PUB_A, $view['items'][0]['url']);
		$this->assertArrayNotHasKey('owner', $view);
	}

	/**
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-removes-items-and-writes-notes-req-ccol-003
	 */
	public function testRemoveAnItemAndWriteNotes(): void {
		$view = $this->dossierOf('subject-1', [self::PUB_A, self::PUB_B]);
		$first = $view['items'][0]['id'];
		$second = $view['items'][1]['id'];

		$view = $this->service->note(owner: 'subject-1', collectionId: $view['id'], itemId: $second, note: 'Belangrijk');
		$view = $this->service->note(owner: 'subject-1', collectionId: $view['id'], itemId: '', note: 'Mijn onderzoek');
		$view = $this->service->removeItem(owner: 'subject-1', collectionId: $view['id'], itemId: $first);

		$stored = $this->store->objects['collection'][$view['id']];
		$this->assertCount(1, $stored['items']);
		$this->assertSame($second, $stored['items'][0]['id']);
		$this->assertSame('Belangrijk', $stored['items'][0]['note']);
		$this->assertSame('Mijn onderzoek', $stored['description']);
		$this->assertStoredDossierIsValid($view['id']);

		$this->expectException(PortalNotFoundException::class);
		$this->service->removeItem(owner: 'subject-1', collectionId: $view['id'], itemId: $first);
	}

	/**
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-the-owner-sees-a-depublished-item-as-no-longer-public-req-ccol-004
	 */
	public function testADepublishedItemStaysInTheOwnersViewMarkedNotPublic(): void {
		$view = $this->dossierOf('subject-1', [self::PUB_A, self::PUB_B]);
		$this->store->publication(self::PUB_B, 'Advies windpark (nieuw)', '-10 days', '-1 day');

		$view = $this->service->view(owner: 'subject-1', collectionId: $view['id']);
		$this->assertCount(2, $view['items']);
		$this->assertTrue($view['items'][0]['public']);
		$this->assertFalse($view['items'][1]['public']);
		$this->assertSame('Advies windpark', $view['items'][1]['title']);
	}

	/**
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 */
	public function testASharedDossierShowsOnlyPublicItemsAndNoOwner(): void {
		$view = $this->dossierOf('subject-1', [self::PUB_A, self::PUB_B]);
		$share = $this->shares->share(owner: 'subject-1', collectionId: $view['id']);
		$this->assertMatchesRegularExpression('/^'.preg_quote($view['id'], '/').'\.[0-9a-f]{48}$/', $share['token']);
		$this->assertStoredDossierIsValid($view['id']);

		$this->store->publication(self::PUB_B, 'Advies windpark', '-10 days', '-1 day');
		$shared = $this->shares->shared(token: $share['token']);

		$this->assertSame('Windpark', $shared['title']);
		$this->assertCount(1, $shared['items']);
		$this->assertSame(self::PUB_A, $shared['items'][0]['publication']);
		$this->assertSame('eerste', $shared['items'][0]['note']);
		foreach (['owner', 'share', 'sourceOf'] as $hidden) {
			$this->assertArrayNotHasKey($hidden, $shared);
		}

		$this->assertStringNotContainsString('subject-1', (string)json_encode($shared));
	}

	/**
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 */
	public function testARevokedOrForgedLinkAnswersNotFound(): void {
		$view = $this->dossierOf('subject-1', [self::PUB_A]);
		$share = $this->shares->share(owner: 'subject-1', collectionId: $view['id']);
		$this->shares->unshare(owner: 'subject-1', collectionId: $view['id']);
		$this->assertNull($this->store->objects['collection'][$view['id']]['share']);
		$this->assertStoredDossierIsValid($view['id']);

		foreach ([$share['token'], $view['id'].'.'.str_repeat('0', 48), 'nonsense', ''] as $token) {
			try {
				$this->shares->shared(token: $token);
				$this->fail('Expected not found for '.$token);
			} catch (PortalNotFoundException) {
				$this->addToAssertionCount(1);
			}
		}

		$again = $this->shares->share(owner: 'subject-1', collectionId: $view['id']);
		$this->assertNotSame($share['token'], $again['token']);
		$this->assertSame('Windpark', $this->shares->shared(token: $again['token'])['title']);
	}

	public function testInputIsCheckedBeforeAnythingIsWritten(): void {
		foreach ([
			['publication' => self::PUB_A],
			['title' => str_repeat('x', 201), 'publication' => self::PUB_A],
			['title' => 'x', 'publication' => self::PUB_A, 'attachment' => '../etc'],
			['title' => 'x', 'publication' => self::PUB_A, 'note' => str_repeat('x', 2001)],
		] as $input) {
			try {
				$this->service->addItem(owner: 'subject-1', input: $input);
				$this->fail('Expected refusal for '.json_encode(array_keys($input)));
			} catch (PortalInputException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->assertSame([], ($this->store->objects['collection'] ?? []));
	}

	/**
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
	 */
	public function testTheItemListForPortaliqMarksADepublishedItem(): void {
		$view = $this->dossierOf('subject-1', [self::PUB_A, self::PUB_B]);
		$this->store->publication(self::PUB_B, 'Advies windpark', '-10 days', '-1 day');

		$provider = new \OCA\OpenCatalogi\Portal\PortalContributionProvider(collections: $this->service);
		$items = $provider->dossierItems($view['id']);
		$this->assertCount(2, $items);
		$this->assertSame(['id', 'title', 'url', 'note', 'public', 'addedAt'], array_keys($items[0]));
		$this->assertTrue($items[0]['public']);
		$this->assertFalse($items[1]['public']);
		$this->assertSame('Advies windpark', $items[1]['title']);
		$this->assertSame([], $provider->dossierItems('does-not-exist'));
		$this->assertSame([], (new \OCA\OpenCatalogi\Portal\PortalContributionProvider())->dossierItems($view['id']));
	}

	public function testDeleteRemovesTheDossier(): void {
		$view = $this->dossierOf('subject-1', [self::PUB_A]);
		$this->service->delete(owner: 'subject-1', collectionId: $view['id']);
		$this->assertArrayNotHasKey($view['id'], $this->store->objects['collection']);
	}

	/**
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-removing-a-portal-account-deletes-that-residents-dossiers-req-ccol-007
	 */
	public function testRemovingEverythingOfOneResidentLeavesOthersAlone(): void {
		$this->dossierOf('subject-1', [self::PUB_A]);
		$this->dossierOf('subject-1', [self::PUB_B]);
		$other = $this->dossierOf('subject-2', [self::PUB_A]);
		$this->store->objects['savedSearch']['s1'] = ['id' => 's1', 'owner' => 'subject-1', 'title' => 'x'];
		$this->store->objects['savedSearch']['s2'] = ['id' => 's2', 'owner' => 'subject-2', 'title' => 'y'];

		$this->assertSame(3, $this->service->removeEverythingOf(owner: 'subject-1'));
		$this->assertSame([$other['id']], array_keys($this->store->objects['collection']));
		$this->assertSame(['s2'], array_keys($this->store->objects['savedSearch']));
		$this->assertSame(0, $this->service->removeEverythingOf(owner: ''));
	}
}

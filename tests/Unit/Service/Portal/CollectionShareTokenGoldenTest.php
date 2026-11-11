<?php

/**
 * Golden tests for opening a shared dossier by its link token: which tokens
 * open it (only the live one, compared in full) and what the shared view
 * holds (only the items public right now).
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

namespace Unit\Service\Portal;

require_once __DIR__ . '/FakePortalObjectStore.php';

use OCA\OpenCatalogi\Exception\PortalNotFoundException;
use OCA\OpenCatalogi\Service\Portal\CollectionShareService;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every token CollectionShareService::shared() is given, and what it answers.
 */
class CollectionShareTokenGoldenTest extends TestCase {

	private const HEX = 'abcdef0123456789abcdef0123456789abcdef0123456789';

	private const LIVE = 'c-1.' . self::HEX;

	/**
	 * A store with one shared dossier, one dossier whose share was revoked,
	 * and publications that are and are not public now.
	 *
	 * @return FakePortalObjectStore
	 */
	private static function store(): FakePortalObjectStore {
		$store = new FakePortalObjectStore();
		$store->publication('pub-1', 'Besluit');
		$store->publication('pub-2', 'Later', '+1 day');
		$store->publication('pub-3', 'Ingetrokken', '-2 days', '-1 day');
		$store->objects['publication']['pub-4'] = ['id' => 'pub-4', 'publicationDate' => gmdate('c', time() - 3600)];
		$store->objects['collection']['c-1'] = [
			'id' => 'c-1',
			'title' => 'Mijn dossier',
			'owner' => 'subject-1',
			'share' => ['token' => self::LIVE, 'createdAt' => '2026-01-01T00:00:00+00:00'],
			'items' => [
				['id' => 'i1', 'publication' => 'pub-1', 'attachment' => '42', 'note' => 'n', 'addedAt' => '2026-01-02T00:00:00+00:00', 'title' => 'old'],
				['id' => 'i2', 'publication' => 'pub-2'],
				['id' => 'i3', 'publication' => 'pub-3'],
				['publication' => 'pub-4', 'title' => 'stored title'],
				['id' => 'i5'],
			],
		];
		$store->objects['collection']['c-2'] = ['id' => 'c-2', 'title' => 'Ingetrokken', 'owner' => 'subject-1', 'share' => null, 'items' => []];
		$store->objects['collection']['c-3'] = ['id' => 'c-3', 'owner' => 'subject-1', 'share' => ['token' => ''], 'items' => []];
		return $store;
	}

	/**
	 * The service over the store.
	 *
	 * @return CollectionShareService
	 */
	private function service(): CollectionShareService {
		$linker = $this->createMock(PublicationLinker::class);
		$linker->method('url')->willReturnCallback(static fn (string $id): string => 'https://example.org/p/' . $id);
		return new CollectionShareService(store: self::store(), linker: $linker);
	}

	/**
	 * Tokens that do not open a dossier.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refusedTokens(): array {
		return [
			'empty' => [''],
			'no dot' => ['c-1' . self::HEX],
			'short secret' => ['c-1.' . substr(self::HEX, 1)],
			'long secret' => ['c-1.' . self::HEX . '0'],
			'upper-case secret' => ['c-1.' . strtoupper(self::HEX)],
			'not hex' => ['c-1.' . str_repeat('g', 48)],
			'other secret' => ['c-1.' . str_repeat('0', 48)],
			'secret of another dossier' => ['c-2.' . self::HEX],
			'unknown dossier' => ['c-9.' . self::HEX],
			'revoked share' => ['c-2.' . str_repeat('1', 48)],
			'empty stored token' => ['c-3.' . self::HEX],
			'extra part' => ['c-1.' . self::HEX . '.x'],
		];
	}

	/**
	 * A token that is not the dossier's live one answers not found.
	 *
	 * @param string $token The token.
	 *
	 * @return void
	 */
	#[DataProvider('refusedTokens')]
	public function testARefusedTokenIsNotFound(string $token): void {
		$this->expectException(PortalNotFoundException::class);
		$this->expectExceptionMessage('Not found');
		$this->service()->shared($token);
	}

	/**
	 * The live token shows the title, description and the items public now.
	 *
	 * @return void
	 */
	public function testTheLiveTokenShowsWhatIsPublicNow(): void {
		$this->assertSame(
			[
				'title' => 'Mijn dossier',
				'description' => '',
				'items' => [
					['id' => 'i1', 'publication' => 'pub-1', 'attachment' => '42', 'note' => 'n', 'addedAt' => '2026-01-02T00:00:00+00:00', 'title' => 'Besluit', 'url' => 'https://example.org/p/pub-1'],
					['id' => '', 'publication' => 'pub-4', 'attachment' => null, 'note' => '', 'addedAt' => '', 'title' => 'stored title', 'url' => 'https://example.org/p/pub-4'],
				],
			],
			$this->service()->shared(self::LIVE)
		);
	}
}

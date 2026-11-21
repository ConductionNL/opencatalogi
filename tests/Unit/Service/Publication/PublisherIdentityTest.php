<?php
/**
 * PublisherIdentityTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Publication;

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Publication\PublisherIdentity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The publisher's RSIN is resolved from the shared organisation and checked with the 11-proef (REQ-PRO-002).
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\PublisherIdentity
 */
final class PublisherIdentityTest extends TestCase {

	private const ORGANISATION = '3f2b1c4d-0000-4000-8000-000000000001';

	/**
	 * The find() calls the ObjectService double received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $finds = [];

	/**
	 * An organisation whose RSIN passes the 11-proef is resolved with its name and TOOI identifier.
	 *
	 * @return void
	 */
	public function testAValidRsinIsResolved(): void {
		$identity = $this->identity(organisation: [
			'name' => 'Gemeente Zuiddrecht',
			'tooi' => 'gm9999',
			'rsin' => '002220647',
		])->of(publication: ['organization' => self::ORGANISATION]);

		self::assertSame(
			expected: [
				'rsin'       => '002220647',
				'name'       => 'Gemeente Zuiddrecht',
				'tooi'       => 'gm9999',
				'rsinStatus' => PublisherIdentity::STATUS_VALID,
				'reason'     => null,
			],
			actual: $identity
		);
		self::assertCount(expectedCount: 1, haystack: $this->finds);
		self::assertSame(expected: self::ORGANISATION, actual: $this->finds[0]['id']);
		self::assertFalse(condition: $this->finds[0]['_rbac']);
		self::assertFalse(condition: $this->finds[0]['_multitenancy']);
	}//end testAValidRsinIsResolved()

	/**
	 * An RSIN that fails the 11-proef is not answered; the reason names the check.
	 *
	 * @return void
	 */
	public function testAnInvalidRsinIsNotStamped(): void {
		$identity = $this->identity(organisation: [
			'name' => 'Gemeente Zuiddrecht',
			'tooi' => 'gm9999',
			'rsin' => '123456789',
		])->of(publication: ['organization' => self::ORGANISATION]);

		self::assertNull(actual: $identity['rsin']);
		self::assertSame(expected: PublisherIdentity::STATUS_MISSING, actual: $identity['rsinStatus']);
		self::assertStringContainsString(needle: '11-proef', haystack: (string) $identity['reason']);
		self::assertSame(expected: 'Gemeente Zuiddrecht', actual: $identity['name']);
		self::assertSame(expected: 'gm9999', actual: $identity['tooi']);
	}//end testAnInvalidRsinIsNotStamped()

	/**
	 * Without an organisation, or with one that cannot be read or has no RSIN, there is no RSIN.
	 *
	 * @return void
	 */
	public function testAMissingOrganisationGivesNoRsin(): void {
		$none = $this->identity(organisation: null)->of(publication: []);
		self::assertNull(actual: $none['rsin']);
		self::assertSame(expected: PublisherIdentity::STATUS_MISSING, actual: $none['rsinStatus']);
		self::assertSame(expected: 'The publication names no organisation.', actual: $none['reason']);
		self::assertSame(expected: [], actual: $this->finds);

		$unread = $this->identity(organisation: null)->of(publication: ['organization' => self::ORGANISATION]);
		self::assertNull(actual: $unread['rsin']);
		self::assertSame(expected: 'The organisation of the publication cannot be read.', actual: $unread['reason']);

		$noRsin = $this->identity(organisation: ['name' => 'Gemeente Zuiddrecht', 'rsin' => '  '])
			->of(publication: ['organization' => ['id' => self::ORGANISATION]]);
		self::assertNull(actual: $noRsin['rsin']);
		self::assertSame(expected: 'The organisation has no RSIN.', actual: $noRsin['reason']);
		self::assertSame(expected: 'Gemeente Zuiddrecht', actual: $noRsin['name']);
		self::assertNull(actual: $noRsin['tooi']);
	}//end testAMissingOrganisationGivesNoRsin()

	/**
	 * A failing OpenRegister is answered as an unreadable organisation, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingLookupGivesNoRsin(): void {
		$lookup = $this->createMock(originalClassName: OpenRegisterLookup::class);
		$lookup->method('registerConfiguration')->willThrowException(new RuntimeException(message: 'not configured'));

		$identity = (new PublisherIdentity(openRegister: $lookup, logger: new NullLogger()))
			->of(publication: ['organization' => self::ORGANISATION]);

		self::assertNull(actual: $identity['rsin']);
		self::assertSame(expected: 'The organisation of the publication cannot be read.', actual: $identity['reason']);
	}//end testAFailingLookupGivesNoRsin()

	/**
	 * The 11-proef itself: nine digits, weights 9..2 and -1, a multiple of eleven, never all zeros.
	 *
	 * @return void
	 */
	public function testTheElevenTest(): void {
		self::assertTrue(condition: PublisherIdentity::passesElevenTest(rsin: '002220647'));
		self::assertTrue(condition: PublisherIdentity::passesElevenTest(rsin: '111222333'));
		self::assertFalse(condition: PublisherIdentity::passesElevenTest(rsin: '123456789'));
		self::assertFalse(condition: PublisherIdentity::passesElevenTest(rsin: '000000000'));
		self::assertFalse(condition: PublisherIdentity::passesElevenTest(rsin: '02220647'));
		self::assertFalse(condition: PublisherIdentity::passesElevenTest(rsin: '0022206470'));
		self::assertFalse(condition: PublisherIdentity::passesElevenTest(rsin: '00222064a'));
	}//end testTheElevenTest()

	/**
	 * A PublisherIdentity over an ObjectService double that answers one organisation.
	 *
	 * @param array<string, mixed>|null $organisation The organisation's properties, or null when it is not found.
	 *
	 * @return PublisherIdentity The identity resolver.
	 */
	private function identity(?array $organisation): PublisherIdentity {
		$this->finds = [];
		$finds       = &$this->finds;
		$objects     = new class($organisation, $finds) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>|null  $organisation The organisation answered.
			 * @param list<array<string, mixed>> $finds        The calls received.
			 */
			public function __construct(private readonly ?array $organisation, private array &$finds) {
			}

			/**
			 * Answer the organisation.
			 *
			 * @param string $id            The id.
			 * @param mixed  $options       The find options.
			 * @param bool   $_rbac         Whether RBAC applies.
			 * @param bool   $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<string, mixed>|null The organisation.
			 */
			public function find(string $id, mixed $options=null, bool $_rbac=true, bool $_multitenancy=true): ?array {
				$this->finds[] = ['id' => $id, 'options' => $options, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];

				return $this->organisation;
			}
		};

		$lookup = $this->createMock(originalClassName: OpenRegisterLookup::class);
		$lookup->method('registerConfiguration')->willReturn(['register' => 'directory', 'schema' => 'nc-organisation']);
		$lookup->method('installedObjectService')->willReturn($objects);

		return new PublisherIdentity(openRegister: $lookup, logger: new NullLogger());
	}//end identity()
}//end class

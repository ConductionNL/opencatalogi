<?php

/**
 * OpenRegisterOrganisations test: an organisation by TOOI identifier, found or created.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\ValueList
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\ValueList;

use OCA\OpenCatalogi\Service\ValueList\OpenRegisterOrganisations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The adapter over stand-ins with OpenRegister's method names.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */
#[CoversClass(OpenRegisterOrganisations::class)]
class OpenRegisterOrganisationsTest extends TestCase {

	/**
	 * An adapter whose container answers the given services.
	 *
	 * @param array<string, object> $services The services by name.
	 *
	 * @return OpenRegisterOrganisations
	 */
	private function adapter(array $services): OpenRegisterOrganisations {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($services): object {
				if (isset($services[$name]) === false) {
					throw new class('no ' . $name) extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
					};
				}

				return $services[$name];
			}
		);

		return new OpenRegisterOrganisations(container: $container);
	}//end adapter()

	/**
	 * A stand-in organisation entity.
	 *
	 * @param string $uuid The uuid.
	 * @param string $name The name.
	 *
	 * @return object
	 */
	private static function organisation(string $uuid, string $name): object {
		return new class($uuid, $name) {
			/**
			 * The TOOI identifier set on it.
			 *
			 * @var string|null
			 */
			public ?string $tooi = null;

			/**
			 * Constructor.
			 *
			 * @param string $uuid The uuid.
			 * @param string $name The name.
			 */
			public function __construct(private string $uuid, private string $name) {
			}

			/**
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}

			/**
			 * @return string
			 */
			public function getName(): string {
				return $this->name;
			}

			/**
			 * @param string|null $tooi The identifier.
			 *
			 * @return void
			 */
			public function setTooi(?string $tooi): void {
				$this->tooi = $tooi;
			}
		};
	}//end organisation()

	/**
	 * The mapper is asked for the organisation whose tooi column holds the identifier.
	 *
	 * @return void
	 */
	public function testAnOrganisationIsFoundByItsTooiIdentifier(): void {
		$mapper = new class(self::organisation(uuid: 'u1', name: 'Gemeente Dordrecht')) {
			/**
			 * The filters asked for.
			 *
			 * @var array<string, mixed>
			 */
			public array $filters = [];

			/**
			 * Constructor.
			 *
			 * @param object $row The organisation it holds.
			 */
			public function __construct(private object $row) {
			}

			/**
			 * @param int                  $limit   The limit.
			 * @param int                  $offset  The offset.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return list<object>
			 */
			public function findAll(int $limit, int $offset, array $filters): array {
				$this->filters = $filters;
				if ($filters['tooi'] !== 'https://t/gm0505') {
					return [];
				}

				return [$this->row];
			}
		};
		$adapter = $this->adapter(services: [OpenRegisterOrganisations::MAPPER => $mapper]);

		$this->assertSame(expected: ['uuid' => 'u1', 'name' => 'Gemeente Dordrecht'], actual: $adapter->findByTooi(tooi: 'https://t/gm0505'));
		$this->assertSame(expected: ['tooi' => 'https://t/gm0505'], actual: $mapper->filters);
		$this->assertNull(actual: $adapter->findByTooi(tooi: 'https://t/gm0642'));
	}//end testAnOrganisationIsFoundByItsTooiIdentifier()

	/**
	 * A created organisation carries the TOOI identifier, and no member is added.
	 *
	 * @return void
	 */
	public function testACreatedOrganisationCarriesTheTooiIdentifier(): void {
		if (class_exists(OpenRegisterOrganisations::NEW_ORGANISATION) === false) {
			eval(
				'namespace OCA\OpenRegister\Service\Organisation; final class NewOrganisation {'
				. ' public function __construct(public readonly string $name, public readonly string $description = "",'
				. ' public readonly string $uuid = "") {} }'
			);
		}

		$created = self::organisation(uuid: 'u2', name: 'waterschap Rivierenland');
		$service = new class($created) {
			/**
			 * What createOrganisation received.
			 *
			 * @var array{name: string, addCurrentUser: bool}|null
			 */
			public ?array $asked = null;

			/**
			 * Constructor.
			 *
			 * @param object $created The organisation it creates.
			 */
			public function __construct(private object $created) {
			}

			/**
			 * @param object $details        The details.
			 * @param bool   $addCurrentUser Whether the caller joins.
			 *
			 * @return object
			 */
			public function createOrganisation(object $details, bool $addCurrentUser = true): object {
				$this->asked = ['name' => $details->name, 'addCurrentUser' => $addCurrentUser];
				return $this->created;
			}
		};
		$mapper = new class {
			/**
			 * The organisations saved.
			 *
			 * @var list<object>
			 */
			public array $saved = [];

			/**
			 * @param object $organisation The organisation.
			 *
			 * @return object
			 */
			public function save(object $organisation): object {
				$this->saved[] = $organisation;
				return $organisation;
			}
		};

		$answer = $this->adapter(services: [OpenRegisterOrganisations::SERVICE => $service, OpenRegisterOrganisations::MAPPER => $mapper])
			->create(name: 'waterschap Rivierenland', tooi: 'https://t/ws0621');

		$this->assertSame(expected: ['uuid' => 'u2', 'name' => 'waterschap Rivierenland'], actual: $answer);
		$this->assertSame(expected: ['name' => 'waterschap Rivierenland', 'addCurrentUser' => false], actual: $service->asked);
		$this->assertSame(expected: 'https://t/ws0621', actual: $created->tooi);
		$this->assertSame(expected: [$created], actual: $mapper->saved);
	}//end testACreatedOrganisationCarriesTheTooiIdentifier()

	/**
	 * Without OpenRegister, or when it fails, the answer is a refusal naming why.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheAnswerIsARefusal(): void {
		try {
			$this->adapter(services: [])->findByTooi(tooi: 'https://t/x');
			$this->fail(message: 'a missing mapper is refused');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString(needle: 'OrganisationMapper', haystack: $e->getMessage());
		}

		$failing = new class {
			/**
			 * @param int                  $limit   The limit.
			 * @param int                  $offset  The offset.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, object>
			 */
			public function findAll(int $limit, int $offset, array $filters): array {
				throw new \Exception('database gone');
			}
		};
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'database gone');
		$this->adapter(services: [OpenRegisterOrganisations::MAPPER => $failing])->findByTooi(tooi: 'https://t/x');
	}//end testWithoutOpenRegisterTheAnswerIsARefusal()
}//end class

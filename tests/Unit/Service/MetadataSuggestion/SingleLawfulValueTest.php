<?php

/**
 * Unit tests for SingleLawfulValue: a field with one lawful value for the
 * officer fills itself, and every other case fills nothing
 * (woo-metadata-suggestions REQ-WMS-004).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004
 */

declare(strict_types=1);

namespace Unit\Service\MetadataSuggestion;

use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\MetadataSuggestion\SingleLawfulValue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SingleLawfulValue::fill().
 */
#[CoversClass(SingleLawfulValue::class)]
final class SingleLawfulValueTest extends TestCase {

	private const AT = '2026-11-16T12:00:00+00:00';

	/**
	 * The service over the given memberships, category declarations and schema slug.
	 *
	 * @param array<int, string>|\Throwable $organisations The officer's organisation uuids, or what the lookup throws.
	 * @param array<string, array<int, string>> $declared Category code to the schema slugs it declares.
	 * @param string $slug The publication's schema slug.
	 *
	 * @return SingleLawfulValue
	 */
	public static function service(TestCase $test, array|\Throwable $organisations, array $declared, string $slug='besluit'): SingleLawfulValue {
		$organisationStore = new class($organisations) {
			/** @var array<int, string> */
			public array $asked = [];

			public function __construct(private array|\Throwable $organisations) {
			}

			public function findByUserId(string $userId): array {
				$this->asked[] = $userId;
				if ($this->organisations instanceof \Throwable) {
					throw $this->organisations;
				}

				return array_map(
					static fn (string $uuid): object => new class($uuid) {
						public function __construct(private string $uuid) {
						}

						public function getUuid(): string {
							return $this->uuid;
						}
					},
					$this->organisations
				);
			}
		};
		$schemas = new class($slug) {
			public function __construct(private string $slug) {
			}

			public function find(int $id): object {
				$slug = $this->slug;
				return new class($slug) {
					public function __construct(private string $slug) {
					}

					public function getSlug(): string {
						return $this->slug;
					}
				};
			}
		};

		$lookup = $test->getMockBuilder(OpenRegisterLookup::class)->disableOriginalConstructor()->onlyMethods(['installedService'])->getMock();
		$lookup->method('installedService')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				SingleLawfulValue::ORGANISATIONS => $organisationStore,
				SingleLawfulValue::SCHEMAS => $schemas,
			}
		);

		$registry = $test->getMockBuilder(InformationCategoryRegistryInterface::class)->getMock();
		$registry->method('all')->willReturn(array_fill_keys(array_keys($declared), ['label' => 'x']));
		$registry->method('schemasFor')->willReturnCallback(static fn (string $code): array => $declared[$code] ?? []);

		$config = $test->getMockBuilder(IAppConfig::class)->getMock();
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => ['publication_register' => '7', 'publication_schema' => '11'][$key] ?? ''
		);
		$time = $test->getMockBuilder(ITimeFactory::class)->getMock();
		$time->method('now')->willReturn(new \DateTimeImmutable('2026-11-16T13:00:00+01:00'));

		return new SingleLawfulValue(
			openRegister: $lookup,
			categories: $registry,
			config: $config,
			time: $time,
			logger: $test->getMockBuilder(LoggerInterface::class)->getMock()
		);
	}//end service()

	/**
	 * An officer double.
	 *
	 * @return IUser
	 */
	private function officer(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer');
		return $user;
	}//end officer()

	/**
	 * A new publication in schema 12 of the publication register.
	 *
	 * @param array<string, mixed> $fields Its fields.
	 *
	 * @return array<string, mixed>
	 */
	private function publication(array $fields=[]): array {
		return $fields + ['title' => 'Besluit', '@self' => ['register' => '7', 'schema' => '12']];
	}//end publication()

	public function testOneOrganisationAndOneCategoryAreFilled(): void {
		$service = self::service($this, ['org-a'], ['infocat001' => ['besluit'], 'infocat002' => ['advies']]);

		$filled = $service->fill(publication: $this->publication(), officer: $this->officer());

		$this->assertSame('org-a', $filled['organization']);
		$this->assertSame('infocat001', $filled['wooCategory']);
		$this->assertSame(
			[
				['field' => 'organization', 'value' => 'org-a', 'rule' => SingleLawfulValue::RULE_ONE_ORGANISATION, 'at' => self::AT],
				['field' => 'wooCategory', 'value' => 'infocat001', 'rule' => SingleLawfulValue::RULE_ONE_CATEGORY, 'at' => self::AT],
			],
			$filled['filledByRule']
		);
	}

	public function testTwoLawfulValuesFillNothing(): void {
		$service = self::service($this, ['org-a', 'org-b'], ['infocat001' => ['besluit'], 'infocat002' => ['besluit']]);

		$filled = $service->fill(publication: $this->publication(), officer: $this->officer());

		$this->assertSame($this->publication(), $filled);
	}

	public function testAnUncomputableSetFillsNothing(): void {
		$service = self::service($this, new \RuntimeException('membership lookup down'), ['infocat001' => ['advies']]);

		$filled = $service->fill(publication: $this->publication(), officer: $this->officer());

		$this->assertArrayNotHasKey('organization', $filled);
		$this->assertArrayNotHasKey('filledByRule', $filled);

		$none = self::service($this, [], ['infocat001' => ['besluit']], 'besluit');
		$this->assertArrayNotHasKey('organization', $none->fill(publication: $this->publication(), officer: $this->officer()));
	}

	public function testAHermiqSuggestionIsNeverAutoFilled(): void {
		// The publication schema itself, so no category declares it: the only
		// candidate for the category is the pending Hermiq suggestion beside it.
		$service = self::service($this, ['org-a', 'org-b'], []);
		$publication = $this->publication();
		$publication['@self'] = ['register' => '7', 'schema' => '11'];
		$publication['metadataSuggestions'] = [['field' => 'wooCategory', 'value' => 'infocat003', 'source' => 'hermiq', 'status' => 'pending']];

		$filled = $service->fill(publication: $publication, officer: $this->officer());

		$this->assertArrayNotHasKey('wooCategory', $filled);
		$this->assertArrayNotHasKey('organization', $filled);
		$this->assertArrayNotHasKey('filledByRule', $filled);
	}

	public function testAFieldAlreadySetIsLeftAlone(): void {
		$service = self::service($this, ['org-a'], ['infocat001' => ['besluit']]);

		$filled = $service->fill(
			publication: $this->publication(['organization' => 'org-z', 'wooCategory' => 'infocat009']),
			officer: $this->officer()
		);

		$this->assertSame('org-z', $filled['organization']);
		$this->assertSame('infocat009', $filled['wooCategory']);
		$this->assertArrayNotHasKey('filledByRule', $filled);
	}

	public function testAnObjectThatIsNoPublicationIsLeftAlone(): void {
		$service = self::service($this, ['org-a'], ['infocat001' => ['besluit']], 'depublication');
		$other = ['reason' => 'r', '@self' => ['register' => '7', 'schema' => '40']];
		$elsewhere = ['title' => 't', '@self' => ['register' => '8', 'schema' => '12']];

		$this->assertSame($other, $service->fill(publication: $other, officer: $this->officer()));
		$this->assertSame($elsewhere, self::service($this, ['org-a'], ['infocat001' => ['besluit']])->fill(publication: $elsewhere, officer: $this->officer()));
	}
}//end class

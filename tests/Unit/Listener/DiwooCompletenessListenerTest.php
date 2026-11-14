<?php

/**
 * Unit tests for DiwooCompletenessListener: a Woo publication is not made public
 * while a mandatory DiWoo field is missing, a document type is stored as its list
 * member's URI, and a validity that ends before it starts is refused.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
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

namespace Unit\Listener;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Listener\DiwooCompletenessListener;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\SitemapDocumentMapper;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Listener\DiwooCompletenessListener
 * @covers \OCA\OpenCatalogi\Service\SitemapDocumentMapper::mandatoryGaps
 * @uses \OCA\OpenCatalogi\Service\SitemapDocumentMapper
 * @uses \OCA\OpenCatalogi\Service\TooiVocabularyService
 * @uses \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry
 * @uses \OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission
 * @uses \OCA\OpenCatalogi\Service\OpenRegisterLookup
 */
class DiwooCompletenessListenerTest extends TestCase {

	private const PUBLICATION_SCHEMA = '12';

	private const OTHER_SCHEMA = '99';

	private const ORGANISATION = 'org-uuid-1';

	private const TOOI = 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855';

	private const BESLUIT = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_465163c9';

	private const BESLISNOTA = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_2977c34f';

	/**
	 * Builds a double of an OpenRegister class whose methods may be absent from the stub.
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
	 * The listener over the real vocabulary and document mapping.
	 *
	 * @param string|null $tooi The organisation's TOOI identifier, or null for none.
	 * @param WooCategoryRegistry|null $categories The category registry; the bundled one by default.
	 * @param CatalogiServiceInterface|null $catalogi Reaches the schema mapper; none by default.
	 *
	 * @return DiwooCompletenessListener The listener.
	 */
	private function listener(?string $tooi = self::TOOI, ?WooCategoryRegistry $categories = null, ?CatalogiServiceInterface $catalogi = null): DiwooCompletenessListener {
		$vocabulary = new TooiVocabularyService();
		$settings = $this->createMock(SettingsService::class);
		$categories ??= new WooCategoryRegistry(
			$vocabulary,
			$settings,
			$this->createMock(LoggerInterface::class),
			new LocalCategoryAdmission($vocabulary),
		);

		$organisations = $this->environmentAwareDouble(\OCA\OpenRegister\Db\OrganisationMapper::class, ['findByUuidFollowingMerge']);
		$organisations->method('findByUuidFollowingMerge')->willReturn($this->organisation(tooi: $tooi));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): mixed => ($id === 'OCA\OpenRegister\Db\OrganisationMapper' ? $organisations : null)
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'publication_schema' ? self::PUBLICATION_SCHEMA : $default)
		);

		return new DiwooCompletenessListener(
			$vocabulary,
			new SitemapDocumentMapper($vocabulary, $categories, new OpenRegisterLookup($container, $appManager)),
			$categories,
			$catalogi ?? $this->createMock(CatalogiServiceInterface::class),
			$config,
			$this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * An organisation row with a name and the given TOOI identifier.
	 *
	 * @param string|null $tooi The TOOI identifier.
	 *
	 * @return object The organisation.
	 */
	private function organisation(?string $tooi): object {
		return new class($tooi) {
			public function __construct(private ?string $tooi) {
			}

			public function getName(): string {
				return 'Gemeente Zuiddrecht';
			}

			public function getTooi(): ?string {
				return $this->tooi;
			}
		};
	}//end organisation()

	/**
	 * A complete Woo publication, with the given fields replaced.
	 *
	 * @param array<string, mixed> $override Fields to set; a null value removes the field.
	 *
	 * @return array<string, mixed> The publication.
	 */
	private static function publication(array $override = []): array {
		$publication = array_merge(
			[
				'title' => 'Besluit parkeerregulering',
				'wooCategory' => 'infocat014',
				'publicationDate' => '2026-11-02T09:00:00+00:00',
			],
			$override
		);

		return array_filter($publication, static fn (mixed $value): bool => $value !== null);
	}//end publication()

	/**
	 * A creating event for an object of a schema.
	 *
	 * @param array<string, mixed> $data The object.
	 * @param string $schema Its schema id.
	 *
	 * @return ObjectCreatingEvent The event.
	 */
	private function creating(array $data, string $schema = self::PUBLICATION_SCHEMA): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setRegister('19');
		$entity->setSchema($schema);
		$entity->setOrganisation(self::ORGANISATION);
		$entity->setObject($data);

		return new ObjectCreatingEvent($entity);
	}//end creating()

	/**
	 * REQ-DWP-003: a Woo publication without a category is not made public; the refusal names the field.
	 *
	 * @return void
	 */
	public function testAWooPublicationMissingAMandatoryFieldIsNotMadePublic(): void {
		$categories = $this->createMock(WooCategoryRegistry::class);
		$categories->method('all')->willReturn(['infocat014' => ['code' => 'infocat014']]);
		$categories->method('schemasFor')->willReturn(['publication']);
		$schema = $this->environmentAwareDouble(\OCA\OpenRegister\Db\Schema::class, ['getSlug']);
		$schema->method('getSlug')->willReturn('publication');
		$schemas = $this->environmentAwareDouble(\OCA\OpenRegister\Db\SchemaMapper::class, ['find']);
		$schemas->method('find')->with(self::OTHER_SCHEMA)->willReturn($schema);
		$catalogi = $this->createMock(CatalogiServiceInterface::class);
		$catalogi->method('getSchemaMapper')->willReturn($schemas);

		$event = $this->creating(data: self::publication(['wooCategory' => null]), schema: self::OTHER_SCHEMA);
		$this->listener(categories: $categories, catalogi: $catalogi)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['wooCategory'], array_keys($event->getErrors()['fields']));
		$this->assertStringContainsString('wooCategory', $event->getErrors()['message']);
	}//end testAWooPublicationMissingAMandatoryFieldIsNotMadePublic()

	/**
	 * REQ-DWP-003: a publication scheduled for next week is checked now; the publisher is named.
	 *
	 * @return void
	 */
	public function testAScheduledWooPublicationIsCheckedWhenScheduled(): void {
		$next = gmdate('Y-m-d\TH:i:sP', (time() + 7 * 86400));
		$event = $this->creating(data: self::publication(['publicationDate' => $next]));
		$this->listener(tooi: null)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['publisher' => 'organisation has no TOOI identifier'], $event->getErrors()['fields']);
	}//end testAScheduledWooPublicationIsCheckedWhenScheduled()

	/**
	 * REQ-DWP-003: a draft without a publication date may be incomplete.
	 *
	 * @return void
	 */
	public function testADraftWithoutPublicationDateIsAccepted(): void {
		$event = $this->creating(data: self::publication(['wooCategory' => null, 'title' => null, 'publicationDate' => null]));
		$this->listener(tooi: null)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testADraftWithoutPublicationDateIsAccepted()

	/**
	 * REQ-DWP-003: a complete Woo publication goes public, on an update as on a create.
	 *
	 * @return void
	 */
	public function testACompleteWooPublicationIsAccepted(): void {
		$creating = $this->creating(data: self::publication());
		$this->listener()->handle($creating);
		$this->assertFalse($creating->isPropagationStopped());

		$updating = new ObjectUpdatingEvent($creating->getObject(), $creating->getObject());
		$this->listener()->handle($updating);
		$this->assertFalse($updating->isPropagationStopped());
		$this->assertSame([], $updating->getModifiedData());
	}//end testACompleteWooPublicationIsAccepted()

	/**
	 * REQ-DWP-003: a publication without a category in a schema no category declares is not held to DiWoo.
	 *
	 * @return void
	 */
	public function testAPublicationNoWooCategoryListsIsNotChecked(): void {
		$event = $this->creating(data: self::publication(['wooCategory' => null, 'title' => null]));
		$this->listener(tooi: null)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAPublicationNoWooCategoryListsIsNotChecked()

	/**
	 * REQ-DWP-002: a document type given as its label, or a per-file one as its code, is stored as the URI.
	 *
	 * @return void
	 */
	public function testALabelIsStoredAsItsUri(): void {
		$event = $this->creating(data: self::publication(['documentsoort' => 'besluit', 'fileDocumentsoorten' => ['17' => 'c_2977c34f']]));
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame(
			['documentsoort' => self::BESLUIT, 'fileDocumentsoorten' => ['17' => self::BESLISNOTA]],
			$event->getModifiedData()
		);
	}//end testALabelIsStoredAsItsUri()

	/**
	 * REQ-DWP-002: a document type the list does not hold is refused, also on a draft.
	 *
	 * @return void
	 */
	public function testAnUnknownDocumentsoortIsRefused(): void {
		$event = $this->creating(data: self::publication(['documentsoort' => 'memo van de wethouder', 'publicationDate' => null]));
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['documentsoort'], array_keys($event->getErrors()['fields']));
		$this->assertSame([], $event->getModifiedData());

		$perFile = $this->creating(data: self::publication(['fileDocumentsoorten' => ['17' => 'besluit', '18' => 'memo']]));
		$this->listener()->handle($perFile);
		$this->assertSame(['fileDocumentsoorten'], array_keys($perFile->getErrors()['fields']));
	}//end testAnUnknownDocumentsoortIsRefused()

	/**
	 * REQ-DWP-001: a validity that ends before it starts is refused naming validUntil.
	 *
	 * @return void
	 */
	public function testAValidityThatEndsBeforeItStartsIsRefused(): void {
		$event = $this->creating(data: self::publication(['validFrom' => '2027-01-01', 'validUntil' => '2026-12-31']));
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['validUntil' => 'ends before validFrom'], $event->getErrors()['fields']);

		$sameDay = $this->creating(data: self::publication(['validFrom' => '2027-01-01', 'validUntil' => '2027-01-01']));
		$this->listener()->handle($sameDay);
		$this->assertFalse($sameDay->isPropagationStopped());
	}//end testAValidityThatEndsBeforeItStartsIsRefused()

	/**
	 * An object outside the publication schema, in a schema no category declares, is left alone.
	 *
	 * @return void
	 */
	public function testAnObjectOfAnotherSchemaIsLeftAlone(): void {
		$event = $this->creating(data: ['documentsoort' => 'memo', 'wooCategory' => 'infocat014', 'publicationDate' => '2026-11-02'], schema: self::OTHER_SCHEMA);
		$this->listener(tooi: null)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getModifiedData());
	}//end testAnObjectOfAnotherSchemaIsLeftAlone()
}//end class

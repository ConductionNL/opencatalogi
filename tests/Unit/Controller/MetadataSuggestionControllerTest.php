<?php

/**
 * The metadata suggestion endpoints (woo-metadata-suggestions REQ-WMS-002,
 * REQ-WMS-003): a person with the update right accepts or rejects, a reader
 * without it is refused and the publication stays unchanged, and Hermiq only
 * adds checked suggestions. The decisions, the store and the value lists are
 * real; OpenRegister, the update right, Hermiq and the clock are doubles.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;
use OCA\OpenCatalogi\Controller\MetadataSuggestionController;
use OCA\OpenCatalogi\Exception\MetadataSuggestionRefusedException;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Publication\PublicationRights;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\MetadataSuggestion\HermiqMetadataClient;
use OCA\OpenCatalogi\Service\MetadataSuggestion\MetadataSuggestionDecisions;
use OCA\OpenCatalogi\Service\MetadataSuggestion\MetadataSuggestionStore;
use OCA\OpenCatalogi\Service\MetadataSuggestion\PublicationDocumentText;
use OCA\OpenCatalogi\Service\MetadataSuggestion\MetadataSuggestionService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Refusal OpenRegister throws for a caller without the right, matched by class name.
 */
class FakeNotAuthorizedException extends \Exception {
}

/**
 * Tests for MetadataSuggestionController over the real decisions.
 */
#[CoversClass(MetadataSuggestionController::class)]
#[UsesClass(MetadataSuggestionDecisions::class)]
#[UsesClass(MetadataSuggestionStore::class)]
#[UsesClass(MetadataSuggestionRefusedException::class)]
#[UsesClass(MetadataSuggestionService::class)]
#[UsesClass(TooiVocabularyService::class)]
final class MetadataSuggestionControllerTest extends TestCase {

	private const PUBLICATION = 'pub-1';

	private const SUGGESTION = 'sug-1';

	/**
	 * The OpenRegister double: stored objects, and every save it was asked for.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Hermiq's double.
	 *
	 * @var HermiqMetadataClient&\PHPUnit\Framework\MockObject\MockObject
	 */
	private HermiqMetadataClient $hermiq;

	/**
	 * The publication and its pending category suggestion.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class([
			self::PUBLICATION => ['title' => 'Besluit', 'soortHandeling' => 'vaststelling'],
			self::SUGGESTION => [
				'publication' => self::PUBLICATION,
				'field' => 'wooCategory',
				'value' => 'infocat001',
				'valueLabel' => 'Wetten',
				'source' => 'rules',
				'reason' => 'r',
				'status' => 'pending',
			],
		]) {
			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/** @var array<int, array<string, mixed>> */
			public array $searches = [];

			/** Whether OpenRegister refuses a save with RBAC on. */
			public bool $refuseRbacSave = false;

			/**
			 * @param array<string, array<string, mixed>> $stored Stored objects by uuid.
			 */
			public function __construct(public array $stored) {
			}

			public function find(string $id, ObjectFindOptions $options, bool $_rbac=true, bool $_multitenancy=true): object {
				if (isset($this->stored[$id]) === false) {
					throw new DoesNotExistException('none');
				}

				$data = $this->stored[$id];

				return new class($data, $id) {
					public function __construct(private array $data, private string $id) {
					}

					public function jsonSerialize(): array {
						return ['object' => $this->data, 'id' => $this->id];
					}
				};
			}

			public function saveObject(array $object, ObjectSaveOptions $options, bool $_rbac=true, bool $_multitenancy=true): array {
				if ($this->refuseRbacSave === true && $_rbac === true) {
					throw new FakeNotAuthorizedException('no');
				}

				$this->saves[] = ['object' => $object, 'schema' => $options->target->schema, 'uuid' => $options->uuid, 'rbac' => $_rbac];
				$uuid = ($options->uuid ?? 'new-' . count($this->saves));
				$this->stored[$uuid] = $object;

				return $object + ['id' => $uuid];
			}

			public function searchObjectsPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true): array {
				$this->searches[] = $query;
				$rows = [];
				foreach ($this->stored as $id => $row) {
					if (($row['publication'] ?? null) === $query['publication']) {
						$rows[] = $row + ['id' => $id];
					}
				}

				return ['results' => $rows];
			}
		};

		$this->hermiq = $this->createMock(HermiqMetadataClient::class);
	}//end setUp()

	/**
	 * The controller for a signed-in user (or none) with or without the update right.
	 *
	 * @param bool $mayUpdate Whether OpenRegister grants update on the publication.
	 * @param string|null $uid The signed-in user, null for none.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return MetadataSuggestionController
	 */
	private function controller(bool $mayUpdate, ?string $uid='editor', array $params=[]): MetadataSuggestionController {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => ['publication_register' => '7', 'metadata_suggestion_schema' => '30'][$key] ?? ''
		);
		$lookup = $this->createMock(OpenRegisterLookup::class);
		$lookup->method('isInstalled')->willReturn(true);
		$lookup->method('objectService')->willReturn($this->objects);
		$lookup->method('registerConfiguration')->willReturn(['register' => '7', 'schema' => '11']);

		$rights = $this->createMock(PublicationRights::class);
		$rights->method('mayUpdate')->willReturn($mayUpdate);

		$tooi = new TooiVocabularyService();
		$records = [];
		foreach ($tooi->informatiecategorieList() as $code => $member) {
			$records[$code] = ['code' => $code, 'label' => $member['label'], 'tooiUri' => $member['uri']];
		}

		$registry = $this->createMock(InformationCategoryRegistryInterface::class);
		$registry->method('all')->willReturn($records);
		$registry->method('schemasFor')->willReturn([]);

		$documents = $this->createMock(PublicationDocumentText::class);
		$documents->method('forPublication')->willReturn('Het college besluit ...');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new \DateTimeImmutable('2026-11-16T13:00:00+01:00'));

		$decisions = new MetadataSuggestionDecisions(
			store: new MetadataSuggestionStore(config: $config, openRegister: $lookup),
			rights: $rights,
			openRegister: $lookup,
			suggestions: new MetadataSuggestionService(tooiVocabulary: $tooi, categories: $registry),
			hermiq: $this->hermiq,
			documents: $documents,
			time: $time
		);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => $params[$key] ?? $default
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new MetadataSuggestionController(
			appName: 'opencatalogi',
			request: $request,
			l10n: $l10n,
			userSession: $session,
			decisions: $decisions
		);
	}//end controller()

	public function testAnEditorAcceptsACategoryAndTheSuggestionRecordsWhoAndWhen(): void {
		$response = $this->controller(mayUpdate: true)->accept(id: self::SUGGESTION);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['object' => ['title' => 'Besluit', 'soortHandeling' => 'vaststelling', 'wooCategory' => 'infocat001'], 'schema' => '11', 'uuid' => self::PUBLICATION, 'rbac' => true],
			$this->objects->saves[0]
		);
		$this->assertSame(self::SUGGESTION, $this->objects->saves[1]['uuid']);
		$this->assertFalse($this->objects->saves[1]['rbac']);
		$this->assertSame('accepted', $response->getData()['suggestion']['status']);
		$this->assertSame('editor', $response->getData()['suggestion']['decidedBy']);
		$this->assertSame('2026-11-16T12:00:00+00:00', $response->getData()['suggestion']['decidedAt']);
	}

	public function testAReaderWithoutUpdateRightsIsRefusedAndNothingIsWritten(): void {
		$accept = $this->controller(mayUpdate: false, uid: 'reader')->accept(id: self::SUGGESTION);
		$reject = $this->controller(mayUpdate: false, uid: 'reader')->reject(id: self::SUGGESTION);

		$this->assertSame(403, $accept->getStatus());
		$this->assertSame('forbidden', $accept->getData()['error']);
		$this->assertSame(403, $reject->getStatus());
		$this->assertSame([], $this->objects->saves);
		$this->assertSame('pending', $this->objects->stored[self::SUGGESTION]['status']);
		$this->assertArrayNotHasKey('wooCategory', $this->objects->stored[self::PUBLICATION]);
	}

	public function testAWriteOpenRegisterRefusesLeavesTheSuggestionPending(): void {
		$this->objects->refuseRbacSave = true;

		$response = $this->controller(mayUpdate: true)->accept(id: self::SUGGESTION);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $this->objects->saves);
		$this->assertSame('pending', $this->objects->stored[self::SUGGESTION]['status']);
	}

	public function testRejectingLeavesThePublicationUnchanged(): void {
		$response = $this->controller(mayUpdate: true)->reject(id: self::SUGGESTION);

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $this->objects->saves);
		$this->assertSame(self::SUGGESTION, $this->objects->saves[0]['uuid']);
		$this->assertSame('rejected', $this->objects->saves[0]['object']['status']);
		$this->assertSame(['title' => 'Besluit', 'soortHandeling' => 'vaststelling'], $this->objects->stored[self::PUBLICATION]);
	}

	public function testADecidedSuggestionIsNotDecidedAgain(): void {
		$this->objects->stored[self::SUGGESTION]['status'] = 'rejected';

		$response = $this->controller(mayUpdate: true)->accept(id: self::SUGGESTION);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('already-decided', $response->getData()['error']);
		$this->assertSame([], $this->objects->saves);
	}

	public function testAnUnknownSuggestionAndAMissingSessionAreRefused(): void {
		$this->assertSame(404, $this->controller(mayUpdate: true)->accept(id: 'nope')->getStatus());
		$this->assertSame(401, $this->controller(mayUpdate: true, uid: null)->accept(id: self::SUGGESTION)->getStatus());
		$this->assertSame([], $this->objects->saves);
	}

	public function testTheOverviewOffersHermiqOnlyToAnEditorWhenItIsThere(): void {
		$this->hermiq->method('isAvailable')->willReturn(true);

		$editor = $this->controller(mayUpdate: true)->index(id: self::PUBLICATION)->getData();
		$reader = $this->controller(mayUpdate: false)->index(id: self::PUBLICATION)->getData();

		$this->assertTrue($editor['hermiq']);
		$this->assertTrue($editor['canDecide']);
		$this->assertSame([self::SUGGESTION], array_column($editor['suggestions'], 'id'));
		$this->assertFalse($reader['hermiq']);
		$this->assertFalse($reader['canDecide']);
		$this->assertSame(404, $this->controller(mayUpdate: true)->index(id: 'nope')->getStatus());
	}

	public function testAskingHermiqWithoutHermiqAnswers409(): void {
		$this->hermiq->method('isAvailable')->willReturn(false);
		$this->hermiq->expects($this->never())->method('propose');

		$response = $this->controller(mayUpdate: true, params: ['source' => 'hermiq'])->create(id: self::PUBLICATION);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('hermiq-unavailable', $response->getData()['error']);
	}

	public function testHermiqOnlyAddsCheckedSuggestionsAndLeavesTheRulesOnesAlone(): void {
		$list = (new TooiVocabularyService())->informatiecategorieList();
		$other = (string)array_keys($list)[1];
		$this->hermiq->method('isAvailable')->willReturn(true);
		$this->hermiq->expects($this->once())->method('propose')
			->with(
				'Het college besluit ...',
				$this->callback(static fn (array $fields): bool => array_column($fields, 'key') === ['wooCategory']),
				'editor'
			)
			->willReturn(['wooCategory' => $list[$other]['label'], 'soortHandeling' => 'ontvangst']);

		$response = $this->controller(mayUpdate: true, params: ['source' => 'hermiq'])->create(id: self::PUBLICATION);

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $response->getData()['suggestions']);
		$this->assertSame(
			['publication' => self::PUBLICATION, 'field' => 'wooCategory', 'value' => $other, 'source' => 'hermiq', 'status' => 'pending'],
			array_intersect_key($this->objects->saves[0]['object'], array_flip(['publication', 'field', 'value', 'source', 'status']))
		);
		$this->assertSame('pending', $this->objects->stored[self::SUGGESTION]['status']);
		$this->assertSame('infocat001', $this->objects->stored[self::SUGGESTION]['value']);
	}

	public function testOnlyHermiqCanBeAskedAndAReaderMayNotAsk(): void {
		$this->hermiq->method('isAvailable')->willReturn(true);

		$this->assertSame(400, $this->controller(mayUpdate: true, params: ['source' => 'rules'])->create(id: self::PUBLICATION)->getStatus());
		$this->assertSame(403, $this->controller(mayUpdate: false, params: ['source' => 'hermiq'])->create(id: self::PUBLICATION)->getStatus());
		$this->assertSame([], $this->objects->saves);
	}
}//end class

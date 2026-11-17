<?php

/**
 * Unit tests for PublishedTableEditController.
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
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublishedTableServiceInterface;
use OCA\OpenCatalogi\Controller\PublicationWriteGuard;
use OCA\OpenCatalogi\Controller\PublishedTableEditController;
use OCA\OpenCatalogi\Service\Publication\PublicationRights;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A fake ObjectService answering one publication, or refusing.
 */
class EditFakeObjects {

	/** @var array<int, array<string, mixed>> */
	public array $finds = [];

	/**
	 * Constructor.
	 *
	 * @param object|\Throwable|null $answer What find() answers.
	 */
	public function __construct(public mixed $answer) {
	}

	/**
	 * ObjectService find.
	 *
	 * @param mixed ...$args The named arguments.
	 *
	 * @return object|null The publication.
	 */
	public function find(mixed ...$args): ?object {
		$this->finds[] = $args;
		if ($this->answer instanceof \Throwable) {
			throw $this->answer;
		}

		return $this->answer;
	}
}

/**
 * The editor side of published tables: every action needs the update right.
 *
 * @covers \OCA\OpenCatalogi\Controller\PublishedTableEditController
 * @covers \OCA\OpenCatalogi\Controller\PublicationWriteGuard
 */
class PublishedTableEditControllerTest extends TestCase {

	private const TABLE = ['id' => 't1', 'publication' => 'p1', 'status' => 'ready', 'schema' => '42'];

	private IRequest&MockObject $request;

	private PublishedTableServiceInterface&MockObject $tables;

	private PublicationRights&MockObject $rights;

	private IUserSession&MockObject $session;

	private EditFakeObjects $objects;

	private \stdClass $publication;

	/**
	 * Wire the controller to its doubles: a signed-in editor and a readable publication.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->tables = $this->createMock(PublishedTableServiceInterface::class);
		$this->rights = $this->createMock(PublicationRights::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->publication = new \stdClass();
		$this->objects = new EditFakeObjects($this->publication);
	}//end setUp()

	/**
	 * The controller over the current doubles.
	 *
	 * @return PublishedTableEditController
	 */
	private function controller(): PublishedTableEditController {
		$registers = $this->createMock(OpenRegisterLookupInterface::class);
		$registers->method('objectService')->willReturn($this->objects);
		$registers->method('registerConfiguration')->with('publication_register', 'publication_schema')->willReturn(['register' => 'pub', 'schema' => 'publication']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new PublishedTableEditController(
			appName: 'opencatalogi',
			request: $this->request,
			tables: $this->tables,
			guard: new PublicationWriteGuard(registers: $registers, rights: $this->rights, userSession: $this->session, l10n: $l10n),
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * An editor lists the publication's tables; the publication is read with RBAC on.
	 *
	 * @return void
	 */
	public function testAnEditorListsTheTables(): void {
		$this->rights->method('mayUpdate')->with($this->publication)->willReturn(true);
		$this->tables->expects($this->once())->method('tablesFor')->with('p1')->willReturn([self::TABLE]);

		$response = $this->controller()->index(id: 'p1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [self::TABLE]], $response->getData());
		$find = $this->objects->finds[0];
		$this->assertSame(expected: ['id', 'options', '_rbac'], actual: array_keys($find));
		$this->assertSame(expected: 'p1', actual: $find['id']);
		$this->assertInstanceOf(expected: ObjectFindOptions::class, actual: $find['options']);
		$this->assertSame(expected: ['pub', 'publication'], actual: [$find['options']->register, $find['options']->schema]);
		$this->assertTrue(condition: $find['_rbac']);
	}//end testAnEditorListsTheTables()

	/**
	 * Without a user, a readable publication or the update right nothing is listed or written.
	 *
	 * @return void
	 */
	public function testEveryActionNeedsTheUpdateRight(): void {
		$this->tables->expects($this->never())->method('tablesFor');
		$this->tables->expects($this->never())->method('publishAttachment');
		$this->tables->expects($this->never())->method('updateDictionary');

		$this->rights->method('mayUpdate')->willReturn(false);
		$this->assertSame(403, $this->controller()->index(id: 'p1')->getStatus());
		$this->assertSame(403, $this->controller()->create(id: 'p1')->getStatus());
		$this->assertSame(403, $this->controller()->updateDictionary(id: 'p1', tableId: 't1')->getStatus());

		$this->objects->answer = new \RuntimeException('not authorized');
		$this->assertSame(404, $this->controller()->index(id: 'p1')->getStatus());
		$this->objects->answer = null;
		$this->assertSame(404, $this->controller()->create(id: 'p1')->getStatus());

		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn(null);
		$this->assertSame(401, $this->controller()->updateDictionary(id: 'p1', tableId: 't1')->getStatus());
	}//end testEveryActionNeedsTheUpdateRight()

	/**
	 * An editor publishes a CSV attachment; the answer is the stored table.
	 *
	 * @return void
	 */
	public function testAnEditorPublishesAnAttachment(): void {
		$this->rights->method('mayUpdate')->willReturn(true);
		$this->request->method('getParam')->with('fileId', '')->willReturn('77');
		$this->tables->expects($this->once())
			->method('publishAttachment')
			->with($this->publication, '77', $this->isInstanceOf(IUser::class))
			->willReturn(['id' => 't1', 'status' => 'ready']);

		$response = $this->controller()->create(id: 'p1');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 't1', 'status' => 'ready'], $response->getData());
	}//end testAnEditorPublishesAnAttachment()

	/**
	 * A missing file id, a refused file and a failing import each get their own answer.
	 *
	 * @return void
	 */
	public function testPublishRefusalsAndFailures(): void {
		$this->rights->method('mayUpdate')->willReturn(true);
		$this->request->method('getParam')->willReturnOnConsecutiveCalls('', '77', '77');
		$this->tables->method('publishAttachment')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('Only a CSV attachment can be published as a table; "a.pdf" is not one.')),
			$this->throwException(new \RuntimeException('database down'))
		);

		$missing = $this->controller()->create(id: 'p1');
		$this->assertSame(400, $missing->getStatus());
		$this->assertSame('Choose the CSV attachment to publish as a table.', $missing->getData()['message']);

		$refused = $this->controller()->create(id: 'p1');
		$this->assertSame(400, $refused->getStatus());
		$this->assertSame('Only a CSV attachment can be published as a table; "a.pdf" is not one.', $refused->getData()['message']);

		$failed = $this->controller()->create(id: 'p1');
		$this->assertSame(500, $failed->getStatus());
		$this->assertSame('table-not-published', $failed->getData()['error']);
	}//end testPublishRefusalsAndFailures()

	/**
	 * An editor writes a column description and gets the dictionary back.
	 *
	 * @return void
	 */
	public function testAnEditorEditsTheDictionary(): void {
		$this->rights->method('mayUpdate')->willReturn(true);
		$this->tables->method('findTable')->with('t1')->willReturn(self::TABLE);
		$columns = [['name' => 'toestellen', 'description' => 'Number of play devices on the site']];
		$this->request->method('getParam')->with('columns', [])->willReturn($columns);
		$dictionary = [['name' => 'toestellen', 'title' => 'toestellen', 'description' => 'Number of play devices on the site', 'type' => 'integer']];
		$this->tables->expects($this->once())->method('updateDictionary')->with(self::TABLE, $columns)->willReturn($dictionary);

		$response = $this->controller()->updateDictionary(id: 'p1', tableId: 't1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['table' => 't1', 'columns' => $dictionary], $response->getData());
	}//end testAnEditorEditsTheDictionary()

	/**
	 * Another publication's table, a table not ready, bad columns and an unknown column are refused.
	 *
	 * @return void
	 */
	public function testDictionaryRefusals(): void {
		$this->rights->method('mayUpdate')->willReturn(true);
		$this->tables->method('findTable')->willReturnOnConsecutiveCalls(
			['publication' => 'p2', 'status' => 'ready'] + self::TABLE,
			['status' => 'failed'] + self::TABLE,
			self::TABLE,
			self::TABLE
		);
		$this->request->method('getParam')->willReturnOnConsecutiveCalls('not a list', [['name' => 'kleur']]);
		$this->tables->method('updateDictionary')->willThrowException(new InvalidArgumentException('This table has no column named kleur.'));

		$this->assertSame(404, $this->controller()->updateDictionary(id: 'p1', tableId: 't1')->getStatus());
		$this->assertSame(404, $this->controller()->updateDictionary(id: 'p1', tableId: 't1')->getStatus());
		$this->assertSame(400, $this->controller()->updateDictionary(id: 'p1', tableId: 't1')->getStatus());
		$unknown = $this->controller()->updateDictionary(id: 'p1', tableId: 't1');
		$this->assertSame(400, $unknown->getStatus());
		$this->assertSame('This table has no column named kleur.', $unknown->getData()['message']);
	}//end testDictionaryRefusals()
}//end class

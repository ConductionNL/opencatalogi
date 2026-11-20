<?php

/**
 * Unit tests for GrantCapabilitiesToDelegatedAdmins.
 *
 * @category Test
 * @package  Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\GrantCapabilitiesToDelegatedAdmins;
use OCA\OpenCatalogi\Service\CapabilityRights;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The groups delegated the admin section keep every capability on upgrade (REQ-WAB-004).
 */
#[CoversClass(GrantCapabilitiesToDelegatedAdmins::class)]
#[UsesClass(CapabilityRights::class)]
class GrantCapabilitiesToDelegatedAdminsTest extends TestCase {

	/**
	 * The query parameters asked, to prove the delegation is read for this app's section.
	 *
	 * @var array<int, mixed>
	 */
	private array $parameters = [];

	/**
	 * A database answering the given delegated groups.
	 *
	 * @param array<int,string> $groups The group ids.
	 *
	 * @return IDBConnection The database.
	 */
	private function db(array $groups): IDBConnection {
		$rows   = array_map(static fn (string $g): array => ['group_id' => $g], $groups);
		$rows[] = false;
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('class = :p');

		$query = $this->createMock(IQueryBuilder::class);
		$query->method('select')->willReturnSelf();
		$query->method('from')->willReturnCallback(
			function (string $table) use ($query): IQueryBuilder {
				$this->parameters[] = $table;
				return $query;
			}
		);
		$query->method('where')->willReturnSelf();
		$query->method('expr')->willReturn($expr);
		$query->method('createNamedParameter')->willReturnCallback(
			function (mixed $value): string {
				$this->parameters[] = $value;
				return ':p';
			}
		);
		$query->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($query);

		return $db;

	}//end db()

	/**
	 * The step over the given groups and catalog schema mapper.
	 *
	 * @param array<int,string> $groups The delegated groups.
	 * @param SchemaMapper      $mapper The mapper.
	 * @param bool              $or     Whether OpenRegister is enabled.
	 *
	 * @return GrantCapabilitiesToDelegatedAdmins The step.
	 */
	private function step(array $groups, SchemaMapper $mapper, bool $or = true): GrantCapabilitiesToDelegatedAdmins {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn($or);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper);

		return new GrantCapabilitiesToDelegatedAdmins($apps, $this->db($groups), $container);

	}//end step()

	/**
	 * Every verb lists the delegated groups; other keys and existing grants are kept.
	 *
	 * @return void
	 */
	public function testTheDelegatedGroupsGetEveryRight(): void {
		$schema = new Schema();
		$schema->setSlug('catalog');
		$schema->setAuthorization(['read' => ['public'], 'openInspection' => ['ter-inzage']]);

		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->with('catalog')->willReturn($schema);
		$mapper->expects($this->once())->method('update')->with($schema);

		$this->step(['woo-beheer', 'woo-beheer'], $mapper)->run($this->createMock(IOutput::class));

		$authorization = $schema->getAuthorization();
		$this->assertSame(['public'], $authorization['read']);
		$this->assertSame(['ter-inzage', 'woo-beheer'], $authorization['openInspection']);
		foreach (array_keys(CapabilityRights::VERBS) as $verb) {
			$this->assertContains('woo-beheer', $authorization[$verb], $verb);
		}

		$this->assertSame(['authorized_groups', 'OCA\OpenCatalogi\Settings\OpenCatalogiAdmin'], $this->parameters);

	}//end testTheDelegatedGroupsGetEveryRight()

	/**
	 * A second run writes nothing.
	 *
	 * @return void
	 */
	public function testItIsIdempotent(): void {
		$complete = GrantCapabilitiesToDelegatedAdmins::granted(authorization: ['read' => ['public']], groups: ['woo-beheer']);
		$schema   = new Schema();
		$schema->setAuthorization($complete);

		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturn($schema);
		$mapper->expects($this->never())->method('update');

		$this->step(['woo-beheer'], $mapper)->run($this->createMock(IOutput::class));

		$this->assertSame($complete, $schema->getAuthorization());

	}//end testItIsIdempotent()

	/**
	 * No delegation, no OpenRegister, or a failing mapper: nothing is written and the upgrade goes on.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWithoutDelegationOrOpenRegister(): void {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->expects($this->never())->method('update');
		$this->step([], $mapper)->run($this->createMock(IOutput::class));
		$this->step(['woo-beheer'], $mapper, false)->run($this->createMock(IOutput::class));

		$failing = $this->createMock(SchemaMapper::class);
		$failing->method('find')->willThrowException(new \RuntimeException('gone'));
		$failing->expects($this->never())->method('update');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('could not be granted'));
		$this->step(['woo-beheer'], $failing)->run($output);

	}//end testNothingIsWrittenWithoutDelegationOrOpenRegister()

	/**
	 * The step is registered in appinfo/info.xml, after InitializeSettings.
	 *
	 * @return void
	 */
	public function testItIsRegistered(): void {
		$xml   = (string) file_get_contents(__DIR__.'/../../../appinfo/info.xml');
		$init  = strpos($xml, '<step>OCA\OpenCatalogi\Repair\InitializeSettings</step>');
		$grant = strpos($xml, '<step>OCA\OpenCatalogi\Repair\GrantCapabilitiesToDelegatedAdmins</step>');

		$this->assertIsInt($grant);
		$this->assertIsInt($init);
		$this->assertGreaterThan($init, $grant);

	}//end testItIsRegistered()
}//end class

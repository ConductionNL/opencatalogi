<?php

/**
 * Unit tests for CapabilityRightsTable.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
 */

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\CapabilityRights;
use OCA\OpenCatalogi\Service\CapabilityRightsTable;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The rights table reads and writes only the eight verb keys of the catalog schema's authorization block.
 */
#[CoversClass(CapabilityRightsTable::class)]
#[UsesClass(CapabilityRights::class)]
class CapabilityRightsTableTest extends TestCase {

	/**
	 * The catalog schema the mapper hands out.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * The schema mapper double.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $mapper;

	/**
	 * A table over a catalog schema holding this authorization block, with these groups existing.
	 *
	 * @param array<string, mixed>|null $authorization The block.
	 * @param array<int, string>        $existing      The group ids that exist.
	 *
	 * @return CapabilityRightsTable
	 */
	private function table(?array $authorization, array $existing = ['ter-inzage', 'woo-team']): CapabilityRightsTable {
		$this->schema = new Schema();
		$this->schema->setAuthorization($authorization);

		$this->mapper = $this->createMock(SchemaMapper::class);
		$this->mapper->method('find')->with(CapabilityRights::CATALOG_SCHEMA)->willReturn($this->schema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($this->mapper);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $group): bool => in_array($group, $existing, true));

		return new CapabilityRightsTable(container: $container, groupManager: $groups);
	}//end table()

	/**
	 * Every right is listed in declaration order with its description and only its string group ids.
	 *
	 * @return void
	 */
	public function testRowsListTheEightRightsWithTheirGroups(): void {
		$rows = $this->table(['read' => ['authenticated'], 'openInspection' => ['ter-inzage', 7, ''], 'publishWooBatch' => 'woo-team'])->rows();

		$this->assertSame(array_keys(CapabilityRights::VERBS), array_column($rows, 'verb'));
		$this->assertSame(['verb' => 'openInspection', 'description' => CapabilityRights::VERBS['openInspection'], 'groups' => ['ter-inzage']], $rows[0]);
		$this->assertSame([], $rows[6]['groups'], 'a grant that is not a list reads as no group');
	}//end testRowsListTheEightRightsWithTheirGroups()

	/**
	 * A schema without an authorization block lists every right with no group.
	 *
	 * @return void
	 */
	public function testAMissingBlockListsNoGroups(): void {
		$rows = $this->table(null)->rows();

		$this->assertCount(8, $rows);
		$this->assertSame([[], [], [], [], [], [], [], []], array_column($rows, 'groups'));
	}//end testAMissingBlockListsNoGroups()

	/**
	 * A grant replaces that right's groups and keeps every other rule, the public read rules among them.
	 *
	 * @return void
	 */
	public function testAGrantReplacesOneRightAndKeepsTheRestOfTheBlock(): void {
		$read  = [['group' => 'public', 'match' => ['published' => ['$lte' => '$now']]], 'authenticated'];
		$table = $this->table(['read' => $read, 'openInspection' => ['woo-team'], 'depublish' => ['woo-team']]);
		$this->mapper->expects($this->once())->method('update')->with($this->identicalTo($this->schema));

		$rows = $table->grant(verb: 'openInspection', groups: ['ter-inzage', 'ter-inzage']);

		$this->assertSame(['read' => $read, 'openInspection' => ['ter-inzage'], 'depublish' => ['woo-team']], $this->schema->getAuthorization());
		$this->assertSame(['ter-inzage'], $rows[0]['groups']);
	}//end testAGrantReplacesOneRightAndKeepsTheRestOfTheBlock()

	/**
	 * An empty list takes the right away from every group.
	 *
	 * @return void
	 */
	public function testAnEmptyGrantRevokesTheRight(): void {
		$table = $this->table(['openInspection' => ['ter-inzage']]);
		$this->mapper->expects($this->once())->method('update');

		$table->grant(verb: 'openInspection', groups: []);

		$this->assertSame(['openInspection' => []], $this->schema->getAuthorization());
	}//end testAnEmptyGrantRevokesTheRight()

	/**
	 * An unknown right is refused and nothing is written, so no other key of the block can be set this way.
	 *
	 * @return void
	 */
	public function testAnUnknownRightIsRefused(): void {
		$table = $this->table(['read' => ['authenticated']]);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown right: read');
		$table->grant(verb: 'read', groups: ['public']);
	}//end testAnUnknownRightIsRefused()

	/**
	 * A group that does not exist is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAGroupThatDoesNotExistIsRefused(): void {
		$table = $this->table([]);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('No such group: nobody');
		$table->grant(verb: 'openInspection', groups: ['ter-inzage', 'nobody']);
	}//end testAGroupThatDoesNotExistIsRefused()

	/**
	 * A group id that is not a string is refused.
	 *
	 * @return void
	 */
	public function testAGroupThatIsNotAnIdIsRefused(): void {
		$table = $this->table([]);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A group must be named by its id.');
		$table->grant(verb: 'openInspection', groups: [['id' => 'ter-inzage']]);
	}//end testAGroupThatIsNotAnIdIsRefused()
}//end class

<?php

/**
 * Unit tests for RenameDutchPublicationColumns.
 *
 * Covers the decision that determines which shard tables the migration touches,
 * plus two invariants the step relies on that were previously asserted only in
 * prose.
 *
 * The DDL/DML paths are deliberately not unit-tested: they need a live
 * database. What IS testable in isolation is the logic deciding which tables
 * are in scope, and that is what these tests pin.
 *
 * @category Tests
 * @package  Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\RenameDutchPublicationColumns;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenCatalogi\Repair\RenameDutchPublicationColumns
 */
class RenameDutchPublicationColumnsTest extends TestCase
{
    /**
     * The step under test.
     *
     * @var RenameDutchPublicationColumns
     */
    private RenameDutchPublicationColumns $step;

    /**
     * Build the step WITHOUT running its constructor.
     *
     * The methods under test are pure — they read neither $db nor $logger — so
     * no collaborators are needed, and mocking IDBConnection can drag in
     * Doctrine types the unit environment does not install. Skipping the
     * constructor keeps the test honest about what it exercises.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->step = (new ReflectionClass(RenameDutchPublicationColumns::class))->newInstanceWithoutConstructor();

    }//end setUp()

    /**
     * Invoke a private method on the step.
     *
     * @param string       $name Method name.
     * @param array<mixed> $args Positional arguments.
     *
     * @return mixed
     */
    private function call(string $name, array $args)
    {
        $m = new ReflectionMethod(RenameDutchPublicationColumns::class, $name);
        $m->setAccessible(true);
        return $m->invokeArgs($this->step, $args);

    }//end call()

    /**
     * Read a private constant off the step.
     *
     * @param string $name Constant name.
     *
     * @return mixed
     */
    private function constant(string $name)
    {
        return (new ReflectionClass(RenameDutchPublicationColumns::class))->getConstant($name);

    }//end constant()

    /**
     * A shard table whose schema id matches is selected.
     *
     * @return void
     */
    public function testMatchesShardOfTheSchema(): void
    {
        self::assertTrue($this->call('isShardOfSchema', ['oc_openregister_table_15_42', ['_42']]));

    }//end testMatchesShardOfTheSchema()

    /**
     * The SAME schema in a DIFFERENT register is also selected.
     *
     * These publication schemas are duplicated across many registers — 25 shard
     * tables over 18 schema ids were observed on the reference install — so
     * migrating only the first register leaves most of the data behind.
     *
     * @return void
     */
    public function testMatchesTheSameSchemaInEveryRegister(): void
    {
        self::assertTrue($this->call('isShardOfSchema', ['oc_openregister_table_15_42', ['_42']]));
        self::assertTrue($this->call('isShardOfSchema', ['oc_openregister_table_2424_42', ['_42']]));

    }//end testMatchesTheSameSchemaInEveryRegister()

    /**
     * Schema 42 must not match schema 142's shard.
     *
     * The suffix carries a leading underscore precisely so `_42` cannot match
     * `..._142`. Without it the step migrates an unrelated schema.
     *
     * @return void
     */
    public function testDoesNotMatchALongerSchemaId(): void
    {
        self::assertFalse($this->call('isShardOfSchema', ['oc_openregister_table_15_142', ['_42']]));

    }//end testDoesNotMatchALongerSchemaId()

    /**
     * A table without the openregister marker is never selected.
     *
     * The suffix alone is not sufficient — `oc_some_other_42` ends in `_42`.
     * The marker check is what keeps the step off tables it does not own.
     *
     * @return void
     */
    public function testRequiresTheOpenregisterMarker(): void
    {
        self::assertFalse($this->call('isShardOfSchema', ['oc_some_other_42', ['_42']]));
        self::assertFalse($this->call('isShardOfSchema', ['', ['_42']]));

    }//end testRequiresTheOpenregisterMarker()

    /**
     * Every destination is snake_case, never camelCase.
     *
     * MagicMapper stores `publicationDate` as `publication_date`, and its
     * de-duplication path DROPS a camelCase column whose snake_case twin
     * exists — so a camelCase destination would be deleted on the next sync.
     *
     * @return void
     */
    public function testEveryDestinationIsSnakeCase(): void
    {
        $map = $this->constant('COLUMN_MAP');
        self::assertIsArray($map);
        foreach ($map as $old => $new) {
            self::assertSame(
                strtolower($new),
                $new,
                "Destination '$new' (from '$old') must be snake_case, not camelCase"
            );
        }

    }//end testEveryDestinationIsSnakeCase()

    /**
     * No two Dutch columns map to the same English name.
     *
     * This step has no collision guard, unlike its siblings in procest and
     * softwarecatalog, and needs none only for as long as the map stays
     * injective. If a later edit introduces a duplicate destination the step
     * would silently overwrite one value with another; this catches it at
     * review time rather than in production.
     *
     * @return void
     */
    public function testColumnMapIsInjective(): void
    {
        $map = $this->constant('COLUMN_MAP');
        self::assertSame(
            count($map),
            count(array_unique(array_values($map))),
            'Two Dutch columns map to the same English name; add a collision guard first'
        );

    }//end testColumnMapIsInjective()

    /**
     * The publication date is in the map.
     *
     * Not decoration: anonymous visibility on the ORI harvest feed is governed
     * by `publicatiedatum <= now`. If this entry were dropped the column would
     * never move, every read would return null, and the feed's visibility rule
     * would change silently.
     *
     * @return void
     */
    public function testPublicationDateIsMigrated(): void
    {
        $map = $this->constant('COLUMN_MAP');
        self::assertArrayHasKey('publicatiedatum', $map);
        self::assertSame('publication_date', $map['publicatiedatum']);

    }//end testPublicationDateIsMigrated()

    /**
     * The step reports a human-readable name.
     *
     * @return void
     */
    public function testGetName(): void
    {
        self::assertNotSame('', $this->step->getName());

    }//end testGetName()
}//end class

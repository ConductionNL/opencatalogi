<?php

/**
 * A permission question asked in the shape the installed OpenRegister takes.
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
 *
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\PermissionQuestion;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\OpenCatalogi\Service\PermissionQuestion
 */
final class PermissionQuestionTest extends TestCase {

	/**
	 * A newer OpenRegister is asked one question object carrying everything.
	 *
	 * @return void
	 */
	public function testANewerOpenRegisterIsAskedOneQuestion(): void {
		$schema  = new stdClass();
		$object  = new stdClass();
		$handler = new class {
			/**
			 * The question asked.
			 *
			 * @var PermissionQuestionAskDouble|null
			 */
			public ?PermissionQuestionAskDouble $asked = null;

			/**
			 * The newer signature.
			 *
			 * @param PermissionQuestionAskDouble $ask The question.
			 *
			 * @return bool
			 */
			public function hasPermission(PermissionQuestionAskDouble $ask): bool {
				$this->asked = $ask;
				return true;
			}//end hasPermission()
		};

		$question = new PermissionQuestion(schema: $schema, action: 'update', askClass: PermissionQuestionAskDouble::class);
		$answer   = $question->askedBy(userId: null, objectOwner: 'eigenaar')->about(object: $object)->answerOf(handler: $handler);

		$this->assertTrue(condition: $answer);
		$this->assertSame(expected: [$schema, 'update', null, 'eigenaar', $object], actual: $handler->asked?->fields());
	}//end testANewerOpenRegisterIsAskedOneQuestion()

	/**
	 * A released OpenRegister is asked with the arguments of its own, and its no is a no.
	 *
	 * @return void
	 */
	public function testAReleasedOpenRegisterIsAskedArgumentByArgument(): void {
		$schema  = new stdClass();
		$handler = new class {
			/**
			 * The arguments asked.
			 *
			 * @var array<int, mixed>
			 */
			public array $asked = [];

			/**
			 * The released signature.
			 *
			 * @param object      $schema      The schema.
			 * @param string      $action      The action.
			 * @param string|null $userId      The user.
			 * @param string|null $objectOwner The owner.
			 * @param object|null $object      The object.
			 *
			 * @return bool
			 */
			public function hasPermission(object $schema, string $action, ?string $userId = null, ?string $objectOwner = null, ?object $object = null): bool {
				$this->asked = [$schema, $action, $userId, $objectOwner, $object];
				return false;
			}//end hasPermission()
		};

		$question = new PermissionQuestion(schema: $schema, action: 'openInspection', askClass: 'OCA\OpenRegister\Service\Object\NoSuchAsk');
		$answer   = $question->askedBy(userId: 'anna')->answerOf(handler: $handler);

		$this->assertFalse(condition: $answer);
		$this->assertSame(expected: [$schema, 'openInspection', 'anna', null, null], actual: $handler->asked);
	}//end testAReleasedOpenRegisterIsAskedArgumentByArgument()
}//end class

/**
 * Stands in for OpenRegister's PermissionAsk.
 */
final class PermissionQuestionAskDouble {

	/**
	 * The user asking.
	 *
	 * @var string|null
	 */
	private ?string $userId = null;

	/**
	 * The owner of the object.
	 *
	 * @var string|null
	 */
	private ?string $objectOwner = null;

	/**
	 * The object asked about.
	 *
	 * @var object|null
	 */
	private ?object $object = null;

	/**
	 * The question about a schema and an action.
	 *
	 * @param object $schema The schema.
	 * @param string $action The action.
	 */
	public function __construct(private object $schema, private string $action) {
	}//end __construct()

	/**
	 * Who asks, and who owns the object.
	 *
	 * @param string|null $userId      The user.
	 * @param string|null $objectOwner The owner.
	 *
	 * @return self
	 */
	public function askedBy(?string $userId, ?string $objectOwner): self {
		$this->userId      = $userId;
		$this->objectOwner = $objectOwner;
		return $this;
	}//end askedBy()

	/**
	 * The object asked about.
	 *
	 * @param object|null $object   The object.
	 * @param string|null $register The register.
	 *
	 * @return self
	 */
	public function about(?object $object, ?string $register): self {
		$this->object = $object;
		return $this;
	}//end about()

	/**
	 * What the question carries.
	 *
	 * @return array<int, mixed>
	 */
	public function fields(): array {
		return [$this->schema, $this->action, $this->userId, $this->objectOwner, $this->object];
	}//end fields()
}//end class

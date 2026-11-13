<?php

/**
 * The publish right as the publication schema declares it.
 *
 * `publicationDate` and `depublicationDate` decide whether a publication is
 * public, so only the publishers group may change them (REQ-OPR-001). The
 * declaration lives in lib/Settings/publication_register.json; these tests
 * read that file, and run OpenRegister's own PropertyRbacHandler on it when
 * OpenRegister is installed next to the app.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\Publication\PublishRight;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-only-publishers-change-whether-a-publication-is-public-req-opr-001
 */
class PublishRightsSchemaTest extends TestCase {

	private const REGISTER = __DIR__ . '/../../../lib/Settings/publication_register.json';

	/**
	 * The publication schema as the app ships it.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function publicationSchema(): array {
		$register = json_decode((string)file_get_contents(self::REGISTER), true, 512, JSON_THROW_ON_ERROR);
		return $register['components']['schemas']['publication'];
	}

	/**
	 * Both dates carry an update rule for the publishers group, and nothing else.
	 *
	 * @return void
	 */
	public function testBothDatesAreUpdatableByThePublishersGroupOnly(): void {
		$properties = $this->publicationSchema()['properties'];
		foreach (['publicationDate', 'depublicationDate'] as $name) {
			$this->assertSame(
				['update' => [['group' => PublishRight::GROUP]]],
				$properties[$name]['authorization'] ?? null,
				$name . ' must be updatable by the publishers group only; read stays as it is'
			);
		}
	}

	/**
	 * Every other property keeps the rights it has today (REQ-OPR-001).
	 *
	 * @return void
	 */
	public function testNoOtherPropertyGainsAPropertyRule(): void {
		$governed = [];
		foreach ($this->publicationSchema()['properties'] as $name => $property) {
			if (is_array($property) === true && array_key_exists('authorization', $property) === true) {
				$governed[] = $name;
			}
		}

		$this->assertSame(['publicationDate', 'depublicationDate'], $governed);
	}

	/**
	 * OpenRegister refuses an editor's new publicationDate, lets a publisher set
	 * it, and lets the editor save a title with the date unchanged.
	 *
	 * @return void
	 */
	public function testOpenRegisterRefusesAnEditorAndAdmitsAPublisher(): void {
		$handlerClass = 'OCA\OpenRegister\Service\PropertyRbacHandler';
		if (class_exists($handlerClass) === false
			|| class_exists('OCA\OpenRegister\Service\PropertyWriteCheck') === false
			|| method_exists('OCA\OpenRegister\Db\Schema', 'getPropertiesWithAuthorization') === false
		) {
			$this->markTestSkipped('OpenRegister is not installed next to the app, so its PropertyRbacHandler cannot be run.');
		}

		$schemaClass = 'OCA\OpenRegister\Db\Schema';
		$schema = new $schemaClass();
		$schema->hydrate(['slug' => 'publication', 'properties' => $this->publicationSchema()['properties']]);

		$stored = ['title' => 'Besluit', 'publicationDate' => null];
		$publish = ['title' => 'Besluit', 'publicationDate' => '2026-09-01T09:00:00+00:00'];
		$retitle = ['title' => 'Besluit (herzien)', 'publicationDate' => null];

		$editor = $this->handler(groups: ['redacteuren']);
		$this->assertSame(['publicationDate'], $editor->getUnauthorizedProperties($this->write($schema, $stored, $publish)));
		$this->assertSame([], $editor->getUnauthorizedProperties($this->write($schema, $stored, $retitle)));
		$this->assertSame(['depublicationDate'], $editor->getUnauthorizedProperties($this->write($schema, $stored, ['depublicationDate' => '2026-09-02T09:00:00+00:00'])));

		$publisher = $this->handler(groups: ['redacteuren', PublishRight::GROUP]);
		$this->assertSame([], $publisher->getUnauthorizedProperties($this->write($schema, $stored, $publish)));
	}

	/**
	 * A PropertyWriteCheck for an update of a stored object.
	 *
	 * @param object $schema The schema.
	 * @param array<string, mixed> $stored The stored object.
	 * @param array<string, mixed> $incoming The submitted properties.
	 *
	 * @return object The write check.
	 */
	private function write(object $schema, array $stored, array $incoming): object {
		$request = 'OCA\OpenRegister\Service\PropertyAccessRequest';
		$check = 'OCA\OpenRegister\Service\PropertyWriteCheck';
		return new $check($schema, new $request($stored, false), $incoming);
	}

	/**
	 * OpenRegister's handler for a signed-in user in the given groups.
	 *
	 * @param array<int, string> $groups The user's groups.
	 *
	 * @return object The handler.
	 */
	private function handler(array $groups): object {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => in_array($group, $groups, true));
		$groupManager->method('isAdmin')->willReturn(false);

		$caller = 'OCA\OpenRegister\Service\PropertyRbacCaller';
		$rules = 'OCA\OpenRegister\Service\PropertyRbacRules';
		$handler = 'OCA\OpenRegister\Service\PropertyRbacHandler';
		$matcher = $this->createMock('OCA\OpenRegister\Service\ConditionMatcher');
		$resolver = $this->createMock('OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver');
		// The publication schema has no lifecycle states, so no field is state-hidden.
		$noStateRules = 'OCA\OpenRegister\Service\Lifecycle\StateFieldRules';
		$resolver->method('resolve')->willReturn(new $noStateRules());

		return new $handler(new $caller($session, $groupManager), new $rules($matcher, $resolver), $this->createMock(LoggerInterface::class));
	}
}

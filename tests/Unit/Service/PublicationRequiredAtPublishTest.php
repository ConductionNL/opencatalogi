<?php

/**
 * PublicationRequiredAtPublishTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-required-at-publish/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\Publication\RequiredToPublish;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\StateFieldRuleListener;
use OCA\OpenRegister\Listener\Support\StateFieldRulePlatform;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Object\NotSuppliedHandler;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The required-to-publish rule as OpenRegister enforces it, on the publication schema exactly as this app imports it
 * (REQ-PRP-001, REQ-PRP-002, design D2): OpenRegister's own state field rule listener and not-supplied handler run
 * over the merged register files. Skipped with a named reason when OpenRegister is not beside the app.
 *
 * @coversNothing
 */
final class PublicationRequiredAtPublishTest extends TestCase {

	/**
	 * The publication schema as it is imported: the base register with every register.d fragment merged.
	 *
	 * @return array<string, mixed>
	 */
	private static function publicationSchema(): array {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($root . '/publication_register.json'), true, flags: JSON_THROW_ON_ERROR);
		$merge = new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments');

		return $merge->invoke(null, $base, $root . '/register.d')['components']['schemas']['publication'];
	}//end publicationSchema()

	/**
	 * Skip unless OpenRegister's listener and handler are loadable.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		foreach ([StateFieldRuleListener::class, NotSuppliedHandler::class, StateFieldRulePlatform::class] as $class) {
			if (class_exists($class) === false) {
				self::markTestSkipped(message: 'OpenRegister is not installed beside this app, so its state field rules cannot run: ' . $class);
			}
		}
	}//end setUp()

	/**
	 * The publication schema as OpenRegister stores it: the sibling-of-properties annotations folded into configuration.
	 *
	 * @return Schema The schema entity.
	 */
	private static function schemaEntity(): Schema {
		$declared = self::publicationSchema();
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('publication');
		$schema->setProperties($declared['properties']);
		$schema->setConfiguration([
			'x-openregister-lifecycle'            => $declared['x-openregister-lifecycle'],
			'x-openregister-not-supplied-reasons' => $declared['x-openregister-not-supplied-reasons'],
		]);

		return $schema;
	}//end schemaEntity()

	/**
	 * OpenRegister's listener over the publication schema, for a caller in no group.
	 *
	 * @return StateFieldRuleListener The listener.
	 */
	private function listener(): StateFieldRuleListener {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('redacteur');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn([]);
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturn(self::schemaEntity());

		$dialect = new ConditionDialect(new CalculationEvaluator(new PlaceholderResolver($session)));
		$resolver = new StateFieldRuleResolver($session, $groups, $dialect);

		return new StateFieldRuleListener(
			new StateFieldRulePlatform($mapper, $this->createMock(RuleRunRecorder::class), $this->createMock(LoggerInterface::class)),
			$resolver,
			new StateConditionEvaluator($resolver, $dialect)
		);
	}//end listener()

	/**
	 * A publication object carrying the given data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function publication(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('5f0c3a8e-4b1d-4c39-9f0e-2b7d6c1a9e10');
		$entity->setSchema('12');
		$entity->setRegister('7');
		$entity->setObject($data);

		return $entity;
	}//end publication()

	/**
	 * REQ-PRP-001: a draft with only a title saves.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-required-at-publish/specs/publications/spec.md#requirement-a-draft-needs-only-a-title-req-prp-001
	 */
	public function testADraftWithOnlyATitleIsSaved(): void {
		$event = new ObjectCreatingEvent(self::publication(['title' => 'Raadsstukken 15 oktober', 'status' => 'draft']));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());
	}//end testADraftWithOnlyATitleIsSaved()

	/**
	 * REQ-PRP-002: publishing a draft without a summary or description is refused, naming the summary first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-required-at-publish/specs/publications/spec.md#requirement-a-draft-needs-only-a-title-req-prp-001
	 */
	public function testPublishingWithoutSummaryIsRefusedByName(): void {
		$old = self::publication(['title' => 'Raadsstukken 15 oktober', 'status' => 'draft']);
		$new = self::publication(['title' => 'Raadsstukken 15 oktober', 'status' => 'published', 'organization' => 'org-1']);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(StateFieldRuleListener::CODE_REQUIRED, $event->getErrors()['code']);
		self::assertSame('summary', $event->getErrors()['field']);
		self::assertSame('published', $event->getErrors()['state']);
	}//end testPublishingWithoutSummaryIsRefusedByName()

	/**
	 * Design D2: a legacy published publication that the upgrade re-saves through RequiredToPublish::recordGaps()
	 * passes both OpenRegister checks (the not-supplied record and the state rule); without it the same save is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-required-at-publish/design.md#d2-existing-publications
	 */
	public function testALegacyPublicationTheUpgradeResavesIsAccepted(): void {
		$legacy = ['title' => 'Besluit 2019', 'status' => 'published', 'summary' => '', 'audience' => 'public'];
		$saved = RequiredToPublish::recordGaps(publication: $legacy);

		self::assertSame([], (new NotSuppliedHandler())->validate($saved, self::schemaEntity()));

		$accepted = new ObjectUpdatingEvent(self::publication($saved), self::publication($legacy));
		$this->listener()->handle($accepted);
		self::assertFalse($accepted->isPropagationStopped(), json_encode($accepted->getErrors()));

		$refused = new ObjectUpdatingEvent(self::publication($legacy), self::publication($legacy));
		$this->listener()->handle($refused);
		self::assertTrue($refused->isPropagationStopped());
	}//end testALegacyPublicationTheUpgradeResavesIsAccepted()
}//end class

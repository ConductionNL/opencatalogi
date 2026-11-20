<?php

/**
 * A read of a Woo assessment writes an attributed processing log entry.
 *
 * @category Test
 * @package  Unit\Service
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

namespace Unit\Service;

use OCA\OpenCatalogi\Repair\SeedWooProcessingActivity;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ProcessingLogMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;
use OCA\OpenRegister\Service\ProcessingLogService;
use OCA\OpenRegister\Service\ProcessingRead;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Unit\Settings\WooReadLoggingDeclarationTest;

require_once __DIR__.'/../Settings/WooReadLoggingDeclarationTest.php';

/**
 * OpenRegister's real ProcessingLogService over the wooAssessment schema as the app imports it (REQ-WAB-001).
 */
#[CoversNothing]
class WooReadLoggingTest extends TestCase {

	/**
	 * An officer's read of an assessment is logged for that object, the officer and the Woo activity.
	 *
	 * @return void
	 */
	public function testAReadOfAnAssessmentWritesAnAttributedEntry(): void {
		if (class_exists(ProcessingLogService::class) === false) {
			$this->fail('OpenRegister ProcessingLogService is not on the autoloader; check out openregister beside this app');
		}

		$configuration = WooReadLoggingDeclarationTest::mergedRegister()['components']['schemas']['wooAssessment']['configuration'];
		$schema = new Schema();
		$schema->setConfiguration($configuration);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn(new Register());
		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('schemas')->willReturn($schemas);
		$catalog->method('registers')->willReturn($registers);

		$activity = new Verwerkingsactiviteit();
		$activity->setUuid('act-woo');
		$activity->setCode(SeedWooProcessingActivity::CODE);
		$activities = $this->createMock(VerwerkingsactiviteitMapperInterface::class);
		$activities->method('resolveReference')->willReturnCallback(
			static fn (string $reference): ?Verwerkingsactiviteit => ($reference === SeedWooProcessingActivity::CODE ? $activity : null)
		);
		$activities->expects($this->never())->method('insert');

		$written = [];
		$log = $this->createMock(ProcessingLogMapperInterface::class);
		$log->method('insertBatch')->willReturnCallback(
			static function (array $entries) use (&$written): int {
				$written = $entries;
				return count($entries);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$service = new ProcessingLogService($log, $activities, $catalog, $session, $this->createMock(LoggerInterface::class));

		$assessment = new ObjectEntity();
		$assessment->setUuid('assessment-1');
		$assessment->setSchema('17');
		$assessment->setRegister('3');
		$service->recordRead(object: $assessment, read: new ProcessingRead(action: 'read', channel: 'api'));

		$this->assertSame(1, $service->flush());
		$this->assertCount(1, $written);
		$this->assertSame('assessment-1', $written[0]->getObjectUuid());
		$this->assertSame('act-woo', $written[0]->getActivityId());
		$this->assertSame('officer', $written[0]->getActor());

	}//end testAReadOfAnAssessmentWritesAnAttributedEntry()
}//end class

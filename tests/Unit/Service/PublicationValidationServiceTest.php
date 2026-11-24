<?php

/**
 * A Woo publication stays public (REQ-PSR-004).
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
 * @spec openspec/changes/publications-for-signed-in-readers/tasks.md#1-the-schema-and-the-rule
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Listener\PublicationAudienceCheck;
use OCA\OpenCatalogi\Service\Publication\PublicationValidationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationValidationService
 * @covers \OCA\OpenCatalogi\Listener\PublicationAudienceCheck
 */
final class PublicationValidationServiceTest extends TestCase {

	/**
	 * The listener, with a translator that marks what it translated.
	 *
	 * @return PublicationAudienceCheck
	 */
	private function listener(): PublicationAudienceCheck {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'NL: ' . $text);
		return new PublicationAudienceCheck(validation: new PublicationValidationService(), l10n: $l10n);
	}//end listener()

	/**
	 * An object entity carrying the data.
	 *
	 * @param array<string, mixed> $data The publication.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * signedIn with a Woo category is refused with the spec's message, on audience.
	 *
	 * @return void
	 */
	public function testASignedInPublicationWithAWooCategoryIsRefused(): void {
		$refusal = (new PublicationValidationService())->refusal(
			publication: ['title' => 'Besluit', 'audience' => 'signedIn', 'wooCategory' => 'infocat008']
		);

		self::assertSame(
			[
				'message' => 'A Woo publication is public by law. Remove the Woo category or make it readable for everyone.',
				'property' => 'audience',
			],
			$refusal
		);
	}//end testASignedInPublicationWithAWooCategoryIsRefused()

	/**
	 * A public Woo publication, and a signedIn one without a Woo category, are accepted.
	 *
	 * @return void
	 */
	public function testEveryOtherCombinationIsAccepted(): void {
		$service = new PublicationValidationService();

		self::assertNull($service->refusal(publication: ['audience' => 'public', 'wooCategory' => 'infocat008']));
		self::assertNull($service->refusal(publication: ['wooCategory' => 'infocat008']));
		self::assertNull($service->refusal(publication: ['audience' => 'signedIn']));
		self::assertNull($service->refusal(publication: ['audience' => 'signedIn', 'wooCategory' => '  ']));
		self::assertNull($service->refusal(publication: ['audience' => 'signedIn', 'wooCategory' => null]));
	}//end testEveryOtherCombinationIsAccepted()

	/**
	 * The listener stops an update that limits a Woo publication to signed-in readers.
	 *
	 * @return void
	 */
	public function testTheSaveIsStoppedWithTheTranslatedMessage(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['title' => 'Besluit', 'audience' => 'signedIn', 'wooCategory' => 'infocat008']),
			$this->entity(['title' => 'Besluit', 'audience' => 'public', 'wooCategory' => 'infocat008'])
		);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('audience', $event->getErrors()['property']);
		self::assertSame('NL: ' . PublicationValidationService::WOO_STAYS_PUBLIC, $event->getErrors()['message']);
	}//end testTheSaveIsStoppedWithTheTranslatedMessage()

	/**
	 * A refusal the listener has no literal for is still translated.
	 *
	 * @return void
	 */
	public function testAnotherRefusalIsStillTranslated(): void {
		$validation = $this->createMock(PublicationValidationService::class);
		$validation->method('refusal')->willReturn(['message' => 'Another rule.', 'property' => 'title']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'NL: ' . $text);
		$event = new ObjectCreatingEvent($this->entity(['title' => 'Besluit']));

		(new PublicationAudienceCheck(validation: $validation, l10n: $l10n))->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message' => 'NL: Another rule.', 'property' => 'title'], $event->getErrors());
	}//end testAnotherRefusalIsStillTranslated()

	/**
	 * A create of a signedIn publication without a Woo category goes through untouched.
	 *
	 * @return void
	 */
	public function testAnAcceptedCreateIsNotStopped(): void {
		$event = new ObjectCreatingEvent($this->entity(['title' => 'Wijkplan', 'audience' => 'signedIn']));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());
	}//end testAnAcceptedCreateIsNotStopped()
}//end class

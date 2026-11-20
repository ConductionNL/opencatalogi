<?php

/**
 * Wiring contract of the capability rights on the controllers.
 *
 * @category Test
 * @package  Unit\Controller
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

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\CapabilityGuard;
use OCA\OpenCatalogi\Controller\CommentPeriodController;
use OCA\OpenCatalogi\Controller\DepublicationController;
use OCA\OpenCatalogi\Controller\InspectionController;
use OCA\OpenCatalogi\Controller\PublicationDisclosureController;
use OCA\OpenCatalogi\Controller\RetentionController;
use OCA\OpenCatalogi\Controller\WooController;
use OCA\OpenCatalogi\Controller\WooRegistrationController;
use OCA\OpenCatalogi\Service\CapabilityRights;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every gated method checks its verb first and carries no admin setting (REQ-WAB-004).
 */
#[CoversNothing]
class CapabilityRightsWiringTest extends TestCase {

	/**
	 * The gated methods and their verbs, as the spec lists them.
	 */
	private const GATED = [
		[InspectionController::class, 'open', 'openInspection'],
		[CommentPeriodController::class, 'open', 'openCommentPeriod'],
		[DepublicationController::class, 'depublish', 'depublish'],
		[DepublicationController::class, 'acknowledgeWithdrawal', 'depublish'],
		[PublicationDisclosureController::class, 'announce', 'announceNationally'],
		[RetentionController::class, 'decide', 'decideRetention'],
		[WooController::class, 'createBatch', 'processWooBatch'],
		[WooController::class, 'getBatch', 'processWooBatch'],
		[WooController::class, 'updateAssessment', 'processWooBatch'],
		[WooController::class, 'markReadyForReview', 'processWooBatch'],
		[WooController::class, 'inventarislijst', 'processWooBatch'],
		[WooController::class, 'publishBatch', 'publishWooBatch'],
		[WooRegistrationController::class, 'request', 'requestWooRegistration'],
		[WooRegistrationController::class, 'confirm', 'requestWooRegistration'],
	];

	/**
	 * Each listed method: no admin setting, a signed-in route, and the guard with its verb as the first statement.
	 *
	 * @return void
	 */
	public function testEveryListedMethodChecksItsVerbAndHasNoAdminAttribute(): void {
		foreach (self::GATED as [$class, $name, $verb]) {
			$label  = $class.'::'.$name;
			$method = new ReflectionMethod($class, $name);

			$this->assertArrayHasKey($verb, CapabilityRights::VERBS, $label);
			$this->assertSame([], $method->getAttributes(AuthorizedAdminSetting::class), $label.' still carries AuthorizedAdminSetting');
			$this->assertCount(1, $method->getAttributes(NoAdminRequired::class), $label.' has no NoAdminRequired');
			$this->assertSame([], $method->getAttributes(PublicPage::class), $label.' is public');
			$this->assertContains(CapabilityGuard::class, (new ReflectionClass($class))->getTraitNames(), $label);

			$lines = file((string) $method->getFileName());
			$first = trim($lines[$method->getStartLine()]);
			$this->assertSame('$refusal = $this->capabilityRefusal(verb: \''.$verb.'\');', $first, $label.' does not check '.$verb.' first');
			$this->assertSame('if ($refusal !== null) {', trim($lines[($method->getStartLine() + 1)]), $label);
			$this->assertSame('return $refusal;', trim($lines[($method->getStartLine() + 2)]), $label);
		}

	}//end testEveryListedMethodChecksItsVerbAndHasNoAdminAttribute()

	/**
	 * The guard asks CapabilityRights::may() with the verb and the session user.
	 *
	 * @return void
	 */
	public function testTheGuardAsksMayWithTheVerb(): void {
		$guard  = new ReflectionMethod(CapabilityGuard::class, 'capabilityRefusal');
		$lines  = file((string) $guard->getFileName());
		$body   = implode('', array_slice($lines, $guard->getStartLine(), ($guard->getEndLine() - $guard->getStartLine())));

		$this->assertStringContainsString('$this->capabilities->may(verb: $verb, userId: $userId) === true', $body);
		$this->assertStringContainsString('Http::STATUS_FORBIDDEN', $body);

	}//end testTheGuardAsksMayWithTheVerb()
}//end class

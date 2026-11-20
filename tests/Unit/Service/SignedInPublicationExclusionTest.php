<?php

/**
 * Every public surface leaves a publication for signed-in readers out (REQ-PSR-002).
 *
 * The public surfaces do not filter on `audience` themselves: they read through
 * OpenRegister with `_rbac: true`, so the `group: public` read rule of the
 * publication schema is the filter, and search and the public detail apply
 * `isObjectPublic()` on top. This test pins all three: the shipped rule admits
 * only `audience: public`, `isObjectPublic()` refuses a signedIn publication
 * that is otherwise live, and each surface reads its publications with
 * `_rbac: true` and never with `_rbac: false`, so none of them goes around the
 * rule.
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

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenCatalogi\Service\PublicationQueryService
 */
final class SignedInPublicationExclusionTest extends TestCase {

	/**
	 * A publication whose window is open: published yesterday, never depublished.
	 *
	 * @param array<string, mixed> $extra Fields to add.
	 *
	 * @return array<string, mixed>
	 */
	private static function livePublication(array $extra=[]): array {
		return $extra + [
			'id' => '0b6e7c8e-4d0f-4c55-9a64-3c1f1d2b9a10',
			'title' => 'Wijkplan Noord',
			'publicationDate' => gmdate('c', (time() - 86400)),
		];
	}//end livePublication()

	/**
	 * The publication query service, with nothing behind it.
	 *
	 * @return PublicationQueryService
	 */
	private function queryService(): PublicationQueryService {
		return new PublicationQueryService(container: $this->createMock(ContainerInterface::class));
	}//end queryService()

	/**
	 * The public API and search: a live signedIn publication is not public.
	 *
	 * @return void
	 */
	public function testTheDirectReadAndSearchTreatASignedInPublicationAsNotPublic(): void {
		$service = $this->queryService();

		self::assertFalse($service->isObjectPublic(objectData: self::livePublication(['audience' => 'signedIn'])));
		self::assertTrue($service->isObjectPublic(objectData: self::livePublication(['audience' => 'public'])));
	}//end testTheDirectReadAndSearchTreatASignedInPublicationAsNotPublic()

	/**
	 * An object stored before the migration has no audience and keeps the schema default, public.
	 *
	 * @return void
	 */
	public function testAPublicationWithoutAudienceKeepsTheDefault(): void {
		self::assertTrue($this->queryService()->isObjectPublic(objectData: self::livePublication()));
	}//end testAPublicationWithoutAudienceKeepsTheDefault()

	/**
	 * Any audience other than public, also one the schema does not know, is not public.
	 *
	 * @return void
	 */
	public function testAnUnknownAudienceIsNotPublic(): void {
		self::assertFalse($this->queryService()->isObjectPublic(objectData: self::livePublication(['audience' => 'staff'])));
	}//end testAnUnknownAudienceIsNotPublic()

	/**
	 * The shipped publication schema's public read rules each require audience public.
	 *
	 * @return void
	 */
	public function testThePublicReadRuleRequiresAudiencePublic(): void {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($root . '/publication_register.json'), true, flags: JSON_THROW_ON_ERROR);
		$merged = (new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments'))->invoke(null, $base, $root . '/register.d');

		$rules = $merged['components']['schemas']['publication']['authorization']['read'];
		$public = array_filter($rules, static fn (mixed $rule): bool => is_array($rule) === true && ($rule['group'] ?? null) === 'public');
		self::assertCount(2, $public);
		foreach ($public as $rule) {
			self::assertSame('public', $rule['match']['audience']);
		}
	}//end testThePublicReadRuleRequiresAudiencePublic()

	/**
	 * The surfaces that read publications through the public rule.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function surfaces(): array {
		return [
			'sitemap' => ['lib/Service/SitemapService.php'],
			'DCAT' => ['lib/Service/DcatService.php'],
			'OOAPI' => ['lib/Service/OoapiService.php'],
			'federation' => ['lib/Service/PublicationService.php'],
		];
	}//end surfaces()

	/**
	 * Each surface reads with `_rbac: true` and never switches RBAC off, so the
	 * public rule, and with it the audience check, applies to every row it serves.
	 *
	 * @param string $file The surface's source file, from the app root.
	 *
	 * @return void
	 */
	#[DataProvider('surfaces')]
	public function testTheSurfaceReadsThroughThePublicRule(string $file): void {
		$source = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
		$code = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

		self::assertDoesNotMatchRegularExpression('/_rbac:\s*false/', $code, $file . ' reads past the public rule');
		if (str_contains($code, 'searchObjects') === true) {
			self::assertMatchesRegularExpression('/_rbac:\s*true/', $code, $file);
		}
	}//end testTheSurfaceReadsThroughThePublicRule()
}//end class

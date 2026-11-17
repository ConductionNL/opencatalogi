<?php

/**
 * Unit tests for RedactedOriginalGuard.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/scanned-attachments-become-searchable/tasks.md#1.4
 */

declare(strict_types=1);

namespace Unit\Service\AttachmentReading;

use OCA\OpenCatalogi\Service\AttachmentReading\RedactedOriginalGuard;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An object service that answers assessment searches with fixed rows.
 */
class GuardFakeObjects {

	/** @var list<array<string, mixed>> */
	public array $rows = [];

	/** @var list<array<string, mixed>> */
	public array $queries = [];

	public bool $fails = false;

	public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		if ($this->fails === true) {
			throw new RuntimeException('search down');
		}

		$this->queries[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		return ['results' => $this->rows];
	}//end searchObjectsPaginated()
}//end class

/**
 * Which file the Woo redaction path marks as the original of a redacted document.
 *
 * @covers \OCA\OpenCatalogi\Service\AttachmentReading\RedactedOriginalGuard
 */
class RedactedOriginalGuardTest extends TestCase {

	private GuardFakeObjects $objects;

	/** @var array<string, string> */
	private array $config = ['woo_register' => 'woo', 'woo_assessment_schema' => 'wooAssessment'];

	private bool $installed = true;

	protected function setUp(): void {
		$this->objects = new GuardFakeObjects();
	}//end setUp()

	private function guard(): RedactedOriginalGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$lookup = $this->createMock(OpenRegisterLookup::class);
		$lookup->method('isInstalled')->willReturnCallback(fn (): bool => $this->installed);
		$lookup->method('objectService')->willReturn($this->objects);
		return new RedactedOriginalGuard(config: $config, openRegister: $lookup);
	}//end guard()

	public function testAFileWithAVerifiedRedactionIsARedactedOriginal(): void {
		$this->objects->rows = [['documentReference' => '42', 'anonymizedDocument' => '43', 'redactionStatus' => 'verified']];

		$this->assertTrue($this->guard()->isRedactedOriginal(fileId: 42));
		$this->assertSame(
			[['query' => ['@self' => ['register' => 'woo', 'schema' => 'wooAssessment'], 'documentReference' => '42', '_limit' => 50], 'rbac' => false, 'multitenancy' => false]],
			$this->objects->queries
		);
	}//end testAFileWithAVerifiedRedactionIsARedactedOriginal()

	public function testAnAssessmentWithoutASeparateRedactedFileMarksNothing(): void {
		$this->objects->rows = [
			['documentReference' => '42', 'anonymizedDocument' => ''],
			['documentReference' => '42', 'anonymizedDocument' => '42'],
			['documentReference' => '420', 'anonymizedDocument' => '43'],
		];

		$this->assertFalse($this->guard()->isRedactedOriginal(fileId: 42));
	}//end testAnAssessmentWithoutASeparateRedactedFileMarksNothing()

	public function testWithoutAWooRegisterNothingIsAnOriginal(): void {
		$this->config = [];

		$this->assertFalse($this->guard()->isRedactedOriginal(fileId: 42));
		$this->assertSame([], $this->objects->queries);
	}//end testWithoutAWooRegisterNothingIsAnOriginal()

	public function testALookupThatCannotRunIsUnknown(): void {
		$this->objects->fails = true;
		$this->assertNull($this->guard()->isRedactedOriginal(fileId: 42));

		$this->objects->fails = false;
		$this->installed = false;
		$this->assertNull($this->guard()->isRedactedOriginal(fileId: 42));
	}//end testALookupThatCannotRunIsUnknown()
}//end class

<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which addresses a document reference may point at (REQ-PBR-001). Only
 * literal addresses and local names are used, so no test resolves DNS.
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-an-editor-adds-a-document-by-its-public-url-req-pbr-001
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DocumentReferenceUrlPolicy;
use OCA\OpenCatalogi\Service\Federation\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Service\DocumentReferenceUrlPolicy
 * @uses   \OCA\OpenCatalogi\Service\Federation\OutboundUrlGuard
 */
class DocumentReferenceUrlPolicyTest extends TestCase {

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function privateAddresses(): array {
		return [
			'private ten' => ['https://10.0.0.12/besluit.pdf'],
			'private 192.168' => ['https://192.168.1.5/besluit.pdf'],
			'loopback' => ['https://127.0.0.1/besluit.pdf'],
			'link-local metadata' => ['https://169.254.169.254/latest'],
			'ipv6 loopback' => ['https://[::1]/besluit.pdf'],
			'localhost' => ['https://localhost/besluit.pdf'],
			'a .local name' => ['https://dms.local/besluit.pdf'],
		];
	}//end privateAddresses()

	/**
	 * @dataProvider privateAddresses
	 */
	public function testAPrivateAddressIsRefusedAsNotPublic(string $url): void {
		$this->assertSame(DocumentReferenceUrlPolicy::NOT_PUBLIC, (new DocumentReferenceUrlPolicy())->refusal(url: $url));
	}//end testAPrivateAddressIsRefusedAsNotPublic()

	public function testAPublicAddressIsAllowed(): void {
		$this->assertNull((new DocumentReferenceUrlPolicy())->refusal(url: 'https://93.184.215.14/besluit.pdf'));
	}//end testAPublicAddressIsAllowed()

	public function testOnlyHttpsIsAllowed(): void {
		$policy = new DocumentReferenceUrlPolicy();

		$this->assertSame(DocumentReferenceUrlPolicy::NOT_HTTPS, $policy->refusal(url: 'http://93.184.215.14/besluit.pdf'));
		$this->assertSame(DocumentReferenceUrlPolicy::NOT_HTTPS, $policy->refusal(url: 'ftp://93.184.215.14/besluit.pdf'));
		$this->assertSame(DocumentReferenceUrlPolicy::NOT_HTTPS, $policy->refusal(url: ''));
		$this->assertSame(DocumentReferenceUrlPolicy::NOT_HTTPS, $policy->refusal(url: 'https://'));
	}//end testOnlyHttpsIsAllowed()

	public function testANameThatDoesNotResolveIsNotShownToBePrivate(): void {
		$guard = $this->getMockBuilder(OutboundUrlGuard::class)->onlyMethods(['addressesOf'])->getMock();
		$guard->method('addressesOf')->willThrowException(new \InvalidArgumentException('Document URL host could not be resolved'));

		$this->assertNull((new DocumentReferenceUrlPolicy(guard: $guard))->refusal(url: 'https://documenten.voorbeeldgemeente.nl/a.pdf'));
	}//end testANameThatDoesNotResolveIsNotShownToBePrivate()

	public function testANameThatResolvesToAPrivateAddressIsRefused(): void {
		$guard = $this->getMockBuilder(OutboundUrlGuard::class)->onlyMethods(['addressesOf'])->getMock();
		$guard->method('addressesOf')->willReturn(['93.184.215.14', '10.1.2.3']);

		$this->assertSame(DocumentReferenceUrlPolicy::NOT_PUBLIC, (new DocumentReferenceUrlPolicy(guard: $guard))->refusal(url: 'https://dms.voorbeeldgemeente.nl/a.pdf'));
	}//end testANameThatResolvesToAPrivateAddressIsRefused()
}//end class

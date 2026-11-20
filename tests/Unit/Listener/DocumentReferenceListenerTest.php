<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A reference to an address that is not public is refused before the write,
 * with the reason the form shows (REQ-PBR-001, scenario "A private address").
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-an-editor-adds-a-document-by-its-public-url-req-pbr-001
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\DocumentReferenceListener;
use OCA\OpenCatalogi\Service\DocumentReferenceUrlPolicy;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Listener\DocumentReferenceListener
 * @uses   \OCA\OpenCatalogi\Service\DocumentReferenceUrlPolicy
 * @uses   \OCA\OpenCatalogi\Service\Federation\OutboundUrlGuard
 */
class DocumentReferenceListenerTest extends TestCase {

	private const PRIVATE_URL = 'https://10.0.0.12/besluit.pdf';

	private const PUBLIC_URL = 'https://93.184.215.14/besluit.pdf';

	/**
	 * The listener, with or without a signed-in user.
	 *
	 * @param bool $signedIn Whether someone is signed in.
	 *
	 * @return DocumentReferenceListener The listener.
	 */
	private function listener(bool $signedIn = true): DocumentReferenceListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['document_reference_schema' => '91'][$key] ?? $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new DocumentReferenceListener(policy: new DocumentReferenceUrlPolicy(), config: $config, userSession: $session);
	}//end listener()

	/**
	 * A reference entity.
	 *
	 * @param string $url Its URL.
	 * @param string $schema Its schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function reference(string $url, string $schema = '91'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister('19');
		$entity->setSchema($schema);
		$entity->setObject(['publication' => 'pub-1', 'url' => $url, 'title' => 'Besluit', 'format' => 'pdf']);
		return $entity;
	}//end reference()

	public function testAPrivateAddressIsRefusedWithTheReason(): void {
		$event = new ObjectCreatingEvent($this->reference(url: self::PRIVATE_URL));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['message' => DocumentReferenceUrlPolicy::NOT_PUBLIC, 'field' => 'url'], $event->getErrors());
	}//end testAPrivateAddressIsRefusedWithTheReason()

	public function testAPublicAddressIsSaved(): void {
		$event = new ObjectCreatingEvent($this->reference(url: self::PUBLIC_URL));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAPublicAddressIsSaved()

	public function testAnUpdateToAPrivateAddressIsRefused(): void {
		$event = new ObjectUpdatingEvent($this->reference(url: self::PRIVATE_URL), $this->reference(url: self::PUBLIC_URL));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testAnUpdateToAPrivateAddressIsRefused()

	public function testAnUpdateThatKeepsTheUrlPasses(): void {
		$event = new ObjectUpdatingEvent($this->reference(url: 'http://old.example/a.pdf'), $this->reference(url: 'http://old.example/a.pdf'));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnUpdateThatKeepsTheUrlPasses()

	public function testAWriteWithoutASessionIsTheSystemsOwn(): void {
		$event = new ObjectCreatingEvent($this->reference(url: self::PRIVATE_URL));

		$this->listener(signedIn: false)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAWriteWithoutASessionIsTheSystemsOwn()

	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->reference(url: self::PRIVATE_URL, schema: '172'));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsLeftAlone()
}//end class

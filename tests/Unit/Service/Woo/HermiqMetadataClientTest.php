<?php

/**
 * Unit tests for HermiqMetadataClient: Hermiq is asked only when it is
 * installed and enabled, and any failure answers "unavailable"
 * (woo-metadata-suggestions REQ-WMS-002).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Service\Woo\HermiqMetadataClient;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for HermiqMetadataClient.
 */
#[CoversClass(HermiqMetadataClient::class)]
final class HermiqMetadataClientTest extends TestCase {

	/**
	 * The client over an installed or absent Hermiq and the given engine.
	 *
	 * @param bool $installed Whether Hermiq is installed.
	 * @param object|\Throwable|null $engine The engine the container gives, or what it throws.
	 *
	 * @return HermiqMetadataClient
	 */
	private function client(bool $installed, object|null $engine): HermiqMetadataClient {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $app === 'hermiq' && $installed);
		$container = $this->createMock(ContainerInterface::class);
		if ($engine instanceof \Throwable) {
			$container->method('get')->willThrowException($engine);
		} else {
			$container->method('get')->with(HermiqMetadataClient::ENGINE)->willReturn($engine);
		}

		return new HermiqMetadataClient(appManager: $apps, container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end client()

	/**
	 * An engine double answering the given availability and fill result.
	 *
	 * @param bool $available What isAvailable() answers.
	 * @param array<string, mixed>|\Throwable $answer What fill() answers or throws.
	 *
	 * @return object The engine.
	 */
	private function engine(bool $available, array|\Throwable $answer): object {
		return new class($available, $answer) {
			/** @var array<int, array<int, mixed>> */
			public array $calls = [];

			public function __construct(private bool $available, private array|\Throwable $answer) {
			}

			public function isAvailable(): bool {
				return $this->available;
			}

			public function fill(string $text, array $fields, string $language, string $userId): array {
				$this->calls[] = [$text, $fields, $language, $userId];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end engine()

	public function testHermiqProposesValuesWhenInstalledAndEnabled(): void {
		$engine = $this->engine(available: true, answer: ['available' => true, 'values' => ['wooCategory' => 'Wetten'], 'draft' => true]);
		$client = $this->client(installed: true, engine: $engine);
		$fields = [['key' => 'wooCategory', 'label' => 'Category', 'type' => 'select', 'options' => ['Wetten']]];

		$this->assertTrue($client->isAvailable());
		$this->assertSame(['wooCategory' => 'Wetten'], $client->propose(text: 'tekst', fields: $fields, userId: 'editor'));
		$this->assertSame([['tekst', $fields, 'nl', 'editor']], $engine->calls);
	}

	public function testAnAbsentHermiqIsNeverAsked(): void {
		$engine = $this->engine(available: true, answer: ['available' => true, 'values' => []]);
		$client = $this->client(installed: false, engine: $engine);

		$this->assertFalse($client->isAvailable());
		$this->assertNull($client->propose(text: 't', fields: [], userId: 'editor'));
		$this->assertSame([], $engine->calls);
	}

	public function testADisabledOrUnresolvableEngineIsUnavailable(): void {
		$this->assertFalse($this->client(installed: true, engine: $this->engine(available: false, answer: []))->isAvailable());
		$this->assertFalse($this->client(installed: true, engine: new \RuntimeException('no such service'))->isAvailable());
		$this->assertNull($this->client(installed: true, engine: new \RuntimeException('no such service'))->propose(text: 't', fields: [], userId: 'u'));
	}

	public function testADeclinedOrFailingFillAnswersNull(): void {
		$declined = $this->client(installed: true, engine: $this->engine(available: true, answer: ['available' => false, 'reason' => 'feature-not-enabled']));
		$failing = $this->client(installed: true, engine: $this->engine(available: true, answer: new \RuntimeException('provider down')));
		$shapeless = $this->client(installed: true, engine: $this->engine(available: true, answer: ['available' => true]));

		$this->assertNull($declined->propose(text: 't', fields: [], userId: 'u'));
		$this->assertNull($failing->propose(text: 't', fields: [], userId: 'u'));
		$this->assertNull($shapeless->propose(text: 't', fields: [], userId: 'u'));
	}
}//end class

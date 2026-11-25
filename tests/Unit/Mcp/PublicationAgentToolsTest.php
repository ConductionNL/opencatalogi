<?php

/**
 * The governed draft tool and its allow-list (hermiq-ai-tooling).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace Unit\Mcp;

use OCA\OpenCatalogi\Mcp\McpArgumentValidator;
use OCA\OpenCatalogi\Mcp\PublicationAgentTools;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * createDraftPublication() saves only the five descriptive fields, through
 * OpenRegister's save with RBAC and multitenancy on, and refuses every field
 * that would publish, withdraw, change the state or set retention.
 */
#[CoversClass(PublicationAgentTools::class)]
#[CoversClass(McpArgumentValidator::class)]
class PublicationAgentToolsTest extends TestCase {

	/**
	 * The object service double: records each save and answers $answer, or throws $failure.
	 *
	 * @var object
	 */
	private object $objects;

	protected function setUp(): void {
		$this->objects = new class {
			/** @var list<array<string, mixed>> */
			public array $saves = [];
			public mixed $answer = null;
			public ?\Throwable $failure = null;

			public function saveObject(array $object, object $options, bool $_rbac, bool $_multitenancy): mixed {
				$this->saves[] = ['object' => $object, 'options' => $options, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($this->failure !== null) {
					throw $this->failure;
				}

				return $this->answer;
			}
		};
	}

	/**
	 * The tools with the object service double and the given register and schema.
	 */
	private function tools(string $register = 'publication', string $schema = 'publication'): PublicationAgentTools {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objects);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => match ($key) {
				'publication_register' => $register,
				'publication_schema' => $schema,
				default => '',
			}
		);

		return new PublicationAgentTools(container: $container, config: $config, validator: new McpArgumentValidator());
	}

	/**
	 * A draft saves its trimmed descriptive fields as the caller, with RBAC and multitenancy on.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-runs-the-existing-rbacd-path-as-the-granting-user-req-008
	 */
	public function testADraftSavesOnlyItsFieldsThroughTheRbacPath(): void {
		$this->objects->answer = [
			'@self' => ['id' => 'p-1'],
			'title' => 'Afvalinzameling 2027',
			'summary' => 'Kort',
			'themes' => ['t-1'],
			'status' => 'draft',
		];

		$answer = $this->tools()->createDraftPublication(publication: [
			'title' => ' Afvalinzameling 2027 ',
			'summary' => 'Kort',
			'description' => '',
			'themes' => ['t-1', 't-1'],
		]);

		$this->assertSame([
			'success' => true,
			'publication' => ['id' => 'p-1', 'title' => 'Afvalinzameling 2027', 'summary' => 'Kort', 'themes' => ['t-1'], 'status' => 'draft'],
		], $answer);
		$this->assertCount(1, $this->objects->saves);
		$save = $this->objects->saves[0];
		$this->assertSame(['title' => 'Afvalinzameling 2027', 'summary' => 'Kort', 'themes' => ['t-1']], $save['object']);
		$this->assertTrue($save['rbac']);
		$this->assertTrue($save['multitenancy']);
		$this->assertSame('publication', $save['options']->target->register);
		$this->assertSame('publication', $save['options']->target->schema);
		$this->assertNull($save['options']->uuid);
		$this->assertArrayNotHasKey('publicationDate', $save['object']);
	}

	/**
	 * The fields that publish or govern a publication.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public static function forbiddenFields(): array {
		return [
			'publication date' => ['publicationDate', '2026-11-25T00:00:00Z'],
			'withdrawal date' => ['depublicationDate', '2027-01-01T00:00:00Z'],
			'status' => ['status', 'published'],
			'retention category' => ['retentionCategory', 'x'],
			'retention term' => ['retentionTermMonths', 12],
		];
	}

	/**
	 * A draft that names a publish, state or retention field is refused and nothing is saved.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	#[DataProvider('forbiddenFields')]
	public function testADraftCannotArrivePublished(string $field, mixed $value): void {
		$answer = $this->tools()->createDraftPublication(publication: ['title' => 'T', $field => $value]);

		$this->assertSame('invalid_arguments', $answer['error']['code'] ?? null);
		$this->assertStringContainsString('"' . $field . '"', $answer['error']['message']);
		$this->assertStringContainsString('cannot be set by this tool', $answer['error']['message']);
		$this->assertSame([], $this->objects->saves);
	}

	/**
	 * A field outside the allow-list is refused, and so are a missing title and a wrong type.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	public function testUnknownMissingAndMistypedFieldsAreRefused(): void {
		$tools = $this->tools();

		$unknown = $tools->createDraftPublication(publication: ['title' => 'T', 'unlisted' => true]);
		$this->assertSame('invalid_arguments', $unknown['error']['code']);
		$this->assertStringContainsString('"unlisted" is not accepted', $unknown['error']['message']);

		$this->assertSame('Field "title" is required.', $tools->createDraftPublication(publication: ['title' => '  '])['error']['message']);
		$this->assertSame('Field "title" must be text.', $tools->createDraftPublication(publication: ['title' => 5])['error']['message']);
		$this->assertSame(
			'Field "themes" must be a list of ids.',
			$tools->createDraftPublication(publication: ['title' => 'T', 'themes' => ['a' => 't-1']])['error']['message']
		);
		$this->assertSame([], $this->objects->saves);
	}

	/**
	 * A save OpenRegister refuses answers a fixed error; nothing from the exception reaches the agent.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-runs-the-existing-rbacd-path-as-the-granting-user-req-008
	 */
	public function testARefusedSaveAnswersAFixedError(): void {
		$this->objects->failure = new RuntimeException('secret internal detail');

		$answer = $this->tools()->createDraftPublication(publication: ['title' => 'T']);

		$this->assertSame('not_saved', $answer['error']['code']);
		$this->assertStringNotContainsString('secret', $answer['error']['message']);
	}

	/**
	 * Without a configured publication register nothing is saved; an empty answer is no success.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-runs-the-existing-rbacd-path-as-the-granting-user-req-008
	 */
	public function testAnUnconfiguredRegisterOrAnEmptyAnswerIsNoSuccess(): void {
		$this->assertSame('not_configured', $this->tools(register: '')->createDraftPublication(publication: ['title' => 'T'])['error']['code']);
		$this->assertSame([], $this->objects->saves);

		$this->objects->answer = ['title' => 'T'];
		$this->assertSame('not_saved', $this->tools()->createDraftPublication(publication: ['title' => 'T'])['error']['code']);
	}

	/**
	 * The draft tool declares scope create, reach instance, and is the only tool on the class.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	public function testTheDraftToolDeclaresItsScopeAndReach(): void {
		$tools = [];
		foreach ((new ReflectionClass(PublicationAgentTools::class))->getMethods() as $method) {
			foreach ($method->getAttributes(McpTool::class) as $attribute) {
				$tools[$method->getName()] = $attribute->newInstance();
			}
		}

		$this->assertSame(['createDraftPublication'], array_keys($tools));
		$tool = $tools['createDraftPublication'];
		$this->assertSame('createDraftPublication', $tool->name);
		$this->assertSame('create', $tool->scope);
		$this->assertSame('instance', $tool->reach);
		$this->assertFalse($tool->readOnlyHint);
		$this->assertStringContainsString('cannot publish', $tool->description);
		$this->assertSame('publication', (new ReflectionMethod(PublicationAgentTools::class, 'createDraftPublication'))->getParameters()[0]->getName());
	}
}

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
use OCA\OpenCatalogi\Contract\AgentPublishingInterface;
use OCA\OpenCatalogi\Contract\AgentWithdrawalInterface;
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
	 * The object service double: records each save and answers `answer`, or throws `failure`.
	 *
	 * @var RecordingObjects
	 */
	private RecordingObjects $objects;

	/**
	 * A fresh object service double per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new RecordingObjects();
	}//end setUp()

	/**
	 * The tools with the object service double and the given register and schema.
	 *
	 * @param string                        $configured The configured publication register and schema.
	 * @param AgentPublishingInterface|null $publishing The publishing service, if any.
	 * @param AgentWithdrawalInterface|null $withdrawal The staging service, if any.
	 *
	 * @return PublicationAgentTools The tools.
	 */
	private function tools(
		string $configured = 'publication',
		?AgentPublishingInterface $publishing = null,
		?AgentWithdrawalInterface $withdrawal = null
	): PublicationAgentTools {
		$config = $this->createConfiguredStub(
			originalClassName: IAppConfig::class,
			configuration: ['getValueString' => $configured]
		);

		return new PublicationAgentTools(
			container: new ObjectsContainer(objects: $this->objects),
			config: $config,
			validator: new McpArgumentValidator(),
			publishing: $publishing,
			withdrawal: $withdrawal
		);
	}//end tools()

	/**
	 * A draft saves its trimmed descriptive fields as the caller, with RBAC and multitenancy on.
	 *
	 * @return void
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

		$answer = $this->tools()->createDraftPublication(
			publication: [
				'title' => ' Afvalinzameling 2027 ',
				'summary' => 'Kort',
				'description' => '',
				'themes' => ['t-1', 't-1'],
			]
		);

		$this->assertSame(
			expected: [
				'success' => true,
				'publication' => ['id' => 'p-1', 'title' => 'Afvalinzameling 2027', 'summary' => 'Kort', 'themes' => ['t-1'], 'status' => 'draft'],
			],
			actual: $answer
		);
		$this->assertCount(expectedCount: 1, haystack: $this->objects->saves);
		$save = $this->objects->saves[0];
		$this->assertSame(expected: ['title' => 'Afvalinzameling 2027', 'summary' => 'Kort', 'themes' => ['t-1']], actual: $save['object']);
		$this->assertTrue(condition: $save['rbac']);
		$this->assertTrue(condition: $save['multitenancy']);
		$this->assertSame(expected: 'publication', actual: $save['options']->target->register);
		$this->assertSame(expected: 'publication', actual: $save['options']->target->schema);
		$this->assertNull(actual: $save['options']->uuid);
	}//end testADraftSavesOnlyItsFieldsThroughTheRbacPath()

	/**
	 * The fields that publish or govern a publication.
	 *
	 * @return array<string, array{string, mixed}> The field and a value for it.
	 */
	public static function forbiddenFields(): array {
		return [
			'publication date' => ['publicationDate', '2026-11-25T00:00:00Z'],
			'withdrawal date' => ['depublicationDate', '2027-01-01T00:00:00Z'],
			'status' => ['status', 'published'],
			'retention category' => ['retentionCategory', 'x'],
			'retention term' => ['retentionTermMonths', 12],
		];
	}//end forbiddenFields()

	/**
	 * A draft that names a publish, state or retention field is refused and nothing is saved.
	 *
	 * @param string $field The forbidden field.
	 * @param mixed  $value A value for it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	#[DataProvider('forbiddenFields')]
	public function testADraftCannotArrivePublished(string $field, mixed $value): void {
		$answer = $this->tools()->createDraftPublication(publication: ['title' => 'T', $field => $value]);

		$this->assertSame(expected: 'invalid_arguments', actual: $answer['error']['code'] ?? null);
		$this->assertStringContainsString(needle: '"' . $field . '"', haystack: $answer['error']['message']);
		$this->assertStringContainsString(needle: 'cannot be set by this tool', haystack: $answer['error']['message']);
		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testADraftCannotArrivePublished()

	/**
	 * A field outside the allow-list is refused, and so are a missing title and a wrong type.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	public function testUnknownMissingAndMistypedFieldsAreRefused(): void {
		$tools = $this->tools();

		$unknown = $tools->createDraftPublication(publication: ['title' => 'T', 'unlisted' => true]);
		$this->assertSame(expected: 'invalid_arguments', actual: $unknown['error']['code']);
		$this->assertStringContainsString(needle: '"unlisted" is not accepted', haystack: $unknown['error']['message']);

		$this->assertSame(
			expected: 'Field "title" is required.',
			actual: $tools->createDraftPublication(publication: ['title' => '  '])['error']['message']
		);
		$this->assertSame(
			expected: 'Field "title" must be text.',
			actual: $tools->createDraftPublication(publication: ['title' => 5])['error']['message']
		);
		$this->assertSame(
			expected: 'Field "themes" must be a list of ids.',
			actual: $tools->createDraftPublication(publication: ['title' => 'T', 'themes' => ['a' => 't-1']])['error']['message']
		);
		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testUnknownMissingAndMistypedFieldsAreRefused()

	/**
	 * A save OpenRegister refuses answers a fixed error; nothing from the exception reaches the agent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-runs-the-existing-rbacd-path-as-the-granting-user-req-008
	 */
	public function testARefusedSaveAnswersAFixedError(): void {
		$this->objects->failure = new RuntimeException(message: 'secret internal detail');

		$answer = $this->tools()->createDraftPublication(publication: ['title' => 'T']);

		$this->assertSame(expected: 'not_saved', actual: $answer['error']['code']);
		$this->assertStringNotContainsString(needle: 'secret', haystack: $answer['error']['message']);
	}//end testARefusedSaveAnswersAFixedError()

	/**
	 * Without a configured publication register nothing is saved; an answer without an id is no success.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-runs-the-existing-rbacd-path-as-the-granting-user-req-008
	 */
	public function testAnUnconfiguredRegisterOrAnEmptyAnswerIsNoSuccess(): void {
		$unconfigured = $this->tools(configured: '')->createDraftPublication(publication: ['title' => 'T']);
		$this->assertSame(expected: 'not_configured', actual: $unconfigured['error']['code']);
		$this->assertSame(expected: [], actual: $this->objects->saves);

		$this->objects->answer = ['title' => 'T'];
		$withoutId = $this->tools()->createDraftPublication(publication: ['title' => 'T']);
		$this->assertSame(expected: 'not_saved', actual: $withoutId['error']['code']);
	}//end testAnUnconfiguredRegisterOrAnEmptyAnswerIsNoSuccess()

	/**
	 * The draft tool declares scope create and reach instance, and is the only tool on the class.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	public function testTheDraftToolDeclaresItsScopeAndReach(): void {
		$tools = [];
		foreach ((new ReflectionClass(objectOrClass: PublicationAgentTools::class))->getMethods() as $method) {
			foreach ($method->getAttributes(name: McpTool::class) as $attribute) {
				$tools[$method->getName()] = $attribute->newInstance();
			}
		}

		$this->assertSame(
			expected: ['createDraftPublication', 'publishPublication', 'depublishPublication', 'archivePublication'],
			actual: array_keys($tools)
		);
		$tool = $tools['createDraftPublication'];
		$this->assertSame(expected: 'createDraftPublication', actual: $tool->name);
		$this->assertSame(expected: 'create', actual: $tool->scope);
		$this->assertSame(expected: 'instance', actual: $tool->reach);
		$this->assertFalse(condition: $tool->readOnlyHint);
		$this->assertStringContainsString(needle: 'cannot publish', haystack: $tool->description);
		$parameter = (new ReflectionMethod(objectOrMethod: PublicationAgentTools::class, method: 'createDraftPublication'))->getParameters()[0];
		$this->assertSame(expected: 'publication', actual: $parameter->getName());
	}//end testTheDraftToolDeclaresItsScopeAndReach()

	/**
	 * The publish tool declares update scope and external reach, and says the distribution cannot be undone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	public function testThePublishToolDeclaresExternalReach(): void {
		$method = new ReflectionMethod(objectOrMethod: PublicationAgentTools::class, method: 'publishPublication');
		$tool   = $method->getAttributes(name: McpTool::class)[0]->newInstance();

		$this->assertSame(expected: 'publishPublication', actual: $tool->name);
		$this->assertSame(expected: 'update', actual: $tool->scope);
		$this->assertSame(expected: 'external', actual: $tool->reach);
		$this->assertStringContainsString(needle: 'cannot be undone', haystack: $tool->description);
		$this->assertStringContainsString(needle: 'approved', haystack: $tool->description);
	}//end testThePublishToolDeclaresExternalReach()

	/**
	 * Bad ids are refused before anything is read; good ones are passed on deduplicated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testThePublishToolChecksItsIds(): void {
		$publishing = $this->createMock(originalClassName: AgentPublishingInterface::class);
		$publishing->expects($this->once())->method('publishApproved')->with(['a', 'b'])
			->willReturn(['published' => ['a'], 'refused' => [['id' => 'b', 'code' => 'not_approved']]]);
		$tools = $this->tools(publishing: $publishing);

		$tooMany = array_map(static fn (int $i): string => 'id-' . $i, range(1, AgentPublishingInterface::MAX_ITEMS + 1));
		foreach ([[], ['a' => 'x'], [''], [3], $tooMany] as $ids) {
			$this->assertSame(expected: 'invalid_arguments', actual: $tools->publishPublication(ids: $ids)['error']['code']);
		}

		$answer = $tools->publishPublication(ids: ['a', ' b ', 'a']);
		$this->assertSame(
			expected: ['success' => false, 'published' => ['a'], 'refused' => [['id' => 'b', 'code' => 'not_approved']]],
			actual: $answer
		);
	}//end testThePublishToolChecksItsIds()

	/**
	 * A caller who may not publish, an unconfigured register, or no publishing service is an error envelope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-runs-the-existing-rbacd-path-as-the-granting-user-req-008
	 */
	public function testThePublishToolAnswersAWholeRefusal(): void {
		foreach (['not_a_publisher', 'not_configured'] as $code) {
			$publishing = $this->createMock(originalClassName: AgentPublishingInterface::class);
			$publishing->method('publishApproved')->willReturn(['error' => $code]);
			$answer = $this->tools(publishing: $publishing)->publishPublication(ids: ['a']);
			$this->assertSame(expected: $code, actual: $answer['error']['code']);
			$this->assertNotSame(expected: '', actual: $answer['error']['message']);
		}

		$this->assertSame(expected: 'not_configured', actual: $this->tools()->publishPublication(ids: ['a'])['error']['code']);

		$all = $this->createMock(originalClassName: AgentPublishingInterface::class);
		$all->method('publishApproved')->willReturn(['published' => ['a'], 'refused' => []]);
		$this->assertTrue(condition: $this->tools(publishing: $all)->publishPublication(ids: ['a'])['success']);
	}//end testThePublishToolAnswersAWholeRefusal()

	/**
	 * Depublish and archive declare their reach, say they write nothing, and require a reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-allow-listed-properties-req-007
	 */
	public function testTheStagingToolsDeclareTheirReachAndWriteNothing(): void {
		foreach (['depublishPublication' => 'external', 'archivePublication' => 'instance'] as $name => $reach) {
			$method = new ReflectionMethod(objectOrMethod: PublicationAgentTools::class, method: $name);
			$tool   = $method->getAttributes(name: McpTool::class)[0]->newInstance();

			$this->assertSame(expected: $name, actual: $tool->name);
			$this->assertSame(expected: 'update', actual: $tool->scope);
			$this->assertSame(expected: $reach, actual: $tool->reach);
			$this->assertStringContainsString(needle: 'does not', haystack: $tool->description);
			$this->assertStringContainsString(needle: 'publishers group', haystack: $tool->description);
			$this->assertSame(expected: ['ids', 'reason'], actual: array_map(static fn ($p) => $p->getName(), $method->getParameters()));
			$this->assertFalse(condition: $method->getParameters()[1]->isOptional());
		}
	}//end testTheStagingToolsDeclareTheirReachAndWriteNothing()

	/**
	 * Bad ids and a missing reason are refused before the service is asked; good ones go on deduplicated and trimmed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testTheStagingToolsCheckIdsAndReason(): void {
		$withdrawal = $this->createMock(originalClassName: AgentWithdrawalInterface::class);
		$withdrawal->expects($this->exactly(2))->method('stage')->willReturnCallback(
			static fn (string $action, array $ids, string $reason): array => [
				'staged'  => [['id' => $ids[0], 'task' => 'task-' . $action]],
				'refused' => [['id' => $ids[1], 'code' => 'not_public']],
			]
		);
		$tools = $this->tools(withdrawal: $withdrawal);

		foreach ([[], [''], [3]] as $ids) {
			$this->assertSame(expected: 'invalid_arguments', actual: $tools->depublishPublication(ids: $ids, reason: 'Rechterlijke uitspraak')['error']['code']);
		}

		foreach (['', '   ', str_repeat(string: 'x', times: 2001)] as $reason) {
			$this->assertSame(expected: 'invalid_arguments', actual: $tools->archivePublication(ids: ['a'], reason: $reason)['error']['code']);
		}

		$this->assertSame(
			expected: [
				'success' => false,
				'staged'  => [['id' => 'a', 'task' => 'task-depublish']],
				'refused' => [['id' => 'b', 'code' => 'not_public']],
			],
			actual: $tools->depublishPublication(ids: ['a', ' b ', 'a'], reason: ' Rechterlijke uitspraak ')
		);
		$this->assertSame(
			expected: [['id' => 'a', 'task' => 'task-archive']],
			actual: $tools->archivePublication(ids: ['a', 'b'], reason: 'Bewaartermijn verstreken')['staged']
		);
	}//end testTheStagingToolsCheckIdsAndReason()

	/**
	 * No staging service, no tasks or no register is an error envelope, and nothing is saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009
	 */
	public function testTheStagingToolsAnswerAWholeRefusal(): void {
		$this->assertSame(expected: 'not_configured', actual: $this->tools()->depublishPublication(ids: ['a'], reason: 'R')['error']['code']);

		foreach (['tasks_unavailable', 'not_configured'] as $code) {
			$withdrawal = $this->createMock(originalClassName: AgentWithdrawalInterface::class);
			$withdrawal->method('stage')->willReturn(['error' => $code]);
			$answer = $this->tools(withdrawal: $withdrawal)->archivePublication(ids: ['a'], reason: 'R');
			$this->assertSame(expected: $code, actual: $answer['error']['code']);
			$this->assertNotSame(expected: '', actual: $answer['error']['message']);
		}

		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testTheStagingToolsAnswerAWholeRefusal()
}//end class

/**
 * A container that holds only the object service double.
 */
class ObjectsContainer implements ContainerInterface {

	/**
	 * Build the container.
	 *
	 * @param RecordingObjects $objects The object service double.
	 */
	public function __construct(private readonly RecordingObjects $objects) {
	}//end __construct()

	/**
	 * The object service double.
	 *
	 * @param string $id The service id.
	 *
	 * @return mixed The double.
	 */
	public function get(string $id): mixed {
		if ($id !== 'OCA\OpenRegister\Service\ObjectService') {
			throw new RuntimeException(message: 'Unexpected service ' . $id);
		}

		return $this->objects;
	}//end get()

	/**
	 * Whether the container holds the id.
	 *
	 * @param string $id The service id.
	 *
	 * @return boolean Whether it is the object service.
	 */
	public function has(string $id): bool {
		return $id === 'OCA\OpenRegister\Service\ObjectService';
	}//end has()
}//end class

/**
 * An object service double that records each save.
 */
class RecordingObjects {

	/**
	 * The saves, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $saves = [];

	/**
	 * What a save answers.
	 *
	 * @var mixed
	 */
	public mixed $answer = null;

	/**
	 * What a save throws, when set.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $failure = null;

	/**
	 * Record a save and answer or throw.
	 *
	 * @param array<string, mixed> $object        The object.
	 * @param object               $options       The save options.
	 * @param boolean              $_rbac         Whether RBAC is on.
	 * @param boolean              $_multitenancy Whether multitenancy is on.
	 *
	 * @return mixed The answer.
	 */
	public function saveObject(array $object, object $options, bool $_rbac, bool $_multitenancy): mixed {
		$this->saves[] = ['object' => $object, 'options' => $options, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		if ($this->failure !== null) {
			throw $this->failure;
		}

		return $this->answer;
	}//end saveObject()
}//end class

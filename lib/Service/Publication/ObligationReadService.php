<?php

/**
 * OpenCatalogi Obligation Read Service.
 *
 * Reads every enabled `obligationSource` and asks each one for its publication
 * obligations through `ObligationsRequestedEvent`. What comes back goes to
 * `ObligationOverviewService::assemble()`, which already knows how to keep a
 * source that failed visible: a source whose listener threw, or that no
 * listener answered, is unread with its reason, never a source with nothing to
 * publish.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Event\ObligationsRequestedEvent;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;

/**
 * Asks every registered source for its obligations and assembles the overview.
 *
 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
 */
class ObligationReadService {

	/**
	 * The reason a source without a listener is unread.
	 *
	 * @var string
	 */
	public const NO_READER = 'No reader answered for this source.';

	/**
	 * How long one source may take before its answer is not trusted.
	 *
	 * @var integer
	 */
	public const READ_LIMIT_SECONDS = 5;

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 * @param ServiceCatalogueService $objects The OpenRegister reader that refuses rather than defaulting.
	 * @param IAppConfig $config App configuration.
	 * @param ObligationOverviewService $overview The assembler.
	 * @param string $appName The app name.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly ServiceCatalogueService $objects,
		private readonly IAppConfig $config,
		private readonly ObligationOverviewService $overview,
		private readonly string $appName = 'opencatalogi',
	) {

	}//end __construct()

	/**
	 * Read every enabled source and assemble the overview.
	 *
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The overview `ObligationOverviewService::assemble()` returns.
	 *
	 * @throws UnreadableRuleException When the source register or schema is not configured.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function read(?DateTimeInterface $now = null): array {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = DateTimeImmutable::createFromInterface($now);
		}

		[$register, $schema] = $this->sourceConfiguration();
		$objectService = $this->objects->getObjectService();

		$result = $objectService->searchObjectsPaginated(
			['@self' => ['register' => $register, 'schema' => $schema], '_limit' => 200],
			_rbac: false,
			_multitenancy: false
		);

		$sources = [];
		foreach (($result['results'] ?? []) as $row) {
			$source = $this->asArray(object: $row);
			if ($source !== []) {
				$sources[] = $source;
			}
		}

		$answers = [];
		foreach ($sources as $source) {
			if ((bool)($source['enabled'] ?? true) === false) {
				continue;
			}

			$appId = (string)($source['appId'] ?? '');
			$answers[$appId] = $this->ask(appId: $appId);

			if (is_array($answers[$appId]) === true) {
				$this->markRead(objectService: $objectService, source: $source, register: $register, schema: $schema, moment: $moment);
			}
		}

		return $this->overview->assemble(sources: $sources, obligationsBySource: $answers, now: $moment);

	}//end read()

	/**
	 * Ask one source for its obligations.
	 *
	 * @param string $appId The source.
	 *
	 * @return array<int, array<string, mixed>>|\Throwable The obligations, or why they could not be read.
	 */
	private function ask(string $appId): array|\Throwable {
		$event = new ObligationsRequestedEvent(appId: $appId);
		$started = microtime(true);

		try {
			$this->dispatcher->dispatchTyped($event);
		} catch (\Throwable $e) {
			return $e;
		}

		if ((microtime(true) - $started) > self::READ_LIMIT_SECONDS) {
			return new \RuntimeException('This source took longer than ' . self::READ_LIMIT_SECONDS . ' seconds to answer.');
		}

		if ($event->isAnswered() === false) {
			return new \RuntimeException(self::NO_READER);
		}

		return $event->getObligations();

	}//end ask()

	/**
	 * Record when a source was last read. A failed write leaves the overview
	 * standing: the read happened, only its moment was not stored.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param array<string, mixed> $source The source.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param DateTimeImmutable $moment The moment of the read.
	 *
	 * @return void
	 */
	private function markRead(object $objectService, array $source, string $register, string $schema, DateTimeImmutable $moment): void {
		$uuid = (string)($source['id'] ?? '');
		if ($uuid === '') {
			return;
		}

		$stored = $source;
		unset($stored['id'], $stored['@self']);
		$stored['lastReadAt'] = $moment->format(DATE_ATOM);

		try {
			$objectService->saveObject(
				$stored,
				[],
				$register,
				$schema,
				$uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return;
		}

	}//end markRead()

	/**
	 * The register and schema the sources live in.
	 *
	 * @return array{0: string, 1: string} The register and schema.
	 *
	 * @throws UnreadableRuleException When either is not configured.
	 */
	private function sourceConfiguration(): array {
		$register = trim($this->config->getValueString($this->appName, 'publication_register', ''));
		$schema = trim($this->config->getValueString($this->appName, 'obligation_source_schema', ''));

		if ($register === '' || $schema === '') {
			throw new UnreadableRuleException(
				'The obligation sources are not configured, so this app cannot say what must be published.'
			);
		}

		return [$register, $schema];

	}//end sourceConfiguration()

	/**
	 * Read an object's properties, whatever shape OpenRegister returned.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return [];
		}

		if (isset($object['id']) === false && isset($object['@self']['id']) === true) {
			$object['id'] = $object['@self']['id'];
		}

		return $object;

	}//end asArray()
}//end class

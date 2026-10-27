<?php

/**
 * OpenCatalogi Obligation Read Service.
 *
 * Asks every enabled obligation source what it must still publish and hands
 * the answers to ObligationOverviewService. A source is asked through
 * ObligationsRequestedEvent, which the app that owns it answers; a source
 * whose app does not answer, or whose reader throws, stays in the overview as
 * unread with the reason.
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

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Event\ObligationsRequestedEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Reads the registered sources and assembles the obligation overview.
 *
 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
 */
class ObligationReadService {

	/**
	 * The reason a source carries when no app answered for it.
	 */
	public const NO_READER = 'No reader answered for this source.';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher          $dispatcher The event dispatcher.
	 * @param ObligationOverviewService $overview   The overview assembler.
	 * @param IAppConfig                $config     App configuration.
	 * @param ContainerInterface        $container  The container, for OpenRegister's ObjectService.
	 * @param IAppManager               $appManager Tells whether OpenRegister is installed.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly ObligationOverviewService $overview,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
	) {

	}//end __construct()

	/**
	 * Read every enabled source and assemble the overview.
	 *
	 * Besides the assembled overview the answer carries `dueThisWeek`,
	 * `publishedThisMonth` and `bySource` (per source: outstanding, late and
	 * the next due date), and the obligations ordered late first, then by due
	 * date.
	 *
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The overview.
	 *
	 * @throws RuntimeException When the sources themselves cannot be read.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function read(?DateTimeInterface $now=null): array {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format(DateTimeInterface::ATOM));
		}

		$sources = $this->loadSources();
		$answers = [];
		foreach ($sources as $source) {
			if ((bool)($source['enabled'] ?? true) === false) {
				continue;
			}

			$appId = (string)($source['appId'] ?? '');
			$answers[$appId] = $this->ask(appId: $appId);
		}

		$overview = $this->overview->assemble(sources: $sources, obligationsBySource: $answers, now: $moment);

		return $this->summarise(overview: $overview, sources: $sources, now: $moment);

	}//end read()

	/**
	 * Ask one source for its obligations.
	 *
	 * @param string $appId The source's app id.
	 *
	 * @return array<int, array<string, mixed>>|\Throwable The obligations, or why there are none.
	 */
	private function ask(string $appId): array|\Throwable {
		try {
			$event = new ObligationsRequestedEvent(appId: $appId);
			$this->dispatcher->dispatchTyped($event);
		} catch (\Throwable $e) {
			return $e;
		}

		return ($event->getObligations() ?? new RuntimeException(self::NO_READER));

	}//end ask()

	/**
	 * Add the totals and the per-source rows the page shows.
	 *
	 * @param array<string, mixed>             $overview The assembled overview.
	 * @param array<int, array<string, mixed>> $sources  The registered sources.
	 * @param DateTimeImmutable                $now      The moment.
	 *
	 * @return array<string, mixed> The overview with its summaries.
	 */
	private function summarise(array $overview, array $sources, DateTimeImmutable $now): array {
		$weekEnd    = $now->add(new DateInterval('P7D'));
		$monthStart = $now->setDate((int)$now->format('Y'), (int)$now->format('m'), 1)->setTime(0, 0);

		$dueThisWeek = 0;
		$publishedThisMonth = 0;
		$bySource = $this->sourceRows(sources: $sources);
		foreach ($overview['obligations'] as $obligation) {
			$state = $obligation['state'];
			if ($state === 'published') {
				$publishedAt = $this->dateOf(value: $obligation['publishedAt'] ?? '');
				$publishedThisMonth += (int)($publishedAt !== null && $publishedAt >= $monthStart);
				continue;
			}

			$due = $this->dateOf(value: $obligation['dueDate'] ?? '');
			$dueThisWeek += (int)($state === 'due' && $due !== null && $due <= $weekEnd);
			$source = $obligation['source'];
			$bySource[$source] = $this->countInto(row: $bySource[$source], state: $state, due: $due);
		}

		$order = ['late' => 0, 'due' => 1, 'unknown' => 2, 'published' => 3];
		usort(
			$overview['obligations'],
			fn (array $a, array $b): int => [$order[$a['state']] ?? 4, (string)($a['dueDate'] ?? '')]
				<=> [$order[$b['state']] ?? 4, (string)($b['dueDate'] ?? '')]
		);

		$overview['dueThisWeek'] = $dueThisWeek;
		$overview['publishedThisMonth'] = $publishedThisMonth;
		$overview['bySource'] = array_values($bySource);

		return $overview;

	}//end summarise()

	/**
	 * An empty row per enabled source, keyed by app id.
	 *
	 * @param array<int, array<string, mixed>> $sources The registered sources.
	 *
	 * @return array<string, array<string, mixed>> The rows.
	 */
	private function sourceRows(array $sources): array {
		$rows = [];
		foreach ($sources as $source) {
			if ((bool)($source['enabled'] ?? true) === false) {
				continue;
			}

			$appId = (string)($source['appId'] ?? '');
			$rows[$appId] = [
				'appId'       => $appId,
				'title'       => (string)($source['title'] ?? $appId),
				'outstanding' => 0,
				'late'        => 0,
				'nextDue'     => null,
			];
		}

		return $rows;

	}//end sourceRows()

	/**
	 * Count one outstanding obligation into its source's row.
	 *
	 * @param array<string, mixed>   $row   The source's row.
	 * @param string                 $state The obligation's state.
	 * @param DateTimeImmutable|null $due   Its due date.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function countInto(array $row, string $state, ?DateTimeImmutable $due): array {
		$row['outstanding']++;
		$row['late'] += (int)($state === 'late');

		$next = $this->dateOf(value: $row['nextDue']);
		if ($due !== null && ($next === null || $due < $next)) {
			$row['nextDue'] = $due->format(DateTimeInterface::ATOM);
		}

		return $row;

	}//end countInto()

	/**
	 * Read a date, or null when it cannot be read.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null The date.
	 */
	private function dateOf(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Throwable $e) {
			return null;
		}

	}//end dateOf()

	/**
	 * The registered sources, as arrays.
	 *
	 * @return array<int, array<string, mixed>> The sources; none when the schema is not configured.
	 *
	 * @throws RuntimeException When OpenRegister cannot be asked.
	 */
	private function loadSources(): array {
		$register = $this->config->getValueString('opencatalogi', 'publication_register', '');
		$schema = $this->config->getValueString('opencatalogi', 'obligation_source_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		if ($this->appManager->isInstalled('openregister') === false) {
			throw new RuntimeException('The obligation sources could not be read: OpenRegister is not installed');
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$result = $objectService->searchObjectsPaginated(
				query: [
					'@self'  => ['register' => $register, 'schema' => $schema],
					'_limit' => 200,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			throw new RuntimeException('The obligation sources could not be read: '.$e->getMessage(), 0, $e);
		}

		$sources = [];
		foreach (($result['results'] ?? []) as $object) {
			$sources[] = $this->asArray(object: $object);
		}

		return $sources;

	}//end loadSources()

	/**
	 * An OpenRegister object as a flat array of its properties.
	 *
	 * @param mixed $object The object or its array.
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

		if (isset($object['object']) === true && is_array($object['object']) === true) {
			return array_merge($object['object'], ['id' => ($object['uuid'] ?? $object['id'] ?? null)]);
		}

		return $object;

	}//end asArray()
}//end class

<?php

/**
 * Withdraws a publication when its comment period closes.
 *
 * This is the one scheduled piece of the comment period, and everything else is
 * derived at the read for exactly that reason: a job that has not run yet must
 * never be the thing that makes a closed period read as closed. What the job
 * does is the one action a clock cannot derive, which is taking the publication
 * down from every channel it reached.
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
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-closed-period-can-withdraw-its-publication-req-pcp-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Finds the closed periods that still owe a withdrawal, and performs it.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-closed-period-can-withdraw-its-publication-req-pcp-005
 */
class CommentPeriodWithdrawalService {

	/**
	 * How many periods one pass considers.
	 *
	 * @var int
	 */
	private const PAGE = 500;

	/**
	 * Why the publication came down, recorded on the depublication.
	 *
	 * @var string
	 */
	public const REASON = 'The public comment period on this publication closed, and the publication was set to be withdrawn when it did.';

	/**
	 * Who the withdrawal is attributed to.
	 *
	 * @var string
	 */
	public const ACTOR = 'comment-period-close';

	/**
	 * Cached OpenRegister ObjectService.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * Constructor.
	 *
	 * @param CommentPeriodService $periods Decides which period still owes a withdrawal.
	 * @param DepublicationService $depublications Takes a publication down from every channel it reached.
	 * @param IAppConfig $config Holds the register and schema identifiers.
	 * @param ContainerInterface $container Server container, for the consumed OR ObjectService.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly CommentPeriodService $periods,
		private readonly DepublicationService $depublications,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve the consumed OpenRegister ObjectService.
	 *
	 * @return object|null The service, or null when OpenRegister is unavailable.
	 *
	 * @spec exclude pure framework plumbing — resolves the consumed OR ObjectService.
	 */
	public function getObjectService(): ?object {
		if ($this->objectService === null) {
			try {
				$this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			} catch (\Throwable $e) {
				$this->logger->warning(
					'[CommentPeriodWithdrawalService] OpenRegister ObjectService unavailable: ' . $e->getMessage()
				);

				return null;
			}
		}

		return $this->objectService;

	}//end getObjectService()

	/**
	 * Withdraw every publication whose comment period closed and asked for it.
	 *
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, int> How many periods were considered, withdrawn and failed.
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-closed-period-can-withdraw-its-publication-req-pcp-005
	 */
	public function withdrawClosedPeriods(?DateTimeInterface $now = null): array {
		$counts = ['considered' => 0, 'withdrawn' => 0, 'failed' => 0];

		$objectService = $this->getObjectService();
		$register = trim($this->config->getValueString('opencatalogi', 'publication_register', ''));
		$schema = trim($this->config->getValueString('opencatalogi', 'comment_period_schema', ''));
		if ($objectService === null || $register === '' || $schema === '') {
			// Named, never counted as a pass with nothing to do: a pass that
			// could not read the periods looks identical to one where no period
			// closed, and only one of those is fine.
			$this->logger->warning(
				'[CommentPeriodWithdrawalService] No comment period register configured, so no withdrawal was '
				. 'considered. This is NOT "nothing to withdraw".'
			);

			return $counts;
		}

		try {
			$result = $objectService->searchObjectsPaginated(
				query: [
					'@self' => ['register' => $register, 'schema' => $schema],
					'automaticWithdrawal' => true,
					'_limit' => self::PAGE,
				],
				_rbac: false,
				_multitenancy: false
			);
			$rows = (array)($result['results'] ?? []);
		} catch (\Throwable $e) {
			$this->logger->error(
				'[CommentPeriodWithdrawalService] The comment periods could not be read: ' . $e->getMessage()
			);

			return $counts;
		}

		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		foreach ($rows as $row) {
			$period = $this->asArray(object: $row);
			$counts['considered']++;
			if ($this->periods->withdrawalDue(period: $period, now: $moment) === false) {
				continue;
			}

			try {
				$this->withdraw(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					period: $period,
					moment: $moment
				);
				$counts['withdrawn']++;
			} catch (\Throwable $e) {
				$counts['failed']++;
				$this->logger->error(
					'[CommentPeriodWithdrawalService] The withdrawal for period '
					. (string)($period['id'] ?? '?') . ' failed: ' . $e->getMessage()
				);
			}
		}

		return $counts;

	}//end withdrawClosedPeriods()

	/**
	 * Withdraw one publication and record it on its period.
	 *
	 * @param object $objectService The consumed OR ObjectService.
	 * @param string $register The register.
	 * @param string $schema The comment period schema.
	 * @param array<string, mixed> $period The period.
	 * @param DateTimeImmutable $moment The moment.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publication-comment-periods/spec.md#requirement-a-closed-period-can-withdraw-its-publication-req-pcp-005
	 */
	private function withdraw(
		object $objectService,
		string $register,
		string $schema,
		array $period,
		DateTimeImmutable $moment,
	): void {
		$publicationId = (string)($period['publication'] ?? '');
		$depublication = $this->depublications->depublish(
			publication: ['id' => $publicationId],
			reason: self::REASON,
			depublishedBy: self::ACTOR,
			channels: $this->channels(),
			now: $moment
		);

		$depublicationSchema = trim($this->config->getValueString('opencatalogi', 'depublication_schema', ''));
		$depublicationId = '';
		if ($depublicationSchema !== '') {
			$saved = $this->asArray(
				object: $objectService->saveObject(
					object: $depublication,
					register: $register,
					schema: $depublicationSchema,
					_rbac: false,
					_multitenancy: false
				)
			);
			$depublicationId = (string)($saved['id'] ?? '');
		}

		$period['withdrawnAt'] = $moment->format(DateTimeInterface::ATOM);
		if ($depublicationId !== '') {
			$period['depublication'] = $depublicationId;
		}

		$objectService->saveObject(
			object: $period,
			register: $register,
			schema: $schema,
			uuid: (string)($period['id'] ?? ''),
			_rbac: false,
			_multitenancy: false
		);

	}//end withdraw()

	/**
	 * The channels a withdrawal goes out to: the ones this instance has a source
	 * set for, which are the ones a publication could have reached.
	 *
	 * Read from `channel_sources`, the same key the delivery path reads, so a
	 * withdrawal cannot address a channel the publication was never sent to and
	 * cannot miss one it was.
	 *
	 * @return array<int, string> The channels.
	 *
	 * @spec exclude pure config plumbing — reads the configured channel sources.
	 */
	private function channels(): array {
		$decoded = json_decode(
			$this->config->getValueString('opencatalogi', NationalIndexService::CHANNEL_SOURCES_KEY, '{}'),
			true
		);
		if (is_array($decoded) === false) {
			return [];
		}

		$channels = [];
		foreach (NationalIndexService::CONFIGURABLE_CHANNELS as $channel) {
			if (trim((string)($decoded[$channel] ?? '')) !== '') {
				$channels[] = $channel;
			}
		}

		return $channels;

	}//end channels()

	/**
	 * Read an object's properties, whatever shape OpenRegister returned.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed> The properties.
	 *
	 * @spec exclude pure shape adaptation over the consumed OR ObjectService.
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return [];
		}

		if (isset($object['object']) === true && is_array($object['object']) === true) {
			$properties = $object['object'];
			$properties['id'] = ($object['id'] ?? ($properties['id'] ?? null));

			return $properties;
		}

		return $object;

	}//end asArray()
}//end class

<?php

/**
 * OpenCatalogi National Index Service.
 *
 * Composes the official notice for the national publication platform and for
 * the local channel, and hands it to integriq's gateway for delivery. It also
 * registers published records with the national Woo index through the same
 * gateway, and records each destination's answer.
 *
 * It implements no transport. No national endpoint is called from this app:
 * the ask is composed here and delivered there, which is the same split every
 * other outbound integration in this app uses.
 *
 * An index we cannot reach is reported as unreachable. It is never reported as
 * an index that answered nothing, because an operator reading "nothing found"
 * concludes the registration is not needed, and an operator reading
 * "unreachable" goes and looks at the connection.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-official-notices-reach-the-national-platform-and-the-local-channel-req-pin-107
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Composes official notices and hands them to the gateway.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-official-notices-reach-the-national-platform-and-the-local-channel-req-pin-107
 */
class NationalIndexService {

	/**
	 * The national publication platform, as a channel name.
	 *
	 * @var string
	 */
	public const CHANNEL_NATIONAL = 'national-publication-platform';

	/**
	 * The local channel of the organisation itself.
	 *
	 * @var string
	 */
	public const CHANNEL_LOCAL = 'local-channel';

	/**
	 * The national Woo index.
	 *
	 * @var string
	 */
	public const CHANNEL_WOO_INDEX = 'national-woo-index';

	/**
	 * The gateway services this app will accept, in order.
	 *
	 * Both names are tried because the app id moves per app. Pointing at one
	 * name nothing answers to would turn every delivery into a silent no-op.
	 *
	 * @var array<int, string>
	 */
	private const GATEWAY_SERVICES = [
		'OCA\Integriq\Service\CallService',
		'OCA\OpenConnector\Service\CallService',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for resolving the gateway.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Compose the official notice for one channel.
	 *
	 * The composition is this app's and is testable without any gateway at
	 * all, which is why it is a method of its own rather than a step inside
	 * the delivery.
	 *
	 * @param array<string, mixed> $decision The decision being made known.
	 * @param string $channel The channel the notice is for.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The notice.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-official-notices-reach-the-national-platform-and-the-local-channel-req-pin-107
	 */
	public function composeNotice(array $decision, string $channel, ?DateTimeInterface $now = null): array {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		return [
			'channel' => $channel,
			'subject' => trim((string)($decision['title'] ?? 'Bekendmaking')),
			'body' => trim((string)($decision['publicationText'] ?? ($decision['description'] ?? ''))),
			'reference' => (string)($decision['id'] ?? ''),
			'publicationDate' => (string)($decision['publicationDate'] ?? ''),
			'responseDate' => (string)($decision['responseDate'] ?? ''),
			'organisation' => (string)($decision['organisation'] ?? ''),
			'composedAt' => $moment->format(DateTimeInterface::ATOM),
		];

	}//end composeNotice()

	/**
	 * Compose the notice for both channels at once.
	 *
	 * @param array<string, mixed> $decision The decision.
	 * @param DateTimeInterface|null $now The moment.
	 *
	 * @return array<int, array<string, mixed>> The two notices.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-official-notices-reach-the-national-platform-and-the-local-channel-req-pin-107
	 */
	public function composeNotices(array $decision, ?DateTimeInterface $now = null): array {
		return [
			$this->composeNotice(decision: $decision, channel: self::CHANNEL_NATIONAL, now: $now),
			$this->composeNotice(decision: $decision, channel: self::CHANNEL_LOCAL, now: $now),
		];

	}//end composeNotices()

	/**
	 * Resolve the gateway, or say the destination is unreachable.
	 *
	 * @return object The gateway.
	 *
	 * @throws IndexUnreachableException When no gateway is installed.
	 */
	private function gateway(): object {
		foreach (self::GATEWAY_SERVICES as $service) {
			try {
				return $this->container->get($service);
			} catch (\Throwable $e) {
				continue;
			}
		}

		throw new IndexUnreachableException(
			message: 'No gateway is installed, so nothing can be delivered to a national channel from here.'
		);

	}//end gateway()

	/**
	 * Hand a notice to the gateway and record what the destination answered.
	 *
	 * @param array<string, mixed> $notice The composed notice.
	 * @param DateTimeInterface|null $now The moment.
	 *
	 * @return array{channel: string, deliveredAt: string, answer: string}
	 *
	 * @throws IndexUnreachableException When the delivery could not be made.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-official-notices-reach-the-national-platform-and-the-local-channel-req-pin-107
	 */
	public function deliver(array $notice, ?DateTimeInterface $now = null): array {
		$answer = $this->handOver(
			channel: (string)($notice['channel'] ?? ''),
			endpoint: 'notices',
			payload: $notice
		);

		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		return [
			'channel' => (string)($notice['channel'] ?? ''),
			'deliveredAt' => $moment->format(DateTimeInterface::ATOM),
			'answer' => $answer,
		];

	}//end deliver()

	/**
	 * Register a published record with the national Woo index.
	 *
	 * @param array<string, mixed> $publication The published record.
	 * @param DateTimeInterface|null $now The moment.
	 *
	 * @return array{channel: string, registeredAt: string, answer: string}
	 *
	 * @throws IndexUnreachableException When the index could not be asked.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-official-notices-reach-the-national-platform-and-the-local-channel-req-pin-107
	 */
	public function registerWithWooIndex(array $publication, ?DateTimeInterface $now = null): array {
		$answer = $this->handOver(
			channel: self::CHANNEL_WOO_INDEX,
			endpoint: 'registrations',
			payload: [
				'reference' => (string)($publication['id'] ?? ''),
				'title' => (string)($publication['title'] ?? ''),
				'publicationDate' => (string)($publication['publicationDate'] ?? ''),
			]
		);

		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		return [
			'channel' => self::CHANNEL_WOO_INDEX,
			'registeredAt' => $moment->format(DateTimeInterface::ATOM),
			'answer' => $answer,
		];

	}//end registerWithWooIndex()

	/**
	 * Withdraw a publication from one channel.
	 *
	 * @param string $channel The channel.
	 * @param string $publicationId The publication.
	 * @param string $reason Why it is coming down.
	 * @param DateTimeInterface|null $now The moment.
	 *
	 * @return array{channel: string, acknowledgedAt: string, answer: string}
	 *
	 * @throws IndexUnreachableException When the channel could not be reached.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	public function withdraw(string $channel, string $publicationId, string $reason, ?DateTimeInterface $now = null): array {
		$answer = $this->handOver(
			channel: $channel,
			endpoint: 'withdrawals',
			payload: ['reference' => $publicationId, 'reason' => $reason]
		);

		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		return [
			'channel' => $channel,
			'acknowledgedAt' => $moment->format(DateTimeInterface::ATOM),
			'answer' => $answer,
		];

	}//end withdraw()

	/**
	 * Hand one payload to the gateway and read the answer.
	 *
	 * @param string $channel The channel, which is the gateway's source name.
	 * @param string $endpoint The endpoint at the destination.
	 * @param array<string, mixed> $payload What is being sent.
	 *
	 * @return string The destination's answer, as it came back.
	 *
	 * @throws IndexUnreachableException When the call failed or answered nothing readable.
	 */
	private function handOver(string $channel, string $endpoint, array $payload): string {
		$gateway = $this->gateway();

		try {
			$response = $gateway->call(
				source: $channel,
				endpoint: $endpoint,
				method: 'POST',
				config: ['body' => json_encode($payload)]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('[NationalIndexService] The delivery to "' . $channel . '" failed: ' . $e->getMessage());
			throw new IndexUnreachableException(
				message: 'The channel "' . $channel . '" could not be reached: ' . $e->getMessage(),
				code: 0,
				previous: $e
			);
		}

		if (is_object($response) === true && method_exists($response, 'getResponse') === true) {
			$response = $response->getResponse();
		}

		if (is_array($response) === true) {
			$response = ($response['body'] ?? json_encode($response));
		}

		if (is_string($response) === false || trim($response) === '') {
			// A delivery that answered nothing is not a delivery that was
			// acknowledged. Returning an empty string here would let the caller
			// record it as done.
			throw new IndexUnreachableException(
				message: 'The channel "' . $channel . '" answered nothing, so the delivery cannot be called acknowledged.'
			);
		}

		return $response;

	}//end handOver()
}//end class

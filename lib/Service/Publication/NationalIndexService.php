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
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
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
	 * PLOOI, the delivery API of open.overheid.nl.
	 *
	 * @var string
	 */
	public const CHANNEL_PLOOI = 'plooi';

	/**
	 * The app config key holding the channel-to-source map.
	 *
	 * A JSON object from channel name to the slug of an integriq source, for
	 * example `{"national-woo-index": "woo-index"}`. A slug, not a uuid, because
	 * integriq's gateway transport addresses its sources by slug.
	 *
	 * @var string
	 */
	public const CHANNEL_SOURCES_KEY = 'channel_sources';

	/**
	 * The channels a source can be set for.
	 *
	 * @var array<int, string>
	 */
	public const CONFIGURABLE_CHANNELS = [
		self::CHANNEL_WOO_INDEX,
		self::CHANNEL_NATIONAL,
		self::CHANNEL_PLOOI,
	];

	/**
	 * The integriq gateway that publishes official notices.
	 *
	 * @var string
	 */
	private const PUBLICATION_GATEWAY = 'publicatie';

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
	 * The source stores this app will accept, in order, for the same reason.
	 *
	 * @var array<int, string>
	 */
	private const SOURCE_STORES = [
		'OCA\Integriq\Service\ConnectionStore',
		'OCA\OpenConnector\Service\ConnectionStore',
	];

	/**
	 * The gateway delivery event classes this app will accept, in order.
	 *
	 * @var array<int, string>
	 */
	private const DELIVERY_EVENTS = [
		'OCA\Integriq\Event\GatewayDeliveryRequestedEvent',
		'OCA\OpenConnector\Event\GatewayDeliveryRequestedEvent',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for resolving the gateway.
	 * @param IAppConfig $config App configuration, for the channel sources.
	 * @param IEventDispatcher $dispatcher Dispatcher for the gateway delivery request.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $config,
		private readonly IEventDispatcher $dispatcher,
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
			'url' => (string)($decision['url'] ?? ''),
			'publicationType' => (string)($decision['publicationType'] ?? ''),
			'effectiveDate' => (string)($decision['effectiveDate'] ?? ($decision['publicationDate'] ?? '')),
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
	 * Resolve one of integriq's services, or say the destination is unreachable.
	 *
	 * @param array<int, string> $services The class names to try, in order.
	 *
	 * @return object The service.
	 *
	 * @throws IndexUnreachableException When no gateway is installed.
	 */
	private function gateway(array $services=self::GATEWAY_SERVICES): object {
		foreach ($services as $service) {
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
	 * A notice for the national publication platform goes through integriq's
	 * `publicatie` gateway by reference; every other channel is called through
	 * its configured source.
	 *
	 * @return array{channel: string, deliveredAt: string, answer: string, delivery?: array<string, mixed>}
	 *
	 * @throws IndexUnreachableException When the delivery could not be made.
	 *
	 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-national-delivery-repair/spec.md#requirement-official-notices-travel-by-reference-through-the-publication-gateway-req-wnd-002
	 */
	public function deliver(array $notice, ?DateTimeInterface $now = null): array {
		$channel = (string)($notice['channel'] ?? '');
		$delivery = null;
		$answer = '';
		if ($channel === self::CHANNEL_NATIONAL) {
			$delivery = $this->deliverThroughPublicationGateway(notice: $notice);
			$answer = (string)json_encode($delivery);
		}

		if ($channel !== self::CHANNEL_NATIONAL) {
			$answer = $this->handOver(channel: $channel, endpoint: 'notices', payload: $notice);
		}

		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\\TH:i:s.uP'));
		}

		$result = [
			'channel' => $channel,
			'deliveredAt' => $moment->format(DateTimeInterface::ATOM),
			'answer' => $answer,
		];
		if ($delivery !== null) {
			$result['delivery'] = $delivery;
		}

		return $result;

	}//end deliver()

	/**
	 * Read the channel-to-source map from the app configuration.
	 *
	 * @return array<string, string> Channel name to integriq source slug; unreadable or empty entries are left out.
	 *
	 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-national-delivery-repair/spec.md#requirement-a-hand-over-to-a-national-channel-calls-the-gateway-with-a-real-source-req-wnd-001
	 */
	public function channelSources(): array {
		$decoded = json_decode($this->config->getValueString('opencatalogi', self::CHANNEL_SOURCES_KEY, '{}'), true);
		if (is_array($decoded) === false) {
			return [];
		}

		$sources = [];
		foreach ($decoded as $channel => $slug) {
			if (is_string($channel) === true && is_string($slug) === true && trim($slug) !== '') {
				$sources[$channel] = trim($slug);
			}
		}

		return $sources;

	}//end channelSources()

	/**
	 * The source slug set for one channel, or a failure that names the setting.
	 *
	 * @param string $channel The channel.
	 *
	 * @return string The source slug.
	 *
	 * @throws IndexUnreachableException When no source is set for the channel.
	 */
	private function sourceSlugFor(string $channel): string {
		$slug = ($this->channelSources()[$channel] ?? '');
		if ($slug === '') {
			throw new IndexUnreachableException(
				message: 'No integriq source is set for the channel "' . $channel . '". Set one in the Woo settings (' . self::CHANNEL_SOURCES_KEY . ').'
			);
		}

		return $slug;

	}//end sourceSlugFor()

	/**
	 * The gateway delivery event class to instantiate, or null when integriq does not ship it.
	 *
	 * Named by string so this app stays installable without integriq (ADR-041).
	 *
	 * @param array<int, string> $candidates Fully qualified class names, in order.
	 *
	 * @return string|null The class name, or null when none exists.
	 */
	private function resolveDeliveryEvent(array $candidates): ?string {
		foreach ($candidates as $candidate) {
			$qualified = '\\' . $candidate;
			if (class_exists($qualified) === true) {
				return $qualified;
			}
		}

		return null;

	}//end resolveDeliveryEvent()

	/**
	 * Send an official notice through integriq's `publicatie` gateway.
	 *
	 * The notice travels as a document reference and a publication
	 * instruction, never as the document itself, because that is what the
	 * gateway accepts.
	 *
	 * @param array<string, mixed> $notice The composed notice.
	 *
	 * @return array<string, mixed> The delivery integriq returned.
	 *
	 * @throws IndexUnreachableException When integriq is absent, took nothing, refused, or did not deliver.
	 */
	private function deliverThroughPublicationGateway(array $notice): array {
		$slug = $this->sourceSlugFor(channel: self::CHANNEL_NATIONAL);

		$eventClass = $this->resolveDeliveryEvent(candidates: self::DELIVERY_EVENTS);
		if ($eventClass === null) {
			throw new IndexUnreachableException(
				message: 'integriq is not installed, so the channel "' . self::CHANNEL_NATIONAL . '" cannot be reached.'
			);
		}

		/*
		 * @var Event&object $event
		 */
		$event = new $eventClass(
			gatewayId: self::PUBLICATION_GATEWAY,
			request: [
				'reference' => [
					'app' => 'opencatalogi',
					'id' => (string)($notice['reference'] ?? ''),
					'url' => (string)($notice['url'] ?? ''),
				],
				'instruction' => [
					'publicationType' => (string)($notice['publicationType'] ?? ''),
					'effectiveDate' => (string)($notice['effectiveDate'] ?? ''),
				],
			],
			sourceApp: 'opencatalogi',
			config: ['source' => $slug]
		);
		$this->dispatcher->dispatchTyped($event);

		$refusal = $event->getRefusal();
		if (is_array($refusal) === true) {
			throw new IndexUnreachableException(
				message: 'The channel "' . self::CHANNEL_NATIONAL . '" refused the notice ('
					. (string)($refusal['code'] ?? '') . '): ' . (string)($refusal['reason'] ?? '')
			);
		}

		$delivery = $event->getDelivery();
		if (is_array($delivery) === false) {
			// Nobody took the request. That is an unreachable channel, never an
			// acknowledged one.
			throw new IndexUnreachableException(
				message: 'No integriq gateway took the notice, so the channel "' . self::CHANNEL_NATIONAL . '" could not be reached.'
			);
		}

		if (($delivery['delivered'] ?? false) !== true) {
			throw new IndexUnreachableException(
				message: 'The channel "' . self::CHANNEL_NATIONAL . '" did not take the notice: ' . (string)($delivery['reason'] ?? '')
			);
		}

		return $delivery;

	}//end deliverThroughPublicationGateway()

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
	 * The channel is resolved to the integriq source set for it in
	 * `channel_sources`, and that source OBJECT is what the gateway is called
	 * with: integriq's CallService::call() takes an ObjectEntity, and passing
	 * the channel name made every hand-over fail with a TypeError.
	 *
	 * @param string $channel The channel.
	 * @param string $endpoint The endpoint at the destination.
	 * @param array<string, mixed> $payload What is being sent.
	 *
	 * @return string The destination's answer, as it came back.
	 *
	 * @throws IndexUnreachableException When the call failed or answered nothing readable.
	 *
	 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-national-delivery-repair/spec.md#requirement-a-hand-over-to-a-national-channel-calls-the-gateway-with-a-real-source-req-wnd-001
	 */
	private function handOver(string $channel, string $endpoint, array $payload): string {
		$slug = $this->sourceSlugFor(channel: $channel);
		$source = $this->gateway(services: self::SOURCE_STORES)->findSourceBySlug(slug: $slug);
		if ($source === null) {
			throw new IndexUnreachableException(
				message: 'The integriq source "' . $slug . '" set for the channel "' . $channel . '" does not exist.'
			);
		}

		$gateway = $this->gateway();

		try {
			$response = $gateway->call(
				source: $source,
				endpoint: $endpoint,
				method: 'POST',
				config: [
					'body' => json_encode($payload),
					'headers' => ['Content-Type' => 'application/json'],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('[NationalIndexService] The delivery to "' . $channel . '" failed: ' . $e->getMessage());
			throw new IndexUnreachableException(
				message: 'The channel "' . $channel . '" could not be reached: ' . $e->getMessage(),
				code: 0,
				previous: $e
			);
		}

		// The gateway returns its call log. Its object carries the status and the
		// response the destination gave.
		$status = 0;
		if (is_object($response) === true && method_exists($response, 'getObject') === true) {
			$log = $response->getObject();
			$status = (int)($log['statusCode'] ?? ($log['response']['statusCode'] ?? 0));
			$response = ($log['response']['body'] ?? '');
		}

		if ($status >= 300) {
			throw new IndexUnreachableException(
				message: 'The channel "' . $channel . '" answered HTTP ' . $status . ', so the delivery cannot be called acknowledged: ' . (string)$response
			);
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

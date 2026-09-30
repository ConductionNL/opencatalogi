<?php

/**
 * OpenCatalogi Woo-index Registration Service.
 *
 * The Woo-index harvests the DiWoo sitemaps, but only after the instance is
 * registered with it and only when `/robots.txt` at the domain root points at
 * them. This service composes the registration request from what the app
 * already holds, hands it to the gateway's national Woo-index channel, and
 * records the answer. It also renders the two web-server rules that serve the
 * app's robots.txt at the root, because a Nextcloud app cannot answer there.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * Compose, send and record the Woo-index registration.
 *
 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
 */
class WooRegistrationService {

	private const APP_NAME = 'opencatalogi';

	public const STATUS_KEY = 'woo_index_registration_status';
	public const URL_KEY = 'woo_index_registration_url';
	public const AT_KEY = 'woo_index_registration_at';
	public const ANSWER_KEY = 'woo_index_registration_answer';

	/**
	 * The app route the root rule serves.
	 */
	private const ROBOTS_ROUTE = '/index.php/apps/opencatalogi/api/robots.txt';

	/**
	 * Constructor.
	 *
	 * @param WooReadinessService  $readiness    The Woo-enabled catalogues.
	 * @param NationalIndexService $index        The national channels.
	 * @param SettingsService      $settings     The OpenRegister reader, for the organisations.
	 * @param IAppConfig           $config       The registration status keys.
	 * @param IURLGenerator        $urlGenerator The instance's base URL.
	 */
	public function __construct(
		private readonly WooReadinessService $readiness,
		private readonly NationalIndexService $index,
		private readonly SettingsService $settings,
		private readonly IAppConfig $config,
		private readonly IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * The stored registration, with the answer the gateway gave.
	 *
	 * @return array{status: string, registeredUrl: string, registeredAt: string, answer: string}
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
	 */
	public function registration(): array {
		return array_merge(
			$this->readiness->getRegistrationConfig(),
			['answer' => $this->config->getValueString(self::APP_NAME, self::ANSWER_KEY, '')]
		);

	}//end registration()

	/**
	 * The registration request, composed from what the app holds.
	 *
	 * @return array{
	 *     organisations: array<int, array{name: string, tooiIdentifier: string}>,
	 *     baseUrl: string,
	 *     robotsTxt: string,
	 *     sitemapIndexes: array<int, string>
	 * }
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
	 */
	public function compose(): array {
		$baseUrl = $this->baseUrl();
		$sitemaps = [];
		$organisations = [];

		foreach ($this->readiness->getWooEnabledCatalogs() as $catalog) {
			$slug = $catalog->getSlug();
			if (is_string($slug) === false || $slug === '') {
				continue;
			}

			foreach (array_keys(SitemapService::INFO_CAT) as $categoryCode) {
				$sitemaps[] = $baseUrl . '/apps/opencatalogi/api/' . $slug . '/sitemaps/' . $categoryCode;
			}

			$organisation = $this->organisation(uuid: (string)($catalog->jsonSerialize()['organization'] ?? ''));
			if ($organisation !== null) {
				$organisations[$organisation['name'] . '|' . $organisation['tooiIdentifier']] = $organisation;
			}
		}

		return [
			'organisations' => array_values($organisations),
			'baseUrl' => $baseUrl,
			'robotsTxt' => $baseUrl . '/robots.txt',
			'sitemapIndexes' => $sitemaps,
		];

	}//end compose()

	/**
	 * Hand the registration to the gateway and record what it answered.
	 *
	 * The status moves to `requested` only on an answer. An unreachable gateway
	 * records nothing as sent; the caller shows the composed request instead.
	 *
	 * @param DateTimeInterface|null $now The moment, now when omitted.
	 *
	 * @return array{sent: bool, request: array<string, mixed>, registration: array<string, string>, reason?: string}
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
	 */
	public function request(?DateTimeInterface $now = null): array {
		$request = $this->compose();

		try {
			$answer = $this->index->registerWithWooIndex(request: $request, now: $now);
		} catch (IndexUnreachableException $e) {
			return [
				'sent' => false,
				'reason' => $e->getMessage(),
				'request' => $request,
				'registration' => $this->registration(),
			];
		}

		$this->config->setValueString(self::APP_NAME, self::STATUS_KEY, 'requested');
		$this->config->setValueString(self::APP_NAME, self::URL_KEY, $request['baseUrl']);
		$this->config->setValueString(self::APP_NAME, self::AT_KEY, (string)$answer['registeredAt']);
		$this->config->setValueString(self::APP_NAME, self::ANSWER_KEY, (string)$answer['answer']);

		return ['sent' => true, 'request' => $request, 'registration' => $this->registration()];

	}//end request()

	/**
	 * Record that the Woo-index confirmed the registration.
	 *
	 * @param DateTimeInterface|null $now The moment, now when omitted.
	 *
	 * @return array<string, string> The stored registration.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
	 */
	public function confirm(?DateTimeInterface $now = null): array {
		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($now !== null) {
			$moment = new DateTimeImmutable($now->format('Y-m-d\TH:i:s.uP'));
		}

		$this->config->setValueString(self::APP_NAME, self::STATUS_KEY, 'registered');
		$this->config->setValueString(self::APP_NAME, self::URL_KEY, $this->baseUrl());
		$this->config->setValueString(self::APP_NAME, self::AT_KEY, $moment->format(DateTimeInterface::ATOM));

		return $this->registration();

	}//end confirm()

	/**
	 * The Apache and nginx rules that serve the app's robots.txt at the root.
	 *
	 * Rendered from the base URL, so an instance under a sub-path gets a rule
	 * that points at its own route.
	 *
	 * @return array{robotsUrl: string, apache: string, nginx: string}
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-administrator-gets-the-rule-that-serves-robots-txt-at-the-domain-root-req-wih-002
	 */
	public function rootRules(): array {
		$baseUrl = $this->baseUrl();
		$path = (string)parse_url($baseUrl, PHP_URL_PATH);
		$route = rtrim($path, '/') . self::ROBOTS_ROUTE;

		return [
			'robotsUrl' => $baseUrl . self::ROBOTS_ROUTE,
			'apache' => "RewriteEngine On\nRewriteRule ^/?robots\\.txt$ " . $route . " [PT,L]",
			'nginx' => "location = /robots.txt {\n    rewrite ^ " . $route . " last;\n}",
		];

	}//end rootRules()

	/**
	 * The instance's base URL without a trailing slash.
	 *
	 * @return string The base URL.
	 */
	private function baseUrl(): string {
		return rtrim($this->urlGenerator->getBaseUrl(), '/');

	}//end baseUrl()

	/**
	 * An organisation's name and TOOI identifier, read from OpenRegister.
	 *
	 * @param string $uuid The organisation.
	 *
	 * @return array{name: string, tooiIdentifier: string}|null The organisation, or null when it cannot be read.
	 */
	private function organisation(string $uuid): ?array {
		if ($uuid === '') {
			return null;
		}

		try {
			$results = $this->settings->getObjectService()->searchObjects(
				query: ['@self' => ['uuid' => $uuid]],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return null;
		}

		$found = ($results[0] ?? null);
		if (is_object($found) === true && method_exists($found, 'jsonSerialize') === true) {
			$found = $found->jsonSerialize();
		}

		if (is_array($found) === false) {
			return null;
		}

		return [
			'name' => (string)($found['name'] ?? ($found['title'] ?? '')),
			'tooiIdentifier' => (string)($found['tooiIdentifier'] ?? ''),
		];

	}//end organisation()
}//end class

<?php

/**
 * OpenCatalogi Dossiq Woo Forward.
 *
 * Hands a newly received Woo request to dossiq, which mints its reference and
 * arms its statutory term on a case (dossiq REQ-WTO-001). While dossiq is
 * installed OpenCatalogi stores nothing for a new request and arms no term of
 * its own.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-a-new-request-is-forwarded-to-dossiq-and-minted-only-there-req-whd-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Calls `OCA\Dossiq\Woo\WooRequestIntake::receive(answers:, receivedAt:, origin:)`
 * and answers dossiq's `outcome`, `requestId`, `reference`, `dueAt`, `message`
 * and `caseUrl` unchanged. A throwing call, or an answer missing one of the
 * five keys or carrying a non-string in one, answers `unavailable`.
 */
class DossiqWooForward {

	/**
	 * The dossiq class that receives a Woo request.
	 */
	public const INTAKE_CLASS = 'OCA\\Dossiq\\Woo\\WooRequestIntake';

	/**
	 * The five keys every answer carries, OpenCatalogi's `receiveWooRequest()` contract.
	 */
	public const ANSWER_KEYS = ['outcome', 'requestId', 'reference', 'dueAt', 'message'];

	public const ORIGIN_PORTAL_FORM = 'portal-form';

	public const ORIGIN_OPENCATALOGI = 'opencatalogi';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Tells whether dossiq is enabled.
	 * @param ContainerInterface $container  Resolves dossiq's intake at call time.
	 * @param LoggerInterface    $logger     Logs why a forward failed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether dossiq is enabled, so new Woo requests go there.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-a-new-request-is-forwarded-to-dossiq-and-minted-only-there-req-whd-002
	 */
	public function isAvailable(): bool {
		return $this->appManager->isEnabledForAnyone('dossiq') === true;
	}//end isAvailable()

	/**
	 * Forward a Woo request to dossiq.
	 *
	 * @param array<string, mixed> $answers    The request's answers in OpenCatalogi's shape.
	 * @param string               $receivedAt When the requester sent it (ISO 8601), or empty.
	 * @param string               $origin     `portal-form` or `opencatalogi`.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string, caseUrl: string}
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-a-new-request-is-forwarded-to-dossiq-and-minted-only-there-req-whd-002
	 */
	public function forward(array $answers, string $receivedAt, string $origin): array {
		try {
			$intake = $this->container->get(self::INTAKE_CLASS);
			$answer = $intake->receive(answers: $answers, receivedAt: $receivedAt, origin: $origin);
		} catch (Throwable $e) {
			$this->logger->error('OpenCatalogi: forwarding a Woo request to dossiq failed: ' . $e->getMessage(), ['exception' => $e]);
			return self::unavailable();
		}

		if (is_array($answer) === false) {
			$this->logger->error('OpenCatalogi: dossiq answered a Woo request with something other than an array.');
			return self::unavailable();
		}

		foreach (self::ANSWER_KEYS as $key) {
			if (is_string($answer[$key] ?? null) === false) {
				$this->logger->error('OpenCatalogi: dossiq answered a Woo request without a string ' . $key . '.');
				return self::unavailable();
			}
		}

		$caseUrl = '';
		if (is_string($answer['caseUrl'] ?? null) === true) {
			$caseUrl = $answer['caseUrl'];
		}

		return [
			'outcome' => $answer['outcome'],
			'requestId' => $answer['requestId'],
			'reference' => $answer['reference'],
			'dueAt' => $answer['dueAt'],
			'message' => $answer['message'],
			'caseUrl' => $caseUrl,
		];
	}//end forward()

	/**
	 * The answer when the request could not be handed over.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string, caseUrl: string}
	 */
	private static function unavailable(): array {
		return [
			'outcome' => 'unavailable',
			'requestId' => '',
			'reference' => '',
			'dueAt' => '',
			'message' => 'The request was not received. Please send it again later.',
			'caseUrl' => '',
		];
	}//end unavailable()
}//end class

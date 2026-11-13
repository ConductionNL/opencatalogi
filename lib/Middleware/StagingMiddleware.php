<?php

/**
 * Staging middleware: closes the public surface to anonymous callers in staging.
 *
 * @category Middleware
 * @package  OCA\OpenCatalogi\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/instance-staging-mode
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Middleware;

use OCA\OpenCatalogi\Controller\RobotsController;
use OCA\OpenCatalogi\Service\InstanceMode;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IUserSession;
use ReflectionMethod;
use RuntimeException;

/**
 * In staging (or when the mode cannot be read) every `#[PublicPage]` route
 * answers an anonymous caller 503 with `X-Robots-Tag: noindex, nofollow` and
 * `{error: "staging"}` (REQ-STG-003). One middleware rather than a check per
 * controller, so a new public route is covered by default. A signed-in user
 * sees the public view as a reader would in production. robots.txt is left
 * to answer, because in staging it disallows everything.
 *
 * @spec openspec/changes/instance-staging-mode
 */
class StagingMiddleware extends Middleware {

	/**
	 * The marker this middleware throws to answer with the staging response.
	 */
	private const CLOSED = 'opencatalogi-staging-closed';

	/**
	 * Constructor.
	 *
	 * @param InstanceMode $mode        The instance mode.
	 * @param IUserSession $userSession Who is calling.
	 */
	public function __construct(
		private readonly InstanceMode $mode,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Refuse an anonymous call to a public route in staging.
	 *
	 * @param object $controller The controller.
	 * @param string $methodName The method.
	 *
	 * @return void
	 *
	 * @throws RuntimeException The staging marker, answered by afterException().
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-the-public-surface-is-closed-to-anonymous-callers-in-staging-req-stg-003
	 */
	public function beforeController($controller, $methodName): void {
		if ($controller instanceof RobotsController || self::isPublic(controller: $controller, method: (string)$methodName) === false) {
			return;
		}

		if ($this->userSession->getUser() !== null || $this->mode->isStaging() === false) {
			return;
		}

		throw new RuntimeException(self::CLOSED);
	}//end beforeController()

	/**
	 * Answer the staging marker; rethrow anything else.
	 *
	 * @param object    $controller The controller.
	 * @param string    $methodName The method.
	 * @param \Exception $exception  The exception.
	 *
	 * @return Response
	 *
	 * @throws \Exception Anything that is not the staging marker.
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-the-public-surface-is-closed-to-anonymous-callers-in-staging-req-stg-003
	 */
	public function afterException($controller, $methodName, \Exception $exception): Response {
		if (($exception instanceof RuntimeException) === false || $exception->getMessage() !== self::CLOSED) {
			throw $exception;
		}

		$response = new JSONResponse(['error' => 'staging'], Http::STATUS_SERVICE_UNAVAILABLE);
		$response->addHeader('X-Robots-Tag', 'noindex, nofollow');

		return $response;
	}//end afterException()

	/**
	 * Whether a controller method is a public page, by attribute or annotation.
	 *
	 * @param object $controller The controller.
	 * @param string $method     The method.
	 *
	 * @return bool
	 */
	private static function isPublic(object $controller, string $method): bool {
		if (method_exists($controller, $method) === false) {
			return false;
		}

		$reflection = new ReflectionMethod($controller, $method);
		if ($reflection->getAttributes(PublicPage::class) !== []) {
			return true;
		}

		return str_contains((string)$reflection->getDocComment(), '@PublicPage');
	}//end isPublic()
}//end class

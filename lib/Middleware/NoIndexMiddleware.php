<?php

/**
 * OpenCatalogi no-index middleware.
 *
 * Keeps the officer side, and on request the whole app, out of search engine indexes.
 *
 * @category Middleware
 * @package  OCA\OpenCatalogi\Middleware
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
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Middleware;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IAppConfig;
use ReflectionException;
use ReflectionMethod;

/**
 * Adds `X-Robots-Tag: noindex, nofollow` to every OpenCatalogi response that is not a public page.
 *
 * A new officer route is covered by default, because only a method that declares itself a
 * public page escapes the header. With the `search_indexing` setting on `blocked` every
 * response carries it, so an acceptance or test install stays out of the indexes while its
 * public side is still served (REQ-WAB-003).
 *
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md
 */
class NoIndexMiddleware extends Middleware {

	/**
	 * The header search engines read.
	 */
	public const HEADER = 'X-Robots-Tag';

	/**
	 * The header value: do not index this page and do not follow its links.
	 */
	public const NOINDEX = 'noindex, nofollow';

	/**
	 * The app setting that keeps an install out of every index.
	 */
	public const SETTING = 'search_indexing';

	/**
	 * The setting value that keeps every surface out of the indexes.
	 */
	public const BLOCKED = 'blocked';

	/**
	 * The setting value that leaves the public side indexable.
	 */
	public const ALLOWED = 'allowed';

	/**
	 * NoIndexMiddleware constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration holding the indexing switch.
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Mark the response noindex unless it answers a public page on an indexable install.
	 *
	 * @param object|null $controller The controller that answered.
	 * @param string      $methodName The controller method that answered.
	 * @param Response    $response   The response to mark.
	 *
	 * @return Response The same response, with the header where it applies.
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		if ($this->indexingBlocked() === true
			|| $this->isPublicPage(controller: $controller, methodName: (string) $methodName) === false
		) {
			$response->addHeader(name: self::HEADER, value: self::NOINDEX);
		}

		return $response;
	}//end afterController()

	/**
	 * Whether the administrator keeps this install out of every index.
	 *
	 * @return bool True when `search_indexing` is `blocked`.
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md
	 */
	private function indexingBlocked(): bool {
		$value = $this->appConfig->getValueString(AppIdentity::APP_ID, self::SETTING, self::ALLOWED);

		return $value === self::BLOCKED;
	}//end indexingBlocked()

	/**
	 * Whether the controller method is declared a public page.
	 *
	 * A method that cannot be read is treated as not public, so it gets the header.
	 *
	 * @param object|null $controller The controller that answered.
	 * @param string      $methodName The controller method that answered.
	 *
	 * @return bool True for a `#[PublicPage]` or `@PublicPage` method.
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md
	 */
	private function isPublicPage(?object $controller, string $methodName): bool {
		if ($controller === null || $methodName === '') {
			return false;
		}

		try {
			$method = new ReflectionMethod($controller, $methodName);
		} catch (ReflectionException) {
			return false;
		}

		if ($method->getAttributes(PublicPage::class) !== []) {
			return true;
		}

		$doc = $method->getDocComment();

		return is_string($doc) === true && preg_match('/@PublicPage\b/', $doc) === 1;
	}//end isPublicPage()
}//end class

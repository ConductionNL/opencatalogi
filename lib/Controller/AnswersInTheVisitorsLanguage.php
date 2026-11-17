<?php

/**
 * OpenCatalogi language negotiation of the public read endpoints.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCP\AppFramework\Http\Response;

/**
 * Hand the visitor's language to OpenRegister before a public read and name
 * the language served on the answer.
 *
 * Consuming controllers MUST be an OCP Controller (they read `$this->request`).
 * Without a query service (a controller built without one) the read stays in
 * the source language and the answer carries no language headers.
 *
 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
 */
trait AnswersInTheVisitorsLanguage {
	/**
	 * Pass `_lang`, `language` or `Accept-Language` of this request to OpenRegister.
	 *
	 * @param PublicationQueryServiceInterface|null $queryService The service that negotiates the language.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
	 */
	private function negotiateVisitorLanguage(?PublicationQueryServiceInterface $queryService): void {
		$queryService?->applyVisitorLanguage(
			queryParams: $this->request->getParams(),
			acceptLanguage: (string)($this->request->server['HTTP_ACCEPT_LANGUAGE'] ?? '')
		);

	}//end negotiateVisitorLanguage()

	/**
	 * Add Content-Language, X-Source-Language and, when a field fell back to
	 * Dutch, X-Content-Language-Fallback to an answer.
	 *
	 * @param T $response The answer.
	 * @param PublicationQueryServiceInterface|null $queryService The service that read the language back.
	 *
	 * @return T The same answer.
	 *
	 * @template T of Response
	 *
	 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
	 */
	private function withVisitorLanguageHeaders(Response $response, ?PublicationQueryServiceInterface $queryService): Response {
		if ($queryService === null) {
			return $response;
		}

		foreach ($queryService->languageHeaders() as $name => $value) {
			$response->addHeader($name, $value);
		}

		return $response;
	}//end withVisitorLanguageHeaders()
}//end trait

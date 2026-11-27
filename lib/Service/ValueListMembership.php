<?php

/**
 * Whether a value-list member may be given as a new value.
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
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\VocabularyRegisterInterface;
use RuntimeException;
use Throwable;

/**
 * A deprecated concept still resolves for the records that carry it
 * ({@see TooiVocabularyService}); as a new value only a concept the vocabulary
 * register holds and has not deprecated is accepted.
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
 */
class ValueListMembership {

	/**
	 * The schemes a publication takes a list value from.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMES = [
		TooiVocabularyService::CATEGORIES_SCHEME,
		TooiVocabularyService::HANDLING_SCHEME,
		TooiVocabularyService::DOCUMENT_TYPE_SCHEME,
		TooiVocabularyService::LANGUAGE_SCHEME,
	];

	/**
	 * Constructor.
	 *
	 * @param VocabularyRegisterInterface $register OpenRegister's vocabulary register.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly VocabularyRegisterInterface $register,
	) {
	}//end __construct()

	/**
	 * Whether a member may be set as a new value: the register holds it in one of the lists and has not deprecated it.
	 *
	 * @param string $uri The member's URI, as a resolver answers it.
	 *
	 * @return bool True when it may be set.
	 *
	 * @throws RuntimeException When the vocabulary register cannot be read; nothing is accepted from a copy.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	public function acceptsNewValue(string $uri): bool {
		foreach (self::SCHEMES as $scheme) {
			try {
				$active = $this->register->activeConceptUris(schemeUri: $scheme);
			} catch (Throwable $e) {
				throw new RuntimeException('The value list ' . $scheme . ' cannot be read from the vocabulary register.', 0, $e);
			}

			if (in_array($uri, $active, true) === true) {
				return true;
			}
		}

		return false;
	}//end acceptsNewValue()
}//end class

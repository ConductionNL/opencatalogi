<?php
/**
 * The register/schema scope keys a caller may never set on a public search.
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
 */

namespace OCA\OpenCatalogi\Service;

/**
 * Strips caller-supplied register/schema scope from a request-derived search query
 * (SCH-PFTS-CAT-002, WOO-581). See {@see CallerScope::strip()}.
 */
final class CallerScope
{

    /**
     * Query keys OpenRegister reads to pick the register/schema a search runs on.
     *
     * `MagicMapper::searchObjectsPaginated()` resolves its scope as
     * `@self.schema ?? _schema ?? schema` (and the same chain for register),
     * plus `@self.schemas ?? _schemas` and `@self.registers ?? _registers` for a
     * multi-schema search. A scalar `@self.schema` wins over a guarded
     * `_schemas` list. OR's `buildSearchQuery()` turns a caller's `schema=` /
     * `register=` into exactly that `@self` entry. See
     * {@see strip()}.
     *
     * @var array<string>
     */
    private const CALLER_SCOPE_KEYS = ['register', 'schema', '_register', '_registers', '_schema', '_schemas'];

    /**
     * The `@self` sub-keys that carry scope (see CALLER_SCOPE_KEYS).
     *
     * @var array<string>
     */
    private const CALLER_SELF_SCOPE_KEYS = ['register', 'registers', 'schema', 'schemas'];

    /**
     * Remove every register/schema scope key a CALLER put in a search query.
     *
     * A guarded scope is only a guard if it is the scope OpenRegister searches.
     * It was not (WOO-581 review): on `/api/search`, `/api/{catalogSlug}` and
     * `/api/catalogi/{id}` an anonymous `?schema=<S>&register=<R>` (or
     * `?@self[schema]=<S>`) reached OR as `@self.schema`, which wins the
     * precedence chain over the guarded `_schemas` list — so a schema without
     * an `authorization` block was read in full, even one in no catalog at
     * all. Every public list route therefore strips these keys from the
     * request-derived query and then writes its own scope: the three above, the
     * federation list (both paths in PublicationService) and the glossary, themes
     * and catalog-list controllers. Round 2 of the review showed why "the server's
     * `@self.schema` wins" is not enough on its own: that holds for the rows, but
     * OR's facet path reads `@self.schemas ?? _schemas` first. Non-scope `@self`
     * filters (`owner`, `created`, `uuid`, …) are kept. Static on purpose: a pure
     * function over the query array, with no state and no collaborators, so every
     * reader calls the same code without a constructor change.
     *
     * @param array $query A search query built from request parameters.
     *
     * @return array The query without any caller-supplied register/schema scope.
     *
     * @spec openspec/changes/archive/2026-08-28-fix-fts-catalog-model-alignment/specs/search/spec.md
     */
    public static function strip(array $query): array
    {
        foreach (self::CALLER_SCOPE_KEYS as $key) {
            unset($query[$key]);
        }

        if (array_key_exists('@self', $query) === true && is_array($query['@self']) === false) {
            unset($query['@self']);
        }

        if (isset($query['@self']) === true) {
            foreach (self::CALLER_SELF_SCOPE_KEYS as $key) {
                unset($query['@self'][$key]);
            }
        }

        return $query;

    }//end strip()

}//end class

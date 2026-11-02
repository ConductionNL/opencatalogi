/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { z } from 'zod'

/**
 * The zod schema of an entity's language: an ISO 639-1 code (two or three
 * letters, with an optional second group such as `en-us`) and a CEFR level,
 * each of which may be empty. Built per call, because its regexes are global
 * and carry state between matches.
 *
 * @return The language schema
 * @spec openspec/specs/entity-typescript-models/spec.md
 */
export function languageSchema() {
	return z.object({
		code: z
			.string()
			.regex(
				/^([a-z]{2,3})(-[a-z]{2,3})?$/g,
				'is niet een geldige ISO 639-1 code (e.g. en-us)',
			)
			.or(z.literal('')),
		level: z
			.string()
			.regex(/^(A|B|C)(1|2)$/g, 'is niet een geldige CEFRL level (e.g. A1)')
			.or(z.literal('')),
	})
}

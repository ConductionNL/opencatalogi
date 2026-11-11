/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { z } from 'zod'

/**
 * The zod schema of the lifecycle status a publication or attachment has.
 *
 * @return {import('zod').ZodEnum} The status schema
 */
export function publicationStatusSchema() {
	return z.enum([
		'Concept',
		'Published',
		'Withdrawn',
		'Archived',
		'Revised',
		'Rejected',
	])
}

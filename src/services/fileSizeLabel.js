/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The size label of a file in the file lists: 'n/a' for an empty file,
 * '< 1 KB' below a kilobyte, else the size in the largest fitting unit
 * with one decimal.
 *
 * @param {number} bytes - The size in bytes
 * @return {string} The label
 * @spec exclude display formatting; no domain behavior of its own.
 */
export function fileSizeLabel(bytes) {
	const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
	if (bytes === 0) return 'n/a'
	const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)))
	if (i === 0 && sizes[i] === 'Bytes') return '< 1 KB'
	if (i === 0) return bytes + ' ' + sizes[i]
	return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]
}

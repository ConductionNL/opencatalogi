/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Whether OpenRegister can generate the Woo annual report, and from which template.
 *
 * @return {Promise<{available: boolean, templateId: string|null, reason: string|null, formats: Array<string>}>} The availability.
 *
 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-an-officer-generates-the-annual-report-from-the-publications-report-req-war-002
 */
export async function fetchAnnualReportAvailability() {
	const { data } = await axios.get(generateUrl('/apps/opencatalogi/api/woo/annual-report'))
	return {
		available: data?.available === true,
		templateId: data?.templateId ?? null,
		reason: data?.reason ?? null,
		formats: Array.isArray(data?.formats) ? data.formats : [],
	}
}

/**
 * The calendar years an officer can pick: this year and the four before it.
 *
 * @param {Date} [now] Today.
 * @return {Array<number>} The years, newest first.
 *
 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-an-officer-generates-the-annual-report-from-the-publications-report-req-war-002
 */
export function reportYears(now = new Date()) {
	const year = now.getFullYear()
	return [0, 1, 2, 3, 4].map((back) => year - back)
}

/**
 * Generate the report through OpenRegister and hand it to the browser as a download.
 *
 * @param {object} request The request.
 * @param {string} request.templateId The template to render.
 * @param {number} request.year The calendar year.
 * @param {string} request.format pdf, ods or csv.
 * @param {(blob: Blob, filename: string) => void} [save] Saves the file; the browser download by default.
 * @return {Promise<string>} The file name.
 *
 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-an-officer-generates-the-annual-report-from-the-publications-report-req-war-002
 */
export async function generateAnnualReport({ templateId, year, format }, save = saveBlob) {
	const response = await axios.post(
		generateUrl('/apps/openregister/api/reports/generate'),
		{ templateId, year: String(year), format },
		{ responseType: 'blob' },
	)
	const filename = filenameFrom(response.headers?.['content-disposition']) || `woo-jaarverslag-${year}.${format}`
	save(response.data, filename)
	return filename
}

/**
 * The file name in a Content-Disposition header.
 *
 * @param {string|undefined} header The header.
 * @return {string} The name, or '' when there is none.
 *
 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-an-officer-generates-the-annual-report-from-the-publications-report-req-war-002
 */
export function filenameFrom(header) {
	const match = /filename="?([^";]+)"?/.exec(header || '')
	return match ? match[1] : ''
}

/**
 * Save a blob as a download in the browser.
 *
 * @param {Blob} blob The file.
 * @param {string} filename Its name.
 * @return {void}
 *
 * @spec exclude browser download glue; covered by the e2e spec.
 */
function saveBlob(blob, filename) {
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = filename
	link.click()
	URL.revokeObjectURL(url)
}

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The sentence the publiccode harvest settings show about the last harvest run.
 */
import { translate as t } from '@nextcloud/l10n'

/**
 * Whether the run stopped on an error: it failed, or it stopped with an error.
 *
 * @param {object} run The last run.
 * @return {boolean} True when the run stopped on an error.
 */
function stoppedOnError(run) {
	return (
		run.status === 'failed' || (run.status === 'stopped' && Boolean(run.error))
	)
}

/**
 * Whether the run finished. The end node stops the run on purpose once every
 * shard has written, so a clean stop without an error is a finished harvest.
 *
 * @param {object} run The last run.
 * @return {boolean} True when the run finished.
 */
function finished(run) {
	return run.status === 'completed' || (run.status === 'stopped' && !run.error)
}

/**
 * The outcome of a run: waiting for the rate limit, stopped on an error,
 * finished, or still running.
 *
 * @param {object} run The last run.
 * @return {'suspended'|'stopped'|'finished'|'running'} The outcome.
 */
function runOutcome(run) {
	if (run.status === 'suspended' && run.resumeAt) {
		return 'suspended'
	}
	if (stoppedOnError(run)) {
		return 'stopped'
	}
	return finished(run) ? 'finished' : 'running'
}

const OUTCOME_LABELS = {
	suspended: (run, started) =>
		t(
			'opencatalogi',
			'Last run started {started}. It waits for the GitHub rate limit and goes on at {resume}.',
			{ started, resume: new Date(run.resumeAt).toLocaleString() },
		),
	stopped: (run, started) =>
		t('opencatalogi', 'Last run started {started} and stopped: {error}', {
			started,
			error: run.error ?? '',
		}),
	finished: (run, started) =>
		t('opencatalogi', 'Last run started {started} and finished.', { started }),
	running: (run, started) =>
		t('opencatalogi', 'Last run started {started} and is still running.', {
			started,
		}),
}

/**
 * The sentence about the last harvest run, with its start time in the
 * viewer's locale.
 *
 * @param {object|null|undefined} run The last run from the harvest status, if any.
 * @return {string} The translated sentence.
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 */
export function lastRunLabel(run) {
	if (!run) {
		return t('opencatalogi', 'The harvest has not run yet.')
	}
	const started = run.started ? new Date(run.started).toLocaleString() : ''
	return OUTCOME_LABELS[runOutcome(run)](run, started)
}

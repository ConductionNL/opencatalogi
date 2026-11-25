# first-time-setup Specification

## MODIFIED Requirements

### Requirement: Connect-to-federation privileged action (ONB-007)

The system MUST expose a `connect-federation` action that synchronizes the
instance with the national OpenCatalogi directory **immediately** (rather than
waiting for `doCronSync`) by calling `DirectoryService::syncDirectory()` against
the default directory URL. The corresponding `connect-federation` setup step MUST
be optional (never gates the shell, so it is always skippable), and MUST report
`done` when the default directory is a known listing. A sync failure (unreachable
or slow directory) MUST surface as a non-fatal step error that the user can skip.

The system MUST guarantee this optionality holds even when the national
directory returns zero listings (an empty central directory) or is
temporarily unreachable: `connect-federation`'s per-step `done` state and any
sync failure MUST NOT be a factor in computing the wizard's overall
`completed` state or in writing `onboarding_completed_version`. Completion
MUST be derivable from the required steps alone (`config-check`,
`catalog-scope`, `create-catalog`), so the wizard can always reach "All set"
regardless of the directory's availability or contents. This MUST be covered
by an automated regression test that fails if a future change makes
`completed` or `onboarding_completed_version` depend on
`connect-federation`'s outcome.

#### Scenario: Action syncs the national directory now
- GIVEN the user reaches the optional `connect-federation` step
- WHEN they run the `connect-federation` action
- THEN `syncDirectory()` runs against the default directory URL and imports its
  listings
- AND the step is reported `done`

#### Scenario: Step is skippable and survives a sync failure
- GIVEN the national directory is unreachable
- WHEN the user runs `connect-federation`
- THEN the step surfaces a non-fatal error
- AND the user can skip the step and complete the wizard
- AND `doCronSync` still federates later on its normal schedule

#### Scenario: Wizard completes when the central directory returns zero listings
- GIVEN the national directory is reachable but currently has zero listings to
  offer ("0 new listings")
- WHEN the user runs `connect-federation` and then proceeds through the wizard
- THEN the `connect-federation` step may report `done: false`
- AND `GET /api/setup/status`'s `completed` field is still `true` once
  `config-check`, `catalog-scope` and `create-catalog` are all `done`
- AND `onboarding_completed_version` is set, so the wizard does not re-show

#### Scenario: Regression guard — completion never depends on federation outcome
- GIVEN a request to `GET /api/setup/status` where `connect-federation`'s
  computed `done` is `false` (directory unreachable or empty) but every
  required step is `done`
- WHEN the response's `completed` field and the `create-first-catalog`
  action's completion write are inspected
- THEN neither is derived from, nor blocked by, the `connect-federation` step
  or `DirectoryService::syncDirectory()`'s outcome

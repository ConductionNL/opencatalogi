---
status: proposed
---

# Federation connection last success

## ADDED Requirements

### Requirement: A listing records its last successful sync apart from its last attempt (REQ-FLS-001)

Each directory listing SHALL record when it last synchronised successfully and, when the last attempt failed, the error. A failed attempt SHALL update the last attempt time and the error but SHALL NOT change the last success. A stored error SHALL NOT contain credentials or URL query strings.

#### Scenario: A peer goes down after working

- **GIVEN** a listing that synchronised successfully on Monday
- **WHEN** the hourly sync on Tuesday gets a timeout from the peer
- **THEN** the listing's last success is still Monday
- **AND** its last attempt is Tuesday with the timeout as its error

#### Scenario: A token in an error message

- **GIVEN** a peer URL that carries `?token=abc` and a failing sync
- **WHEN** the error is stored on the listing
- **THEN** the stored error contains no query string

### Requirement: The directory page shows when each connection last worked (REQ-FLS-002)

The directory page SHALL show for each listing the last successful sync, the last attempt when it differs, and the error of a failed last attempt. A listing that never succeeded SHALL say so. The status text for screen readers SHALL include the last success.

#### Scenario: An administrator checks a failing peer

- **GIVEN** a listing whose last attempt failed and whose last success was two days ago
- **WHEN** the administrator opens the Directory page
- **THEN** the listing's row shows the last successful sync two days ago, the failed last attempt and its error

#### Scenario: A new peer that never answered

- **GIVEN** a listing added today whose first sync failed
- **WHEN** the administrator opens the Directory page
- **THEN** the row says it never synchronised successfully

### Requirement: An administrator re-syncs one listing from its row (REQ-FLS-003)

For administrators, each listing row SHALL offer Sync now, which runs the single-listing sync and refreshes the row with the result. Users who are not administrators SHALL NOT see it.

#### Scenario: An administrator retries after the peer is back

- **GIVEN** a failing listing whose peer is reachable again
- **WHEN** the administrator presses Sync now on its row
- **THEN** the row shows a last successful sync of just now and no error

#### Scenario: A user who is not an administrator

- **GIVEN** a signed-in user without admin rights on the Directory page
- **WHEN** the page renders
- **THEN** no Sync now button is shown

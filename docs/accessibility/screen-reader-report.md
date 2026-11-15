---
title: Screen reader report
---

# Screen reader report

Report date: 2026-11-13

This report says which screen reader passes have been run over OpenCatalogi,
by whom, when, and with what result. A pass that nobody has run says
"not yet run". A pass is only reported as run with the name of the person who
ran it and the date.

Two flows are covered:

- **Officer flow**: create a publication with a title and a category, attach a
  document, publish it, and withdraw it with a reason.
- **Citizen flow**: open the public catalogue, search for a publication, open
  it, and open one of its documents.

## Virtual screen reader

Status: not yet run

The virtual pass walks the officer flow with `@guidepup/virtual-screen-reader`
in Playwright (`tests/e2e/officer-screen-reader.spec.ts`) and stores the spoken
transcript as a test artefact. The spec has not been written yet, so there is
no transcript to summarise.

## NVDA with Firefox on Windows

### Officer flow

Status: not yet run
Run by:
Date:
Versions:
What worked:
What failed:

### Citizen flow

Status: not yet run
Run by:
Date:
Versions:
What worked:
What failed:

## VoiceOver with Safari on macOS

### Officer flow

Status: not yet run
Run by:
Date:
Versions:
What worked:
What failed:

### Citizen flow

Status: not yet run
Run by:
Date:
Versions:
What worked:
What failed:

## How to run a pass

You need a test instance with the demo data loaded, an officer account, and
about an hour per screen reader.

1. Note the versions: the screen reader, the browser, the operating system,
   and the OpenCatalogi version (Apps, OpenCatalogi).
2. Start the screen reader before you open the browser. Use the keyboard only.
3. Officer flow. Sign in as the officer and open OpenCatalogi, then Publications.
   - Create a publication. Listen for: the button's name, the dialog's title
     when it opens, a name for every field, and that focus moves into the dialog.
   - Fill in a title and pick a category. Listen for: the category list's name,
     the number of options, and the option you chose after closing it.
   - Save, then attach a document on the publication page. Listen for: a name on
     the attach control, and an announcement that the upload finished.
   - Publish. Listen for: an announcement that the publication is published.
   - Withdraw with a reason. Listen for: the reason field's name, and an
     announcement that the publication is withdrawn.
   - Close each dialog with Escape. Listen for: focus returning to the control
     that opened it.
4. Citizen flow. Sign out and open the public catalogue page.
   - Search for a word from the demo data. Listen for: the search field's name
     and the number of results.
   - Open a publication. Listen for: its title as a heading, and the page's
     landmarks (navigation, main).
   - Open a document. Listen for: the link's name saying what it opens.
5. Fill in the section for this screen reader and flow above: change "not yet
   run" to "passed" or "failed", and fill in your name, the date
   (YYYY-MM-DD), the versions, what worked, and what failed. Give each failure
   an issue link in the OpenCatalogi repository, or upstream when the control
   comes from Nextcloud or `@nextcloud/vue`.
6. Change the report date at the top to the date of your pass.

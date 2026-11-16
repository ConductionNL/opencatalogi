# Plain language summary

A publication can carry a summary in plain words next to its own summary. A Woo
decision is written for lawyers. The plain language summary tells every reader what
the decision means for them, at a reading level the officer names.

## The fields

| Field | What it holds |
|---|---|
| `plainSummary` | The summary in plain words, at most 1,500 characters. |
| `plainSummaryLevel` | The reading level it is written at: `A2`, `B1` or `B2`. The default is `B1`. |
| `plainSummaryProvenance` | Who wrote it, with which text assistant, when it was accepted and by whom. |

Taalniveau B1 is the level the Dutch government uses for text written for citizens.
Choose A2 for text that must reach readers with low literacy. Choose B2 only when the
subject cannot be explained at B1. The level is what you declare: the app does not
measure the reading level of a text.

A summary without a level is refused, and the error names `plainSummaryLevel`. A
summary saved without a level field at all gets B1.

The provenance is written by the server. A save that changes the summary records the
person who saved it. A save that tries to write the provenance itself gets the stored
provenance back. Anonymous readers do not see the provenance, because it names a user.

## Writing it

Open the publication. The Publication card shows the plain language summary and its
reading level next to the summary. Edit both there and save.

Some tips for B1:

- Use short sentences, with one message per sentence.
- Address the reader as "u" and say what happened to them.
- Use common words. Explain a legal term when you cannot avoid it.
- Say what the reader can do next.

For example: "U kreeg geen parkeervergunning. U maakte bezwaar. De gemeente blijft bij
het besluit."

## Drafting it with a text assistant

When a text assistant for simplifying text is installed in Nextcloud (hermiq, or any
other app that offers the task type `core:text2text:simplification`), an officer can
ask for a draft. The assistant gets the title, the summary and the description of the
publication.

A draft is a suggestion. Nothing is stored until the officer edits it and accepts it.
The stored provenance then reads `ai`, names the text assistant and names the officer
who accepted it. A draft that fails or times out stores nothing, and the officer sees
the error.

Only an officer who may change the publication can ask for a draft. Without a text
assistant, writing the summary by hand always works.

| Route | What it does |
|---|---|
| `GET /api/plain-summary/assistant` | Answers `{available}`: whether a text assistant can draft. |
| `POST /api/publications/{id}/plain-summary-draft` | Asks for a draft and answers `{taskId}`. Answers 409 `no-provider` without a text assistant. |
| `GET /api/publications/{id}/plain-summary-draft/{taskId}` | Answers `{status, text?, error?}`. Stores nothing. |
| `POST /api/publications/{id}/plain-summary` | Stores `{text, level, fromTaskId?}` with its provenance. |

## On the public API and in DCAT

The public publication response carries `plainSummary` and `plainSummaryLevel`. The
DCAT feed emits the plain summary as `dct:abstract`, tagged with the publication's
`language`, or `nl` when the publication has none.

# MCP Tool Surface — hermiq-ai-tooling delta

Extends the capability created by `opencatalogi-mcp-adoption` with governed write-action tools. REQ-001…REQ-006 are claimed by that change and stand unmodified — in particular REQ-002 (no dialect write verb on any schema) remains binding; every write in this delta is a curated `#[McpTool]` method. This delta adds REQ-007…REQ-011.

## ADDED Requirements

### Requirement: Write actions are curated tools with allow-listed properties (REQ-007)
OpenCatalogi MUST expose exactly four write tools as `#[McpTool]` methods discovered via the existing `IMcpScannableServices::opencatalogi` registration: `opencatalogi.createDraftPublication` (scope `create`, reach `instance`), `opencatalogi.publishPublication` (scope `update`, reach `external`), `opencatalogi.depublishPublication` (scope `update`, reach `external`), and `opencatalogi.archivePublication` (scope `update`, reach `instance`). Each tool MUST write only its allow-listed properties, enforced server-side before the object write: `createDraftPublication` → `title`, `summary`, `description`, `organization`, `themes` (and MUST reject any argument naming `publicationDate`, `depublicationDate`, `status`, or a retention property); `publishPublication` → `publicationDate` only; `depublishPublication` → `depublicationDate` only; `archivePublication` → `status: "archived"` only. No write tool may exist for `catalog`, `document`, `organization`, `theme`, or any other schema, and no tool may declare scope `delete`. Reach values MUST use Hermiq's `ToolReachResolver` vocabulary; publish/depublish MUST declare reach `external` because harvested, sitemapped, and federated copies of a published record are not recalled by depublication, and their descriptions MUST state that irreversibility.

#### Scenario: The catalogue carries exactly four write tools with honest metadata

- GIVEN the MCP tool catalogue for app id `opencatalogi`,
- WHEN every tool with a non-read scope is inspected,
- THEN exactly the four tools above are found with the scope and reach stated here,
- AND the publish and depublish descriptions state that distribution is irreversible.

> @e2e exclude backend catalogue metadata — covered by PHPUnit against the attribute scanner output; no UI surface.

#### Scenario: A draft cannot arrive published

- GIVEN an agent calls `opencatalogi.createDraftPublication` with a `publicationDate` argument,
- WHEN the tool validates its arguments,
- THEN the call is rejected with a structured invalid-arguments error,
- AND no object is created; a call without forbidden arguments creates an object with no `publicationDate`, invisible to the `public` group.

> @e2e exclude backend argument-validation path — covered by PHPUnit; the public-invisibility consequence is asserted in the REQ-009 e2e flow.

### Requirement: Every write runs the existing RBAC'd path as the granting user (REQ-008)
Each write tool MUST delegate to the same `PublicationService`/ObjectService write path the UI uses, with `_rbac: true` and the catalog-scope checks running unchanged, acting as the granting user of the agent's session. A write the UI path would refuse for that user MUST fail identically through the tool, with the same domain error. The tool layer owns argument validation, the property allow-list, staging, and attribution — never a bypass of an existing check.

#### Scenario: An agent cannot publish what its human may not

- GIVEN a user without write access to a publication,
- WHEN an agent granted by that user stages and (hypothetically) executes `opencatalogi.publishPublication` for it,
- THEN the phase-2 write is denied by OpenRegister RBAC exactly as the UI write would be,
- AND `publicationDate` is unchanged.

> @e2e exclude backend gate parity — covered by PHPUnit invoking the tool path and the service path against the same fixture user.

### Requirement: Publish, depublish, and archive execute only after a server-verified human approval (REQ-009)
`publishPublication`, `depublishPublication`, and `archivePublication` MUST be two-phase: phase 1 validates and stages a batch proposal (target publication ids, intended property write, requesting agent, granting user) and writes no property; phase 2 executes only when presented with an approval token the server verifies was minted for a human approver distinct from the acting agent, unexpired, and bound to that exact batch. The gate MUST be enforced in OpenCatalogi's tool path — a client that never presents a valid token can never reach the property write, whether or not that client is Hermiq. The batch is the approval unit: the approver MUST be shown the full item list, and one approval covers exactly one staged batch. `createDraftPublication` MUST be single-phase (a draft is invisible to the public by construction).

#### Scenario: The canonical batch-publish chat flow

- GIVEN twelve draft dataset descriptions and a user asking their agent to publish them,
- WHEN the agent stages one `publishPublication` batch and the editor approves it in Hermiq,
- THEN phase 2 sets `publicationDate` on each item and the records become publicly readable, sitemapped, and harvestable,
- AND a batch the editor rejects writes nothing.

@e2e tests/e2e/spec-coverage/hermiq-ai-tooling.spec.ts

#### Scenario: An unapproved publish proposal never publishes

- GIVEN a staged publish batch for which no approval token is ever presented,
- WHEN the staging period ends,
- THEN no `publicationDate` was written for any item in the batch,
- AND the expired proposal is visible in the audit trail as proposed-but-not-approved.

> @e2e exclude backend two-phase state machine — covered by PHPUnit.

#### Scenario: A token cannot be replayed onto a different batch

- GIVEN an approval token minted for batch A,
- WHEN phase 2 of batch B is invoked with it,
- THEN the execution is refused and batch B remains staged.

> @e2e exclude backend token-binding check — covered by PHPUnit.

### Requirement: Every agent write is attributed to the agent principal in the audit trail (REQ-010)
Every write performed through a curated write tool MUST record, alongside OpenRegister's normal audit fields: the acting agent identity (from the MCP session context), the granting user, the tool id, the batch/proposal reference, and the approval token id where a gate applies. An agent-proposed, human-approved publication MUST be answerable as such from the audit trail alone — never as a purely human act.

#### Scenario: A published record is traceable to agent, approver, and grant

- GIVEN a publication published via an approved batch,
- WHEN its audit trail is read,
- THEN it names the agent, the granting user, the tool id, the batch reference, and the approval token id.

> @e2e exclude backend audit assertion — covered by PHPUnit reading the audit trail after a tool-path publish.

### Requirement: Hermiq governance is honoured but never relied upon (REQ-011)
The tools MUST declare metadata such that Hermiq classifies all four as writes, default-denied per agent until granted, with publish/depublish surfaced as external-reach; verifying that classification is part of this change's acceptance. At the same time, no OpenCatalogi-side check may assume the caller is Hermiq: the property allow-lists (REQ-007), the RBAC path (REQ-008), and the approval gate (REQ-009) MUST each hold against an arbitrary MCP client, so the app's own guarantees stand even if the agent layer is misconfigured or replaced.

#### Scenario: An ungoverned MCP client gains no extra power

- GIVEN an MCP client that is not Hermiq and performs no grant or approval bookkeeping,
- WHEN it invokes `opencatalogi.publishPublication` phases 1 and 2 without a valid approval token,
- THEN staging succeeds at most, no property is ever written,
- AND the failed execution attempt is auditable.

> @e2e exclude backend client-independence check — covered by PHPUnit driving the tool methods directly, bypassing Hermiq entirely.

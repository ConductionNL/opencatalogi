# publiccode-developer-overheid-harvest Specification

**Status**: in-progress
**Scope**: opencatalogi
**OpenSpec changes**:

- [publiccode-developer-overheid-harvest](../../changes/publiccode-developer-overheid-harvest/)

## Purpose

OpenCatalogi reads the developer.overheid.nl OSS-register, the national list of open-source repositories Dutch government organisations publish, and turns every repository with a `publiccode.yml` into a searchable component in the Componenten catalogue. The file is fetched from the forge it lives on through integriq; a component the GitHub harvest also finds stays one component. The flow and mappings are declared in the register fragment (ADR-031); the API key stays in OpenRegister's credential broker (ADR-064).

## Requirements

The requirements are defined in the open change and land here when it is archived: [publiccode-developer-overheid-harvest delta spec](../../changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md) (REQ-PDH-001 to REQ-PDH-011).

## Non-Functional Requirements

- **Performance:** One list request per 100 repositories and one file or detail request per repository; a run over 100 repositories finishes within the flow's 60-minute limit.
- **Accessibility:** WCAG 2.2 AA for the admin section; see the delta spec for the touched success criteria.
- **Internationalization:** Dutch and English MUST be supported (ADR-007).

## Acceptance Criteria

- [ ] A run against a mock of the register writes one component per repository and a second run updates them.
- [ ] A repository the GitHub harvest also finds is one object with both harvests' fields.
- [ ] Harvested components are found anonymously in search and the public API.
- [ ] The API key appears nowhere in OpenCatalogi.

## Notes

- Depends on integriq `sources-developer-overheid` for the sources `developer-overheid-oss` and `gitlab-raw`.
- Shares the slug rule and the `publiccode` schema with `publiccode-github-harvest`.
- Out of scope: the API-register, tombstoning components that leave the register.

# Woo build queue for this repository

33 OpenSpec changes in this repository close gaps in the Woo capability programme. Each has a change folder under `openspec/changes/` and an issue titled `[OpenSpec] <change-name>` that the OpenSpec workflow keeps in step with the spec.

## How to pick up a change

1. Take the first change below whose dependencies are all merged on `development`. A dependency in another repository is linked to its issue there; check that issue's linked PR is merged.
2. Inside a wave, the order below is the order to build. Statutory rows come first.
3. Read `openspec/woo-build-rules.md` before the first command, then the change's `proposal.md`, its specs and its `tasks.md`.
4. The decisions the specs cite (D1 to D13) are in `openspec/woo-decisions.md`. A spec never contradicts one. If a task seems to, stop and say so in the issue.
5. Work on the branch the issue names, open one PR with `--base development`, and close the issue through the PR.

Two things need a person, not an agent: settling the Woo refusal grounds against the law (dossiq `woo-refusal-grounds-list`, task 1, blocks seeding), and the screen-reader pass for row 15.5.

## Wave 1

| change | rows | depends on |
|---|---|---|
| [opencatalogi/diwoo-metadata-on-the-publication](https://github.com/ConductionNL/opencatalogi/issues/1753) | 2.3 (statutory), 2.13, 2.23, 2.25 | nothing |
| [opencatalogi/api-says-what-it-filtered](https://github.com/ConductionNL/opencatalogi/issues/1756) | 13.27; 13.26 gated on D10 | nothing |
| [opencatalogi/dcat-catalogue-completion](https://github.com/ConductionNL/opencatalogi/issues/1757) | 8.5, 8.6, 8.8 | nothing |
| [opencatalogi/instance-staging-mode](https://github.com/ConductionNL/opencatalogi/issues/1758) | 13.5 | nothing |
| [opencatalogi/oai-pmh-endpoint](https://github.com/ConductionNL/opencatalogi/issues/1759) | 8.12 (9.10 struck by D10) | nothing |
| [opencatalogi/officer-flow-accessibility](https://github.com/ConductionNL/opencatalogi/issues/1760) | 15.1, 15.5, 15.6 | nothing |
| [opencatalogi/publication-detail-for-the-portal](https://github.com/ConductionNL/opencatalogi/issues/1761) | 6.25; supports 6.15, 6.16, 6.19, 6.36, 7.20 | nothing |
| [opencatalogi/publication-lifecycle-on-or](https://github.com/ConductionNL/opencatalogi/issues/1754) | 5.9, 5.16, 5.17, 9.21 (5.14 moved to publication-lifecycle-on-or-draft-purge) | nothing |
| [opencatalogi/publication-plain-language-and-translation](https://github.com/ConductionNL/opencatalogi/issues/1762) | 15.4; 14.7 gated on D10 | nothing |
| [opencatalogi/publication-tells-its-source](https://github.com/ConductionNL/opencatalogi/issues/1755) | 1.17, 1.20 | nothing |
| [opencatalogi/published-file-carries-its-facts](https://github.com/ConductionNL/opencatalogi/issues/1763) | 2.20, 4.29 | nothing |
| [opencatalogi/subjects-as-first-class-records](https://github.com/ConductionNL/opencatalogi/issues/1764) | 16.9; 6.28 (OpenCatalogi half) | nothing |
| [opencatalogi/woo-access-boundary-hardening](https://github.com/ConductionNL/opencatalogi/issues/1765) | 12.5, 12.9, 12.27, 12.32 | nothing |
| [opencatalogi/woo-review-surface](https://github.com/ConductionNL/opencatalogi/issues/1766) | 4.2, 4.7, 4.12 | nothing |

## Wave 2

| change | rows | depends on |
|---|---|---|
| [opencatalogi/inspection-period-rolls-on-the-calendar](https://github.com/ConductionNL/opencatalogi/issues/1767) | supports 10.9 (statutory) | nothing |
| [opencatalogi/attachments-are-files](https://github.com/ConductionNL/opencatalogi/issues/1768) | 4.16 | nothing |
| [opencatalogi/diwoo-metadata-on-the-publication-filinq-handoff](https://github.com/ConductionNL/opencatalogi/issues/1795) | supports 2.25 (filinq handoff, D8); split from diwoo-metadata-on-the-publication | [opencatalogi/diwoo-metadata-on-the-publication](https://github.com/ConductionNL/opencatalogi/issues/1753) |
| [opencatalogi/harvest-protocol-plugins](https://github.com/ConductionNL/opencatalogi/issues/1769) | 1.6 | nothing |
| [opencatalogi/publication-lifecycle-on-or-draft-purge](https://github.com/ConductionNL/opencatalogi/issues/1796) | 5.14; split from publication-lifecycle-on-or | [opencatalogi/publication-lifecycle-on-or](https://github.com/ConductionNL/opencatalogi/issues/1754) |
| [opencatalogi/publication-relations-place-and-source-ids](https://github.com/ConductionNL/opencatalogi/issues/1770) | 2.15, 2.19; 2.16 gated on D10 | nothing |
| [opencatalogi/publication-schedule-guards](https://github.com/ConductionNL/opencatalogi/issues/1771) | 5.11, 5.13, 5.21 | `opencatalogi/integration-publish-by-reference`, [opencatalogi/publication-lifecycle-on-or](https://github.com/ConductionNL/opencatalogi/issues/1754) |
| [opencatalogi/publication-withdrawal-aftercare](https://github.com/ConductionNL/opencatalogi/issues/1772) | 5.4, 5.5, 5.18 | [openregister/object-archive-state](https://github.com/ConductionNL/openregister/issues/4390) |
| [opencatalogi/publications-name-their-responsible-organisation](https://github.com/ConductionNL/opencatalogi/issues/1773) | 2.21, 2.22 | [opencatalogi/diwoo-metadata-on-the-publication](https://github.com/ConductionNL/opencatalogi/issues/1753) |
| [opencatalogi/publications-reference-the-shared-organisation](https://github.com/ConductionNL/opencatalogi/issues/1774) | 12.34 | [openregister/object-organisation-from-a-property](https://github.com/ConductionNL/openregister/issues/4391) |
| [opencatalogi/theme-archive-hotspot](https://github.com/ConductionNL/opencatalogi/issues/1775) | 11.14 | [openregister/appraisal-inherited-from-a-parent](https://github.com/ConductionNL/openregister/issues/4382) |
| [opencatalogi/woo-annual-report](https://github.com/ConductionNL/opencatalogi/issues/1776) | 16.5 | nothing |
| [opencatalogi/woo-metadata-suggestions](https://github.com/ConductionNL/opencatalogi/issues/1777) | 13.19; 14.1, 14.2 gated on D10 | nothing |
| [opencatalogi/woo-national-output-assurance](https://github.com/ConductionNL/opencatalogi/issues/1778) | 5.19, 9.13, 9.20, 13.9 | [opencatalogi/diwoo-metadata-on-the-publication](https://github.com/ConductionNL/opencatalogi/issues/1753) |
| [opencatalogi/woo-redaction-scans-and-text-layer](https://github.com/ConductionNL/opencatalogi/issues/1779) | 4.14, 4.15 | [openregister/anonymisation-image-seam](https://github.com/ConductionNL/openregister/issues/4380), [openregister/redaction-release-safeguards](https://github.com/ConductionNL/openregister/issues/4392) |
| [opencatalogi/woo-value-lists-on-the-concept-register](https://github.com/ConductionNL/opencatalogi/issues/1780) | 13.16, 13.17; supports 12.30 (categories half, REQ-WVC-005) | [dossiq/woo-refusal-grounds-list](https://github.com/ConductionNL/dossiq/issues/3288) |

## Wave 3

| change | rows | depends on |
|---|---|---|
| [opencatalogi/woo-decision-shows-what-was-withheld](https://github.com/ConductionNL/opencatalogi/issues/1797) | supports 6.16 (second half: withheld documents and grounds on the public read of a dossiq Woo decision) | [opencatalogi/publication-detail-for-the-portal](https://github.com/ConductionNL/opencatalogi/issues/1761), [opencatalogi/woo-value-lists-on-the-concept-register](https://github.com/ConductionNL/opencatalogi/issues/1780), [dossiq/woo-refusal-grounds-list](https://github.com/ConductionNL/dossiq/issues/3288) |
| [opencatalogi/woo-request-intake-hands-over-to-dossiq](https://github.com/ConductionNL/opencatalogi/issues/1781) | supports 7.1 to 7.6, 10.8 | [dossiq/woo-request-takes-over-from-opencatalogi](https://github.com/ConductionNL/dossiq/issues/3289) |
| [opencatalogi/woo-value-list-curation](https://github.com/ConductionNL/opencatalogi/issues/1782) | 13.12, 13.13, 13.14, 13.15, 13.32 | [opencatalogi/publications-reference-the-shared-organisation](https://github.com/ConductionNL/opencatalogi/issues/1774), [opencatalogi/woo-value-lists-on-the-concept-register](https://github.com/ConductionNL/opencatalogi/issues/1780) |

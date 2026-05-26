# Capability: dashboard-widgets

## ADDED Requirements

### Requirement: Dashboard metrics (REQ-DASH-001)
The dashboard MUST load publication data and derive metrics: total/published/concept/depublished counts, KPIs, publications-by-category data, and an activity chart, only rendering data sections when data is available.

#### Scenario: Counts derived from publications
- **GIVEN** a set of loaded publications in mixed states
- **WHEN** the dashboard computes metrics
- **THEN** the published, concept, and depublished counts MUST reflect those states

### Requirement: Dashboard actions and layout (REQ-DASH-002)
The dashboard MUST offer quick actions to create a publication and open a publication, MUST resolve a schema name for display, MUST expose widget definitions, and MUST persist a layout change.

#### Scenario: Layout change persisted
- **GIVEN** the dashboard layout is rearranged
- **WHEN** the layout-change handler runs
- **THEN** the new layout MUST be persisted

### Requirement: Dashboard side bar (REQ-DASH-003)
The dashboard side bar MUST fetch catalogs and publication types, MUST offer adding a publication or an attachment and viewing a publication, MUST filter the publication-type options, and MUST clean up its state on teardown.

#### Scenario: Publication types fetched and filtered
- **GIVEN** the side bar mounts
- **WHEN** publication types are fetched
- **THEN** the filtered publication-type options MUST be derived from the fetched set

### Requirement: Dashboard widgets (REQ-DASH-004)
The catalogs, unpublished-publications, and unpublished-attachments widgets MUST fetch their data, map results to display items, and refresh on show.

#### Scenario: Widget refreshes on show
- **GIVEN** a dashboard widget
- **WHEN** the widget is shown
- **THEN** it MUST fetch data and present the resulting items

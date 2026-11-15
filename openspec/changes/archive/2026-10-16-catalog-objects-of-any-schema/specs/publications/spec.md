## ADDED Requirements

### Requirement: An object of another schema in a catalog opens on its own detail page (COS-001)

On a catalog's publications page, a row whose `@self` register and schema are not the publication pair SHALL open on the `CatalogObjectDetail` page for that register, schema and id, by a click, the View action, or a ctrl, cmd, shift or middle click in a new tab. This SHALL also hold in a catalog that does not hold the publication pair, and for every row while the publication register and schema ids are still unresolved. A row whose `@self` register or schema is not a numeric id SHALL open in CnIndexPage's form for its own schema instead, except on a middle click, which SHALL do nothing. The page SHALL show the object's properties in a read-only Data card, including free-form object properties whose schema names a `widget`. The object SHALL be edited through the page's Edit button, not inline.

#### Scenario: A component's description can be read per language
- **GIVEN** a catalog over the publiccode schema, holding a component with a Dutch and an English short and long description
- **WHEN** a user clicks the component's row on the catalog's publications page
- **THEN** the browser SHALL be on `/publications/<catalogSlug>/<register>/<schema>/<id>`
- **AND** the Data card SHALL show the Dutch and the English short and long description

#### Scenario: A ctrl click opens the component in a new tab
- **GIVEN** the same catalog
- **WHEN** a user ctrl-clicks the component's row
- **THEN** its detail page SHALL open in a new tab and the list SHALL stay open

#### Scenario: The component is edited in the form, with its description
- **GIVEN** a component's detail page
- **WHEN** a user clicks the page's Edit button
- **THEN** the form SHALL open with a JSON field holding the description per language

#### Scenario: A row without numeric ids opens in the form
- **GIVEN** a row whose `@self` register or schema is a slug instead of a numeric id
- **WHEN** a user clicks the row
- **THEN** the form for its own schema SHALL open and no page SHALL be navigated to

@e2e exclude A stored object always carries numeric register and schema ids in `@self`, so a seeded fixture cannot produce this row; covered by the CatalogPublicationsIndex component test.

#### Scenario: Rows open on the generic page while the publication ids are unresolved
- **GIVEN** the publication register and schema settings are not yet resolved
- **WHEN** a user clicks a row of the catalog
- **THEN** the row SHALL open on the CatalogObjectDetail page

@e2e exclude Needs an instance whose OpenCatalogi settings were never imported; covered by the CatalogPublicationsIndex component test.

### Requirement: The publications page offers the filter and columns sidebar (COS-002)

The Publications page SHALL enable CnIndexPage's sidebar with its search, filters and column toggles, the same as the library's index pages.

#### Scenario: The sidebar is offered on a catalog of components
- **GIVEN** a catalog over the publiccode schema
- **WHEN** a user opens its publications page
- **THEN** the page SHALL offer the sidebar with the filters and columns of the publiccode schema

### Requirement: A catalog without the publication schema lists a default column set (COS-003)

When a catalog does not hold the publication pair, its publications page SHALL list the columns Name, Summary and Updated, from the objects' `@self` metadata, instead of every property of the page's schema. The other properties SHALL stay available as optional columns in the sidebar. The publiccode schema SHALL fill `@self.summary` from the Dutch short description, else the English one. OpenRegister fills it when the object is saved, so a component saved before the schema carried this configuration SHALL have an empty Summary until it is saved again, e.g. by a re-harvest.

#### Scenario: The Componenten catalog lists three columns
- **GIVEN** a catalog over the publiccode schema only
- **AND** a component saved after the OpenCatalogi settings with this change were imported
- **WHEN** a user opens its publications page
- **THEN** the table SHALL have the columns Name, Summary and Updated
- **AND** the component's Summary SHALL be its Dutch short description

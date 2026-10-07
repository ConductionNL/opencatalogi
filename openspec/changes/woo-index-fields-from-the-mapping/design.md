# Design: woo-index-fields-from-the-mapping

## D1. Where the call goes

`lib/Service/Woo/WooIndexFieldMapper.php` (new) with `map(array $publication, ?array $organisation): array{fields: array, origin: array, refusal: ?array}`. `SitemapService::mapDiwooDocument()` calls it once per publication (not per file: the four fields are publication-level) and caches the answer for the request in an array keyed by publication uuid.

The event class is integriq's. The mapper checks `class_exists(\OCA\Integriq\Event\MappingExecutionRequestedEvent::class)` and dispatches with `IEventDispatcher::dispatchTyped()`. The class name, the slug `woo-index-publication` and `sourceApp: "opencatalogi"` are constants in the mapper with a docblock pointing at integriq's `docs/developers/run-a-mapping-from-another-app.md`. Do not rename integriq's namespace or id here; if integriq's id or namespace moves, it moves in a coordinated pass.

## D2. Input

`{title, name, tooiIdentifier, tooiCategorieUri, category, soortHandeling}`: `title` and `wooCategory` from the publication, `tooiIdentifier` from the organisation the sitemap already resolves (`publisher` today), `tooiCategorieUri` from the resolved category, `soortHandeling` from the publication or file where today's code reads it. A missing value is passed as an empty string, which the mapping's `default` filters handle.

## D3. Output and fallback

Per field: the output value when it is a non-empty string, else today's value. `origin[field]` is `mapping` or `built-in`. When `isHandled()` is false: all `built-in`, refusal null (integriq absent). When `getRefusal()` is set: all `built-in` and the refusal kept for the report. A Throwable from a listener is caught and counts as refusal `failed` with the message.

## D4. Validation stays

The mapped `informatiecategorie` goes through the same TOOI resolver as now (`REQ-WIC-001`); `publisher` must start with the TOOI organisation URI base; `soortHandeling` must be a DiWoo value. A failure is added with `addViolation()` on the same axis as today, so the readiness self-check (WOO-HR-001) catches a wrong edit on integriq's page.

## D5. Report

The readiness report (`WooReadinessService`) adds `mapping: {source: "integriq" | "built-in", refusal: ?{code, reason}, fieldsFromMapping: [...]}` from one sample publication. The settings page's readiness card shows one line: "Woo-index fields from integriq mapping woo-index-publication" or "Woo-index fields built in", with the refusal when there is one. Board `OcInstellingen` draws the readiness card; it has no line for this yet, so the line goes under the existing readiness result.

## D6. Tests

`tests/Unit/Service/Woo/WooIndexFieldMapperTest.php` with a listener on the real integriq event class when it is loadable (skipped with a named reason otherwise) and a stand-in event class in `tests/Fixtures` with the same constructor and accessors for the other cases. `SitemapServiceTest` asserts a mapped `officieleTitel` appears in the XML and a refused mapping leaves today's output byte-identical.

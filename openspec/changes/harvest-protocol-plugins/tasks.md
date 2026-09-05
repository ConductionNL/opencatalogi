# Tasks: harvest-protocol-plugins

- [ ] 0.1 Author the delta spec: plugin interface, per-protocol fetch/parse
      contracts, incremental semantics per protocol
- [ ] 1.1 Protocol plugin interface + registry; `dcat-jsonld` refactored onto
      it without behaviour change
- [ ] 1.2 DCAT Turtle/RDF-XML plugin (RDF library enters the composer tree
      here; pin and audit it)
- [ ] 1.3 OAI-PMH client plugin: resumption-token loop, `from` incremental,
      deleted-status handling (XML via `file_get_contents` +
      `simplexml_load_string` in tests)
- [ ] 1.4 CKAN plugin: package walk + mapping
- [ ] 1.5 schema.org plugin: sitemap walk + JSON-LD script extraction
- [ ] 2.1 Unit tests per plugin against local fixtures
- [ ] 2.2 Integration test: harvest this instance's own `oai-pmh-endpoint`
      round-trip
- [ ] 3.1 Quality gates as usual

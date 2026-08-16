---
kind: code
---

# Proposal: cms-proxy-removal

## Summary

Delete `MenusController`, `PagesController` and `GlossaryController` from
OpenCatalogi, one release after `cms-handover` reduced them to deprecated
proxies pointing at Portaliq's content API.

## Motivation

This change exists because `cms-handover` requires it to exist. Its Task 4
says so explicitly, and the reason is stated there: **a deprecation with no
scheduled removal becomes permanent.**

The proxies are the price of not breaking ten live municipal deployments at
cutover. They are not a design — they are a transition, and a transition with
no end date is just a second implementation that nobody owns. Writing this
change on the same day as the handover means "the proxy is still there" shows
up in a backlog rather than being remembered by whoever happens to notice.

## Preconditions

This change SHALL NOT be merged until all of the following hold. Each is a
thing to check, not a box to tick from memory:

- [ ] `cms-handover` has shipped and been in a release for at least one cycle.
- [ ] Portaliq's content API is serving the menus, pages and glossary of every
      deployment that previously read OpenCatalogi's endpoints — verified per
      deployment, not inferred from one.
- [ ] The proxy endpoints' access logs show no callers over a full release
      cycle. **Absence of logging is not absence of callers**: if the endpoints
      are not instrumented, instrument them first and wait, rather than reading
      an empty grep as proof.
- [ ] `tilburg-woo-ui` — the largest known consumer — no longer calls them.

## Affected Projects

- [ ] `opencatalogi` — remove the three controllers, their routes, their tests
      and their spec coverage.

## Risks

- **The consumer you did not know about.** These are public read endpoints; a
  municipality's own integration may call them without anyone here knowing.
  The log check above is the only real defence, and it is worth more than the
  deprecation notice.
- **Removing the routes is easy to do partially.** The controllers, the route
  entries, the tests and the specs go together; leaving a route pointing at a
  deleted controller is a 500, not a 404.

## Out of scope

Catalogues, publications, themes and directory federation. Only the website
CMS moved, and only its proxies are removed here.

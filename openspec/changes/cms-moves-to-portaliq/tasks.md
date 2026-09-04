# Tasks

## 1. The migration

- [x] 1.1 `opencatalogi:cms:migrate-to-portaliq`, dry-run by default.
- [x] 1.2 `--portal` is REQUIRED. The target model demands one and the source
      cannot supply it.
- [x] 1.3 `slug` → `route`; a slugless page becomes the portal root.
- [x] 1.4 `contents` blocks → a 12-column widget grid, stacked in authored
      order.
- [x] 1.5 `text` → `markdown`, rewriting `content` to the key the widget reads.
- [x] 1.6 Menus keep `title`, `position` and `items`.
- [x] 1.7 Migrated pages are `published`, not drafts.

## 2. Refusals and reports

- [x] 2.1 An unknown block type is REFUSED. Guessing a widget key produces a
      page that renders nothing and reports no error.
- [x] 2.2 `hero.subtitle`, and a menu's `groups` / `hideBeforeLogin` / `icon`,
      are carried in props AND reported. Portaliq declares none of them.

## 3. Verified live

- [x] 3.1 Refuses without `--portal`.
- [x] 3.2 Dry run reports every page and menu, and names what will not render.
- [x] 3.3 `--apply` moved 8 objects; the Home page's hero and text blocks became
      a hero widget at gridY 0 and a markdown widget at gridY 4, props intact,
      `content` correctly rewritten to `markdown`.
- [x] 3.4 12 unit tests on the mapping rules.

⚠️ `occ` has no user session, so the write needs `_rbac: false,
_multitenancy: false` exactly as the read does. Without it the command reports
eight permission failures and moves nothing. This is the third command in this
programme to hit it.

## 4. Follows this change

- [ ] 4.1 Remove `page` and `menu` from the register descriptor.
- [ ] 4.2 Remove `PagesController`, `MenusController` and their 8 routes.
      Consumers move to Portaliq's `/api/content/pages` and `/api/content/menus`,
      which is a BREAKING change for anything calling the old paths.
- [ ] 4.3 Remove the 4 manifest pages and the CMS frontend.
- [ ] 4.4 Update the e2e specs that drive `/pages` and `/menus` (CMS-001,
      CMS-006, CMS-010, CMS-016).

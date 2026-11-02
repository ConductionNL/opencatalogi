# Design: publications-selection-actions

## D1. Configuration over code

A bulk action names a handler and, through `props`, the dialog it opens:

```json
{ "id": "lock", "label": "Lock", "icon": "Lock", "handler": "openSelectionDialog",
  "props": { "dialog": "massLockObjects" } }
```

`openSelectionDialog` knows no dialog by name. `props.dialog` opens a
`navigationStore` dialog, `props.modal` a modal.

## D2. The selection travels with the call

CnIndexPage calls a bulk handler with `{ actionId, selectedIds, count }`. The
mass dialogs need the rows themselves (`@self.register`, `@self.schema`, the
title). `CatalogPublicationsIndex` binds every bulk action whose handler names
a function in the app's handler map (`cnCustomComponents`) to a function that
adds `objects`, the selected rows of the list (`CnIndexPage.selectedObjects`,
filtered to `selectedIds`), and `props`. No second request is made.

## D3. After the dialog

A handler returns true when it opened something. The page then waits for
`navigationStore.modal` and `navigationStore.dialog` to be empty again, clears
the selection and reloads the list.

## D4. Rights

The bulk actions follow `showDeleteAction`, the same rule as the built-in
delete. Every dialog writes through OpenRegister, which applies its own RBAC.

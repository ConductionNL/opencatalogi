# Retrofit — generic-object-modals

Describes observed behavior of 27 frontend units (modals, dialogs, presentation components) under the `generic-object-modals` capability as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units
- src/modals/object/ViewObject.vue, ObjectModal.vue, UploadObject.vue, DownloadObject.vue, LockObject.vue (single-object lifecycle — GOM-001)
- src/modals/object/MassDeleteObject.vue, MassDepublishObjects.vue, MassPublishObjects.vue, MassLockObjects.vue, MassUnlockObjects.vue, MassValidateObjects.vue (bulk operations — GOM-002)
- src/modals/object/MergeObject.vue, MigrationObject.vue, src/dialogs/generic/CopyObjectDialog.vue (transformation — GOM-003)
- src/dialogs/generic/DeleteObjectDialog.vue, src/dialogs/logs/ViewLogDialog.vue, src/dialogs/category/DeleteCategoryDialog.vue, DeleteMultipleCategoriesDialog.vue (confirmation dialogs — GOM-004)
- src/views/shared/EntityDetailPage.vue, src/components/GenericObjectTable.vue, PropertiesPanel.vue, MarkdownEditor.vue, PaginationComponent.vue, PublicationCard.vue, PublishedIcon.vue, SelectAttachmentsList.vue, SelectedObjectsList.vue (presentation — GOM-005)

## Approach
- For each unit: describe observed inputs (store state, props), outputs (store method calls, emitted events), pre/postconditions and failure modes.
- Draft REQs that match behavior (not aspirational) — grouped by the observable behavior class, capped at 5.
- Notes section surfaces observed-but-suspicious behavior (English labels outside `t()`) and the delegated-authorization security posture.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md). Cluster: `generic-object-modals`. Umbrella: ConductionNL/opencatalogi#664.

# OpenCatalogi Quality Refactoring Plan

## Current Status

### Achievements So Far ✅
- **5,483 violations auto-fixed** across 50 files
- PHPMD, PHPQA, PHPMetrics successfully installed and configured
- Composer dependencies aligned with OpenRegister's working setup
- Warnings reduced by 87% (1,196 → 153)

### Remaining Issues ❌
- **PHPCS Errors**: 2,652 (need: 0)
- **PHPMD Violations**: 352 (need: ≤50)
- **Quality Score**: 0% (need: ≥90%)

## Priority Refactoring Targets

### 1. DirectoryService (Highest Priority)
**Complexity**: 254 (threshold: 50)  
**Coupling**: 19 dependencies (threshold: 13)

Methods to refactor:
- `syncDirectory()`: 185 lines, complexity 36, NPath 129,888
- `syncListing()`: complexity 23, NPath 21,603
- `getUniqueDirectories()`: complexity 18, NPath 402

**Impact**: Reducing this will eliminate ~50+ violations

### 2. PublicationsController
**Complexity**: 68 (threshold: 50)  
**Coupling**: 16 dependencies (threshold: 13)

Methods to refactor:
- `show()`: 216 lines, complexity 15, NPath 2,306
- `uses()`: 168 lines, complexity 15, NPath 866
- `index()`: 135 lines, complexity 16, NPath 3,073

**Refactoring Strategy for `show()`**:
1. Extract validation logic → `validatePublicationStatus()`
2. Extract extend parameter building → `buildExtendParameters()`
3. Extract CORS header logic → `addCorsHeaders()`

### 3. CatalogiService
**Complexity**: 62 (threshold: 50)

Methods to refactor:
- `index()`: complexity 15, NPath 2,304
- `paginate()`: complexity 11

### 4. Migration Files
- `Version6Date20241011085015::changeSchema()`: 184 lines
  - Extract table creation into separate methods

## Refactoring Techniques

### For Long Methods (>100 lines)
1. **Extract Method**: Break into logical sub-methods
2. **Extract Class**: Move related logic to service classes
3. **Replace Conditional with Polymorphism**: For complex if/else chains

### For High Cyclomatic Complexity
1. **Simplify Conditionals**: Use early returns
2. **Extract Guard Clauses**: Move validation logic
3. **Replace Nested Conditionals**: With method calls

### For High Coupling
1. **Dependency Injection**: Inject only needed services
2. **Service Locator**: Use for optional dependencies
3. **Facade Pattern**: Simplify complex subsystem interactions

## Quick Wins (Low Effort, High Impact)

### 1. Add Missing Use Statements (~50 violations)
Files with `MissingImport` errors need explicit `use` statements.

### 2. Remove Unnecessary Else Clauses (~30 violations)
Replace:
```php
if ($condition) {
    return $value;
} else {
    return $other;
}
```

With:
```php
if ($condition) {
    return $value;
}
return $other;
```

### 3. Extract Repeated Validation Logic
Many controllers duplicate publication status checks.

## Estimated Impact

| Action | PHPMD Reduction | Effort |
|--------|-----------------|--------|
| Refactor DirectoryService | -50 to -70 | High |
| Refactor PublicationsController | -30 to -40 | Medium |
| Add missing use statements | -50 | Low |
| Remove else expressions | -30 | Low |
| Extract validation methods | -20 | Low |
| **Total Potential** | **-180 to -210** | |

**Target**: Reduce from 352 to ≤50 (need to eliminate 302)

## Implementation Order

1. **Phase 1**: Quick wins (1-2 hours)
   - Add missing use statements
   - Remove unnecessary else clauses
   - Extract common validation methods

2. **Phase 2**: Medium complexity (3-4 hours)
   - Refactor PublicationsController methods
   - Refactor CatalogiService methods
   - Extract helper methods

3. **Phase 3**: High complexity (5-8 hours)
   - Refactor DirectoryService completely
   - Break down migration methods
   - Reduce coupling in Application class

## Next Steps

1. Start with Phase 1 quick wins
2. Run quality checks after each major change
3. Commit incrementally to track progress
4. Re-run full quality suite after Phase 1

---

Generated: $(date)

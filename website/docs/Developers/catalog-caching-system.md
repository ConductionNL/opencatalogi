# Catalog Caching System - Technical Documentation

## Overview

The OpenCatalogi catalog caching system is a performance optimization that eliminates database queries from the publications API endpoints by implementing a cached aggregation strategy for catalog filters. This system maintains the catalog filtering functionality while significantly improving response times.

## Architecture

### Problem Statement

Previously, the publications endpoint would query the database to fetch all catalogs, extract their registers and schemas, and use these as filters for publications. This approach caused performance bottlenecks due to:

1. Database queries on every API request
2. Aggregation processing on each call
3. Scaling issues with multiple catalogs

### Solution Design

The new system implements a 'warmup' cache strategy where:

1. **Cache Building**: Catalog data is aggregated once and stored in IAppConfig
2. **Event-Driven Invalidation**: Cache is invalidated when catalogs change
3. **Automatic Rebuild**: Cache rebuilds automatically on cache misses
4. **Fast Retrieval**: API endpoints use cached data without database queries

## Implementation Details

### Core Components

#### 1. CatalogiService Cache Methods

```php
/**
 * Warms up the catalog cache by aggregating all catalog registers and schemas
 */
public function warmupCatalogCache(): array

/**
 * Retrieves cached catalog filters from IAppConfig  
 */
public function getCachedCatalogFilters(): ?array

/**
 * Invalidates the catalog cache by removing cached values
 */
public function invalidateCatalogCache(): bool

/**
 * Enhanced getCatalogFilters with cache-first strategy
 */
public function getCatalogFilters(null|string|int $catalogId = null): array
```

#### 2. Event Listeners for Cache Invalidation

Both `ObjectCreatedEventListener` and `ObjectUpdatedEventListener` include:

```php
/**
 * Handles catalog cache invalidation when catalog objects are modified
 */
private function handleCatalogCacheInvalidation(array $objectData): void
```

#### 3. PublicationsController Integration

```php
/**
 * Adds cached catalog filters to search query for performance
 */
private function addCachedCatalogFilters(array &$searchQuery): void
```

### Cache Storage Strategy

The system uses Nextcloud's IAppConfig for persistent storage:

- **Key**: `cached_catalog_registers` - JSON array of unique registers
- **Key**: `cached_catalog_schemas` - JSON array of unique schemas  
- **Key**: `catalog_cache_timestamp` - Cache build timestamp

### Cache Lifecycle

#### 1. Initialization
- App boot triggers cache warmup if not present
- Aggregates all catalog registers and schemas
- Stores results in IAppConfig

#### 2. Runtime Usage
- API endpoints check cache first
- Use cached filters for search queries
- Fall back to database if cache unavailable

#### 3. Invalidation Triggers
- Catalog object creation (ObjectCreatedEvent)
- Catalog object updates (ObjectUpdatedEvent)
- Manual cache clearing

#### 4. Automatic Rebuild
- Cache miss triggers automatic warmup
- Ensures system resilience
- Transparent to API consumers

## Performance Impact

### Before Optimization
- Database query per API request
- Aggregation processing overhead
- Response time increases with catalog count
- Scaling limitations

### After Optimization
- Zero database queries for filtering
- O(1) cache lookups from IAppConfig
- Consistent response times
- Horizontal scalability

### Benchmarking
Expected performance improvements:
- **Catalog Filtering**: ~200ms → <5ms per request
- **API Response Times**: 50-70% reduction
- **Database Load**: Significant reduction in catalog-related queries

## API Changes

### Backwards Compatibility
All existing API endpoints remain fully compatible:
- `/index.json` - Publication listing with catalog filtering
- `/publications/{id}` - Single publication retrieval  
- `/publications/{id}/uses` - Publication relations
- `/publications/{id}/used` - Reverse relations

### New Filtering Behavior
Search queries now include cached catalog filters:
```php
$searchQuery['@self']['register'] = $cachedRegisters;
$searchQuery['@self']['schema'] = $cachedSchemas;
```

## Error Handling and Monitoring

### Logging
The system provides comprehensive logging:
```
OpenCatalogi: Invalidated catalog cache due to catalog creation: [catalogID]
OpenCatalogi: Failed to invalidate catalog cache for catalog update: [catalogID]  
OpenCatalogi: Exception during catalog cache invalidation: [error]
OpenCatalogi: Failed to add cached catalog filters: [error]
```

### Graceful Degradation
- Cache failures don't break API functionality
- Automatic fallback to database queries
- Error logging without request failures
- System continues operating during cache rebuilds

### Monitoring Points
- Cache hit/miss rates
- Cache rebuild frequency
- Event listener execution
- API response times

## Development Guidelines

### Adding New Catalog-Aware Features
When developing features that interact with catalogs:

1. **Use CatalogiService**: Always use `getCatalogFilters()` method
2. **Cache Awareness**: Consider cache implications in catalog modifications
3. **Event Integration**: Ensure proper event listener integration
4. **Error Handling**: Implement graceful fallbacks for cache failures

### Testing Considerations
- Unit tests for cache methods
- Integration tests for event listeners
- Performance regression tests
- Cache invalidation scenario testing

### Debugging Cache Issues
1. Check IAppConfig keys: `cached_catalog_registers`, `cached_catalog_schemas`
2. Verify event listener execution in logs
3. Monitor cache rebuild frequency
4. Test manual cache invalidation

## Migration and Deployment

### Deployment Steps
1. Deploy updated code
2. Cache will auto-build on first API request
3. Monitor logs for successful cache operations
4. Verify performance improvements

### Rollback Strategy
- System degrades gracefully to database queries
- No data migration required
- Cache can be manually cleared if needed

### Configuration
No additional configuration required:
- Uses existing catalog schema/register settings
- Leverages existing IAppConfig infrastructure
- Automatic operation after deployment

## Future Enhancements

### Potential Optimizations
1. **Selective Cache Updates**: Update cache incrementally instead of full rebuilds
2. **Cache Warming Strategies**: Proactive cache building during low-traffic periods
3. **Distributed Caching**: Multi-instance cache synchronization
4. **Cache Metrics**: Built-in performance monitoring

### Extension Points
- Custom cache invalidation rules
- Alternative cache storage backends
- Cache warming hooks for external systems
- Performance metric collection interfaces

## Troubleshooting

### Common Issues

#### Cache Not Building
- Check catalog schema/register configuration
- Verify OpenRegister service availability
- Check IAppConfig permissions

#### Performance Not Improved
- Verify cache is being used (check logs)
- Ensure proper catalog configuration
- Monitor database query logs

#### Event Listeners Not Firing
- Check OpenRegister event system
- Verify event listener registration
- Monitor error logs for exceptions

### Manual Cache Management
```php
// Clear cache manually
$catalogiService->invalidateCatalogCache();

// Force cache rebuild  
$catalogiService->warmupCatalogCache();

// Check cache status
$cachedData = $catalogiService->getCachedCatalogFilters();
```

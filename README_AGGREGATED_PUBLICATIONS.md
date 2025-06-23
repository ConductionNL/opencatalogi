# Aggregated Publications API

The OpenCatalogi application now includes a powerful aggregated publications endpoint that combines publications from all default directory listings into a single response using asynchronous HTTP requests.

## Overview

The `/api/publications/aggregated` endpoint fetches publications from all publication endpoints of listings marked as 'default' and 'available', combining the results into a unified response with comprehensive metadata and error handling.

## Features

- **Asynchronous Processing**: Uses React PHP and Guzzle for concurrent HTTP requests
- **Error Handling**: Graceful handling of failed endpoints with detailed error reporting
- **Status Tracking**: Updates listing status based on endpoint response
- **Source Attribution**: Each publication includes metadata about its source
- **Performance Metrics**: Detailed statistics about execution time and success rates
- **Configurable Timeouts**: Optional Guzzle configuration via query parameters

## API Endpoint

### GET /api/publications/aggregated

**Parameters:**
- `timeout` (optional): Request timeout in seconds (max 120)
- `connect_timeout` (optional): Connection timeout in seconds (max 30)
- `headers` (optional): JSON-encoded custom headers object

**Example Request:**
```bash
# Basic request
curl "https://your-opencatalogi-instance.com/api/publications/aggregated"

# With custom timeouts
curl "https://your-opencatalogi-instance.com/api/publications/aggregated?timeout=60&connect_timeout=15"

# With custom headers
curl "https://your-opencatalogi-instance.com/api/publications/aggregated?headers={\"Authorization\":\"Bearer token\"}"
```

## Response Format

```json
{
  "results": [
    {
      "id": "publication-1",
      "title": "Example Publication",
      "description": "Publication description",
      "_source": {
        "endpoint": "https://api.example.com/publications",
        "listing_title": "Example Catalog",
        "catalog_id": "catalog-123"
      }
    }
  ],
  "total": 1,
  "sources": [
    {
      "endpoint": "https://api.example.com/publications",
      "listing_title": "Example Catalog",
      "publication_count": 1,
      "status": "success"
    }
  ],
  "errors": [],
  "statistics": {
    "total_endpoints": 1,
    "successful_calls": 1,
    "failed_calls": 0,
    "total_publications": 1,
    "execution_time": 250.5
  }
}
```

## Response Fields

### Main Response
- `results`: Array of combined publications from all successful endpoints
- `total`: Total number of publications returned
- `sources`: Array of source information for each endpoint
- `errors`: Array of errors encountered during processing
- `statistics`: Performance and execution statistics

### Publication Object
Each publication in the `results` array includes:
- Original publication data from the source endpoint
- `_source`: Metadata about the source endpoint

### Source Information
- `endpoint`: The publication endpoint URL
- `listing_title`: Human-readable name of the source catalog
- `publication_count`: Number of publications from this source
- `status`: 'success' or 'error'
- `error`: Error message (only present if status is 'error')

### Statistics
- `total_endpoints`: Number of publication endpoints found
- `successful_calls`: Number of successful HTTP requests
- `failed_calls`: Number of failed HTTP requests
- `total_publications`: Total publications aggregated
- `execution_time`: Total execution time in milliseconds

## HTTP Status Codes

- `200 OK`: All endpoints successful
- `207 Multi-Status`: Some endpoints failed, but at least one succeeded
- `500 Internal Server Error`: System error occurred
- `503 Service Unavailable`: All endpoints failed

## Error Handling

The API gracefully handles various error scenarios:

1. **HTTP Errors**: Non-2xx responses from publication endpoints
2. **Network Errors**: Connection timeouts, DNS failures, etc.
3. **Invalid JSON**: Malformed responses from endpoints
4. **Missing Results**: Endpoints that don't return a 'results' property
5. **System Errors**: Configuration issues, missing services, etc.

## Implementation Details

### DirectoryService::getPublications()

The core functionality is implemented in the `DirectoryService::getPublications()` method:

```php
public function getPublications(array $guzzleConfig = []): array
```

**Parameters:**
- `$guzzleConfig`: Optional Guzzle HTTP client configuration

**Returns:**
- Array with combined results, sources, errors, and statistics

### Asynchronous Processing

The method uses React PHP promises to execute HTTP requests concurrently:
- Creates promises for each publication endpoint
- Executes all requests simultaneously using `React\Promise\all()`
- Processes results and combines publications
- Updates listing status based on response

### Status Updates

After each endpoint call, the corresponding listing is updated with:
- `statusCode`: HTTP status code from the response
- `available`: Boolean indicating success/failure
- `lastSync`: ISO timestamp of the request

## Configuration Requirements

The aggregated publications feature requires:

1. **Default Listings**: At least one listing marked as `default: true` and `available: true`
2. **Publication Endpoints**: Listings must have valid `publications` field values
3. **OpenRegister Service**: The OpenRegister app must be installed and configured

## Use Cases

1. **Federated Search**: Search across multiple catalogs simultaneously
2. **Data Aggregation**: Combine publications from various sources
3. **Performance Monitoring**: Track availability of external catalogs
4. **API Gateway**: Provide unified access to distributed publication data

## Performance Considerations

- Concurrent requests significantly improve performance over sequential calls
- Default timeouts are set to reasonable values (30s request, 10s connect)
- Failed endpoints don't block successful ones
- Execution time is tracked and reported

## Security

- All requests include appropriate User-Agent headers
- CORS headers are properly configured for public API access
- Input validation on timeout parameters
- Graceful error handling prevents information leakage 
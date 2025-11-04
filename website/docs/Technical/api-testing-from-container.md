# API Testing from Nextcloud Docker Container

This document describes how to test OpenCatalogi APIs directly from within the Nextcloud Docker container using Cursor's integrated terminal, without needing external tools like `jq`.

## Overview

When developing OpenCatalogi features, it's often necessary to test API endpoints to verify functionality. Rather than setting up external API testing tools, we can leverage the Docker container's internal environment to make direct API calls using `curl` and standard Unix text processing tools.

## Prerequisites

- Docker container running Nextcloud with OpenCatalogi
- Access to Cursor IDE with integrated terminal
- Basic knowledge of curl and Unix text processing commands

## Container Access Method

### Finding the Container Name

First, identify your Nextcloud container:

```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker ps | grep nextcloud
```

This typically returns something like:
```
master-nextcloud-1   nextcloud:latest   ...
```

### Executing Commands in Container

Use `docker exec` to run commands inside the container:

```bash
docker exec master-nextcloud-1 [COMMAND]
```

## API Testing Techniques

### Basic API Call

```bash
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=5"
```

**Key Parameters:**
- `-s`: Silent mode (suppress progress meter)
- `http://localhost`: Use internal container network
- Full Nextcloud path: `/index.php/apps/opencatalogi/api/`

### JSON Response Processing Without jq

Since we don't want to install `jq`, use built-in text processing:

#### Extract Specific Fields with grep
```bash
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=1" | grep -o '"total":[0-9]*'
```

#### View Response Structure
```bash
# First 20 lines of response
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=5" | head -20

# Last 10 lines of response  
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=5" | tail -10

# Search for specific JSON keys
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=5" | grep -A 10 '"facets":'
```

#### Pretty Print JSON with Python
```bash
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=1" | python3 -m json.tool | tail -10
```

### URL Encoding for Complex Parameters

For complex query parameters, use curl's `--data-urlencode`:

```bash
docker exec master-nextcloud-1 curl -s -G "http://localhost/index.php/apps/opencatalogi/api/publications" \
  --data-urlencode "_facets[@self][register][type]=terms" \
  --data-urlencode "_limit=5" | grep -A 10 '"facets":'
```

**Benefits of --data-urlencode:**
- Properly encodes special characters like `[`, `]`, `@`
- Handles spaces and other URL-unsafe characters
- More reliable than manual URL encoding

### Testing Different Endpoints

#### Facetable Discovery
```bash
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_facetable=true&_limit=1" | grep -A 30 '"facetable":'
```

#### Federation Testing
```bash
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/federation/publications?_limit=2&_aggregate=true" | tail -30
```

#### Facet Queries
```bash
# Terms facet
docker exec master-nextcloud-1 curl -s -G "http://localhost/index.php/apps/opencatalogi/api/publications" \
  --data-urlencode "_facets[@self][published][type]=date_histogram" \
  --data-urlencode "_facets[@self][published][interval]=month" \
  --data-urlencode "_limit=3" | grep -A 15 '"facets":'
```

### Response Analysis Patterns

#### Count Results
```bash
# Get total count
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=1" | grep -o '"total":[0-9]*'

# Count actual results in response
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=50" | grep -c '"id":'
```

#### Check for Specific Fields
```bash
# Check if facets are present
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_facetable=true&_limit=1" | grep '"facetable":'

# Check directory field in results
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=5" | grep '"directory":'
```

## Advanced Techniques

### Save Response to File for Analysis
```bash
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_facetable=true&_limit=0" > /tmp/facetable_response.json
cat /tmp/facetable_response.json | grep -A 100 facetable
```

### Performance Testing
```bash
# Time API calls
time docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=50" > /dev/null
```

### Log Monitoring During Testing
```bash
# In separate terminal - monitor Nextcloud logs during API calls
cd /home/rubenlinde/nextcloud-docker-dev && docker exec master-nextcloud-1 tail -f /var/www/html/data/nextcloud.log | head -20 &
```

## Common API Testing Scenarios

### 1. Faceting System Testing
```bash
# Discover available facets
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_facetable=true&_limit=1" | grep -A 30 '"facetable":'

# Test facet query
docker exec master-nextcloud-1 curl -s -G "http://localhost/index.php/apps/opencatalogi/api/publications" \
  --data-urlencode "_facets[@self][schema][type]=terms" \
  --data-urlencode "_limit=3" | grep -A 20 '"facets":'

# Test federation with facets
docker exec master-nextcloud-1 curl -s -G "http://localhost/index.php/apps/opencatalogi/api/publications" \
  --data-urlencode "_facetable=true" \
  --data-urlencode "_aggregate=true" \
  --data-urlencode "_limit=10" | grep -A 30 '"facetable":'
```

### 2. Federation Testing
```bash
# Check federation sources
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_aggregate=true&_limit=5" | tail -20

# Compare local vs federated counts
echo "Local count:"
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_aggregate=false&_limit=1" | grep -o '"total":[0-9]*'
echo "Federated count:"
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_aggregate=true&_limit=1" | grep -o '"total":[0-9]*'
```

### 3. Directory Information Testing
```bash
# Check if directory field is being added to publications
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=5" | grep '"directory":'

# Check facetable directory information
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_facetable=true&_limit=1" | grep -A 10 '"directory":'
```

## Troubleshooting Tips

### 1. Empty Responses
If API returns empty or unexpected results:
```bash
# Check the raw response structure
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=1" | head -50

# Check HTTP status
docker exec master-nextcloud-1 curl -s -w "HTTP Status: %{http_code}\n" "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=1" > /dev/null
```

### 2. URL Encoding Issues
When parameters contain special characters:
```bash
# Use --data-urlencode instead of manual encoding
docker exec master-nextcloud-1 curl -s -G "http://localhost/index.php/apps/opencatalogi/api/publications" \
  --data-urlencode "_facets[@self][register][type]=terms" \
  --data-urlencode "_limit=5"
```

### 3. Container Access Issues
```bash
# Verify container is running
docker ps | grep nextcloud

# Check if API endpoint exists
docker exec master-nextcloud-1 curl -s -w "HTTP Status: %{http_code}\n" "http://localhost/index.php/apps/opencatalogi/api/publications" -o /dev/null
```

## Best Practices

1. **Always use `-s` flag** with curl to suppress progress output
2. **Use `--data-urlencode`** for complex parameters to avoid encoding issues
3. **Pipe to text processing tools** like `grep`, `head`, `tail` instead of installing jq
4. **Monitor logs in parallel** when testing to see backend behavior
5. **Save complex responses to files** for detailed analysis
6. **Test both local and federated endpoints** to verify federation logic
7. **Use meaningful limits** (_limit=1 for structure checks, higher for data testing)

## Example Testing Workflow

```bash
# 1. Check basic API functionality
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_limit=1" | head -10

# 2. Test facetable discovery
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_facetable=true&_limit=1" | grep -A 20 '"facetable":'

# 3. Test facet queries
docker exec master-nextcloud-1 curl -s -G "http://localhost/index.php/apps/opencatalogi/api/publications" \
  --data-urlencode "_facets[@self][schema][type]=terms" \
  --data-urlencode "_limit=3" | grep -A 10 '"facets":'

# 4. Test federation
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_aggregate=true&_limit=5" | tail -10

# 5. Verify directory faceting
docker exec master-nextcloud-1 curl -s "http://localhost/index.php/apps/opencatalogi/api/publications?_facetable=true&_aggregate=true&_limit=1" | grep -A 30 '"directory":'
```

This approach provides efficient API testing without requiring additional tools or complex setup, leveraging the existing Docker container environment effectively. 
# Prometheus Metrics Endpoint

## Problem
Expose application metrics in Prometheus text exposition format at `GET /api/metrics` and a health check at `GET /api/health` for monitoring, alerting, and operational dashboards. These endpoints enable integration with standard observability stacks (Prometheus + Grafana) used by Dutch municipalities and hosting providers.

## Proposed Solution
Implement Prometheus Metrics Endpoint following the detailed specification. Key requirements include:
- Requirement: Metrics endpoint MUST expose standard metrics
- Requirement: Publication metrics MUST be exposed
- Requirement: Catalog metrics MUST be exposed
- Requirement: Listing metrics MUST be exposed
- Requirement: Search metrics MUST be exposed

## Scope
This change covers all requirements defined in the prometheus-metrics specification.

## Success Criteria
- Metrics endpoint returns valid Prometheus format
- App info metric is present
- App up metric indicates health
- Metrics endpoint requires admin authentication
- Metrics endpoint handles database errors gracefully

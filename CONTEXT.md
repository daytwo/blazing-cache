# Blazing Cache

Domain language for cache behavior and invalidation semantics in this plugin.

## Language

**Sentinel Dependency**:
A synthetic dependency identifier that represents a collection view instead of one concrete element, so list pages can be invalidated when membership changes.
_Avoid_: Marker ID, fake dependency, list token

**Dependency URI**:
The route-like identifier registered by templates to describe which cached page should be invalidated when a dependency changes.
_Avoid_: Cache key, URL path, page token

**Cache Key**:
The canonical storage identifier derived from request path, normalized query parameters, and pagination rules.
_Avoid_: Dependency URI, filename, route

**Asset Invalidation**:
Best-effort invalidation behavior for asset-related dependencies, where purge precision depends on explicitly registered dependency mappings.
_Avoid_: Guaranteed asset purge, exact asset matching

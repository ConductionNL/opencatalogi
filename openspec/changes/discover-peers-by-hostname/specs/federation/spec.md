# Federation

## ADDED Requirements

### Requirement: A peer can be added by hostname (FED-013)

`DirectoryService::syncDirectory()` SHALL accept a bare host (`cloud.example.org`, `https://cloud.example.org`, `cloud.example.org:8443`). Before validating the URL, it SHALL resolve the host to the peer's directory URL by reading the peer's public Nextcloud capabilities.

#### Scenario: The peer advertises its directory

- **WHEN** `GET <host>/ocs/v2.php/cloud/capabilities?format=json` (header `OCS-APIRequest: true`) returns `opencatalogi.discovery.links.directory = /apps/opencatalogi/api/directory`
- **THEN** the directory URL is `<host>/apps/opencatalogi/api/directory`, or `<host>/index.php/apps/opencatalogi/api/directory` when `core.mod-rewrite-working` is false.

#### Scenario: The peer does not advertise discovery

- **WHEN** the capabilities answer has no `opencatalogi.discovery.links.directory`, or cannot be fetched
- **THEN** the conventional path `<host>/index.php/apps/opencatalogi/api/directory` is used.

#### Scenario: A full URL is unchanged

- **WHEN** the input is a URL with a path
- **THEN** it is used exactly as given, and no capabilities request is made.

#### Scenario: The resolution is SSRF-guarded

- **WHEN** the host is local, private or otherwise refused by `assertSafeOutboundUrl()`
- **THEN** resolution throws `InvalidArgumentException` before any request is made.
- **AND WHEN** the advertised path is protocol-relative or contains `..`
- **THEN** it is ignored and the conventional path is used.

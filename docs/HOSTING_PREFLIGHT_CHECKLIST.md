# Hosting / Server Preflight Checklist

> **Do not design the production deployment around assumed hosting capabilities.**

Use this checklist as the mandatory first step for every production web project, before choosing the deployment architecture or investing substantial development time.

**Core principle:** First inspect the real target hosting/server environment. Then design the deployment architecture around verified capabilities.

Treat provider documentation and control-panel settings as claims until they are tested in the actual account and runtime. Record both **configured** values and **measured/tested** behavior, with dates and evidence. Never record passwords, tokens, private keys, or other secrets in this document.

## Why this exists

Shared-hosting deployments, including environments such as Netcup, can expose material differences between SSH, PHP CLI, FastCGI, the web server, and the control panel. Familiar-looking access does not guarantee a normal Linux shell, standard tools, identical filesystem paths, forwarded authorization headers, usable symlinks, background processes, or matching upload limits. Discovering those constraints early is cheaper and safer than redesigning the application or release process late.

## Evidence record

For every check, prefer measured evidence over assumptions.

| Field | Value |
|---|---|
| Date and time checked | |
| Checked by | |
| Target account/environment | |
| Provider documentation consulted | |
| Commands/probes used | |
| Evidence location | |

- [ ] Each critical capability is marked as documented, configured, or actually tested.
- [ ] Tests were performed in the target account and relevant runtime.
- [ ] Temporary probes and test data were removed after use.
- [ ] No secret values were copied into this checklist or its evidence.

## 1. Project deployment requirements

Write down what the application actually needs before touching the server.

| Requirement | Project need | Version, limit, or target | Critical? | Notes |
|---|---|---|---|---|
| Runtime(s) | PHP / Node / Python / static / other | | Yes / No | |
| Framework(s) | | | Yes / No | |
| Build-time requirements | | | Yes / No | |
| Runtime requirements | | | Yes / No | |
| Database | | | Yes / No | |
| Queues/workers | | | Yes / No | |
| Cron/scheduled jobs | | | Yes / No | |
| Persistent storage | | | Yes / No | |
| Private storage | | | Yes / No | |
| Typical/maximum media size | | | Yes / No | |
| Maximum request/upload size | | | Yes / No | |
| Expected execution time | | | Yes / No | |
| Domains/subdomains | | | Yes / No | |
| TLS | | | Yes / No | |
| Outgoing network/API access | | | Yes / No | |
| Incoming webhooks | | | Yes / No | |
| CI/CD requirements | | | Yes / No | |
| Rollback requirements | | | Yes / No | |
| Expected traffic growth | | | Yes / No | |
| Expected storage growth | | | Yes / No | |

- [ ] Build-time and runtime needs are explicitly separated.
- [ ] Hard requirements are distinguished from preferences.
- [ ] Expected growth and operational headroom are quantified.
- [ ] These requirements are evaluated in the hosting requirements matrix below.

## 2. Access and hosting control panel

| Check | Recorded value | Verified how | Tested? |
|---|---|---|---|
| Provider | | | Yes / No |
| Hosting plan/product | | | Yes / No |
| Control panel and version | | | Yes / No |
| SSH availability | | | Yes / No |
| SFTP availability | | | Yes / No |
| FTP availability, if relevant | | | Yes / No |
| Database administration | | | Yes / No |
| DNS access | | | Yes / No |
| TLS/certificate management | | | Yes / No |
| PHP selector | | | Yes / No |
| Cron UI | | | Yes / No |
| Application/PHP/web logs | | | Yes / No |
| Backup controls | | | Yes / No |
| Restore process | | | Yes / No |
| Provider support restrictions | | | Yes / No |

- [ ] Exact account-independent path patterns and capabilities are recorded where useful.
- [ ] Login and recovery procedures are known without storing credentials here.
- [ ] A restore was tested or the provider's restore constraints were confirmed.

## 3. Operating environment

Verify each execution context independently.

| Item | Web/FastCGI/FPM | CLI/SSH | Evidence |
|---|---|---|---|
| Web server: Apache/nginx/other | | N/A | |
| PHP/runtime version | | | |
| Enabled PHP extensions | | | |
| Loaded `php.ini` and scanned INI files | | | |
| Relevant `php.ini` values | | | |
| Environment variables available by name | | | |
| Shell type | N/A | | |
| OS/kernel, if exposed | | | |
| CPU architecture | | | |
| Locale | | | |
| Timezone | | | |
| Process restrictions | | | |

- [ ] Web server type and request execution model are confirmed.
- [ ] PHP FastCGI/FPM and PHP CLI are inspected separately.
- [ ] Only non-secret environment variable names and availability are recorded.
- [ ] Provider process, command, and resource restrictions are documented.

## 4. PHP CLI vs web/FastCGI parity

> **Critical:** PHP CLI and web/FastCGI are separate runtime contexts until proven otherwise. Never infer web behavior from an SSH command alone.

| Comparison | CLI value/path | Web/FastCGI value/path | Match? | Impact/workaround |
|---|---|---|---|---|
| PHP version | | | Yes / No | |
| `memory_limit` | | | Yes / No | |
| `max_execution_time` | | | Yes / No | |
| `max_input_time` | | | Yes / No | |
| `post_max_size` | | | Yes / No | |
| `upload_max_filesize` | | | Yes / No | |
| `max_file_uploads` | | | Yes / No | |
| `open_basedir` | | | Yes / No | |
| `session.save_path` | | | Yes / No | |
| Temporary directory | | | Yes / No | |
| Loaded extensions | | | Yes / No | |
| INI scan directories | | | Yes / No | |
| Real filesystem paths | | | Yes / No | |
| User/group identity, if observable | | | Yes / No | |

- [ ] CLI values were captured from the exact PHP binary intended for deployment jobs.
- [ ] Web values were captured through the real domain/subdomain and request path.
- [ ] A tiny temporary authenticated or access-restricted web probe was used if necessary.
- [ ] The probe returned only the minimum required diagnostic values.
- [ ] The probe was removed immediately and its URL no longer responds.
- [ ] `phpinfo()` is not publicly accessible and was not left on the server.
- [ ] Every mismatch has an impact assessment and accepted workaround or blocking result.

## 5. Shell capabilities

Do not assume SSH provides a normal Linux environment. Test the exact command and required options, not only whether a similarly named binary exists.

| Tool | Required? | Available? | Version/path | Required options tested? | Alternative |
|---|---|---|---|---|---|
| `bash` / `sh` | | | | | |
| `cp` | | | | | |
| `mv` | | | | | |
| `rm` | | | | | |
| `mkdir` | | | | | |
| `find` | | | | | |
| `grep` | | | | | |
| `sed` | | | | | |
| `awk` | | | | | |
| `stat` | | | | | |
| `sha256sum` | | | | | |
| `tar` | | | | | |
| `gzip` | | | | | |
| `unzip` | | | | | |
| `curl` | | | | | |
| `wget` | | | | | |
| `git` | | | | | |
| `rsync` | | | | | |
| `ssh` | | | | | |
| `scp` | | | | | |
| `node` | | | | | |
| `npm` | | | | | |
| `composer` | | | | | |

- [ ] Missing tools are explicitly recorded.
- [ ] Required quoting, exit-code, permission, and filesystem behavior was tested.
- [ ] Every unavailable dependency is replaced by a verified tool, an application runtime API, or a changed deployment design.

## 6. Node / build environment

| Check | Configured/documented | Actually tested | Evidence/limit |
|---|---|---|---|
| Node is available | | | |
| Supported Node version | | | |
| npm is available | | | |
| Package installation works | | | |
| Child process execution works | | | |
| Persistent Node processes are allowed | | | |
| Available memory and time are sufficient | | | |
| Build directories are writable | | | |

Choose one build model before designing the release pipeline:

- [ ] **A. Build on server** — every required runtime, tool, resource, and permission was tested.
- [ ] **B. Build in CI and upload artifacts** — artifact transport and runtime compatibility were tested.
- [ ] **C. Static deployment** — all dynamic requirements are handled elsewhere or removed.
- [ ] **D. External build service** — integration, artifact integrity, availability, and cost are accepted.

**Selected model and reason:**

## 7. Filesystem and path model

Map the real filesystem without recording secrets or unrelated account data.

| Purpose | CLI-visible path/pattern | Web-visible path/pattern | Owner/access | Verified? |
|---|---|---|---|---|
| Account root | | | | |
| Application root | | | | |
| Public document root | | | | |
| Admin document root | | | | |
| Private storage | | | | |
| Temporary storage | | | | |
| Log locations | | | | |
| Release directory | | | | |
| Backup directory | | | | |

- [ ] `realpath` behavior is tested from CLI and web contexts.
- [ ] Relative symlink creation, resolution, and serving are tested.
- [ ] Absolute symlink creation, resolution, and serving are tested.
- [ ] Symlink traversal works under the web user where the design requires it.
- [ ] Writable directories and ownership/permission behavior are proven.
- [ ] Executable-file and mount restrictions are documented.
- [ ] `open_basedir` boundaries are tested in the web context.
- [ ] CLI and FastCGI path namespaces are compared.

> **Warning:** CLI and the web server may expose the same files through different absolute path prefixes. Never build release paths from one context and assume they work in the other.

## 8. Document root and routing

| Check | Result | Verified how | Notes |
|---|---|---|---|
| Domain document root | | | |
| Subdomain document root | | | |
| Document roots can be changed | | | |
| `.htaccess` is supported | | | |
| Rewrite/module behavior works | | | |
| `DirectoryIndex` behavior | | | |
| Static files are served correctly | | | |
| Fallback routing to `index.php` works | | | |
| Custom error pages work | | | |
| Redirect rules work | | | |
| Canonical HTTPS behavior works | | | |

- [ ] Routing was tested with a normal route, unknown route, static file, and nested path.
- [ ] Document-root changes were proven in the actual control panel, not merely assumed.

## 9. HTTP headers / FastCGI / reverse proxy

Test headers end to end through the real public request path.

| Header | Required? | Sent value type | Application received? | Transformation/rule |
|---|---|---|---|---|
| `Authorization` | | Basic / Bearer | | |
| `Content-Type` | | | | |
| `Content-Length` | | | | |
| `X-Forwarded-Proto` | | | | |
| `Host` | | | | |
| Required custom `X-*` headers | | | | |

- [ ] `Authorization` reaches the application runtime unchanged or through a documented safe mapping.
- [ ] Provider-specific forwarding/rewrite rules are recorded and tested.
- [ ] Trusted-proxy configuration accepts forwarded headers only from legitimate proxies.
- [ ] Header tests use non-secret disposable values.

Do not assume Apache/FastCGI forwards `Authorization` automatically.

## 10. Request / response limits

| Limit | Configured/documented | Measured value | Test payload | Result |
|---|---|---|---|---|
| Request body limit | | | | |
| Upload limit | | | | |
| Proxy/body limit | | | | |
| PHP `post_max_size` | | | | |
| PHP `upload_max_filesize` | | | | |
| Request timeout | | | | |
| FastCGI timeout | | | | |
| Reverse-proxy timeout | | | | |
| Idle timeout | | | | |
| Response size limit | | | | |
| Files per request / `max_file_uploads` | | | | |

- [ ] Representative payloads were tested through the browser/application path.
- [ ] Failure behavior and user-visible error handling are known.
- [ ] Effective limit is based on the lowest limit across every request layer.

## 11. Resource limits

| Resource | Documented limit | Measured/observed | Project peak | Headroom | Result |
|---|---|---|---|---|---|
| Web PHP memory | | | | | |
| Shell/CLI memory | | | | | |
| CPU restrictions | | | | | |
| Process count | | | | | |
| Execution time | | | | | |
| Storage quota | | | | | |
| Inode/file count | | | | | |
| Bandwidth/traffic | | | | | |
| Database storage | | | | | |
| Database connections | | | | | |

- [ ] Peak demand and expected growth fit with explicit safety headroom.
- [ ] Quota monitoring and exhaustion behavior are understood.

## 12. Database

Use only safe, read-only probes during initial inspection.

| Check | Value/result | Verified how | Notes |
|---|---|---|---|
| Engine and version | | | |
| Host and port | | | |
| Application can connect | | | |
| Charset and collation | | | |
| Database/session timezone | | | |
| Transaction support | | | |
| `max_allowed_packet`, if relevant | | | |
| Connection limits | | | |
| Backup/export capability | | | |
| Restore procedure | | | |
| Migration permissions | | | |
| Database user privileges | | | |

- [ ] Required extensions/drivers exist in both relevant runtime contexts.
- [ ] Least-privilege application and migration needs are understood.
- [ ] Backup consistency and restoration constraints are documented.
- [ ] No destructive schema or data changes were made during preflight.

## 13. Persistent storage / media

| Check | Requirement | Tested result | Evidence/notes |
|---|---|---|---|
| Maximum application file size | | | |
| Browser upload path | | | |
| SFTP import path, if needed | | | |
| Private vs public media separation | | | |
| Filesystem permissions | | | |
| MIME/`fileinfo` support | | | |
| Large-file streaming | | | |
| Large-file hashing | | | |
| Temporary space | | | |
| Cleanup policy | | | |
| Backup coverage | | | |

- [ ] A representative large test file was uploaded/imported, processed, read, and removed.
- [ ] Private media cannot be fetched directly from the public document root.
- [ ] Temporary and persistent storage capacity includes operational headroom.
- [ ] Backup and restore coverage includes required media and metadata.

## 14. Cron / queues / background workers

| Capability | Required? | Available? | Actually tested | Restrictions/workaround |
|---|---|---|---|---|
| Cron | | | | |
| Minimum cron frequency | | | | |
| Correct CLI PHP/runtime path | | | | |
| Queue workers | | | | |
| Long-running processes | | | | |
| Supervisor/systemd access | | | | |
| Process restart/termination behavior | | | | |

- [ ] A scheduled test task ran under the expected user, directory, environment, and runtime.
- [ ] Overlap, locking, retries, missed runs, and failure reporting are defined.
- [ ] Worker restart behavior after deploys and provider maintenance is known.
- [ ] If persistent workers are impossible, the application design was changed before implementation.

## 15. Outbound network access

Test from the application runtime and CLI where each is used.

| Destination/service | Required port/protocol | DNS works | TLS validates | Connection works | Restrictions |
|---|---|---|---|---|---|
| GitHub API | | | | | |
| SMTP | | | | | |
| Payment/API providers | | | | | |
| Webhook destinations | | | | | |
| Object storage | | | | | |
| External build systems | | | | | |
| Other required service | | | | | |

- [ ] Outbound port restrictions are documented.
- [ ] TLS certificate validation succeeds with the actual runtime trust store.
- [ ] Timeouts, retries, and failure behavior are acceptable.

## 16. Inbound connectivity

| Check | Requirement | Tested result | Notes |
|---|---|---|---|
| GitHub/CI callbacks | | | |
| Webhook routes | | | |
| Firewall/WAF behavior | | | |
| Basic authorization | | | |
| Bearer authorization | | | |
| Rate limiting | | | |
| Required IP allow/deny rules | | | |

- [ ] Each external sender can reach the exact HTTPS route it will use.
- [ ] Authentication headers and request bodies survive all proxy/WAF layers.
- [ ] Replay protection, request verification, and safe failure responses are defined.

## 17. DNS and TLS

| Check | Current state | Target state | Owner | Verified? |
|---|---|---|---|---|
| Nameservers | | | | |
| A records | | | | |
| AAAA records | | | | |
| CNAME records | | | | |
| Propagation/TTL plan | | | | |
| `www`/non-`www` policy | | | | |
| Admin/API subdomains | | | | |
| Certificate issuance | | | | |
| Certificate renewal | | | | |
| HTTPS redirects | | | | |
| HSTS decision | | | | |

- [ ] Existing records and TTLs are captured before any planned cutover.
- [ ] Certificate issuance and renewal work for every required hostname.
- [ ] Redirect loops, mixed content, and canonical host behavior are tested.
- [ ] DNS will not be switched before validation and rollback plans exist.

## 18. CI/CD compatibility

Determine exactly how CI will interact with the host.

| Candidate transport | Available? | End-to-end tested? | Restrictions/decision |
|---|---|---|---|
| SSH | | | |
| SCP | | | |
| SFTP | | | |
| Authenticated HTTPS API | | | |
| Provider deployment mechanism | | | |

- [ ] Maximum artifact size and realistic transfer time are known.
- [ ] Chunking/resume requirements are known.
- [ ] Credentials can be scoped, rotated, and stored in the approved secret store.
- [ ] Host-key or server-certificate verification is enforced.
- [ ] Every artifact carries an immutable source revision/release identifier.
- [ ] Checksums are validated after transfer.
- [ ] Upload and activation steps are retry-safe and idempotent.
- [ ] A staging directory exists outside the live release path.
- [ ] Atomic activation is possible and proven.

**Selected CI/CD transport and reason:**

## 19. Deployment and atomicity

Define this before the first real deployment.

| Decision | Defined value | Tested? | Evidence/notes |
|---|---|---|---|
| Build artifact format | | | |
| Immutable release identifier | | | |
| Staging path | | | |
| Pre-activation validation | | | |
| Maintenance mode, if needed | | | |
| Atomic switch mechanism | | | |
| Configuration preservation | | | |
| Database migration order | | | |
| Filesystem migration order | | | |
| Old-release cleanup policy | | | |

- [ ] Partial transfers cannot become the live release.
- [ ] Activation is a single atomic operation or has a documented safe substitute.
- [ ] Shared configuration and persistent data survive release switches.
- [ ] Database compatibility across deployment and rollback windows is understood.
- [ ] Editing live files manually is not the normal release mechanism.

## 20. Rollback

> **Mandatory before cutover:** No public cutover without a tested rollback path.

| Rollback item | Location/method | Responsible person | Tested? | Estimated time |
|---|---|---|---|---|
| Backup location | | | | |
| Previous application files | | | | |
| Previous static release | | | | |
| Database rollback limitations | | | | |
| Configuration backup | | | | |
| Symlink/document-root/pointer revert | | | | |
| Rollback command sequence | | | | |

- [ ] Rollback triggers and decision authority are defined.
- [ ] The exact rollback sequence is written and safely rehearsed.
- [ ] Backups can be restored, not merely created.
- [ ] Irreversible database and filesystem changes are identified.
- [ ] Monitoring verifies service health after rollback.

## 21. Logging and observability

| Signal | Access/location | Retention | Tested? | Failure indicator |
|---|---|---|---|---|
| Application logs | | | | |
| PHP error logs | | | | |
| Web server access/error logs | | | | |
| Database errors, where available | | | | |
| CI logs | | | | |
| Disk/quota monitoring | | | | |

- [ ] A deliberately safe test event can be traced through relevant logs.
- [ ] Log timestamps, timezone, rotation, and retention are understood.
- [ ] A failed deployment can be identified quickly with clear ownership and alerts.
- [ ] Logs do not expose secrets or sensitive request data.

## 22. Security baseline

- [ ] Production debug mode is disabled.
- [ ] Secrets are stored outside the public root and approved for the platform.
- [ ] Private storage is outside the document root or equivalently access-controlled.
- [ ] File and directory permissions follow least privilege.
- [ ] HTTPS works for every production hostname.
- [ ] Secure cookie/session settings are enabled and tested.
- [ ] CSRF protection is enabled where applicable.
- [ ] Authorization forwarding is verified end to end.
- [ ] No public diagnostic scripts remain.
- [ ] `.env` and equivalent configuration files cannot be downloaded.
- [ ] Repository metadata such as `.git` cannot be downloaded.
- [ ] Backup archives are not stored under a public document root.
- [ ] Directory listing is disabled unless explicitly required.
- [ ] Default/example credentials and unnecessary control-panel users are absent.

## 23. Performance reality check

Test representative behavior before declaring the hosting suitable.

| Scenario | Representative load/payload | Target | Measured result | PASS? |
|---|---|---|---|---|
| Normal dynamic request | | | | |
| Database-backed request | | | | |
| Representative media request | | | | |
| Large upload/import | | | | |
| Large-media hashing/processing | | | | |
| Production build, if run on host | | | | |
| Static asset delivery | | | | |

- [ ] Tests use production-like payloads without production secrets or personal data.
- [ ] Cold/warm behavior and realistic concurrency are considered.
- [ ] Measured latency, throughput, memory, and execution time are recorded where relevant.
- [ ] Results include sufficient headroom for expected growth and traffic peaks.

## 24. Hosting requirements matrix

Complete this comparison from the project requirements and verified hosting evidence.

| Requirement | Needed by project | Hosting provides | Verified how | Result | Notes |
|---|---|---|---|---|---|
| Runtime and version | | | | NOT TESTED | |
| Build environment | | | | NOT TESTED | |
| Database | | | | NOT TESTED | |
| Request/upload limits | | | | NOT TESTED | |
| Storage and media | | | | NOT TESTED | |
| Cron/workers | | | | NOT TESTED | |
| Outbound/inbound network | | | | NOT TESTED | |
| Filesystem/document roots | | | | NOT TESTED | |
| CI/CD transport | | | | NOT TESTED | |
| Atomic deployment | | | | NOT TESTED | |
| Rollback | | | | NOT TESTED | |
| Observability/security | | | | NOT TESTED | |
| Other critical requirement | | | | NOT TESTED | |

Allowed result values:

- `PASS`
- `PASS WITH WORKAROUND`
- `FAIL`
- `NOT TESTED`

Every `PASS WITH WORKAROUND` must name an owner, risk, acceptance decision, and validation method in the notes or linked evidence.

## 25. Go / no-go gate

### GO only when

- [ ] Every critical requirement is `PASS` or has an explicitly accepted workaround.
- [ ] Web and CLI runtime differences are documented.
- [ ] The filesystem and document-root model is understood and tested.
- [ ] The deployment transport is proven end to end.
- [ ] Representative large-file behavior is proven.
- [ ] Rollback is defined and tested to an appropriate level.
- [ ] No required runtime capability is merely assumed.

### NO-GO when any applies

- [ ] A required capability is unavailable.
- [ ] Provider restrictions force an unsafe deployment design.
- [ ] Required file or request sizes cannot be handled reliably.
- [ ] No acceptable atomic deployment path exists.
- [ ] Rollback cannot be performed safely.

**Decision:** GO / GO WITH RESTRICTIONS / NO-GO

**Decision owner:**

**Accepted restrictions and rationale:**

> Changing hosting early is often cheaper than redesigning the application late.

## 26. Final server profile

```text
Provider:
Plan:
Date checked:
Checked by:
Domains:
Web server:
Web PHP:
CLI PHP:
Database:
SSH:
SFTP:
Node:
npm:
Cron:
Long-running workers:
open_basedir:
Document roots:
Private storage:
Upload limits:
Execution limits:
Memory limits:
Storage quota:
CI/CD method:
Deployment method:
Rollback method:
Known restrictions:
Accepted workarounds:
Decision: GO / GO WITH RESTRICTIONS / NO-GO
```

## 27. Recheck triggers

Repeat this preflight, update the evidence date, and revisit the go/no-go decision when:

- [ ] The hosting plan changes.
- [ ] The provider changes.
- [ ] The PHP or another runtime version changes.
- [ ] A server migration occurs.
- [ ] The control panel or server stack changes.
- [ ] The project architecture changes.
- [ ] Upload or media requirements increase.
- [ ] The CI/CD method changes.
- [ ] A material provider restriction, outage pattern, or performance regression appears.

Do not carry an old `PASS` forward without retesting the capability affected by the change.

---

**Hosting preflight completed before deployment architecture: YES / NO**

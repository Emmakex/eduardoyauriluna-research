# Site Architect #475.17 — exportación autónoma verificable

El producto es **Site Architect 0.11.1 real**, no otro plugin reducido.

Desde el commit exacto del repositorio de Eduardo:

    python3 scripts/test-site-architect-product-export.py
    python3 scripts/export-site-architect-product.py --source-commit "$(git rev-parse HEAD)"

Artefactos generados en dist/site-architect-standalone:
- site-architect-0.11.1-standalone-source-candidate.zip
- checksum SHA-256 del ZIP
- manifest de procedencia con hashes SHA-256 de todos los archivos

Al extraerlo, site-architect/ contiene TODO el plugin real, un CI propio,
script constructor, prueba, README y SOURCE_COMMIT. Su ZIP WordPress se
reconstruye fuera del repositorio original y es idéntico byte a byte.

La extensión Research queda presente y ligada al Theme Research; WordPress
genérico conserva solo las operaciones de lectura ya probadas. No se incluye
Theme, Blueprint de la instancia, contraseñas, datos ni configuración de Eduardo.

## Aceptación

GitHub Actions verifica el build autónomo y lo instala desde el ZIP
reconstruido en WordPress nuevo. En Theme Research comprueba el Blueprint:
Bootstrap 8 Lines con evidencia, identidad y 24 hidrataciones bilingües,
Apply/Verify, Preview idempotente y Rollback. En WordPress genérico bloquea
el Blueprint Research antes de cualquier escritura.

## Límites pendientes

No existe aún un repositorio verificable Emmakex/site-architect, y la conexión
disponible no permite crearlo. Esta exportación queda lista para importarse
en un repositorio privado nuevo una vez creado. No se presume licencia de
publicación ni autorización para publicar código privado.

No se ha certificado todo el contenido/renders de producción ni el cutover
reversible, ni la escritura de un segundo Theme arbitrario. La web de Eduardo
conserva Site Architect 0.11.1 intacto. El SOURCE_COMMIT es la revisión de
origen, no un supuesto commit del futuro repositorio de producto.


## #475.18 — Bounded governance clone acceptance

The standalone product's actual ZIP is installed in fresh isolated WordPress
with Research Theme. The next CI gate verifies all 12 Theme-owned Pages across
EN/ES (24 inspect states, Theme roles, slots, routes and storage), SEO/GEO's
structural verification policy and reversible **Design** and **Navigation**
operations through their real Preview, explicit confirmation, Apply, Verify
and Rollback services. Negative tests reject arbitrary CSS and unregistered
navigation targets; a generic WordPress profile rejects Research Design and
Navigation inspection without changing state. The isolated CI test is explicitly
blocked outside disposable WP-CLI. Test:
`scripts/site-architect-475-clone-governance.php`.

The clone may expose a legal/Privacy Page in **draft** state while its Theme-owned role, model, URL and slots are valid. #475.18 explicitly preserves such drafts; it does not publish legally sensitive content just to satisfy a test. Legal draft readiness must be reviewed separately before a public release.

This extends clone **functional** confidence only. It does not prove actual
HTTP-rendered SEO/GEO, Media ingest/assignment, all REST credentials, or
complete production-versus-clone state/render parity. Reusing this evidence
must not be described as permission for production cutover.


## #475.19 — Real HTTP rendering, not an inferred SEO flag

The isolated Research-Theme WordPress now starts a loopback-only PHP server at
127.0.0.1:8080, using a temporary front-controller router only in the
disposable WordPress clone. The exact independently rebuilt Site Architect
0.11.1 plugin is used to hydrate the canonical Blueprint in the clone.

The actual **Rendered Verifier** requests the frontend URLs over HTTP. Each
publicly available Theme-owned EN/ES Page must pass HTTP 200, self-canonical,
HTML language, current language hreflang, both alternate languages, x-default,
OG URL and JSON-LD presence. The response must contain the real Theme
stylesheet, header, both navigation regions and an HTML5 doctype.

A Research collection with no public content and a draft legal Page are
reported separately as **not public**, never silently counted as HTTP-200
or published by CI. Required Home/About/CV/Contact pages cannot be skipped.
The test also reorders the bounded footer navigation, calls the real
**Navigation Verify with include_rendered=true** (both languages), restores
the original state and rolls back all hydration snapshots.

All HTTP addresses must match 127.0.0.1:8080. PHP test and router files
are not distributed with the plugin, nor installed on Eduardo production.
The CI uploads a bounded JSON proof containing only local URL status and
Boolean SEO/rollback checks; no page text or credentials.

This is HTTP-rendered **clone functionality**, not screenshot/pixel-diff
certification or production vs clone parity. Outstanding: Media lifecycle
and source provenance, complete remote REST capability checks, real
production inventory parity, external repository setup and reversible
cutover rehearsal.

The #475.19 hardened gate also sends real anonymous HTTP requests to every
Theme-suppressed URL and requires **404**, not merely a PHP-side unavailable
flag. The currently accepted clone baseline is at least **14/24 rendered
EN/ES HTTP 200** with complete SEO/HTML checks; the remaining **10/24**
are recorded as not public with actual HTTP 404 and reason (legal draft or
no published collection objects). These are **launch/content readiness
blockers**, not fully delivered public pages.


## #475.20 — Managed Media and REST least-privilege clone gates

The same exact 0.11.1 WordPress ZIP rebuilt from autonomous source now runs a
full Media lifecycle in an isolated WordPress Research Theme clone:

- Preview synthetic 1x1 PNG with mandatory reviewed alt text, MIME validation,
  SHA-256 checksum and privately staged binary;
- reject spoofed binary, missing alt text, cross-connection Apply and Apply
  without confirmation; Apply and Verify checksum and provenance;
- update title and alt through the real remote Media Metadata operations,
  then restore exactly with rollback;
- assign the image to Theme-owned Home/EN primary Media, verify the rendered
  figure and attachment marker over loopback HTTP, and rollback the relation;
- reject deleting the ingested Media while assigned; after detaching,
  rollback the original ingest and confirm the owned attachment is gone.

The test dispatches the ACTUAL registered WordPress REST routes, verifying:
anonymous requests are rejected; a temporary read-only token with only
site.read/media.read cannot execute Media mutations; a consumed nonce
cannot be replayed; revocation makes the token unusable. Tokens never
enter test reports and are revoked even on failure. HTTPS simulation is
restricted to in-process REST dispatch in the disposable CI clone.

Tests and generated Media are NOT shipped in the installable plugin. There
is no production upload, authority expansion or runtime Plugin/Theme change.
A small CI JSON report complements the prior HTTP proof. Full production
parity, external source repository and reversible cutover remain unverified.

## #475.21 — Eduardo release authority

A single release/no-go evidence matrix now lives in
`docs/SITE_ARCHITECT_EDUARDO_RELEASE_GATES.md`. A read-only public production
HTTP survey compares 24 Theme route/language surfaces with #475.19's clone
outcome, preserving all unverified private/visual/cutover gates as OPEN.
Neither a public 200 nor a reproducible ZIP certifies live control of Eduardo.


## #475.22 — Private WordPress readback, explicitly not live yet

The full read-only inventory observer is in
docs/SITE_ARCHITECT_PRIVATE_READONLY_INVENTORY.md.
The exported real Site Architect 0.11.1 plugin is audited in the disposable
WordPress clone inside a MySQL READ ONLY transaction: active plugin SHA/version,
Theme contract and 24 Page/language slots, aggregate post/Media records, and
only non-sensitive remote connection posture. The schema validator prohibits
credential/user/content leakage and matches clone HTML/visibility evidence.

**Status:** observer/clone acceptance can be certified by CI; *real Eduardo
private WordPress data is not yet read back*. Actual production requires
explicitly authorized, host-pinned, protected WP-CLI read-only invocation.
No CI secret or public WordPress endpoint is created. Never treat this
as permission to update/redeploy the already functioning installation.


## #475.23 — Existing protected production MCP readback, strictly read-only

The repository already has an OVH Phase 2C Eduardo-reference MCP and a
phase2d-production principal used by its accepted deployment workflow.
The new guarded read-only workflow and report contract are detailed in
docs/SITE_ARCHITECT_MCP_READONLY_READBACK.md. Its six fixed existing
read tools require no new WordPress tokens, endpoints or plugin mutation.

**Acceptance split:** offline sanitizer and CI can pass before merge; the
live production MCP result may be observed only after the protected main
push job runs (or a separate authorized workflow_dispatch). Green CI alone
does NOT certify that live access, the installed Plugin version or its
private resource totals match the independent source export.

#475.22 native MySQL private inventory, visual mobile/desktop, doctoral CV
content, legal review, external private source repository and reversible
cutover remain open until separately evidenced. A successful MCP inventory
is not a full underlying WordPress/Media database audit.

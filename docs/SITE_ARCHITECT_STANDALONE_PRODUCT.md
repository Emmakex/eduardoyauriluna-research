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

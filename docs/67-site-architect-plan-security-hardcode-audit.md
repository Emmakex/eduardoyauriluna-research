# Site Architect — Plan Security Hardcode Audit

## Status

P1 follow-up to `docs/63-greenfield-hardcode-coupling-audit.md` and `docs/65-site-architect-research-resource-registry-audit.md`.

This document classifies hardcoded knowledge inside `Eduardo_Research_Manager_Plan` after the Research Resource Registry was introduced.

The objective is **not** to make Plan dynamic. `Eduardo_Research_Manager_Plan` is the fail-closed mutation/security boundary. Descriptive duplication may be centralized, but authorization, bounded schemas and risk gates must remain explicit unless an equally strict replacement is proven.

## Core rule

```text
Research Resource Registry = descriptive vertical facts
Plan = authorization + validation + risk boundary
Executor = execution of already validated actions
```

A Registry entry must never authorize a mutation by itself.

## Classification table

| Current Plan knowledge | Classification | Direction |
| --- | --- | --- |
| `ALLOWED_OPTIONS` | KEEP SECURITY | Keep a closed explicit option allowlist. Individual storage facts may later be generated only if the resulting set remains exact and reviewable. |
| `ALLOWED_SURFACE_KEYS` | KEEP SECURITY + CENTRALIZE candidate | It duplicates Theme page/surface knowledge, but it also bounds writable options. Do not automatically expose every Theme page as writable. |
| `ALLOWED_POST_FIELDS` | KEEP SECURITY | Keep explicit. Registry must not add WordPress post fields implicitly. |
| `STRUCTURED_RESEARCH_POST_TYPES` | CENTRALIZE | Pure descriptive duplication of the Research Resource Registry when used to classify evidence-sensitive title mutations. May derive from Registry without widening mutation permission. |
| `MAX_TITLE_BYTES` / `MAX_EXCERPT_BYTES` / `MAX_CONTENT_BYTES` | KEEP SECURITY | Bounded content limits are policy, not accidental hardcoding. |
| explicit `normalize_action()` branches | KEEP SECURITY | The closed dispatcher is an authorization boundary. Do not replace with automatic Registry dispatch. |
| Research creation actions in `action_risk()` | KEEP DOMAIN + KEEP SECURITY | Research creation remains evidence-sensitive. Any future derivation must fail closed to equal-or-higher risk. |
| evidence-sensitive field/risk needles | KEEP DOMAIN + KEEP SECURITY | Academic/evidence semantics are intentional Research policy. Consolidation is allowed only if risk cannot be weakened. |
| language defaults `'en'` in normalizers | CENTRALIZE | Resolve operational defaults from the Greenfield Theme Contract while preserving current EN behavior. |
| Theme collection option calls using `'en'` | CENTRALIZE | Use the contract default language when the call is selecting the primary contract vocabulary rather than literal bilingual content. |
| `allowed_option_key()` reconstruction of surface `_es` keys | CENTRALIZE inside KEEP SECURITY boundary | Use exact contract-derived compatible storage keys, but preserve a closed writable option set. |
| `allowed_meta_key()` prefix authorization | SECURITY HARDENING | Current prefix rule is broader than the desired exact trusted contract. Replace eventually with exact registered meta keys; never broaden it. |
| `is_theme_owned_page()` Theme page discovery | CENTRALIZE | Reuse Greenfield Theme Contract page ownership/state while keeping the same fail-closed ownership check. |
| `theme_home_page_id()` | CENTRALIZE | Reuse the Theme Contract `home` page identity rather than independently reading preset structure. |
| `target_signature()` structured creation action list | CENTRALIZE candidate | Safe only after `normalize_action()` has already authorized the action; Registry may provide descriptive action identity. |

## 1. Explicit action dispatch is a security boundary

Current Plan contains branches such as:

```text
create_page
create_insight
create_line
create_output
create_project
create_software
create_dataset
```

These branches must remain explicit unless a future schema registry is itself a closed, versioned security contract.

Do **not** implement:

```text
if Registry knows action → action is allowed
```

The correct relationship remains:

```text
Plan explicitly allows action
→ Plan validates exact schema and values
→ Registry may provide already-known descriptive facts
→ Executor executes validated action
```

## 2. Safe descriptive centralization: structured post types

`STRUCTURED_RESEARCH_POST_TYPES` repeats the same five WordPress post types now owned descriptively by `Eduardo_Research_Manager_Resource_Registry`.

Its current use in `action_risk()` is to ensure title mutation on any structured Research object is `evidence-required`.

A safe migration may replace the local list with:

```text
Research Resource Registry::post_types()
```

provided the rule remains:

```text
structured Research title change → evidence-required
```

and an unknown resource never receives a lower risk because of missing Registry data.

## 3. Option allowlist must stay closed

`ALLOWED_OPTIONS` and `allowed_option_key()` protect WordPress from arbitrary option writes.

The current code contains both fixed options and surface option patterns. The surface patterns duplicate the existing EN/ES storage scheme.

A future cleanup may derive the exact physical page option keys from the Greenfield Theme Contract, but the result must still be an explicit finite set.

Desired model:

```text
Theme Contract pages
+
Theme Contract compatible languages
+
compatibility storage resolver
→ exact set of writable structured page option keys
```

Not allowed:

```text
prefix match → writable option
```

## Update — Site Architect #475.5 (12 November 2026)

The Core `Plan::action_risk()` no longer owns scholarly risk needles, Research
creation behavior or evidence-sensitive academic field semantics. The policy
method `risk_for_action(action, is_theme_owned_page)` in the Research vertical
keeps all existing Research classifications exactly as accepted in 0.11.1.
The Theme ownership callback is supplied by `Plan` using the active Contract.

`Plan` remains the **only** fail-closed checksum, evidence-confirmation and
source-reference enforcement boundary. It accepts only the three known risk
labels, promotes an unknown action or invalid policy classification to
`evidence-required`, and recalculates risk on validation. No Theme preset or
descriptive Registry entry can grant a mutation; a new policy is a reviewed PHP
implementation, not client data. Existing Research error codes and exact
academic messages are preserved through `evidence_blocker_message()`, which
supplies **copy only**, never a bypass.

`Executor::preview` uses the same injected policy and Theme Contract as Plan.
The full Research old-risk behavior and alternative Theme (including evidence
blocking, checksum and **derived-risk tamper rejection** and invalid-risk fallback)
are regression tested. This cut also closed the original Plan validation gap:
previously a caller could change only the stored `risk` field without touching
the checksum and bypass the evidence gate. Validation now compares the submitted
risk to the freshly reconstructed risk, returning the established
`research_manager_plan_changed` code and unchanged message on drift. Remaining work: type-aware ownership and Research-specific normalizers
still located in the generic Plan; this is **not** second-client acceptance.

## Update — Site Architect #475.4 (12 November 2026)

The analysis in §4 records the **historical** prefix/policy concern. The current
Plan on the #475.4 branch already used an exact allowlist, but that list was
built automatically from the descriptive Research Resource Registry. That could
silently grant new metadata writes if a new Registry field was added.

#475.4 replaces that dynamic grant with `Site_Architect_Mutation_Policy::allowed_meta_keys()`.
The Research implementation lists **49 explicit, existing keys** (9 shared,
including provenance and translations, plus 40 additional Registry-backed keys).
`Plan` now accepts an exact key only when explicitly present in the active
mutation policy. There is no prefix/wildcard authorization and no implication
that merely registering a resource permits a mutation. Tests enumerate all
previously accepted metadata, reject unknown Research prefixes and WordPress
internal meta, and prove a second Theme cannot inherit Research keys.

This is only a policy-authority extraction. Per-resource ownership and typed
field schemas remain further hardening work, and the current vertical evidence
risk rules are not changed by #475.4. No production site or plugin version is
changed by this source-level microcut.

## 4. Metadata allowlist needs hardening

Current Plan accepts post meta when the key starts with either:

```text
_eduardo_research_
_research_
```

That is fail-limited by namespace, but it is still broader than the desired exact contract.

Target direction:

```text
exact Theme-owned page meta
+
exact Research Resource Registry meta
+
exact shared Research metadata
+
exact provenance metadata
→ finite writable meta allowlist
```

This should tighten security while preserving all current legitimate operations.

The migration must prove every currently used metadata key remains accepted before the prefix fallback is removed.

## 5. Default language duplication

Several creation normalizers still use literal `en` for:

- missing action language;
- primary Theme collection option lookup;
- Insight type lookup;
- Research Output / Project / Software / Dataset contract option lookup.

These are operational defaults, not literal English content, so they should progressively resolve through:

```text
Eduardo_Research_Manager::contract()->default_language()
```

The current Eduardo instance must continue to resolve to English.

## 6. Theme page ownership duplication

`is_theme_owned_page()` and `theme_home_page_id()` independently inspect `eduardo_research_preset()`.

That is valid Greenfield knowledge but duplicated ownership logic.

Target direction:

```text
Greenfield Theme Contract
→ pages()
→ page_state()/page_id()
```

Plan should consume that authoritative boundary while retaining the same fail-closed checks for front-page and status mutations.

## 7. Risk policy must not become data-driven by accident

The current Plan distinguishes:

```text
standard
editorial-review
evidence-required
```

Academic identifiers, publication state, authorship, affiliations, research relationships, methods, funding, DOI and similar fields are intentionally evidence-sensitive.

This is Research vertical policy and should remain explicit or move only to a dedicated versioned risk contract.

A generic Registry should never be able to lower risk merely by omitting a field annotation.

## 8. Recommended implementation order

Use microphases:

1. derive only `STRUCTURED_RESEARCH_POST_TYPES` from the Research Resource Registry;
2. replace behavior-preserving operational `'en'` defaults with Theme Contract default language;
3. centralize Theme home/page ownership through the Greenfield Theme Contract;
4. make page option allowlisting consume exact contract-derived storage keys while remaining finite;
5. inventory every legitimate writable post meta key;
6. introduce an exact trusted meta allowlist;
7. only then remove the broad `_research_*` / `_eduardo_research_*` prefix fallback;
8. leave explicit action dispatch, bounded post fields, content limits, evidence gates and risk policy closed.

Each security-sensitive change requires CI and later governed production readback before acceptance.

## 9. Acceptance criteria

The Plan cleanup is successful only if:

- no new action becomes valid;
- no new WordPress option becomes writable;
- no new metadata key becomes writable;
- no post field is added implicitly;
- evidence-required operations remain evidence-required;
- current Research resources remain exactly the same;
- EN/ES production storage remains compatible;
- Preview / Apply / Verify / Rollback semantics remain unchanged;
- stale/checksum/idempotency boundaries are unaffected;
- CI passes;
- production acceptance is proven separately through Site Architect governance.

The goal is a **smaller duplication surface with an equal-or-stronger security boundary**, not a more generic mutation engine.

## Update — #475.6 Research creation schemas (13 November 2026)

Six typed Research resource/Insight normalization methods and their scholarly
sanitizers now reside in `Eduardo_Research_Manager_Research_Creation_Normalizer`
instead of generic `Plan`. This includes unchanged bounds, DOI/date/URL parsing,
verified line relations, authors/ORCID and their existing error codes.

`Plan` still owns the **exact seven creation-action branches** (generic Page
plus six Research actions), checksums, duplicate targets and the evidence gate.
It dispatches to the vertical only when the injected mutation policy is the
**actual Research policy**. A different Theme cannot claim `create_insight`
by listing it in its own policy, even if the executor previously recognized
that historical action name. Registry data still never confers write rights.

This is **not** a generic typed-normalizer/executor plugin API yet; typed
ownership and a reviewed second-Theme executor remain for future cuts.
No Theme or production WordPress migration accompanies this source refactor.

## Update — #475.7 Research resource execution ownership (13 November 2026)

The Research execution spec resolver moved out of the generic Executor into
`Eduardo_Research_Manager_Research_Execution_Spec`. Only the five exact
Research structured creation action → post-type mappings are recognized in
this vertical adapter. Descriptive Resource Registry entries do not grant
additional executable actions or post types; existing metadata/defaults and
conflict/rollback codes are preserved byte-for-byte for recognized resources.

The Executor retains only a narrow legacy compatibility delegator and requires
the actual Research mutation policy **and** a Research Theme Contract identity.
It enforces this boundary before Research read-state, write and rollback
operations, including Insight creation. This is independent of the Plan's
pre-existing fail-closed action authorization, checksum, evidence and
verification gates. Regression tests cover both Research schema parity and
cross-Theme rejects for read/write/rollback (without invoking WordPress writes).

This step does **not** change existing post_meta/post_field resource ownership
or make arbitrary second-Theme types executable. Those require a separate
typed-resource compatibility inventory and a reviewed executor extension seam.
No Theme, REST/MCP, storage, version or production WordPress change.


## Microcorte #475.8 — autoridad de propiedad de recursos para post_meta y post_field (2026-11-13)

- **CORE**: nueva interfaz opcional `Site_Architect_Resource_Ownership_Policy`, obligatoria en la práctica para mutaciones de `post_meta` / `post_field`. La existencia de una acción o clave en una política no basta: Plan exige también Theme id, tipo de recurso y, para Page, pertenencia a páginas del Theme Contract.
- **VERTICAL RESEARCH**: la política Research reconoce únicamente `page` controlada por el contrato, `post` legado y los cinco CPTs Research existentes (`research_line`, `research_output`, `research_project`, `research_software`, `research_dataset`). No concede acceso a CPTs desconocidos, ni a páginas ajenas. El `post` legado **no prueba por sí solo identidad de Insight**; no estrechar todavía sin inventario productivo.
- **LEGACY COMPAT**: para los tipos de recurso ya reconocidos, se conserva el conjunto exacto de **49 claves metadata** históricas y los cinco post fields bounded. Este microcorte no convierte el mapa descriptivo por CPT del Registry en una autorización automática ni pretende que sus campos sean editables por separado: ese estrechamiento necesita evidencia distinta, para no romper Eduardo.
- **Executor**: lectura de estado, escritura y rollback vuelven a pasar la misma comprobación, incluso si se invocan sus rutas privadas. `post_status` sigue restringido a publicar la página controlada. `Plan::validate` recompone la acción y mantiene checksum/evidencia/anti-drift.
- **Segundo Theme**: muestra un ejemplo positivo con `sample` + Page `landing` realmente controlada, clave `sa_sample_flag`, y casos negativos de mezcla de políticas, CPT ajeno, Page sin contrato y key fuera de allowlist.
- **No despliegue**: Site Architect 0.11.1 en eduardoyauriluna.com, Theme, API REST/MCP, scopes, versionado y almacenamiento continúan intactos.

**No declarar portabilidad completa**: las opciones `show_on_front` / `page_on_front` y la creación de páginas conservan supuestos Research, y la separación de rutas ejecutoras y la distribución empaquetada requieren microcortes posteriores. #475 permanece abierto hasta equivalencia en clon aislado.

### Evidencia añadida: aceptación histórica de Pages editoriales

El workflow `Research Manager Identity Policy` prueba cambios y rollback de `post_title` tanto para `post` como para páginas `page` de WordPress creadas fuera de las páginas estructurales del Theme. El gate #475.8 **no debe romper esa aceptación**: se autoriza ese `post_field` heredado bajo la política y Theme Research correctos, con los límites y riesgos existentes. Esto no autoriza `post_meta` en páginas ajenas; `post_status` mantiene la guarda exacta `publish` + Page del contrato. Una futura reducción deberá tener su propia compatibilidad y prueba productiva.

### Compatibilidad adicional M4: rollback del estado de Insights

El gate `Research Manager Remote M4 Insight Status Quality` demuestra que `InsightEditor` guarda snapshots con acción `post_field/post_status` para publicar/despublicar y delega su rollback a `Executor`, sin usar un Plan genérico de publicación de páginas. La nueva comprobación de **pertenencia** permite ese read/write/rollback en recursos `post` del vertical Research, pero la restricción preexistente de `Plan::normalize_action` sigue impidiendo construir nuevas acciones genéricas `post_status` sobre Insights. No redefinir el contrato M4 ni reimplementar su motor.


## Microcorte #475.9 — propiedad nativa de campos en creaciones Research

**Origen de los hechos:** #475.8 preservó las listas históricas de 49 `post_meta` keys y cinco `post_field` fields, mientras que #475.7 movió el catálogo de tipos de creación a `Research_Execution_Spec`. Quedaba una dependencia: la especificación de creación aceptaba el mapa `meta` del Registry descriptivo si coincidía el post type, sin comparar cada campo con una autoridad ejecutable independiente.

**Cambio revisado:** `Eduardo_Research_Manager_Research_Resource_Fields` declara por código el mapa exacto de `field => meta_key` de cinco recursos académicos: `research_line`, `research_output`, `research_project`, `research_software` y `research_dataset`. `Research_Execution_Spec::for_action` comprueba igualdad estricta de ese mapa **antes** de permitir crear/verificar/restaurar objetos académicos. Un campo añadido, omitido, reordenado o remapeado en el Registry sin aprobación queda **fail-closed**. El Registry sigue describiendo recursos, no autorizando escrituras.

La política Research delega el reconocimiento de los siete tipos WordPress aceptados (`page`, `post` y cinco Research CPTs), y las listas legacy de campos, al mismo componente del vertical. Los 49 `post_meta` históricos y cinco `post_field` históricos **siguen siendo compatibles** en recursos cuya propiedad ya está autorizada: este microcorte no estrecha ni expande la superficie de mutación genérica. Para Pages ajenas, solo se preserva la excepción editorial de `post_field` aprobada en #475.8.

**Tests de aceptación:** comparación exacta de cinco mapas con el comportamiento aceptado; negativos de campo desconocido, omitido, reordenado, remapeado, tipo ajeno; matrices positivas de compatibilidad; segunda política/Theme, riesgo/evidencia, Preview/Apply/Verify/Rollback y PHP 8.1/8.2. Se conserva la edición de títulos editorial y el rollback M4 de Insights.

**Límite:** únicamente contratos Research, adaptador Research de ejecución, test offline, CI y auditoría. Sin modificación de la cabecera 0.11.1, Theme, Blueprint, Bootstrap, REST/MCP, credenciales, almacenamiento ni producción de Eduardo. No implica una nueva distribución instalable ni una segunda instancia probada. Siguiente microcorte posible: separar las rutas de ejecución Research de la mecánica genérica, y después demostrar equivalencia en clon aislado antes de cutover.


## Microcorte #475.10 — extraer rutas ejecutables de cinco objetos Research del Executor CORE

**Dependencia observada:** en 0.11.1 el `Executor` orquesta `Plan → Preview → Apply → Verify → Rollback`; todavía contenía lecturas de estado, metadatos, escritura `wp_insert_post`, colisiones y rollback por provenance de los cinco tipos `research_line/output/project/software/dataset`. En #475.7 se extrajo el catálogo cerrado de tipos y en #475.9 se certificaron mapas nativos `field => meta_key`.

**Cambio:** un adaptador de ejecución **Research-only**, `Eduardo_Research_Manager_Research_Object_Execution`, recibe `Site_Architect_Mutation_Policy` y `Eduardo_Research_Manager_Contract` concretos. Cada ruta pública `spec/read_state/create/restore/desired_value` comprueba **política Research real**, **Theme id exacto `research`**, acción incluida en la política y schema cerrado de `Research_Execution_Spec`; no basta añadir un tipo al Registry ni reutilizar una política del otro Theme. El `Executor` delega read/create/restore/desired de cinco CPTs conservando Preview, Apply, Verify, snapshots y checksum en su mecanismo existente.

**Parity:** estado missing/exists, orden y defaults de valores, consulta SQL privada de primera fila de postmeta, nombres de metadatos, ausencia de overwrite al encontrar slug ocupado, colisión de token, actualización de caché/rutas y rollback que solo borra el recurso con token propio. `create_page` y `create_insight` NO cambian; su propia extracción requerirá otro corte. El comportamiento de `Plan`, del `post_field` y `post_meta`, y de los snapshots M4 de Insights queda intacto.

**Pruebas:** test offline de cinco familias y cuatro rutas que niega otro Theme, otra política o una política Research falsa **antes de operaciones WordPress**, compara specs, states missing y desired fields; WP CI conserva los acceptance tests legados. Nuevos lint/static checks de PHP 8.1/8.2 en workflow acotado; seis archivos en alcance.

**Sin instalación productiva**, sin cambio de header/site versión 0.11.1, sin alterar el Theme ni migrar datos. #475 sigue abierto: extraer otras rutas Research donde la auditoría justifique compatibilidad, empaquetar Site Architect independiente y verificar paridad completa en un clon aislado de Eduardo antes de un cutover reversible.

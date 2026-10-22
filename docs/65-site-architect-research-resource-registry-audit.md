# Site Architect — Research Resource Registry Audit

## Status

**P1 contract-consolidation design.**

This document refines `docs/63-greenfield-hardcode-coupling-audit.md` for the Research vertical resource mappings currently duplicated across Site Architect.

It does not make the Research model generic and it does not widen any mutation surface.

## 1. Finding

The same Research resource truth is currently re-declared in several places:

- `Eduardo_Research_Manager_Contract::research_post_types()` lists structured Research post types;
- `Eduardo_Research_Manager_Resource_Inventory::types()` maps public inventory types to read scopes;
- `Eduardo_Research_Manager_Resource_Inventory::post_type()` separately maps inventory names to WordPress post types;
- `Eduardo_Research_Manager_Plan` separately enumerates structured Research post types and creation actions for validation/risk;
- `Eduardo_Research_Manager_Executor::research_object_spec()` independently maps creation actions to WordPress post types, metadata fields, defaults and rollback/conflict codes.

That duplication is a **CENTRALIZE** finding, not a reason to remove Research specialization.

## 2. Registry responsibility

The Research Resource Registry should own descriptive vertical facts such as:

```text
resource key
→ inventory key
→ WordPress post type
→ creation action
→ read scope
→ identifier type
→ metadata field map
→ array-valued fields
→ compatibility defaults
→ creation/rollback conflict codes
```

The Registry may be consumed by readers/executors to avoid duplicated maps.

## 3. Security boundary

The Registry is **not** an authorization engine.

`Eduardo_Research_Manager_Plan` remains a fail-closed mutation boundary and must continue to validate:

- permitted action types;
- exact field schemas;
- bounded values;
- evidence-sensitive risk;
- active-language constraints;
- Theme-owned options/meta;
- statuses and destructive-operation policy.

Adding a Registry entry must never automatically authorize a new mutation action.

The intended relationship is:

```text
Research Resource Registry = descriptive vertical contract
Plan = security/validation contract
Executor = execution of already validated actions
```

## 4. Current structured Research resources

| Registry key | Inventory key | Post type | Creation action | Read scope |
| --- | --- | --- | --- | --- |
| `line` | `lines` | `research_line` | `create_line` | `research.read` |
| `output` | `outputs` | `research_output` | `create_output` | `research.read` |
| `project` | `projects` | `research_project` | `create_project` | `research.read` |
| `software` | `software` | `research_software` | `create_software` | `research.read` |
| `dataset` | `datasets` | `research_dataset` | `create_dataset` | `research.read` |

`pages` and `insights` remain separate surface families:

- Pages are Theme-owned Greenfield resources, not Research structured objects.
- Insights are editorial WordPress posts with Research semantics, but they have a distinct creation/read contract and should not be forced into the structured-object Registry merely for symmetry.

## 5. Existing metadata contracts to centralize

### Research Line

- `evidence_status` → `_research_evidence_status`
- `research_status` → `_research_status`
- `central_question` → `_research_central_question`
- `order` → `_research_order`
- `topics` → `_research_topics`
- `methods` → `_research_methods`
- array fields: `topics`, `methods`
- compatibility defaults: `evidence_status=unverified`, `research_status=planned`

### Research Output

- `output_type` → `_research_output_type`
- `output_type_verified` → `_research_output_type_verified`
- `review_status` → `_research_review_status`
- `review_status_verified` → `_research_review_status_verified`
- `publication_date` → `_research_publication_date`
- `venue` → `_research_venue`
- `doi` → `_research_doi`
- `doi_verified` → `_research_doi_verified`
- `authors` → `_research_authors`
- `line_ids` → `_research_line_ids`
- array fields: `authors`, `line_ids`
- compatibility defaults for verification flags: `0`

### Research Project

- `project_status` → `_research_project_status`
- `question` → `_research_question`
- `role` → `_research_role`
- `start_date` → `_research_start_date`
- `end_date` → `_research_end_date`
- `partner` → `_research_partner`
- `funding` → `_research_funding`
- `project_url` → `_research_project_url`
- `methods` → `_research_methods`
- `line_ids` → `_research_line_ids`
- array fields: `methods`, `line_ids`

### Research Software

- `software_status` → `_research_software_status`
- `version` → `_research_software_version`
- `release_date` → `_research_release_date`
- `repository_url` → `_research_repository_url`
- `archive_url` → `_research_archive_url`
- `license` → `_research_license`
- `documentation_url` → `_research_documentation_url`
- `doi` → `_research_doi`
- `doi_verified` → `_research_doi_verified`
- `programming_languages` → `_research_programming_languages`
- `line_ids` → `_research_line_ids`
- array fields: `programming_languages`, `line_ids`
- compatibility default: `doi_verified=0`

### Research Dataset

- `version` → `_research_dataset_version`
- `publication_date` → `_research_publication_date`
- `repository` → `_research_repository`
- `doi` → `_research_doi`
- `doi_verified` → `_research_doi_verified`
- `license` → `_research_license`
- `access_level` → `_research_access_level`
- `methodology` → `_research_methodology`
- `provenance` → `_research_provenance`
- `size` → `_research_size`
- `documentation_url` → `_research_documentation_url`
- `ethics_notes` → `_research_ethics_notes`
- `formats` → `_research_formats`
- `line_ids` → `_research_line_ids`
- array fields: `formats`, `line_ids`
- compatibility default: `doi_verified=0`

## 6. Shared metadata outside the per-resource map

All structured Research objects also use bounded shared state including:

- `_research_language`;
- `_eduardo_research_manager_creation_token` as the current compatibility provenance key;
- normal WordPress post title/slug/status/excerpt/content.

Those shared keys must remain explicit and closed.

## 7. Implementation sequence

Use small behavior-preserving commits:

1. introduce a read-only `Research_Resource_Registry` containing the exact current mappings;
2. make `Contract::research_post_types()` derive from it;
3. make Resource Inventory derive structured type/post-type/read-scope facts from it;
4. make Executor consume the exact metadata/default/conflict spec from it;
5. only after parity is proven, let Plan consume safe descriptive subsets such as the structured post-type list while retaining its own explicit action/schema validation;
6. verify no public resource key, post type, metadata key, scope, risk or rollback behavior changes.

## 8. Acceptance criteria

The consolidation is successful only if:

- the current five structured Research resources remain exactly the same;
- no WordPress post type or metadata key changes;
- no new action becomes authorized;
- current read scopes remain unchanged;
- Preview / Apply / Verify / Rollback semantics remain unchanged;
- evidence-required behavior remains unchanged;
- inventory output remains compatible;
- creation and rollback conflict handling remains compatible;
- CI passes and production behavior is later read back through the governed Site Architect path.

The goal is one authoritative Research vertical map, **not** a more permissive manager.

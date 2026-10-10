# Greenfield site blueprint contract

The next Manager layer will be a declarative blueprint for the known Research website. It is not a scanner and it does not infer content from WordPress.

A blueprint describes desired Theme-owned resources from structured input. The first version should contain:

- site identity and supported languages;
- Theme page keys and language-specific structured slots;
- academic resource declarations for Insights, Outputs/Publications, Projects, Software and Datasets;
- explicit translation pair references;
- SEO/GEO metadata that belongs to the Manager contract;
- relationships between academic records;
- creation order and dependencies.

The blueprint compiler should produce existing bounded Manager plans rather than bypass Resource Services. It must be deterministic and idempotent: applying the same blueprint to an already matching clean Research site should produce no unintended duplicate resources.

The blueprint must never contain Gutenberg layout instructions. Layout remains Theme-owned.

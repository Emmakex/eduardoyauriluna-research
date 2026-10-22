# Greenfield Engineering Rule — Managed WordPress from Zero

## Status

**Mandatory repository-wide engineering rule.**

This rule supplements `ENGINEERING_RULES.md` and defines the architectural meaning of the Greenfield track used in this repository. It is intentionally independent from `Emmakex/Site-Manager`.

If a historical roadmap, implementation shortcut, portability experiment or old architecture note conflicts with this Greenfield rule, the Greenfield rule applies unless Eduardo explicitly approves a later architecture decision.

## 1. Canonical Greenfield definition

This repository develops and operates the **Greenfield** approach to governed WordPress:

> Start from a new WordPress installation that we design and build ourselves, so the Theme, information architecture, content model, SEO/GEO model, performance strategy and governance contracts are known from the beginning and can be optimized for safe operation from ChatGPT.

The current real production case is `eduardoyauriluna.com`.

Greenfield does **not** mean generic discovery of an arbitrary pre-existing WordPress site. The system is allowed to know the architecture that we deliberately created.

## 2. Greenfield product assumptions

A Greenfield site is one where we control the initial architecture, including as applicable:

- WordPress installation and baseline configuration;
- Theme and frontend authority;
- page and route contracts;
- structured content models;
- navigation model;
- media contracts;
- multilingual model;
- SEO/GEO and structured-data strategy;
- design tokens and bounded presentation variants;
- performance and technical optimization;
- safe mutation surfaces;
- verification and rollback semantics;
- the governance path from ChatGPT to WordPress.

Because those contracts are created by us, Greenfield governance should prefer **deterministic contracts over runtime inference**.

## 3. Greenfield is optimized WordPress, not conventional page-builder WordPress

The goal is still WordPress, but designed to be more governable and more optimizable from the start.

Engineering should favor:

```text
known contract
→ bounded business action
→ Plan / Preview
→ Apply
→ Verify
→ Rollback when applicable
```

rather than relying on arbitrary visual-builder state, unrestricted options/meta access, or ad-hoc DOM manipulation.

The Theme may remain frontend authority when that produces better performance, SEO/GEO consistency and deterministic governance.

## 4. Specialization is allowed and often desirable

Greenfield must not be made artificially generic merely to resemble Site Manager.

Domain-aware behavior is valid when it serves the site type we intentionally built. For the current researcher implementation this includes, where genuine and useful:

- researcher identity;
- research lines;
- publications and outputs;
- projects, research software and datasets;
- evidence-sensitive academic claims;
- scholarly metadata and identifiers;
- multilingual research content;
- research-specific SEO/GEO semantics.

A future Greenfield implementation may use other site blueprints or vertical contracts, but it should still benefit from knowing its architecture in advance.

## 5. Hardcode rule

Do **not** interpret every hardcode as a portability defect.

Classify hardcodes as follows:

### REMOVE
Accidental instance coupling that makes even the intended Greenfield architecture more fragile, such as unnecessary domain, person, numeric ID or obsolete-version assumptions in reusable logic.

### CENTRALIZE
Valid Greenfield constants or conventions that are duplicated across many files and should have one authoritative contract/source.

### KEEP — DOMAIN CONTRACT
Deliberate specialization that represents the known vertical/site architecture and improves correctness, semantics or automation.

### KEEP — SECURITY CONTRACT
Closed allowlists, scopes, bounded actions, typed mutations, safe field restrictions, stale-state protection and fail-closed behavior. These must not be loosened merely to increase apparent portability.

### EXTRACT LATER
Patterns worth comparing with Site Manager after both tracks have independent production evidence. Extraction must not make this repository depend on Site Manager.

## 6. Instance data must not define the architecture

The current site is Eduardo-specific, but there is a distinction between:

```text
Greenfield architecture
≠ Eduardo's instance data
```

Names, biography, domain, specific research agenda, concrete content and other instance data may be required for `eduardoyauriluna.com`, but they should not be confused with the fundamental Greenfield governance model when separation is practical and useful.

This does **not** require turning the current Research Manager into a generic multi-client product inside this repository.

## 7. No Brownfield discovery requirement here

This repository is not required to discover and understand arbitrary existing WordPress installations.

Do not add complexity solely to support unknown combinations of:

- themes;
- page builders;
- SEO plugins;
- ACF models;
- WooCommerce setups;
- multilingual plugins;
- legacy URL structures;
- arbitrary client conventions.

That problem belongs to the Brownfield / Site Manager track.

## 8. Independent evolution from Site Manager

Greenfield and Brownfield are deliberately separate engineering experiments.

Greenfield asks:

> **How should we build WordPress from zero if we want excellent performance, SEO/GEO and safe AI governance from the first day?**

Site Manager / Brownfield asks:

> **How can we safely discover, understand and govern a WordPress site that already exists and that we did not design?**

Neither repository should be forced to copy the other's internal architecture during this phase.

## 9. Future comparison and convergence

Only after both tracks have sufficient real production evidence should we compare them on:

- ChatGPT operability;
- capability coverage;
- safety and fail-closed behavior;
- discovery versus deterministic contracts;
- verification quality;
- rollback/recovery;
- performance impact;
- SEO/GEO quality;
- maintainability;
- amount of special-case code;
- adaptability to real client scenarios.

Potential shared concepts may later include authentication, scopes, audit, operation lifecycle, verification, rollback and normalized capability reporting, but any convergence must be evidence-driven rather than assumed in advance.

## 10. Current authority

For `eduardoyauriluna.com`, Research Manager (name provisional) and this repository's Gateway remain the current Greenfield production governance implementation until Eduardo explicitly approves a replacement.

The product name may change later. The **Greenfield architectural role** defined by this rule remains independent of that naming decision.

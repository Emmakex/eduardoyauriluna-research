# Research Manager — Greenfield runtime contract

The Research installation is a new WordPress site. `greenfield` is therefore the Manager's default operating mode.

## Runtime rule

Greenfield means the Manager already knows the active Research Theme contract and may create Theme-owned resources directly. It must not require legacy discovery, legacy mapping or migration preview before normal creation.

Safety features are orthogonal to migration. Contract validation, checksummed plans, snapshots, verification and rollback remain valid in Greenfield mode because they protect writes; they do not imply that the Manager is interpreting an old website.

## Capability boundary

Greenfield enables direct contract creation and disables legacy discovery, legacy mapping, legacy URL preservation and migration preview.

Migration is an explicit alternate mode. It may reuse the same resource services and safety infrastructure, but legacy interpretation must live behind migration-specific capabilities and must never become a prerequisite for Greenfield operations.

## Implementation

`Eduardo_Research_Manager_Mode` is the single runtime source for the operating scenario. On first plugin activation the option `eduardo_research_manager_mode` is initialized to `greenfield`. Invalid or missing values resolve safely to Greenfield for this Research product.

`Eduardo_Research_Manager::mode()` exposes the resolved scenario and capability map to future admin/API orchestration without coupling individual Resource Services to migration concerns.

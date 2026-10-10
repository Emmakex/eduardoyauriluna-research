# Greenfield write-safety boundary

Greenfield removes legacy interpretation; it does not remove write safety.

Blueprint preview is non-mutating. It may classify desired resources and ask existing Resource Services to build bounded plans, but only the Manager Executor may apply those plans. This preserves plan checksums, evidence gates where applicable, snapshots, verification and rollback.

The blueprint layer must not call `wp_insert_post`, `update_post_meta`, `update_option` or equivalent mutation APIs to bypass Resource Services/Executor for managed Research resources.

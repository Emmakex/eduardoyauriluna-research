# Greenfield blueprint status vocabulary

The blueprint compiler will use explicit statuses rather than migration-style inference:

- `create` — the Theme-owned resource is absent and can be created from the known contract.
- `hydrate` — the resource exists under the expected contract and structured slots/metadata differ.
- `already-matching` — desired and stored managed state already agree; no write is needed.
- `blocked` — a collision, incompatible contract or unsupported desired state prevents a safe write.

`blocked` is not permission to infer or migrate unknown content. If adoption of existing/legacy content is desired, that work belongs to the explicit Migration scenario.

# Greenfield milestone changelog

## Added

- Manager operating-mode service with explicit Greenfield/Migration capability maps.
- Greenfield orchestration boundary exposing existing Resource Services directly.
- Declarative blueprint validator and summary.
- Initial non-mutating Page blueprint compiler.
- Research blueprint v1 example.
- Static CI and runtime assertion fixtures for scenario isolation, non-mutation and malformed declarations.
- Architecture, acceptance, security and next-step documentation.

## Changed

- Plugin activation now initializes Research Manager mode to Greenfield when unset.
- Manager bootstrap exposes Greenfield, blueprint and blueprint-compiler services.

## Preserved

- Research Theme remains frontend authority.
- Resource Services remain the only source of bounded creation plans.
- Executor remains the write/snapshot/verification/rollback boundary.

# Code review focus

Review priority:

1. Scenario isolation: Greenfield must not acquire migration behavior implicitly.
2. Contract reuse: compiler must delegate Page plan construction to Page Resource.
3. Non-mutation: blueprint validation/preview must not write WordPress state.
4. Drift behavior: incompatible existing state must be blocked, not adopted.
5. Bootstrap ordering: Mode/Greenfield/Blueprint classes load before Manager bootstrap uses them.
6. PHP 8.1 compatibility.

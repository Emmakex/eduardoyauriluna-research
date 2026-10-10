# Greenfield architecture map

```text
Research blueprint (known desired state)
        |
        v
Blueprint validator
        |
        v
Blueprint compiler (non-mutating classification)
        |
        +--> Page Resource
        +--> Insight Resource
        +--> Output Resource
        +--> Project Resource
        +--> Software Resource
        +--> Dataset Resource
                 |
                 v
          bounded Manager plans
                 |
                 v
             Executor
       preview/apply/verify
          snapshot/rollback
                 |
                 v
          Research Theme
       frontend/layout authority
```

There is no scan/interpret legacy stage in this path. That stage belongs only to the separate Migration scenario.

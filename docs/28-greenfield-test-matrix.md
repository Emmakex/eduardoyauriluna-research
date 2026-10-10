# Greenfield test matrix

| Area | Expected |
| --- | --- |
| Mode unset | Greenfield |
| Existing arbitrary content | Still Greenfield |
| Explicit Migration | Greenfield orchestrator blocked |
| Invalid mode value | Safe fallback to Greenfield |
| Unsupported blueprint key | Rejected |
| Unsupported language | Rejected |
| Malformed Page declaration | Rejected |
| Blueprint preview | Non-mutating |
| Missing Theme Page | `create` plan from Page Resource |
| Existing aligned Theme Page | `already-matching` |
| Existing contract drift | `blocked` |
| Gutenberg layout requirement | Never introduced |

# Database Reconnaissance

## Automatically detected tables
- `public`

Claude must verify:
- columns/types
- PK/FK
- indexes
- unique constraints
- nullable/default rules
- enums/checks
- triggers/functions
- RLS policies
- application reads/writes
- whether each entity should be kept, split, merged or redesigned

**Do not perform a PostgreSQL-to-MySQL line-by-line conversion.**

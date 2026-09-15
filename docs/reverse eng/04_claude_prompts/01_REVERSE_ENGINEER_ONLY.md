# Claude Code — Reverse Engineering Only

You are the reverse-engineering analyst for the Aviation Club International (ACI) Laravel rebuild.

The reference project is the GitHub/Lovable application available in the reference workspace.

It currently uses Lovable/TanStack Start/React/TypeScript/Supabase and is associated with Vercel/Supabase.

The new production application must use:
- Laravel
- MySQL
- Blade
- Livewire
- Alpine.js
- Tailwind CSS
- Laravel authentication
- Laravel Filesystem
- provider-independent payment architecture
- SiteGround-compatible deployment

## THIS TURN IS ANALYSIS ONLY

Do NOT:
- create migrations
- create Eloquent models
- modify Laravel code
- convert React files
- install Supabase
- install PostgreSQL
- implement e-commerce
- delete reference files

Inspect the entire reference application.

Analyse:
1. project structure
2. dependencies
3. routes
4. pages
5. components
6. forms
7. user journeys
8. admin journeys
9. authentication
10. authorization
11. Supabase database calls
12. Supabase schema/migrations
13. RLS policies
14. RPC/functions/triggers
15. storage
16. notifications
17. validation
18. external integrations
19. SEO
20. configuration
21. error handling
22. edge cases
23. security weaknesses
24. legacy/duplicate functionality

For every discovered feature document:

SOURCE
→ CURRENT BEHAVIOUR
→ DATA
→ SUPABASE DEPENDENCY
→ BUSINESS RULE
→ SECURITY RULE
→ LARAVEL REPLACEMENT
→ MYSQL REQUIREMENT
→ TEST REQUIREMENT

Create/update:
docs/reverse-engineering/SOURCE_INVENTORY.md
docs/reverse-engineering/ROUTES.md
docs/reverse-engineering/FEATURES.md
docs/reverse-engineering/WORKFLOWS.md
docs/reverse-engineering/DATABASE.md
docs/reverse-engineering/SUPABASE.md
docs/reverse-engineering/AUTHORIZATION.md
docs/reverse-engineering/STORAGE.md
docs/reverse-engineering/VALIDATION.md
docs/reverse-engineering/NOTIFICATIONS.md
docs/reverse-engineering/LEGACY_RISKS.md

Cite source filenames and line/function references whenever practical.

Do not guess. If something is uncertain, mark it UNCERTAIN.

STOP after producing the analysis. Wait for approval.

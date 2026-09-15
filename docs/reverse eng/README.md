# ACI Reverse Engineering Package

Prepared from the uploaded GitHub ZIP `aviation-community-hub-main(4).zip`.

## Purpose
Reverse engineer the existing Lovable/Supabase application and use the resulting evidence to rebuild a clean Laravel + MySQL application suitable for SiteGround.

## Source facts discovered
- Project root: `aviation-community-hub-main`
- Files: 196
- Route-related files: 61
- Supabase-related files: 35
- Schema/migration candidates: 19
- Tables detected in SQL/schema candidates: 1

## Critical rule
This is **reverse engineering, not code conversion**.

The reference application remains unchanged. Claude Code should document its behaviour first, then redesign that behaviour for Laravel.

## Target
Laravel + MySQL + Blade + Livewire + Alpine.js + Tailwind CSS + Laravel authentication + Laravel Filesystem + provider-independent payments, deployable to SiteGround.

## Package workflow
1. Inspect `01_source_analysis/`.
2. Give Claude Code the reverse-engineering prompt.
3. Review Claude's evidence-based reports.
4. Resolve gaps/contradictions.
5. Design Laravel/MySQL architecture.
6. Approve the database.
7. Implement module by module.
8. Add the e-shop as a new requirement, not as a falsely reverse-engineered feature.

# SiteGround Target Requirements

The runtime must not depend on Supabase, Vercel, PostgreSQL or a Node application server.

Target:
- supported PHP/Laravel version
- MySQL
- HTTPS
- Laravel public document root
- writable storage/cache
- environment configuration
- SMTP
- cron/scheduler where needed
- suitable queue strategy
- backups

Node/npm may be used for asset compilation but not required as a production application server.

# BarangayConnect

Barangay management system for Barangay Tambo, Parañaque City. The original archive uses PHP, HTML/CSS/JavaScript, and MySQL. A Supabase migration has been started in this copy.

> **Migration status:** The database schema, RLS/storage policies, server-side Supabase REST helper, resident registration, unified login, server-side Turnstile verification, and portal role-session checks are included. The original data modules still contain MySQL-specific queries and are not yet migrated; requests, borrowing, inventory, transparency, emergency contacts, reports, notifications, profile management, admin management, and chatbot persistence will not work against Supabase until those modules are converted. Do not deploy this package as a complete system.

## Supabase preparation

1. Create a Supabase project.
2. Run `database/supabase_target_schema.sql` in the Supabase SQL Editor. It creates the target PostgreSQL schema, RLS policies, and Storage buckets.
3. Configure these variables in the PHP server environment from `.env.example`: `SUPABASE_URL`, `SUPABASE_ANON_KEY`, `SUPABASE_SERVICE_ROLE_KEY`, `TURNSTILE_SITE_KEY`, and `TURNSTILE_SECRET_KEY`. The service-role and Turnstile secret keys must remain server-side.
4. Enable PHP cURL and sessions. Serve the project through Apache/PHP; a static `file://` URL will not run PHP.
5. Provision the designated full-access administrator in Supabase Auth, then create or update its `public.profiles` row with `role='superadmin'` and `status='active'`. Existing `admin` profiles remain compatible. Resident accounts can use the public registration endpoint, which assigns only the `resident` role.
6. Configure Cloudflare Turnstile for the site hostname. Until keys are configured, login remains disabled.

`database/supabase_target_schema.sql` is a target schema only. It does not copy data out of the old MySQL installation. No existing production database was included in the ZIP, so its records cannot be migrated here.

See [IMPLEMENTATION_NOTES.md](IMPLEMENTATION_NOTES.md) for the migration audit, changed files, and outstanding modules.

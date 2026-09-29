# Supabase and PHP setup

This guide configures the Supabase pieces included in this copy. The application migration is incomplete; see `IMPLEMENTATION_NOTES.md` before using it.

## 1. Create Supabase resources

1. Create a Supabase project and save its Project URL, anon/public key, and service-role key.
2. Run `database/supabase_target_schema.sql` in the Supabase SQL Editor.
3. In Supabase Auth settings, set the website URL and redirect URL to the hosted `FrontPage/html/Front.html` address. Keep email confirmation enabled so newly registered email addresses are verified.
4. Configure a Cloudflare Turnstile widget for the same hostname and copy its site key and secret key.

## 2. Configure PHP on Apache

PHP must have the `curl` extension and sessions enabled. Enable Apache `mod_rewrite` and `AllowOverride All` for this project so Admin/Resident HTML routes pass through the role guard.

Set these values in the PHP server process environment. For local Apache, place them in the relevant virtual host or Apache environment config; do not put secrets in JavaScript or a public PHP file:

```apache
SetEnv SUPABASE_URL "https://YOUR_PROJECT.supabase.co"
SetEnv SUPABASE_ANON_KEY "YOUR_ANON_KEY"
SetEnv SUPABASE_SERVICE_ROLE_KEY "YOUR_SERVICE_ROLE_KEY"
SetEnv TURNSTILE_SITE_KEY "YOUR_TURNSTILE_SITE_KEY"
SetEnv TURNSTILE_SECRET_KEY "YOUR_TURNSTILE_SECRET_KEY"
```

The PHP code loads the project-root `.env` file for local development; process environment values take precedence. Keep `.env` private and never commit real secrets. For production, configure the variables in the PHP/Apache process environment and use real Turnstile keys. On loopback development only, the login form uses a local test token so it does not depend on the external Turnstile widget; this token is rejected from non-local requests.

Open the site through Apache at the project URL. Check `FrontPage/php/connection_test.php` for Supabase configuration and schema reachability. The unified login stays disabled until a Turnstile site key is configured.

## 3. Provision admin accounts

Create the designated full-access administrator through Supabase Auth using an email the barangay controls. Then add the matching profile row in the SQL Editor, replacing the UUID and values:

```sql
insert into public.profiles (id, role, status, full_name, email, username)
values ('AUTH_USER_UUID', 'superadmin', 'active', 'Barangay Administrator', 'admin@example.gov.ph', 'superadmin');
```

For an existing project, first run `database/supabase_superadmin_migration.sql`. To promote an existing Auth account, use that account's exact email and update only its matching active profile to `role='superadmin'`. Keep the service-role key private. Public resident registration assigns only the `resident` role.

## 4. Cutover limitation

The supplied ZIP has no populated MySQL database to export. The remaining PHP data endpoints have not been converted, so this configuration does not make those modules Supabase-ready. Do not enable production resident records or upload sensitive IDs until their APIs and Storage access checks are migrated and verified.

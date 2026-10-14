# Implementation notes and migration boundary

## Existing project audit

The supplied archive is a PHP + MySQL project, not a Supabase application. It has static HTML portal shells, browser localStorage state, and about 30 PHP files containing MySQL queries. Emergency-call links and several transparency status badges were already present. No populated production database was included in the ZIP.

## Changes included in this delivery

- Removed the map-pin glyph from the public landing page and restyled the hero with a locally bundled barangay/community illustration, dark overlay, separator rules, and responsive admin guide cards.
- Added an Admin Panel manual on the public page and a footer link to it.
- Added a first-visit privacy notice gate with an explicit “I Understand” action. Acknowledgment is remembered in that browser using localStorage. The privacy office contact is intentionally directed to the Barangay Tambo office because no verified DPO contact was provided.
- Removed the FAQ entry and FAQ page frame from the Resident portal navigation.
- Embedded the existing Transparency page in the resident dashboard homepage.
- Added resident borrowing history printing with resident, item, quantity, request date, expected and actual return dates, status, and reference number. Actual return is shown only when the current record has one.
- Added delayed and archived transparency badge colors.
- Added a Supabase target schema, RLS policies, and Storage buckets for resident-private, document-private, signature-private, and transparency-public files.
- Added a PHP Supabase REST/Auth/Storage client, environment-only server credentials, secure session helpers, Turnstile verification, and signed-URL helpers.
- Replaced the public login role tabs with a unified login form. Login verifies Turnstile in PHP, authenticates through Supabase Auth, retrieves the role from `profiles`, and starts a PHP session. Resident registration creates a Supabase Auth account and requires email verification.
- Added server-side role checks for portal HTML routes through Apache rewrite rules and PHP. Root `.htaccess` also blocks web access to `.env` files.
- Replaced the old MySQL setup/connection check with a Supabase readiness check and removed the old MySQL schema/install/migration scripts.

## Supabase migration status

`database/supabase_target_schema.sql` is a **target schema**, not an automatic migration of the existing database. Authentication, announcements, emergency contacts/procedures, the admin resident directory, privacy acknowledgments, and the resident-owned profile endpoint use Supabase. Several business APIs still depend on MySQL, including requests, borrowing, inventory, transparency, reports, notifications, feedback, and chatbot persistence. Convert and secure those remaining endpoints before treating the application as a complete Supabase deployment. A credentials template does not activate Supabase by itself.

## Not implemented / requires backend work or supplied configuration

- Migration of requests, borrowing, inventory, transparency, reports, notifications, feedback, chatbot, and remaining admin-management APIs from MySQL to Supabase.
- A consistent authorization audit and private Supabase Storage upload/download flow for the remaining business APIs.
- Private ID upload flow, approved-document generation/e-signature/download controls, resident QR-token verification, and printable official borrowing history (the current print view is resident-only and uses available request fields).
- A data export/import mapping for populated MySQL installations. The ZIP contained schema/code only, not the user’s database records.
- A 15-person pilot and full system evaluation. No participants or completed feedback were supplied, so no results are claimed.

Do not deploy this package as a complete or secure resident-records application until those backend items are completed. Configure secrets in the PHP server environment and keep them out of public JavaScript.

## Supabase work now included

- Both portal shells check the PHP Supabase session role before showing the portal. Apache rewrite protection applies to Admin/Resident HTML routes when `mod_rewrite` and `.htaccess` overrides are enabled.
- PHP session checks re-read the active Supabase profile and role; resident profile reads and edits are scoped to the signed-in resident.
- Privacy acknowledgments can be written to Supabase after an authenticated user accepts the browser notice.

## Migration still incomplete

The legacy application has roughly 30 database-coupled PHP files. Most still contain MySQL queries and call the removed MySQL connection functions. The system is not runnable end-to-end against Supabase until all those endpoints are migrated. The new authentication/schema code has not been tested against a live Supabase project, and no PHP CLI or configured Supabase project was available in this environment.

The schema is a new target structure; no production MySQL data was present in the archive. Existing resident/admin accounts and records need an export/import process and role reconciliation before cutover. Admin account provisioning must be controlled by the barangay; public signup always creates resident roles.

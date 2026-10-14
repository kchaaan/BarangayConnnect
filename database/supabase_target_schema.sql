-- BarangayConnect Supabase target schema (PostgreSQL)
-- Apply to a NEW Supabase project only after reviewing the migration notes.
-- Existing MySQL data is not copied by this script. Authentication passwords stay in Supabase Auth.
create extension if not exists pgcrypto;

do $$ begin
  create type public.bc_role as enum ('superadmin','admin','resident');
exception when duplicate_object then null; end $$;
alter type public.bc_role add value if not exists 'superadmin';

do $$ begin
  create type public.bc_record_status as enum ('pending','ongoing','completed','delayed','archived','approved','rejected','released','returned');
exception when duplicate_object then null; end $$;

create table if not exists public.profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  role public.bc_role not null default 'resident',
  status text not null default 'active' check (status in ('active','inactive','suspended')),
  full_name text not null,
  email text, phone text unique, username text unique,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create table if not exists public.residents (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null unique references public.profiles(id) on delete cascade,
  resident_id text not null unique,
  first_name text not null, middle_name text, last_name text not null, suffix text, username text unique, email text,
  birth_date date, gender text, address text, purok text, contact_number text,
  civil_status text, profile_photo_path text, profile_data jsonb not null default '{}'::jsonb, qr_token_hash text unique,
  created_at timestamptz not null default now(), updated_at timestamptz not null default now()
);
create table if not exists public.document_requests (
  id uuid primary key default gen_random_uuid(), resident_id uuid not null references public.residents(id),
  document_type text not null, purpose text not null, status text not null default 'pending' check (status in ('pending','in_progress','approved','rejected','released')),
  requested_at timestamptz not null default now(), approved_at timestamptz, released_at timestamptz,
  document_path text, esignature_path text, remarks text, created_by uuid references public.profiles(id)
);
create table if not exists public.borrowing_records (
  id uuid primary key default gen_random_uuid(), resident_id uuid not null references public.residents(id),
  item_name text not null, quantity integer not null check (quantity > 0),
  date_borrowed date, expected_return_date date, actual_return_date date,
  status text not null default 'pending' check (status in ('pending','approved','rejected','borrowed','returned','overdue')),
  remarks text, created_at timestamptz not null default now()
);
create table if not exists public.transparency_records (
  id uuid primary key default gen_random_uuid(), title text not null, description text, category text,
  amount numeric(14,2) check (amount is null or amount >= 0),
  status text not null default 'pending' check (status in ('pending','ongoing','completed','delayed','archived')),
  project_date date, public_document_path text, created_at timestamptz not null default now(), updated_at timestamptz not null default now()
);
create table if not exists public.emergency_contacts (
  id uuid primary key default gen_random_uuid(), name text not null, organization text,
  contact_number text not null, description text, icon text, is_critical boolean not null default false,
  sort_order integer not null default 0, active boolean not null default true,
  created_at timestamptz not null default now(), updated_at timestamptz not null default now()
);
alter table public.emergency_contacts add column if not exists icon text;
alter table public.emergency_contacts add column if not exists is_critical boolean not null default false;
alter table public.emergency_contacts add column if not exists sort_order integer not null default 0;
alter table public.emergency_contacts add column if not exists updated_at timestamptz not null default now();

-- Seed the public emergency homepage and Admin Emergency Contacts list.
-- Barangay Tambo local contact numbers supplied by the project owner.
update public.emergency_contacts
set active = false, updated_at = now()
where lower(trim(name)) in ('red cross', 'public works', 'mmda hotline', 'fire department', 'police station', 'maynilad', 'meralco', 'dswd disaster response unit');

with desired_contacts(name, organization, contact_number, description, icon, is_critical, sort_order) as (
  values
    ('Emergency', 'National Emergency Hotline', '911', 'Unified 911 for police, fire, medical, and disaster response.', 'fa-ambulance', true, 1),
    ('Barangay Landline', 'Barangay Tambo', '(02) 8654-0596', 'Call Barangay Tambo landline for local assistance.', 'fa-building', true, 2),
    ('Barangay Mobile (Globe)', 'Barangay Tambo', '0977 723-2933', 'Call Barangay Tambo mobile hotline for local assistance.', 'fa-mobile-alt', true, 3),
    ('Police Station - Tambo/Solaire', 'Parañaque City Police Station', '(02) 8887-1437', 'Police Station hotline serving Tambo/Solaire.', 'fa-shield-alt', true, 4),
    ('Parañaque Fire Central', 'Bureau of Fire Protection', '(02) 8826-9131 / (02) 8826-3176', 'Parañaque Fire Central emergency and rescue hotlines.', 'fa-fire', true, 5),
    ('Barangay Health Center', 'Barangay Tambo', '(02) 8403-4910', 'Barangay Health Center hotline.', 'fa-first-aid', true, 6),
    ('NDRRMC', 'National Disaster Risk Reduction and Management Council', '(02) 8911-5061 to 65 local 100', 'National disaster coordination and response.', 'fa-house-damage', true, 7)
), inserted as (
  insert into public.emergency_contacts(name, organization, contact_number, description, icon, is_critical, sort_order, active)
  select d.name, d.organization, d.contact_number, d.description, d.icon, d.is_critical, d.sort_order, true
  from desired_contacts d
  where not exists (
    select 1 from public.emergency_contacts e where lower(trim(e.name)) = lower(trim(d.name))
  )
  returning id
)
update public.emergency_contacts e
set organization = d.organization,
    contact_number = d.contact_number,
    description = d.description,
    icon = d.icon,
    is_critical = d.is_critical,
    active = true,
    sort_order = d.sort_order,
    updated_at = now()
from desired_contacts d
where lower(trim(e.name)) = lower(trim(d.name));

create table if not exists public.id_requests (
  id uuid primary key default gen_random_uuid(), resident_id uuid not null references public.residents(id),
  valid_id_path text not null, resident_photo_path text not null,
  status text not null default 'pending' check (status in ('pending','approved','rejected')),
  remarks text, submitted_at timestamptz not null default now(), approved_at timestamptz
);
create table if not exists public.privacy_acknowledgments (
  id uuid primary key default gen_random_uuid(), user_id uuid not null references public.profiles(id) on delete cascade,
  notice_version text not null, acknowledged boolean not null default true, acknowledged_at timestamptz not null default now(), unique(user_id,notice_version)
);

create or replace function public.bc_is_admin() returns boolean
language sql stable security definer set search_path=public
as $$ select exists(select 1 from public.profiles p where p.id=auth.uid() and p.role::text in ('admin','superadmin') and p.status='active') $$;
create or replace function public.bc_my_resident_id() returns uuid
language sql stable security definer set search_path=public
as $ select r.id from public.residents r where r.user_id=auth.uid() limit 1 $;

-- These SECURITY DEFINER helpers are for authenticated RLS policies, not anonymous callers.
revoke execute on function public.bc_is_admin() from public, anon;
revoke execute on function public.bc_my_resident_id() from public, anon;
grant execute on function public.bc_is_admin() to authenticated, service_role;
grant execute on function public.bc_my_resident_id() to authenticated, service_role;

alter table public.profiles enable row level security;
alter table public.residents enable row level security;
alter table public.document_requests enable row level security;
alter table public.borrowing_records enable row level security;
alter table public.transparency_records enable row level security;
alter table public.emergency_contacts enable row level security;
alter table public.id_requests enable row level security;
alter table public.privacy_acknowledgments enable row level security;

drop policy if exists profiles_read_self_or_admin on public.profiles;
create policy profiles_read_self_or_admin on public.profiles for select to authenticated using (id=auth.uid() or public.bc_is_admin());
drop policy if exists residents_read_self_or_admin on public.residents;
create policy residents_read_self_or_admin on public.residents for select to authenticated using (user_id=auth.uid() or public.bc_is_admin());
drop policy if exists resident_update_self on public.residents;
create policy resident_update_self on public.residents for update to authenticated using (user_id=auth.uid()) with check (user_id=auth.uid());
drop policy if exists requests_resident_read_admin_all on public.document_requests;
create policy requests_resident_read_admin_all on public.document_requests for select to authenticated using (resident_id=public.bc_my_resident_id() or public.bc_is_admin());
drop policy if exists borrow_resident_read_admin_all on public.borrowing_records;
create policy borrow_resident_read_admin_all on public.borrowing_records for select to authenticated using (resident_id=public.bc_my_resident_id() or public.bc_is_admin());
drop policy if exists transparency_public_read on public.transparency_records;
create policy transparency_public_read on public.transparency_records for select to anon,authenticated using (status <> 'archived');
drop policy if exists emergency_public_read on public.emergency_contacts;
create policy emergency_public_read on public.emergency_contacts for select to anon,authenticated using (active);
drop policy if exists id_requests_owner_admin_read on public.id_requests;
create policy id_requests_owner_admin_read on public.id_requests for select to authenticated using (resident_id=public.bc_my_resident_id() or public.bc_is_admin());
drop policy if exists privacy_ack_owner_read on public.privacy_acknowledgments;
create policy privacy_ack_owner_read on public.privacy_acknowledgments for select to authenticated using (user_id=auth.uid() or public.bc_is_admin());
-- No client write policies are granted for approvals, role assignments, document release, or ID decisions.
-- Add narrow insert policies only when request forms are migrated and validated. Admin writes belong in
-- PHP server endpoints using a server-side secret or trusted authenticated JWT and strict authorization.


-- Existing modules found in the PHP/MySQL audit; these preserve current features during migration.
create table if not exists public.announcements (
 id uuid primary key default gen_random_uuid(), title text not null, content text not null, category text,
 status text not null default 'draft', author text,
 announcement_date timestamptz, featured boolean not null default false, active boolean not null default true,
 created_at timestamptz not null default now(), updated_at timestamptz not null default now()
);
alter table public.announcements add column if not exists status text not null default 'draft';
alter table public.announcements add column if not exists author text;
alter table public.announcements enable row level security;
drop policy if exists announcements_public_read on public.announcements;
create policy announcements_public_read on public.announcements for select to anon, authenticated using (active and status = 'published');
create table if not exists public.resident_feedback (
 id uuid primary key default gen_random_uuid(), resident_id uuid references public.residents(id), status text,
 message text not null, source text, submitter_name text, created_at timestamptz not null default now()
);
create table if not exists public.community_reports (
 id uuid primary key default gen_random_uuid(), resident_id uuid references public.residents(id), title text,
 category text, description text not null, location text, status text not null default 'pending',
 attachment_path text, admin_remarks text, created_at timestamptz not null default now(), updated_at timestamptz not null default now()
);
create table if not exists public.resident_notifications (
 id uuid primary key default gen_random_uuid(), resident_id uuid not null references public.residents(id),
 title text not null, message text not null, type text, priority text default 'normal', is_read boolean not null default false,
 link_section text, created_at timestamptz not null default now()
);
create table if not exists public.inventory_categories (
 id uuid primary key default gen_random_uuid(), name text not null unique, description text, created_at timestamptz not null default now()
);
create table if not exists public.inventory_items (
 id uuid primary key default gen_random_uuid(), category_id uuid references public.inventory_categories(id) on delete set null,
 name text not null, description text, total_qty integer not null default 0 check(total_qty>=0), available_qty integer not null default 0 check(available_qty>=0),
 unit text not null default 'pcs', status text not null default 'available', image_path text,
 created_at timestamptz not null default now(), updated_at timestamptz not null default now()
);
create table if not exists public.emergency_procedures (
 id uuid primary key default gen_random_uuid(), title text not null, steps jsonb not null default '[]'::jsonb,
 icon text, sort_order integer not null default 0, active boolean not null default true, updated_at timestamptz not null default now()
);
alter table public.emergency_procedures add column if not exists active boolean not null default true;
alter table public.emergency_procedures add column if not exists updated_at timestamptz not null default now();
alter table public.emergency_procedures enable row level security;
drop policy if exists emergency_procedures_public_read on public.emergency_procedures;
create policy emergency_procedures_public_read on public.emergency_procedures for select to anon, authenticated using (active);
create table if not exists public.transparency_budget (
 id uuid primary key default gen_random_uuid(), fiscal_year integer not null unique, amount numeric(14,2) not null default 0 check(amount>=0), updated_at timestamptz not null default now()
);
create table if not exists public.transparency_budget_history (
 id uuid primary key default gen_random_uuid(), budget_id uuid references public.transparency_budget(id) on delete set null,
 previous_amount numeric(14,2), new_amount numeric(14,2) not null, changed_at timestamptz not null default now(), changed_by uuid references public.profiles(id)
);
create table if not exists public.transparency_items (
 id uuid primary key default gen_random_uuid(), name text not null, type text, budget numeric(14,2) not null default 0,
 spent numeric(14,2) not null default 0, status text not null default 'Pending', description text,
 start_date date, end_date date, created_at timestamptz not null default now(), updated_at timestamptz not null default now()
);
create table if not exists public.transparency_documents (
 id uuid primary key default gen_random_uuid(), item_id uuid not null references public.transparency_items(id) on delete cascade,
 file_path text not null, created_at timestamptz not null default now()
);
create table if not exists public.chatbot_sessions (
 session_id text primary key, resident_id uuid references public.residents(id), metadata jsonb not null default '{}'::jsonb,
 created_at timestamptz not null default now(), updated_at timestamptz not null default now()
);
create table if not exists public.chatbot_messages (
 id uuid primary key default gen_random_uuid(), session_id text not null references public.chatbot_sessions(session_id) on delete cascade,
 role text not null check(role in ('user','assistant','system')), content text not null, created_at timestamptz not null default now()
);
-- Supporting service request tables use UUID identifiers and private object paths.
create table if not exists public.service_requests (
 id uuid primary key default gen_random_uuid(), request_code text unique not null, resident_id uuid references public.residents(id),
 request_type text not null, document_type text not null, status text not null default 'pending', remarks text,
 payload jsonb not null default '{}'::jsonb, submitted_at timestamptz not null default now(), updated_at timestamptz not null default now()
);
create table if not exists public.service_request_files (
 id uuid primary key default gen_random_uuid(), request_id uuid not null references public.service_requests(id) on delete cascade,
 file_label text not null, file_name text not null, file_type text not null, storage_path text not null, created_at timestamptz not null default now()
);

-- Private buckets. Public access is limited to published transparency attachments.
insert into storage.buckets(id,name,public,file_size_limit,allowed_mime_types) values
 ('resident-private','resident-private',false,10485760,array['image/jpeg','image/png','application/pdf']),
 ('document-private','document-private',false,20971520,array['application/pdf','image/jpeg','image/png']),
 ('transparency-public','transparency-public',true,20971520,array['application/pdf','image/jpeg','image/png']),
 ('signature-private','signature-private',false,5242880,array['image/png','image/jpeg'])
on conflict(id) do update set public=excluded.public,file_size_limit=excluded.file_size_limit,allowed_mime_types=excluded.allowed_mime_types;

alter table public.announcements enable row level security;
alter table public.resident_feedback enable row level security;
alter table public.community_reports enable row level security;
alter table public.resident_notifications enable row level security;
alter table public.inventory_categories enable row level security;
alter table public.inventory_items enable row level security;
alter table public.emergency_procedures enable row level security;
alter table public.transparency_budget enable row level security;
alter table public.transparency_budget_history enable row level security;
alter table public.transparency_items enable row level security;
alter table public.transparency_documents enable row level security;
alter table public.chatbot_sessions enable row level security;
alter table public.chatbot_messages enable row level security;
alter table public.service_requests enable row level security;
alter table public.service_request_files enable row level security;

-- Public read data. Writes are made by PHP after it verifies the user's server-side role.
drop policy if exists announcements_public_read on public.announcements;
create policy announcements_public_read on public.announcements for select to anon, authenticated using (active and status = 'published');
drop policy if exists emergency_procedures_public_read on public.emergency_procedures;
create policy emergency_procedures_public_read on public.emergency_procedures for select to anon,authenticated using(active);
drop policy if exists inventory_public_read on public.inventory_items;
create policy inventory_public_read on public.inventory_items for select to authenticated using(true);
drop policy if exists categories_public_read on public.inventory_categories;
create policy categories_public_read on public.inventory_categories for select to authenticated using(true);
drop policy if exists budget_public_read on public.transparency_budget;
create policy budget_public_read on public.transparency_budget for select to anon,authenticated using(true);
drop policy if exists transparency_items_public_read on public.transparency_items;
create policy transparency_items_public_read on public.transparency_items for select to anon,authenticated using(status <> 'Archived');
drop policy if exists transparency_docs_public_read on public.transparency_documents;
create policy transparency_docs_public_read on public.transparency_documents for select to anon, authenticated using (exists (select 1 from public.transparency_items i where i.id = item_id and i.status <> 'Archived'));
drop policy if exists reports_owner_admin_read on public.community_reports;
create policy reports_owner_admin_read on public.community_reports for select to authenticated using(resident_id=public.bc_my_resident_id() or public.bc_is_admin());
drop policy if exists requests_owner_admin_read on public.service_requests;
create policy requests_owner_admin_read on public.service_requests for select to authenticated using(resident_id=public.bc_my_resident_id() or public.bc_is_admin());
drop policy if exists request_files_owner_admin_read on public.service_request_files;
create policy request_files_owner_admin_read on public.service_request_files for select to authenticated using(exists(select 1 from public.service_requests r where r.id=request_id and (r.resident_id=public.bc_my_resident_id() or public.bc_is_admin())));
drop policy if exists notifications_owner_admin_read on public.resident_notifications;
create policy notifications_owner_admin_read on public.resident_notifications for select to authenticated using(resident_id=public.bc_my_resident_id() or public.bc_is_admin());
drop policy if exists feedback_admin_read on public.resident_feedback;
create policy feedback_admin_read on public.resident_feedback for select to authenticated using(public.bc_is_admin() or resident_id=public.bc_my_resident_id());
drop policy if exists id_doc_owner_admin_read on storage.objects;
create policy id_doc_owner_admin_read on storage.objects for select to authenticated using(bucket_id in ('resident-private','document-private','signature-private') and (name like auth.uid()::text || '/%' or public.bc_is_admin()));
drop policy if exists id_doc_owner_upload on storage.objects;
create policy id_doc_owner_upload on storage.objects for insert to authenticated with check(bucket_id='resident-private' and name like auth.uid()::text || '/%');
drop policy if exists public_transparency_read on storage.objects;
create policy public_transparency_read on storage.objects for select to anon,authenticated using(bucket_id='transparency-public');
drop policy if exists transparency_admin_write on storage.objects;
create policy transparency_admin_write on storage.objects for all to authenticated using(bucket_id='transparency-public' and public.bc_is_admin()) with check(bucket_id='transparency-public' and public.bc_is_admin());
-- PHP service-role writes bypass RLS only after server-side session role checks. Keep key out of browser code.

-- Add the full-access Super Admin role to an existing BarangayConnect Supabase project.
-- Run this migration first. Promote/create the specific profile in a separate SQL Editor run.
alter type public.bc_role add value if not exists 'superadmin';

create or replace function public.bc_is_admin() returns boolean
language sql stable security definer set search_path=public
as $$
  select exists (
    select 1 from public.profiles p
    where p.id = auth.uid()
      and p.role::text in ('admin', 'superadmin')
      and p.status = 'active'
  )
$$;

-- After the migration above succeeds, promote only the account confirmed by the owner.
-- Replace the email, then run this separately and verify the returned row:
-- update public.profiles
-- set role = 'superadmin', status = 'active'
-- where lower(email) = lower('OWNER-CONTROLLED-EMAIL')
-- returning id, email, role, status;

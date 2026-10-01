-- Run once in Supabase SQL Editor to seed the homepage and Admin Emergency Contacts.
-- Safe to rerun: rows are matched by name, and all seven are kept active and ordered.
-- Local Barangay Tambo contact numbers supplied by the project owner.

-- Hide the replaced defaults when refreshing an existing installation.
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

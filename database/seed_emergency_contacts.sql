-- Run once in Supabase SQL Editor to seed the homepage and Admin Emergency Contacts.
-- Safe to rerun: rows are matched by name, and all eight are kept active and ordered.
-- Government hotline sources:
-- https://ehotlines.e.gov.ph/
-- https://www.spd.ncrpo.pnp.gov.ph/wp-content/uploads/2024/04/RA8353-2.pdf
-- https://www.dpwh.gov.ph/dpwh/node/39534

with desired_contacts(name, organization, contact_number, description, icon, is_critical, sort_order) as (
  values
    ('Medical Emergency', 'National Emergency Hotline', '911', 'Unified 911 for police, fire, medical, and disaster response.', 'fa-ambulance', true, 1),
    ('Fire Department', 'Bureau of Fire Protection', '(02) 8426-0219 / (02) 8426-0246', 'Fire and rescue services.', 'fa-fire', true, 2),
    ('Police Station', 'Parañaque City Police Station', '0956-346-7827 / 0998-598-7926', 'Parañaque City Police Station hotline.', 'fa-shield-alt', true, 3),
    ('Red Cross', 'Philippine Red Cross', '143', 'Disaster relief and emergency assistance.', 'fa-heart', true, 4),
    ('Public Works', 'Department of Public Works and Highways', '165-02', 'DPWH hotline for public works and infrastructure concerns.', 'fa-tools', false, 5),
    ('NDRRMC', 'National Disaster Risk Reduction and Management Council', '(02) 8911-5061 to 65 local 100', 'National disaster coordination and response.', 'fa-house-damage', true, 6),
    ('MMDA Hotline', 'Metro Manila Development Authority', '136', 'Metro Manila assistance and response hotline.', 'fa-road', true, 7),
    ('DSWD Disaster Response Unit', 'Department of Social Welfare and Development', '(02) 8856-3665 / (02) 8852-8081', 'Disaster response assistance.', 'fa-hands-helping', true, 8)
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

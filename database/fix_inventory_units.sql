-- Repair inventory item units corrupted by incorrect mysqli bind types (stored as "0").
-- Safe to run multiple times.

UPDATE inventory_items SET unit = 'pcs' WHERE name = 'Monoblock Chair (White)' AND (unit = '0' OR unit = '');
UPDATE inventory_items SET unit = 'pcs' WHERE name = 'Folding Table (6ft)' AND (unit = '0' OR unit = '');
UPDATE inventory_items SET unit = 'set' WHERE name = 'Sound System Set' AND (unit = '0' OR unit = '');
UPDATE inventory_items SET unit = 'unit' WHERE name = 'Portable Generator' AND (unit = '0' OR unit = '');
UPDATE inventory_items SET unit = 'set' WHERE name = 'Volleyball Net' AND (unit = '0' OR unit = '');
UPDATE inventory_items SET unit = 'kit' WHERE name = 'First Aid Kit' AND (unit = '0' OR unit = '');
UPDATE inventory_items SET unit = 'pcs' WHERE unit = '0' OR unit = '';

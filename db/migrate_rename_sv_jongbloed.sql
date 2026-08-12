-- Renames the SV translation to 'Statenvertaling (Jongbloed)', matching the
-- parenthesized style of the other SV-family editions.
-- Mirrors app/migrations/Version20260812090000.php for manual application.

UPDATE translations SET name = 'Statenvertaling (Jongbloed)' WHERE code = 'SV';

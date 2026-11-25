# Migrations

Plain `.sql` files, applied in filename order by `php bin/migrate.php` and
recorded in the `schema_migrations` table so each runs once.

Naming: `NNN_short_description.sql` — for example `003_add_user_avatar.sql`.

The baseline (`001_initial_schema`, `002_seed_reference_data`) is represented
by `../schema.sql` and `../seed.sql` and is marked as applied by `seed.sql`,
so a fresh install and an upgraded install converge on the same state.

Write forward-only migrations. Do not edit a migration that has shipped — add
a new one.

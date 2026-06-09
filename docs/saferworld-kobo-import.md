# Saferworld Kobo Import

The `saferworld:import-kobo` command imports historical Kobo CSV submissions as
normal Ushahidi posts. It uses the existing survey fields and post-value tables,
so imported records are available to permissions, maps, exports, and dashboard
queries.

## Recommended environment command

Run the coordinator independently against the dev and production API
environments:

```bash
php artisan saferworld:provision-ewer \
  /secure/path/NAGAASHO_EWER.xlsx \
  /secure/path/kobo-export.csv \
  --user-email=admin@example.com
```

The command:

- creates the `NAGAASHO EWER` survey if it does not exist;
- reuses the existing survey on subsequent runs;
- imports submissions through the normal typed post storage;
- skips Kobo UUIDs that are already present;
- writes to whichever database the current API environment is configured to use.

For Docker development:

```bash
docker compose cp /local/path/NAGAASHO_EWER.xlsx api:/tmp/NAGAASHO_EWER.xlsx
docker compose cp /local/path/kobo-export.csv api:/tmp/kobo-export.csv

docker compose exec api php artisan saferworld:provision-ewer \
  /tmp/NAGAASHO_EWER.xlsx \
  /tmp/kobo-export.csv \
  --user-email=admin@example.com
```

Run the equivalent command inside the dev API container and then inside the
production API container. Dev and production use separate databases, so each
environment must run the command once.

Do not commit the Kobo export to Git if it contains sensitive survey data.
Transfer it through the deployment platform's secure file mechanism, execute
the command, and remove the temporary file afterwards.

## Manual commands

### 1. Import the survey schema

The matching survey must exist before importing submissions:

```bash
php artisan saferworld:import-xlsform storage/app/NAGAASHO_EWER.xlsx \
  --name="NAGAASHO EWER" \
  --dry-run
```

Remove `--dry-run` after reviewing the question count:

```bash
php artisan saferworld:import-xlsform storage/app/NAGAASHO_EWER.xlsx \
  --name="NAGAASHO EWER"
```

The command prints the new form ID. Use that ID in the Kobo import.

### 2. Prepare ownership

Imported posts must have an owner. Use one default user:

```bash
php artisan saferworld:import-kobo storage/app/kobo-export.csv \
  --form-id=3 \
  --user-id=1 \
  --dry-run
```

For field-monitor ownership, create a JSON file:

```json
{
  "HDR01": 21,
  "HDR02": 22,
  "BR01": 23
}
```

Then run:

```bash
php artisan saferworld:import-kobo storage/app/kobo-export.csv \
  --form-id=3 \
  --user-map=storage/app/kobo-users.json \
  --user-id=1 \
  --dry-run
```

`--user-id` is the fallback owner when a monitor code is not in the map.

### 3. Override field mapping when needed

Headers are automatically matched to survey field labels and keys. Add a JSON
mapping file only for headers that do not match:

```json
{
  "fields": {
    "Incidence Type": "incidence_type",
    "District where incidence occurred": "district"
  }
}
```

Use it with:

```bash
php artisan saferworld:import-kobo storage/app/kobo-export.csv \
  --form-id=3 \
  --user-map=storage/app/kobo-users.json \
  --mapping=storage/app/kobo-fields.json \
  --dry-run
```

### 4. Import

Remove `--dry-run` after reviewing the mapping and row totals:

```bash
php artisan saferworld:import-kobo storage/app/kobo-export.csv \
  --form-id=3 \
  --user-map=storage/app/kobo-users.json \
  --user-id=1
```

The importer stores Kobo `_uuid` in post metadata and skips an already imported
UUID when the command is run again.

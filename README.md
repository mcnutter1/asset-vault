Asset Vault (PHP/MySQL)

Overview
- PHP web app to manage assets and insurance policies.
- Tracks hierarchical assets (parent/child), photos, and valuation history (purchase, current, replacement).
- Tracks policies with versions/renewals, coverages, costs, and links to assets (with inheritance).
- Mobile-first UI with lightweight Material-inspired styling. Camera capture supported on mobile.

Quick Start
1) Create a MySQL database and import `schema.sql`.
2) Copy `config.sample.php` to `config.php` and set DB creds and app settings.
3) Serve `public/` via PHP built-in server or your web server.
   - Example: `php -S localhost:8080 -t public`

Features Implemented
- Assets CRUD with parent/child tree, photos (multiple, stored in DB), and valuation history.
- Policies CRUD with versions (renewals), coverages, premium history per version, and policy document uploads (stored in DB).
- Link assets to policies; children can inherit policies based on link flag.
- Coverage library (common coverages with defaults by policy type).
- Dashboard with key counts and upcoming expirations (basic).

Planned Enhancements
- Charts for valuation and premium trends (ready JSON endpoints; can add Chart.js).
- User auth and roles.
- Rich audit logs and diffing.

Structure
- `public/` — entrypoint and pages
- `public/assets/` — css/js/images (served by web server)
- `includes/` — layout and shared UI
- `lib/` — database and helpers
- `schema.sql` — database schema and seeds

API
- `public/api.php` provides a JSON API with API key auth.
  - Auth: send `Authorization: Bearer <api_key>` or `X-API-Key: <api_key>` (or `?api_key=`)
    - Keys are validated via the login server using `validate_api_key_c` from `public/auth.php`.
  - GET lists: `GET /api.php?entity=assets|people|policies[&limit=100&offset=0&q=...]`
  - GET detail: `GET /api.php?entity=assets|people|policies&id=123`
    - Detail responses include linked info (see below).
  - POST bulk updates: `POST /api.php` with JSON body `{ "entity": "assets|people|policies", "updates": [ { "id": 1, "fields": { ... } } ] }`
  - Updatable columns:
    - assets: parent_id, name, category_id, description, location, make, model, serial_number, year, odometer_miles, hours_used, purchase_date, notes, location_id, asset_location_id, public_token
    - people: first_name, last_name, dob, notes, gender
    - policies: policy_group_id, version_number, policy_number, insurer, policy_type, start_date, end_date, premium, status, notes

  - Linked info in detail responses:
    - assets: `values`, `addresses`, `properties` (if present), `files` (metadata), `locations`, `children`, `owners` (people via person_assets), `policies` (direct with coverage), `policies_inherited` (from ancestors).
    - policies: `coverages` (with definition info), `assets` (with mapping and apply-to-children), `people` (role + optional coverage), `files` (metadata), `group_versions`.
    - people: `assets` (role), `policies` (role + optional coverage), `files` (metadata).

  - Include links in list responses: add `&include=owners,policies,values` (assets), `&include=coverages,assets,people` (policies), `&include=assets,policies` (people).

Uploads Storage
- All uploaded images/documents are stored in MySQL (`files` table as LONGBLOB) — not on the filesystem.
- Files are streamed via `public/file.php?id=<id>[&download=1]` with appropriate `Content-Type`/`Content-Disposition`.

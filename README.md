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

Uploads Storage
- All uploaded images/documents are stored in MySQL (`files` table as LONGBLOB) — not on the filesystem.
- Files are streamed via `public/file.php?id=<id>[&download=1]` with appropriate `Content-Type`/`Content-Disposition`.

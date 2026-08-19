# SRM Design Library 2.0.0

Phase 2 adds a cloud/master library on top of the working local Phase 1 library.

## Modes

- **Master:** Publish templates locally and expose them through the SRM REST API.
- **Client:** Connect to a Master site, browse its templates, preview them and import them to Elementor Saved Templates.
- **Hybrid:** Keep local publishing/API enabled while also browsing a different Master site.

## Master setup

1. Install/upgrade this plugin on the website that will hold the central library.
2. Go to **SRM Library → Cloud Settings**.
3. Select **Master**.
4. Optionally set a shared access key. Leave blank only if you intentionally want the library API public.
5. Continue adding templates through **SRM Library → Add New Template** exactly as in Phase 1.
6. Those published templates are automatically available through the cloud API.

## Client setup

1. Install the same plugin ZIP on another WordPress/Elementor website.
2. Go to **SRM Library → Cloud Settings**.
3. Select **Client**.
4. Enter the Master WordPress site URL, e.g. `https://master.example.com`.
5. If the Master uses an access key, enter the same key.
6. Save settings.
7. Open **SRM Library → Cloud Library**.
8. Click **Refresh Library** and test **Import to Elementor**.

## REST endpoints

- `/wp-json/srm-design-library/v1/status`
- `/wp-json/srm-design-library/v1/templates`
- `/wp-json/srm-design-library/v1/templates/{id}`

If an access key is configured, clients send it as `X-SRMDL-Key`.

## Phase 2 scope

Included:
- Master REST API
- Optional shared access key
- Client connection
- Catalog caching and manual refresh
- Search/category/type/compatibility filters
- Cloud thumbnails and live preview
- Remote Elementor JSON fetch
- One-click import into Elementor Saved Templates
- Source metadata on imported Elementor templates

Not yet included (next phases):
- Direct insert into the currently open Elementor page
- License/subscription server
- ZIP asset bundle / forced local asset mirroring
- Automatic plugin updater

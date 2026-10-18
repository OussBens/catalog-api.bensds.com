# CaisseDZ Catalog API: move to catalog-api.bensds.com

The API moved from `https://bensds.com/catalog-api` to its own subdomain, **`https://catalog-api.bensds.com`**, because `public_html` (bensds.com) now holds the BENS Digital Solutions website.

| | Before | After |
|---|---|---|
| Address | `https://bensds.com/catalog-api` | `https://catalog-api.bensds.com` |
| Folder on the server | `/home/bensds64/public_html/catalog-api` | `/home/bensds64/caissedz/catalog-api.bensds.com` |

The endpoints are the same. Only the start of the address changes:

| Before | After |
|---|---|
| `https://bensds.com/catalog-api/products` | `https://catalog-api.bensds.com/products` |
| `https://bensds.com/catalog-api/products/barcode/{code}` | `https://catalog-api.bensds.com/products/barcode/{code}` |
| `https://bensds.com/catalog-api/index.php/products` | `https://catalog-api.bensds.com/index.php/products` (both forms still work) |

The same applies to `/products/{id}`, `/products/{id}/photo`, `/upload-photo`, `/mobile-products`, `/import`, `/brands` and `/categories`.

## What was changed in this folder

Only what the new address needs. Nothing else was touched.

**`.htaccess`.** The app now sits at the root of its subdomain, not under `/catalog-api/`.
- `RewriteBase /catalog-api/` became `RewriteBase /`.
- `RedirectMatch 403 ^/catalog-api/(config|models|controllers|services|middleware)/.*$` became `RedirectMatch 403 ^/(config|models|controllers|services|middleware)/.*$`.

**`.env`.**
- `API_URL="https://catalog-api.bensds.com"`
- `UPLOAD_PATH="/home/bensds64/caissedz/catalog-api.bensds.com/uploads/"`
- `UPLOAD_URL="https://catalog-api.bensds.com/uploads/"`

**`index.php`.** The fallback address used when `.env` has no `API_URL` (lines 21, 161 and 319) is now `https://catalog-api.bensds.com`. New photo links are built from `API_URL`, so they now start with `https://catalog-api.bensds.com/uploads/photos/…`.

## On the server (cPanel)

1. Under **Domains**, create `catalog-api.bensds.com` with document root `/home/bensds64/caissedz/catalog-api.bensds.com`. Then run **SSL/TLS Status → Run AutoSSL**. HTTPS is required: bensds.com sends HSTS with `includeSubDomains`, so browsers refuse plain http on every subdomain.
2. Extract this zip into `/home/bensds64/caissedz/`, which gives `/home/bensds64/caissedz/catalog-api.bensds.com/`.
3. **Fix the photo links already in the database.** Each photo's full address is saved in `catalog_product.photo`. In phpMyAdmin, on `bensds64_caissedz_catalogue`, run:
   ```sql
   UPDATE catalog_product
   SET photo = REPLACE(photo, 'https://bensds.com/catalog-api/', 'https://catalog-api.bensds.com/')
   WHERE photo LIKE 'https://bensds.com/catalog-api/%';
   ```
4. Open `https://catalog-api.bensds.com/`. It should answer `"CaisseDZ Catalog API"` with `"database": "Connectée"`. Then open `https://catalog-api.bensds.com/products` and one photo link.
5. If a **cron job** in cPanel runs a script from `/home/bensds64/public_html/catalog-api/`, change its path to `/home/bensds64/caissedz/catalog-api.bensds.com/`.
6. Once everything works, delete `/home/bensds64/public_html/catalog-api`.

## What the app developer has to change

- **The API base URL in every client** (the mobile app, CaisseDZ desktop, any script or tool): change `https://bensds.com/catalog-api` to `https://catalog-api.bensds.com`. Installed copies that still use the old address stop working once the old folder is removed, so ship the update first.
- **Saved photo links on the client side.** If an app keeps product data offline (local database or cache), photo links that start with `https://bensds.com/catalog-api/` must be refreshed from the API or rewritten the same way as the SQL above.
- **Any hard-coded paths** in the developer's own scripts, notes or docs that use `/home/bensds64/public_html/catalog-api`.
- The comments at the top of the helper scripts (`install-*.php`, `import-*.php`, `test-*.php`, …) and the link in `test-upload.php` still mention the old address. They were left as they were (see below).

## Security issues noticed (not changed)

These were found while looking through the folder and have **not** been fixed. The app shares a hosting account with bensds.com, so a weakness here affects that website too.

1. **No authentication on the API.** Anyone on the internet can create, change and delete products (`POST`/`PUT`/`DELETE /products`), upload photos (`/upload-photo`, `/products/{id}/photo`), import CSVs (`/import`) and add products (`/mobile-products`). `middleware/AuthMiddleware.php` and `auth.php` exist, but `index.php` never uses them. `API_KEY` and `JWT_SECRET` are in `.env` but unused. At minimum, every request that changes data should require a secret key (in a header, never in the URL) that the apps send.
2. **Helper scripts anyone can open in a browser.** They should be deleted from the server (keep a copy offline) or moved outside the web folder:
   - `install-*.php` (11 files), `install-composer-fixed.php`, `download-deps.php`, `create-autoload.php`
   - `diagnostic.php`, `test-api.php`, `test-upload.php`, `index_old.php`
   - `import-*.php`, `import_data.php`, `run-import.php`, `analyze-csv.php`, `delete-no-photo.php`
   - `composer.phar`
3. **The import/delete scripts' password is public.** It is `import2026`, written in the scripts' own comments. `delete-no-photo.php` even prints it in its "access protected" message. That script can delete products.
4. **The 160 MB `en.openbeautyfacts.org.products.csv` can be downloaded by anyone.** It also uses disk space on the hosting plan. Move it off the server once the import is done.
5. **Errors are shown to visitors.** `index.php` sets `ini_set('display_errors', 1)`, which can reveal server paths and SQL details. Use `0` in production.
6. **`.composer/`** (Composer cache and `auth.json`) sits inside the web folder. Its `.htaccess` denies access, which is good. It is not needed at runtime and can be deleted.
7. **Uploads.** `uploads/.htaccess` blocks script execution in the photo folder. Keep that file. The `/upload-photo` route keeps the file extension sent by the client: the real type is checked, but use the detected type's extension, as `/products/{id}/photo` already does.

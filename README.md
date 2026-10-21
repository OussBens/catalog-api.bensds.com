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

## CaisseDZ : licences signées (Desktop) et Smart Scans comptés par bens_ds (2026-10-19)

Desktop — Plans & Pricing : CaisseDZ Desktop s'authentifie avec sa **licence BENS signée** et ses
Smart Scans sont **comptés par bens_ds** (scans inclus de la formule, de l'essai et des packs, chacun
avec sa date de fin ; les inclus d'abord, puis le pack qui expire le plus tôt). CaisseDZ Mobile aussi,
au compte de son numéro (celui de la connexion Firebase) : les forfaits mensuels `ss_forfaits` /
`ss_abonnements` ne donnent plus de scans (l'essai, MOB-STD et les packs MOB-SSP-* sont dans bens_ds).

- `smartscan_core/LicenceBens.php` : vérifie la clé (Ed25519, sodium) pour le poste de `X-Device-Id`,
  comme l'application ; l'ancienne clé SHA-256 est refusée.
- `smartscan_core/RevBens.php` : réserve un scan chez bens_ds avant l'OCR, le confirme si du texte est
  lu, le libère sinon (non compté). `GET /smartscan/quota` renvoie le solde (`limit`, `remaining`,
  `buckets`, `expiring_soon`).
- Retirés : `analyze-csv.php`, `auth.php`, `create-autoload.php`, `diagnostic.php`, `download-deps.php`
  (à supprimer aussi sur le serveur). L'extension des photos envoyées vient de leur type réel.

**`.env`** (en plus) :

```
LICENSE_PUBLIC_KEY="…"      # tableau de bord → Licence products → CaisseDZ Desktop → clé publique
LICENSE_PRODUCT="1"         # son numéro
REV_API_URL="https://bensds.com/api"
REV_SERVER_TOKEN="…"        # le même que dans le .env de bens_ds
SMARTSCAN_SECRET="…"        # l'ancien LICENSE_SECRET (empreintes des numéros mobiles), puis supprimer LICENSE_SECRET
```

PHP : extension **sodium** activée (cPanel → Select PHP Version → Extensions). Après l'envoi, ouvrir
l'administration et cliquer « Installer / mettre à jour les tables » (colonne `ss_scans.rev_reservation`).

**Tests** (sans serveur ni base MySQL) :

```
php -d extension=sodium smartscan_core/tests/licence_bens_test.php     # vecteurs partagés avec bens_ds
php -d extension=sodium smartscan_core/tests/smartscan_desktop_test.php # licence, quota, réservation
```

## Rayons : sections proposées à l'accueil de CaisseDZ Desktop (2026-10-20)

Les 50 000 catégories brutes (Open Food / Beauty Facts, en plusieurs langues) ne peuvent pas être
montrées telles quelles. Chaque produit est rangé dans l'un des **16 rayons** (`catalog_rayon` : noms
FR/AR/EN, icône Lucide, mots-clés), et le **porc et l'alcool sont exclus** (`catalog_product.exclu`).
Chaque rayon propose ses **2 000 produits les mieux classés** : `score` = recherches par code-barres
× 100 (toutes les installations, compté par `GET /products/barcode/{code}`) + 50 si code-barres
algérien (613…) + 10 s'il a une photo.

- `GET /rayons` : `{plafond, rayons: [{code, nom_fr, nom_ar, nom_en, icone, ordre, produits}]}`.
- `GET /rayons/{code}/products?page=1&limit=500` : `{rayon, total, page, limit, total_pages,
  products: [{id, code_barres, codes_barres, nom, marque, photo}]}`.
- Code : `catalogue_core/` (`Rayons.php` tri, exclusion, classement ; `RayonsSchema.php` tables ;
  `routes.php`), administration `admin_rayons.php` (même mot de passe que Smart Scan).

**Mise en service**

1. Envoyer `catalogue_core/`, `admin_rayons.php` et `index.php`.
2. Ouvrir `admin_rayons.php` (les tables s'installent seules), puis **Reclasser tout le catalogue**
   (la page se recharge par lots de 20 s jusqu'à la fin), ou en SSH : `php catalogue_core/reclasser.php`.
3. Vérifier `https://catalog-api.bensds.com/rayons`.
4. À refaire après un import de produits ou un changement de mots-clés (ou en tâche cron, la nuit,
   pour rafraîchir le classement : `php /home/bensds64/caissedz/catalog-api.bensds.com/catalogue_core/reclasser.php`).

**Tests** : `php catalogue_core/tests/rayons_test.php` (sans serveur ni MySQL).

## Deploying (`mise run deploy` / `mise run depwin`)

Only what is **committed** goes up, and only the files that changed in git since the version live
on the host (it keeps that commit in `.deploy-rev`, refused to visitors). Whoever deploys next,
from Windows or Linux, compares with the same version: commit, `git push`, then deploy.

```
mise run deploy -- --dry-run     # what would go up / be removed, nothing sent
mise run deploy                  # Linux (Arch)
mise run depwin                  # Windows (runs the same script in WSL)
mise run deploy -- --all         # every deployable file (e.g. a fresh folder)
```

- **Never sent:** `.env*`, `.git*`, `*.md`, `mise.toml`, `tool/`, `build/`, `tests/` folders,
  `uploads/photos/`. **Never deleted** on the host, except files deleted in git since the live
  version (never `vendor/`).
- **Order:** a folder's `.htaccess` first, `index.php` / `admin_*.php` / the root `.htaccess` last;
  each file replaces the old one only once complete; the first failure stops it and the live
  version isn't changed, so running it again sends everything again.
- **Refused** if the host runs a commit your clone doesn't have (`git pull` first), or if HEAD
  doesn't contain it (`--force` to override). Uncommitted or unpushed work is listed.
- **After:** checks that the API answers and prints what to do on the host (new `.env` keys,
  « Installer / mettre à jour les tables », « Reclasser »).

**Setup, once.** The connection lives in your environment, never in the repo; each variable falls
back on bens_ds's `BENS_FTP_*` (same cPanel account):

| Variable | |
|---|---|
| `CATALOG_FTP_HOST` | the FTP server |
| `CATALOG_FTP_USER` | the FTP account |
| `CATALOG_FTP_PASS_CMD` | a command printing the password (e.g. `pass show bensds/ftp`) |
| `CATALOG_FTP_DIR` | the API's folder as that account sees it (default `caissedz/catalog-api.bensds.com`) |
| `CATALOG_URL` | default `https://catalog-api.bensds.com` |

- **Arch Linux:** `sudo pacman -S mise lftp git curl`; the variables in `~/.bashrc`; then
  `git pull && mise run deploy`.
- **Windows:** WSL with `sudo apt install lftp git curl`; the variables in Windows (`setx
  CATALOG_FTP_HOST …`); the password with `mise run depwin-pass` (saved encrypted for your
  Windows user), then `mise run depwin`.
- **Tests:** `mise run test`.

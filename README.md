# CaisseDZ Catalog API — catalog-api.bensds.com

The product catalogue behind CaisseDZ (Desktop and Mobile): plain PHP 8 + MySQL on the bensds.com
cPanel account. About 615,000 products (Open Food / Beauty Facts imports, then what CaisseDZ shops add),
sorted into 16 sections (« rayons »), ranked by what sells in the shops. It also runs Smart Scan (receipt
OCR, scans counted by bens_ds).

- **Barcode lookup** for the apps (`GET /products/barcode/{code}`).
- **Sections** offered when a PC is set up: each section's 5,000 best-ranked products, pork and alcohol
  left out (`catalogue_core/Rayons.php`).
- **Trending**: each CaisseDZ Desktop PC reports its tickets per barcode and the product sheets it typed;
  products sold in 3+ shops and 20+ tickets over 90 days are trending; unknown barcodes become catalogue
  products, known ones get their empty fields filled (`catalogue_core/Tendance.php`, `Contributions.php`).
  The bens_ds dashboard shows it (Official apps → CaisseDZ → Trending).
- **Smart Scan** for Desktop (signed BENS licence) and Mobile (Firebase sign-in) (`smartscan_core/`).

## Endpoints

| Route | Who | |
|---|---|---|
| `GET /` | anyone | API info, database status |
| `GET /products`, `/products/{id}`, `/products/barcode/{code}` | anyone | products (50 a page), one product, a barcode lookup (counted in the ranking) |
| `GET /brands`, `/categories` | anyone | lists |
| `POST`/`PUT`/`DELETE /products…`, `/upload-photo`, `/products/{id}/photo`, `/mobile-products`, `/import` | `X-API-Key` = `API_KEY` | writes (refused when `API_KEY` isn't set) |
| `GET /rayons` | anyone | the sections: names FR/AR/EN, icon, products offered, trending count |
| `GET /rayons/{code}/products?page=&limit=[&depuis=AAAA-MM-JJ]` | anyone | a page of a section's best products (`depuis`: only those that became trending since that day) |
| `POST /tendance` | `X-Device-Id` + `X-License-Key` (the PC's signed licence) | a PC's report: `{produits, confirmations, ventes}` → `{ventes, inconnus, crees, completes, votes, confirmes, supprimes}` |
| `GET /tendance/stats` | `X-Server-Token` = `REV_SERVER_TOKEN` | the bens_ds dashboard's trending data (with per-day series) |
| `/admin/*` | `X-Server-Token` = `REV_SERVER_TOKEN` | the catalogue's administration for the BENS dashboard (`catalogue_core/Admin.php`): `GET catalogue`, `GET`/`PUT rayons/{code}`, `POST rayons`, `POST reclasser {depuis}` (≈ 15 s batches), `POST installer`, `POST tendances` (every sold product recomputed now), `GET a-confirmer`, `POST produits/{id}/confirmer`/`supprimer` |
| `/smartscan/*` | licence (Desktop) or `X-Firebase-Token` (Mobile) | quota, `scan`, `extract`, `demande` (see `smartscan_core/routes.php`) |

## Configuration (`.env`, never in git)

| Key | |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` (or `DB_DSN`) | the MySQL database |
| `API_URL` | `https://catalog-api.bensds.com` (photo links are built from it) |
| `API_KEY` | the key for writes (`X-API-Key`) |
| `ADMIN_PASSWORD` | `admin_rayons.php` and `admin_smartscan.php` |
| `LICENSE_PUBLIC_KEY`, `LICENSE_PRODUCT` | checking the PCs' signed licences (bens_ds dashboard → Licence products → CaisseDZ Desktop) |
| `REV_API_URL`, `REV_SERVER_TOKEN` | bens_ds (`https://bensds.com/api`) and the token shared with it (Smart Scan counting, dashboard stats) |
| `MISTRAL_API_KEY`, `SMARTSCAN_*` | Smart Scan's OCR and its limits |
| `FIREBASE_PROJECT_ID`, `SMARTSCAN_SECRET` | CaisseDZ Mobile's sign-in, phone fingerprints |
| `TENDANCE_POSTES_MIN`, `TENDANCE_TICKETS_MIN` | the trending rule (default 3 shops, 20 tickets) |
| `CONFIRMATION_DEPART`, `CONFIRMATION_SEUIL`, `CONFIRMATION_SUPPRESSION` | a shop-added product's confirmation: starts at 2, confirmed at 5, deleted at 0 |

PHP needs the **sodium** extension (cPanel → Select PHP Version → Extensions).

## Administration

- **The BENS dashboard** (admin.bensds.com → **Catalogue**) does everything below through `/admin/*`: overview with
  charts, sections (names, keywords, preview), trending, products to confirm (confirm / delete by hand),
  maintenance (update tables, re-sort with progress, recompute trends). `admin_rayons.php` stays as a backup.
- `admin_rayons.php` (password `ADMIN_PASSWORD`): **Rayons** (names, icon, keywords, a preview of each
  section, « Reclasser tout le catalogue » in 20-second batches) and **Tendance** (numbers, best sellers,
  what shops added, « Recalculer les tendances »). Missing tables or columns are installed as soon as it
  opens.
- `admin_smartscan.php`: Smart Scan's clients, quotas and scans.
- From SSH: `php catalogue_core/reclasser.php` (the whole catalogue).

## Tests

No server or MySQL needed (SQLite in memory): `mise run test`, i.e.

```
php catalogue_core/tests/rayons_test.php
php -d extension=sodium catalogue_core/tests/tendance_test.php
php -d extension=sodium catalogue_core/tests/admin_test.php
php -d extension=sodium smartscan_core/tests/licence_bens_test.php
php -d extension=sodium smartscan_core/tests/smartscan_desktop_test.php
```

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
Chaque rayon propose ses **5 000 produits les mieux classés** : `score` = recherches par code-barres
× 100 (toutes les installations, compté par `GET /products/barcode/{code}`) + 50 si code-barres
algérien (613…) + 10 s'il a une photo.

- `GET /rayons` : `{plafond, rayons: [{code, nom_fr, nom_ar, nom_en, icone, ordre, produits, tendances}]}`.
- `GET /rayons/{code}/products?page=1&limit=500` : `{rayon, total, page, limit, total_pages,
  products: [{id, code_barres, codes_barres, nom, nom_ar, tendance, marque, photo}]}`.
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

## Tendance et noms arabes (2026-10-21)

Chaque poste CaisseDZ Desktop envoie, au lancement, combien de tickets par jour contenaient chaque
code-barres, et les fiches saisies dans la boutique (`POST /tendance`, authentifié par sa licence BENS
signée : `X-Device-Id` + `X-License-Key`, comme Smart Scan, avec `LICENSE_PUBLIC_KEY` du `.env`) :
`{produits: [{code_barres, codes_barres, nom, nom_ar, marque, categorie}], ventes: [{jour, code_barres,
tickets}]}` → `{ventes, inconnus, crees, completes}`.

- `catalog_vente_jour` : tickets par produit, poste (`CD-XXXX-XXXX`) et jour, 50 au plus (une boutique ne
  fait pas la tendance seule) ; renvoyer un jour le remplace. Oubliées après 120 jours.
- **Fiches des boutiques** (`Contributions.php`) : un code-barres inconnu devient tout de suite un produit
  du catalogue (`contribue_par` = le poste), rangé dans son rayon, **à confirmer** (voir ci-dessous) ; un produit connu ne reçoit que ses champs
  **vides** (nom, nom arabe, marque, catégorie, codes-barres en plus), jamais remplacés. Chaque création ou
  complément est noté dans `catalog_contribution` (poste, champs).
- Sur 90 jours : `postes_90j`, `ventes_90j`, `points_tendance` = 1 000 par boutique + 10 par ticket
  (1 000 tickets au plus), **ajoutés au score du rayon**. `tendance = 1` dès **3 boutiques et 20 tickets**
  (`.env` `TENDANCE_POSTES_MIN`, `TENDANCE_TICKETS_MIN`) ; `tendance_le` : depuis quand. Chaque rayon
  propose ses **5 000** premiers (`Rayons::PLAFOND`). Recalcul immédiat des produits reçus, et quelques centaines d'autres à chaque envoi (la
  fenêtre glisse) ; tout d'un coup : `admin_rayons.php` → Tendance → Recalculer.
- `GET /rayons/{code}/products?depuis=AAAA-MM-JJ` : seulement les produits devenus tendance depuis ce jour
  (le poste ajoute ceux des rayons qu'il a déjà téléchargés). Chaque produit porte `nom_ar` et `tendance` ;
  `GET /rayons` ajoute `tendances` par rayon.
- **Confirmation** (`Confirmations.php`) : un produit créé par une boutique commence avec `confirmation` = 2
  (`a_confirmer` = 1). Chaque autre boutique qui scanne ce code-barres à la caisse répond « c'est bien ce
  produit » (+1) ou « ce n'est pas ça » (−1) : `confirmations: [{code_barres, vote}]` dans `POST /tendance`,
  un vote par poste et par produit (le dernier remplace le précédent), pas celui de la boutique qui l'a créé.
  À 5 : confirmé pour de bon (`confirme_le`), proposé dans les rayons ; à 0 : supprimé du catalogue (avec ses
  codes-barres, ventes et votes). À confirmer, il n'est proposé ni dans les rayons ni dans les tendances, mais
  répond à `GET /products/barcode/{code}` (`a_confirmer`, `confirmation`, `confirmation_seuil`). Votes dans
  `catalog_confirmation`, confirmations et suppressions notées dans `catalog_contribution`.
- `nom_ar` : le nom lui-même s'il est en arabe (au reclassement), sinon le premier nom arabe proposé par un
  poste (`nom_ar_poste`), jamais remplacé ensuite.
- `GET /tendance/stats` (en-tête `X-Server-Token` = `REV_SERVER_TOKEN`, le jeton partagé avec bens_ds) : le
  tableau de bord BENS (Official apps → CaisseDZ → Trending) : seuils, totaux, tendances par rayon, plus vendus,
  chaque poste (`catalog_poste` : dernier envoi), dernières contributions. Aussi dans `admin_rayons.php` → Tendance.
- Code : `catalogue_core/Tendance.php`. **Tests** : `php catalogue_core/tests/tendance_test.php`.

**Mise en service** : déployer, `admin_rayons.php` → **Installer / mettre à jour les tables**, puis
**Reclasser tout le catalogue** (reprend les noms arabes).

## Import d'une boutique et tendance imposée (2026-10-24)

Pour amener d'un coup les produits d'une boutique (l'export de son logiciel) dans le catalogue et en tendance.

- `catalog_product.tendance_forcee` : `NULL` = tendance calculée (comme avant) ; `1` / `0` = imposée par
  l'administration, gardée par chaque recalcul (`Tendance::recalculer`). Une tendance imposée compte au moins
  les points d'une tendance tout juste atteinte (3 boutiques, 20 tickets), pour remonter dans son rayon.
  Revenir au calcul : `UPDATE catalog_product SET tendance_forcee = NULL WHERE …`, puis « Recalculer les tendances ».
- `POST /admin/sans-tendance {rayons: ["cosmetiques", "hygiene", "parapharmacie"]}` : chaque produit de ces rayons a sa tendance
  imposée à 0 → `{rayons, produits}`.
- `POST /admin/comparer {codes: [...]}` (300 au plus) : `{existants: [{code_barres, id, nom, photo, rayon,
  tendance}], absents, invalides}`, **sans compter de recherche** (contrairement à `GET /products/barcode`, qui
  ajoute 100 au score).
- `POST /admin/importer {produits: [{code_barres, nom}]}` (300 au plus) : un absent est créé confirmé
  (`contribue_par` = `ADMIN`, rangé dans son rayon, porc / alcool exclus) ; un produit déjà là n'est pas modifié ;
  tous ont leur tendance imposée à 1 → `{crees: [{code_barres, id}], tendances, ignores}`.
- `tool/import_boutique.py` (jamais déployé) enchaîne tout depuis un PC : sans `--appliquer`, comparaison seule
  (rapport `<csv>_comparaison.csv`) ; avec, les trois étapes puis une photo par produit créé, prise sur
  Open Beauty Facts / Open Food Facts (CC BY-SA) d'après le code-barres (`<csv>_crees.csv`, `<csv>_sans_photo.csv`,
  reprise après coupure par `<csv>_etat.json`). Seuls les EAN / UPC à clé juste partent ; prix et stocks restent
  sur le PC. `API_URL`, `REV_SERVER_TOKEN`, `API_KEY` : environnement ou `.env`.
- **Tests** : `php -d extension=sodium catalogue_core/tests/admin_test.php`.

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

**Setup, once.** The connection lives in your environment, never in the repo. Without
`CATALOG_FTP_USER`, each variable falls back on bens_ds's `BENS_FTP_*` (the catalogue on bens_ds's FTP
account); with its own account (`osm-catalog@catalog-api.bensds.com`), the catalogue uses only its own
password, saved apart from bens_ds's (`%USERPROFILE%\.bens\catalog-ftp-pass` vs `ftp-pass`):

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
  CATALOG_FTP_HOST …`); the password with `mise run depwin-pass` **from this folder** (saved encrypted
  for your Windows user, asked once), then `mise run depwin`. Run from bens_ds's folder, the same command
  saves bens_ds's password instead.
- **After a deploy that changes tables:** open `admin_rayons.php`; missing tables or columns are added
  as soon as it opens (or « Installer / mettre à jour les tables »), then « Reclasser tout le catalogue ».
- **Tests:** `mise run test`.

## Security

Found when the folder was first looked through (2026-10), and where they stand:

1. **No authentication on the API** — fixed: every write needs `X-API-Key` (refused when `API_KEY`
   isn't set); `/tendance` and `/smartscan` check the PC's signed licence; `/tendance/stats` needs
   `REV_SERVER_TOKEN`.
2. **Helper scripts open to anyone** (`install-*.php`, `import-*.php`, `test-*.php`, `diagnostic.php`,
   `index_old.php`, `delete-no-photo.php`, `composer.phar`…) — not in this repository (the deploy never
   sends them). Check the server and delete any still there.
3. **The import/delete scripts' password (`import2026`) is public** — those scripts aren't in the
   repository; delete them from the server if they're still there.
4. **The 160 MB Open Beauty Facts CSV can be downloaded** — `*.csv` is ignored by git; delete it from
   the server once imports are done.
5. **Errors shown to visitors** — fixed: `display_errors` is `0` in `index.php` and the admin pages;
   errors go to `error_log`, and the API answers JSON (500) instead of a blank page.
6. **`.composer/` in the web folder** — not needed at runtime; delete it from the server if present.
7. **Uploads** — `uploads/.htaccess` blocks scripts in the photo folder (keep it); an uploaded photo's
   extension comes from its real type.

## History: the move to catalog-api.bensds.com (2026-10)

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

### What was changed in this folder

Only what the new address needs. Nothing else was touched.

**`.htaccess`.** The app now sits at the root of its subdomain, not under `/catalog-api/`.
- `RewriteBase /catalog-api/` became `RewriteBase /`.
- `RedirectMatch 403 ^/catalog-api/(config|models|controllers|services|middleware)/.*$` became `RedirectMatch 403 ^/(config|models|controllers|services|middleware)/.*$`.

**`.env`.**
- `API_URL="https://catalog-api.bensds.com"`
- `UPLOAD_PATH="/home/bensds64/caissedz/catalog-api.bensds.com/uploads/"`
- `UPLOAD_URL="https://catalog-api.bensds.com/uploads/"`

**`index.php`.** The fallback address used when `.env` has no `API_URL` (lines 21, 161 and 319) is now `https://catalog-api.bensds.com`. New photo links are built from `API_URL`, so they now start with `https://catalog-api.bensds.com/uploads/photos/…`.

### On the server (cPanel)

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

### What the app developer had to change

- **The API base URL in every client** (the mobile app, CaisseDZ desktop, any script or tool): change `https://bensds.com/catalog-api` to `https://catalog-api.bensds.com`. Installed copies that still use the old address stop working once the old folder is removed, so ship the update first.
- **Saved photo links on the client side.** If an app keeps product data offline (local database or cache), photo links that start with `https://bensds.com/catalog-api/` must be refreshed from the API or rewritten the same way as the SQL above.
- **Any hard-coded paths** in the developer's own scripts, notes or docs that use `/home/bensds64/public_html/catalog-api`.
- The comments at the top of the helper scripts (`install-*.php`, `import-*.php`, `test-*.php`, …) and the link in `test-upload.php` still mention the old address. They were left as they were (see below).

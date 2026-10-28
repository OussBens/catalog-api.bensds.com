# Tâches à finir dans le cloud (branche `taches-cloud`)

Données des boutiques, sans prix ni stock : `tool/donnees/`. Python 3, bibliothèque standard seulement.
Variables d'environnement nécessaires : `API_URL` (https://catalog-api.bensds.com), `REV_SERVER_TOKEN`, `API_KEY`.
Réseau : accès à catalog-api.bensds.com et à world.openfoodfacts.org, world.openbeautyfacts.org,
world.openproductsfacts.org (et leurs images, images.openfoodfacts.org…).

Tous les scripts reprennent là où ils se sont arrêtés grâce à leur fichier d'état. **Commiter et pousser
`tool/donnees/` sur `taches-cloud` toutes les 15 minutes environ et à la fin de chaque tâche**, pour ne rien perdre
si la session s'arrête. Ne jamais faire tourner deux tâches Open Facts en même temps (réponses 429).

## 1. Finir la recherche de la boutique bouzid (lecture seule)

    PYTHONIOENCODING=utf-8 python -u tool/enrichir_boutique.py tool/donnees/bouzid_produits.csv --sortie tool/donnees

État : `tool/donnees/bouzid_recherche.json` (1 657 codes déjà cherchés sur ~5 160 au départ).
Sortie : `tool/donnees/bouzid_enrichi.csv` et les photos dans `tool/donnees/bouzid_photos/`.
« N codes sans réponse d'Open Facts » à la fin : relancer la même commande jusqu'à 0.

## 2. Importer bouzid dans le catalogue, en tendance (modifie le catalogue : accord donné par le propriétaire)

Le serveur doit avoir le commit c9ddb59 ou plus récent (le propriétaire déploie depuis son PC).

    PYTHONIOENCODING=utf-8 python -u tool/import_boutique.py tool/donnees/bouzid_enrichi.csv --appliquer --rayons "" --photos tool/donnees/bouzid_photos --sortie tool/donnees

- `--rayons ""` est obligatoire : sans lui, l'import remet les rayons cosmétiques, hygiène et parapharmacie
  sans tendance (c'était pour anes seulement).
- Pas de `--sans-tendance` : les produits de bouzid doivent être en tendance. Le but voulu : seuls les produits
  d'anes et de bouzid en tendance, tout le reste du catalogue sans tendance (avant l'import : 6 830 tendances,
  exactement anes).
- Une erreur HTTP (400, 404…) sur /admin/importer : s'arrêter et le signaler, le serveur n'est sans doute pas à jour.
- Vérifier après : `GET /admin/catalogue` (en-tête `X-Server-Token`), `totaux.tendances` = 6 830 + les produits de
  bouzid.

## 3. Rattraper les photos d'anes

    PYTHONIOENCODING=utf-8 python -u tool/import_boutique.py tool/donnees/anes.csv --appliquer --revoir-photos --sortie tool/donnees

État : `tool/donnees/anes_etat.json` (`revus` : 2 114 produits sur 5 915 déjà revus, 533 photos au total).
Cherche de nouveau la photo des produits d'anes créés sans photo (une partie a été notée « sans photo » à tort à
cause de refus 429 d'Open Food Facts). L'étape 2 (« Importés ») rejoue l'import d'anes sans rien recréer.

## Pour finir

Donner le bilan : photos, marques et quantités trouvées pour bouzid ; produits créés, complétés et mis en tendance ;
photos d'anes rattrapées ; tendances du catalogue. Pousser `tool/donnees/` (avec `git add -f` : `*.csv` est ignoré).

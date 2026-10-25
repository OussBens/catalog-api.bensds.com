"""Les produits d'une boutique dans le catalogue CaisseDZ (routes /admin/* de catalogue_core/Admin.php).

    python tool/import_boutique.py anes.csv                # comparaison seule : rien n'est modifié
    python tool/import_boutique.py anes.csv --appliquer    # tout le processus
    python tool/import_boutique.py anes.csv --appliquer --revoir-photos   # et la photo des créés restés sans
    python tool/import_boutique.py bouzid_enrichi.csv --appliquer --rayons "" --sans-tendance   # un fichier enrichi

Le processus (--appliquer) :
  1. plus aucune tendance dans les rayons --rayons (cosmétiques, hygiène, parapharmacie) : POST /admin/sans-tendance ;
     --rayons "" : étape passée, et chaque produit créé est rangé d'après son nom et sa catégorie ;
  2. chaque produit de la boutique : absent → créé (confirmé, rangé dans son rayon), déjà là → seuls ses champs vides
     et ses codes-barres manquants sont complétés ; tous mis en tendance (sauf --sans-tendance) : POST /admin/importer ;
     un produit créé que son nom range hors de ces rayons (ou nulle part) va dans le premier (cosmétiques) ;
  3. une photo pour chaque produit créé, prise sur Open Beauty Facts / Open Food Facts (licence CC BY-SA)
     d'après son code-barres : POST /products/{id}/photo. Avec une colonne « Photo » (tool/enrichir_boutique.py) :
     le fichier de --photos, envoyé aussi aux produits déjà là qui n'ont pas de photo, sans chercher ailleurs.

Le CSV : séparateur « ; », colonnes « Code-barres » et « Nom du produit » (l'export du logiciel de la boutique) ;
facultatives : « Autres codes-barres » (séparés par « | »), « Marque », « Quantité », « Couleur », « Photo »,
« Catégorie Open Facts ». Seuls les codes-barres EAN / UPC (clé de contrôle juste) sont envoyés : les codes internes
d'une boutique (« 09660 », « RAZOR »…) ne disent rien aux autres. Les prix et les stocks ne quittent pas le PC.

Configuration : API_URL, REV_SERVER_TOKEN (routes /admin) et API_KEY (photos), lus dans l'environnement, sinon
dans le .env de ce dossier. Les rapports (comparaison, créés, sans photo) et l'état (photos déjà envoyées, pour
reprendre après une coupure) vont dans --sortie.
"""
import argparse
import csv
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
import uuid

ICI = os.path.dirname(os.path.abspath(__file__))
LOT = 300  # Admin::LOT_BOUTIQUE
OCTETS_MAX = 60000  # le corps d'une requête /admin : 64 Ko au plus
AGENT = 'CaisseDZ-Catalogue/1.0 (catalog-api.bensds.com)'
SOURCES_PHOTO = ['https://world.openbeautyfacts.org', 'https://world.openfoodfacts.org']


def config():
    env = {}
    chemin = os.path.join(ICI, '..', '.env')
    if os.path.exists(chemin):
        for ligne in open(chemin, encoding='utf-8'):
            m = re.match(r'\s*([A-Z_]+)\s*=\s*"?(.*?)"?\s*$', ligne)
            if m:
                env[m.group(1)] = m.group(2)
    lire = lambda k, d='': os.environ.get(k) or env.get(k) or d
    return {
        'url': lire('API_URL', 'https://catalog-api.bensds.com').rstrip('/'),
        'jeton': lire('REV_SERVER_TOKEN'),
        'cle': lire('API_KEY'),
    }


def ean_valide(code):
    if not re.fullmatch(r'\d{8}|\d{12,14}', code):
        return False
    chiffres = [int(c) for c in code][::-1]
    somme = sum(v * (3 if i % 2 == 0 else 1) for i, v in enumerate(chiffres[1:]))
    return (10 - somme % 10) % 10 == chiffres[0]


def lire_csv(chemin):
    """Les produits du CSV : {code_barres, nom} et, si le CSV les a, codes_barres, nom_ar, marque, taille, couleur,
    categorie, photo (le nom de fichier, pas envoyé). Le premier code EAN / UPC valable sert de code principal."""
    produits, internes, vus = [], 0, set()
    with open(chemin, encoding='utf-8-sig', newline='') as f:
        for ligne in csv.DictReader(f, delimiter=';'):
            val = lambda k, n=200: re.sub(r'\s+', ' ', ligne.get(k) or '').strip()[:n]
            codes = [c.strip() for c in [ligne.get('Code-barres') or ''] + (ligne.get('Autres codes-barres') or '').split('|')]
            codes = [c for c in dict.fromkeys(codes) if ean_valide(c) and c not in vus][:10]  # Contributions::CODES_MAX
            nom = val('Nom du produit')
            if not codes:
                internes += 1
                continue
            if not nom:
                continue
            vus.update(codes)
            p = {'code_barres': codes[0], 'nom': nom}
            extras = {'codes_barres': codes[1:], 'nom_ar': nom if re.search('[ء-ي]', nom) else '',
                      'marque': val('Marque', 120), 'taille': val('Quantité', 50), 'couleur': val('Couleur', 50),
                      'categorie': val('Catégorie Open Facts') or val('Catégorie'), 'photo': val('Photo')}
            p.update({k: v for k, v in extras.items() if v})
            produits.append(p)
    return produits, internes


def paquets(produits):
    """Les produits en lots de LOT au plus et de moins de OCTETS_MAX une fois en JSON."""
    lot, taille = [], 0
    for p in produits:
        t = len(json.dumps(p, ensure_ascii=False).encode('utf-8')) + 2
        if lot and (len(lot) >= LOT or taille + t > OCTETS_MAX):
            yield lot
            lot, taille = [], 0
        lot.append(p)
        taille += t
    if lot:
        yield lot


def appel(cfg, methode, route, corps=None):
    requete = urllib.request.Request(cfg['url'] + route, method=methode,
                                     data=None if corps is None else json.dumps(corps, ensure_ascii=False).encode('utf-8'),
                                     headers={'X-Server-Token': cfg['jeton'], 'Content-Type': 'application/json',
                                              'User-Agent': AGENT})
    for essai in range(5):  # les routes /admin peuvent être rejouées : un produit déjà créé n'est pas recréé
        try:
            with urllib.request.urlopen(requete, timeout=180) as r:
                return json.load(r)['data']
        except urllib.error.HTTPError as e:
            sys.exit(f'{methode} {route} : HTTP {e.code} {e.read().decode("utf-8", "replace")[:300]}')
        except (urllib.error.URLError, TimeoutError, ConnectionError):
            if essai == 4:
                raise
            time.sleep(30 * (essai + 1))


def lots(liste):
    for i in range(0, len(liste), LOT):
        yield i, liste[i:i + LOT]


def ecrire(chemin, entetes, lignes):
    with open(chemin, 'w', encoding='utf-8-sig', newline='') as f:
        w = csv.writer(f, delimiter=';')
        w.writerow(entetes)
        w.writerows(lignes)


def comparer(cfg, produits):
    existants, absents = [], []
    for i, lot in lots(produits):
        r = appel(cfg, 'POST', '/admin/comparer', {'codes': [p['code_barres'] for p in lot]})
        existants += r['existants']
        absents += r['absents']
        print(f'  comparés {min(i + LOT, len(produits))}/{len(produits)}', end='\r')
    print()
    return existants, absents


def lire(lien, timeout):
    """GET [lien] : (octets, type), None si la ressource n'existe pas (404), False si le serveur ne répond pas ou
    refuse pour l'instant (429 : trop de lectures, 5xx, délai dépassé) après 4 essais."""
    for essai in range(4):
        try:
            with urllib.request.urlopen(urllib.request.Request(lien, headers={'User-Agent': AGENT}), timeout=timeout) as r:
                return r.read(), r.headers.get_content_type()
        except urllib.error.HTTPError as e:
            if e.code == 404:
                return None
        except (urllib.error.URLError, TimeoutError, ConnectionError):
            pass
        time.sleep(15 * (essai + 1))
    return False


def photo_publique(code):
    """L'image de face du produit sur Open Beauty / Food Facts : (octets, type), None s'il n'y en a pas, False si
    une source n'a pas répondu (le produit est cherché de nouveau au prochain lancement)."""
    for base in SOURCES_PHOTO:
        r = lire(f'{base}/api/v2/product/{code}.json?fields=image_front_url,image_url', 30)
        time.sleep(0.7)  # Open Food Facts : 100 lectures par minute au plus
        if r is False:
            return False
        try:
            d = json.loads(r[0]) if r else {}
        except ValueError:
            return False
        lien = (d.get('product') or {}).get('image_front_url') or (d.get('product') or {}).get('image_url')
        if d.get('status') == 1 and lien:
            image = lire(lien, 60)
            if image is False:
                return False
            if image and image[1] in ('image/jpeg', 'image/png', 'image/webp') and len(image[0]) <= 5 * 1024 * 1024:
                return image
    return None


def envoyer_photo(cfg, produit_id, octets, genre):
    limite = uuid.uuid4().hex
    ext = {'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp'}[genre]
    corps = (f'--{limite}\r\nContent-Disposition: form-data; name="photo"; filename="photo.{ext}"\r\n'
             f'Content-Type: {genre}\r\n\r\n').encode() + octets + f'\r\n--{limite}--\r\n'.encode()
    req = urllib.request.Request(f'{cfg["url"]}/products/{produit_id}/photo', data=corps, method='POST',
                                 headers={'X-API-Key': cfg['cle'], 'User-Agent': AGENT,
                                          'Content-Type': f'multipart/form-data; boundary={limite}'})
    for essai in range(5):  # une coupure du réseau ou du serveur : on réessaie un peu plus tard
        try:
            with urllib.request.urlopen(req, timeout=120) as r:
                return json.load(r).get('success') is True
        except urllib.error.HTTPError:
            return False
        except (urllib.error.URLError, TimeoutError, ConnectionError):
            if essai == 4:
                raise
            time.sleep(30 * (essai + 1))


def photo_locale(cfg, o, produits, etat, garder):
    """3. (--photos) La photo de la colonne « Photo » pour chaque produit, créé ou déjà là, qui n'en a pas encore."""
    genres = {'.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.png': 'image/png', '.webp': 'image/webp'}
    locales = {p['code_barres']: os.path.join(o.photos, p['photo']) for p in produits
               if p.get('photo') and os.path.exists(os.path.join(o.photos, p['photo']))}
    existants, _ = comparer(cfg, [{'code_barres': c} for c in locales])
    a_faire, vus = [], set()
    for e in existants:
        if not e['photo'] and e['code_barres'] not in etat['photos'] and e['id'] not in vus:
            vus.add(e['id'])
            a_faire.append((e['code_barres'], e['id']))
    for n, (code, produit_id) in enumerate(a_faire, 1):
        chemin = locales[code]
        with open(chemin, 'rb') as f:
            etat['photos'][code] = envoyer_photo(cfg, produit_id, f.read(),
                                                 genres.get(os.path.splitext(chemin)[1].lower(), 'image/jpeg'))
        garder()
        print(f'3. Photos {n}/{len(a_faire)} : {sum(etat["photos"].values())} envoyées', end='\r')
    print()


def main():
    for flux in (sys.stdout, sys.stderr):
        flux.reconfigure(encoding='utf-8', errors='replace')  # console Windows (cp1256…)
    a = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    a.add_argument('csv')
    a.add_argument('--appliquer', action='store_true', help='modifier le catalogue (sinon : comparaison seule)')
    a.add_argument('--rayons', default='cosmetiques,hygiene,parapharmacie',
                   help='rayons remis sans tendance avant l\'import ("" : aucun, les produits rangés d\'après leur nom)')
    a.add_argument('--sans-tendance', action='store_true', help='ne pas mettre les produits de la boutique en tendance')
    a.add_argument('--photos', help='dossier des photos de la colonne « Photo » (sinon : Open Facts, pour les créés)')
    a.add_argument('--sortie', default='.', help='dossier des rapports et de l\'état')
    a.add_argument('--revoir-photos', action='store_true', help='chercher de nouveau la photo des produits créés sans')
    o = a.parse_args()
    cfg = config()
    if not cfg['jeton'] or (o.appliquer and not cfg['cle']):
        sys.exit('REV_SERVER_TOKEN (et API_KEY pour les photos) manquent : environnement ou .env')
    os.makedirs(o.sortie, exist_ok=True)
    nom = os.path.splitext(os.path.basename(o.csv))[0]
    sortie = lambda suffixe: os.path.join(o.sortie, f'{nom}_{suffixe}')

    produits, internes = lire_csv(o.csv)
    print(f'{len(produits)} produits à code-barres EAN/UPC, {internes} lignes à code interne ou en double laissées de côté')

    if not o.appliquer:
        existants, absents = comparer(cfg, produits)
        noms = {p['code_barres']: p['nom'] for p in produits}
        ecrire(sortie('comparaison.csv'), ['Code-barres', 'Nom boutique', 'Statut', 'Id catalogue', 'Nom catalogue', 'Photo', 'Rayon'],
               [[e['code_barres'], noms[e['code_barres']], 'existe', e['id'], e['nom'], 'oui' if e['photo'] else 'non', e['rayon'] or '']
                for e in existants] + [[c, noms[c], 'absent', '', '', '', ''] for c in absents])
        print(f'Déjà dans le catalogue : {len(existants)} (dont {sum(not e["photo"] for e in existants)} sans photo)')
        print(f'Absents, à créer       : {len(absents)}')
        print(f'Rapport : {sortie("comparaison.csv")}  (rien n\'a été modifié ; --appliquer pour lancer)')
        return

    etat_chemin = sortie('etat.json')
    etat = json.load(open(etat_chemin, encoding='utf-8')) if os.path.exists(etat_chemin) else {'crees': {}, 'photos': {}}
    garder = lambda: json.dump(etat, open(etat_chemin, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)

    rayons = [x.strip() for x in o.rayons.split(',') if x.strip()]
    if not rayons:
        print('1. Aucun rayon à remettre sans tendance (--rayons ""), étape passée')
    elif not etat.get('sans_tendance'):
        r = appel(cfg, 'POST', '/admin/sans-tendance', {'rayons': rayons})
        print(f'1. Plus de tendance dans {", ".join(r["rayons"])} : {r["produits"]} produits')
        etat['sans_tendance'] = r
        garder()
    else:
        print('1. Rayons déjà remis sans tendance (état), étape passée')

    tendances, completes, faits = 0, 0, 0
    for lot in paquets([{k: v for k, v in p.items() if k != 'photo'} for p in produits]):
        corps = {'produits': lot, 'tendance': not o.sans_tendance}
        if rayons:
            corps['rayons'] = rayons
        r = appel(cfg, 'POST', '/admin/importer', corps)
        for c in r['crees']:
            etat['crees'][c['code_barres']] = c['id']
        tendances += r['tendances']
        completes += r.get('completes', 0)
        faits += len(lot)
        garder()
        print(f'2. Importés {faits}/{len(produits)} : {len(etat["crees"])} créés, {completes} complétés, '
              f'{tendances} en tendance', end='\r')
    print()

    if o.photos:
        photo_locale(cfg, o, produits, etat, garder)
        ecrire(sortie('crees.csv'), ['Code-barres', 'Nom', 'Id catalogue', 'Photo'],
               [[c, next((p['nom'] for p in produits if p['code_barres'] == c), ''), i,
                 'oui' if etat['photos'].get(c) else 'non'] for c, i in etat['crees'].items()])
        print(f'Terminé : {len(etat["crees"])} créés, {completes} complétés, {tendances} en tendance, '
              f'{sum(etat["photos"].values())} photos envoyées')
        print(f'Rapport : {sortie("crees.csv")}')
        return

    noms = {p['code_barres']: p['nom'] for p in produits}
    revus = set(etat.setdefault('revus', []))  # --revoir-photos : déjà cherchés de nouveau, pas à refaire
    a_faire = [(c, i) for c, i in etat['crees'].items()
               if c not in etat['photos'] or (o.revoir_photos and not etat['photos'][c] and c not in revus)]
    plus_tard = 0
    for n, (code, produit_id) in enumerate(a_faire, 1):
        image = photo_publique(code)
        if image is False:
            plus_tard += 1
        else:
            etat['photos'][code] = bool(image) and envoyer_photo(cfg, produit_id, *image)
            if o.revoir_photos:
                etat['revus'].append(code)
            garder()
        print(f'3. Photos {n}/{len(a_faire)} : {sum(etat["photos"].values())} trouvées, {plus_tard} sans réponse', end='\r')
    print()
    if plus_tard:
        print(f'{plus_tard} produits sans réponse d\'Open Facts : relancer la même commande pour les chercher de nouveau')

    ecrire(sortie('crees.csv'), ['Code-barres', 'Nom', 'Id catalogue', 'Photo'],
           [[c, noms.get(c, ''), i, 'oui' if etat['photos'].get(c) else 'non'] for c, i in etat['crees'].items()])
    sans = [[c, noms.get(c, ''), i] for c, i in etat['crees'].items() if not etat['photos'].get(c)]
    ecrire(sortie('sans_photo.csv'), ['Code-barres', 'Nom', 'Id catalogue'], sans)
    print(f'Terminé : {len(etat["crees"])} créés, {tendances} en tendance, {len(sans)} créés sans photo publique')
    print(f'Rapports : {sortie("crees.csv")}, {sortie("sans_photo.csv")}')


if __name__ == '__main__':
    main()

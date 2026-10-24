"""Les produits d'une boutique dans le catalogue CaisseDZ (routes /admin/* de catalogue_core/Admin.php).

    python tool/import_boutique.py anes.csv                # comparaison seule : rien n'est modifié
    python tool/import_boutique.py anes.csv --appliquer    # tout le processus

Le processus (--appliquer) :
  1. plus aucune tendance dans les rayons --rayons (cosmétiques, hygiène, parapharmacie) : POST /admin/sans-tendance ;
  2. chaque produit de la boutique : absent → créé (confirmé, rangé dans son rayon), déjà là → pas modifié ;
     tous mis en tendance : POST /admin/importer ; un produit créé que son nom range hors de ces rayons (ou nulle
     part) va dans le premier (cosmétiques) ;
  3. une photo pour chaque produit créé, prise sur Open Beauty Facts / Open Food Facts (licence CC BY-SA)
     d'après son code-barres : POST /products/{id}/photo.

Le CSV : séparateur « ; », colonnes « Code-barres » et « Nom du produit » (l'export du logiciel de la boutique).
Seuls les codes-barres EAN / UPC (clé de contrôle juste) sont envoyés : les codes internes d'une boutique
(« 09660 », « RAZOR »…) ne disent rien aux autres. Les prix et les stocks ne quittent pas le PC.

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
    produits, internes, vus = [], 0, set()
    with open(chemin, encoding='utf-8-sig', newline='') as f:
        for ligne in csv.DictReader(f, delimiter=';'):
            code = (ligne.get('Code-barres') or '').strip()
            nom = re.sub(r'\s+', ' ', (ligne.get('Nom du produit') or '')).strip()[:200]
            if not ean_valide(code):
                internes += 1
            elif code not in vus and nom:
                vus.add(code)
                produits.append({'code_barres': code, 'nom': nom})
    return produits, internes


def appel(cfg, methode, route, corps=None):
    requete = urllib.request.Request(cfg['url'] + route, method=methode,
                                     data=None if corps is None else json.dumps(corps).encode('utf-8'),
                                     headers={'X-Server-Token': cfg['jeton'], 'Content-Type': 'application/json',
                                              'User-Agent': AGENT})
    try:
        with urllib.request.urlopen(requete, timeout=180) as r:
            return json.load(r)['data']
    except urllib.error.HTTPError as e:
        sys.exit(f'{methode} {route} : HTTP {e.code} {e.read().decode("utf-8", "replace")[:300]}')


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


def photo_publique(code):
    """L'image de face du produit sur Open Beauty / Food Facts : (octets, type) ou None."""
    for base in SOURCES_PHOTO:
        try:
            req = urllib.request.Request(f'{base}/api/v2/product/{code}.json?fields=image_front_url,image_url',
                                         headers={'User-Agent': AGENT})
            with urllib.request.urlopen(req, timeout=30) as r:
                d = json.load(r)
            lien = (d.get('product') or {}).get('image_front_url') or (d.get('product') or {}).get('image_url')
            if d.get('status') == 1 and lien:
                with urllib.request.urlopen(urllib.request.Request(lien, headers={'User-Agent': AGENT}), timeout=60) as r:
                    octets, genre = r.read(), r.headers.get_content_type()
                if genre in ('image/jpeg', 'image/png', 'image/webp') and len(octets) <= 5 * 1024 * 1024:
                    return octets, genre
        except (urllib.error.URLError, TimeoutError, ValueError):
            pass
        time.sleep(0.7)  # Open Food Facts : 100 lectures par minute au plus
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


def main():
    for flux in (sys.stdout, sys.stderr):
        flux.reconfigure(encoding='utf-8', errors='replace')  # console Windows (cp1256…)
    a = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    a.add_argument('csv')
    a.add_argument('--appliquer', action='store_true', help='modifier le catalogue (sinon : comparaison seule)')
    a.add_argument('--rayons', default='cosmetiques,hygiene,parapharmacie', help='rayons remis sans tendance avant l\'import')
    a.add_argument('--sortie', default='.', help='dossier des rapports et de l\'état')
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
    if not etat.get('sans_tendance'):
        r = appel(cfg, 'POST', '/admin/sans-tendance', {'rayons': rayons})
        print(f'1. Plus de tendance dans {", ".join(r["rayons"])} : {r["produits"]} produits')
        etat['sans_tendance'] = r
        garder()
    else:
        print('1. Rayons déjà remis sans tendance (état), étape passée')

    tendances = 0
    for i, lot in lots(produits):
        r = appel(cfg, 'POST', '/admin/importer', {'produits': lot, 'rayons': rayons})
        for c in r['crees']:
            etat['crees'][c['code_barres']] = c['id']
        tendances += r['tendances']
        garder()
        print(f'2. Importés {min(i + LOT, len(produits))}/{len(produits)} : {len(etat["crees"])} créés, {tendances} en tendance', end='\r')
    print()

    noms = {p['code_barres']: p['nom'] for p in produits}
    a_faire = [(c, i) for c, i in etat['crees'].items() if c not in etat['photos']]
    for n, (code, produit_id) in enumerate(a_faire, 1):
        image = photo_publique(code)
        etat['photos'][code] = bool(image) and envoyer_photo(cfg, produit_id, *image)
        garder()
        print(f'3. Photos {n}/{len(a_faire)} : {sum(etat["photos"].values())} trouvées', end='\r')
    print()

    ecrire(sortie('crees.csv'), ['Code-barres', 'Nom', 'Id catalogue', 'Photo'],
           [[c, noms.get(c, ''), i, 'oui' if etat['photos'].get(c) else 'non'] for c, i in etat['crees'].items()])
    sans = [[c, noms.get(c, ''), i] for c, i in etat['crees'].items() if not etat['photos'].get(c)]
    ecrire(sortie('sans_photo.csv'), ['Code-barres', 'Nom', 'Id catalogue'], sans)
    print(f'Terminé : {len(etat["crees"])} créés, {tendances} en tendance, {len(sans)} créés sans photo publique')
    print(f'Rapports : {sortie("crees.csv")}, {sortie("sans_photo.csv")}')


if __name__ == '__main__':
    main()

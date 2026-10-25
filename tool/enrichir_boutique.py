"""La marque, la quantité et la photo des produits d'une boutique, cherchées d'après leurs codes-barres.

    python tool/enrichir_boutique.py bouzid_produits.csv --sortie C:/Users/oussa/Desktop

Rien n'est modifié : ni le catalogue, ni Open Facts. Les sources, dans l'ordre :
  1. le catalogue CaisseDZ (POST /admin/comparer, puis GET /products/{id}, qui ne comptent pas de recherche) ;
  2. Open Food Facts, puis Open Beauty / Products Facts pour un code que Food ne connaît pas (licence CC BY-SA).
Un produit à plusieurs codes-barres est cherché sous chacun (les autres codes sur Open Food Facts seul), jusqu'à
trouver une photo et une marque.

Le CSV (séparateur « ; ») : « Code-barres », « Autres codes-barres » (séparés par « | »), « Nom du produit »,
« Quantité (contenance) » ou « Quantité ». Sortie : <nom>_enrichi.csv (Code-barres ; Autres codes-barres ; Nom du
produit ; Marque ; Quantité ; Couleur ; Photo ; Source photo ; Id catalogue ; Catégorie Open Facts), les photos dans <nom>_photos/<code>.jpg,
et l'état (<nom>_recherche.json) pour reprendre après une coupure : relancer la même commande.
"""
import argparse
import csv
import json
import os
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from import_boutique import AGENT, appel, config, ean_valide, lire, lots  # noqa: E402

SOURCES = ['https://world.openfoodfacts.org', 'https://world.openbeautyfacts.org', 'https://world.openproductsfacts.org']
PAUSE = 1.0  # Open Facts : 100 lectures par minute au plus, partagées avec un autre import qui tournerait

COULEURS = [  # le nom arabe ou français → la couleur
    (r'أحمر|احمر|حمراء|rouge', 'rouge'), (r'أخضر|اخضر|خضراء|vert', 'vert'), (r'أزرق|ازرق|زرقاء|bleu', 'bleu'),
    (r'أصفر|اصفر|صفراء|jaune', 'jaune'), (r'أسود|اسود|سوداء|noir', 'noir'), (r'أبيض|ابيض|بيضاء|blanc', 'blanc'),
    (r'وردي|روز|rose', 'rose'), (r'بنفسجي|violet', 'violet'), (r'برتقالي|orange', 'orange'),
    (r'رمادي|gris', 'gris'), (r'بني|marron', 'marron'), (r'ذهبي|doré|gold', 'doré'), (r'فضي|argent', 'argenté'),
]


def couleur(nom):
    for motif, c in COULEURS:
        if re.search(r'(?<![\w\u0621-\u064A])(?:' + motif + r')(?![\w\u0621-\u064A])', nom, re.I):
            return c
    return ''


def lire_produits(chemin):
    produits = []
    with open(chemin, encoding='utf-8-sig', newline='') as f:
        for l in csv.DictReader(f, delimiter=';'):
            codes = [c.strip() for c in [l.get('Code-barres') or ''] + (l.get('Autres codes-barres') or '').split('|')]
            codes = [c for c in dict.fromkeys(codes) if ean_valide(c)]
            if codes:
                produits.append({'codes': codes, 'nom': re.sub(r'\s+', ' ', l.get('Nom du produit') or '').strip(),
                                 'quantite': l.get('Quantité (contenance)') or l.get('Quantité') or ''})
    return produits


def open_facts(code, sources=SOURCES):
    """La fiche du code sur Open Facts : {nom, marque, quantite, image} ; None si aucune source ne l'a, False si une
    source n'a pas répondu (cherché de nouveau au prochain lancement)."""
    for base in sources:
        r = lire(f'{base}/api/v2/product/{code}.json?fields=product_name,product_name_fr,product_name_ar,brands,quantity,'
                 'categories,image_front_url,image_url&lc=fr', 30)
        time.sleep(PAUSE)
        if r is False:
            return False
        try:
            d = json.loads(r[0]) if r else {}
        except ValueError:
            return False
        p = d.get('product') or {}
        if d.get('status') == 1:
            return {'nom': p.get('product_name_fr') or p.get('product_name') or p.get('product_name_ar') or '',
                    'marque': (p.get('brands') or '').split(',')[0].strip(),
                    'quantite': (p.get('quantity') or '').strip(),
                    'categorie': (p.get('categories') or '').split(',')[-1].strip(),
                    'image': p.get('image_front_url') or p.get('image_url') or '',
                    'source': base.split('//world.')[1].split('.')[0]}
    return None


def main():
    for flux in (sys.stdout, sys.stderr):
        flux.reconfigure(encoding='utf-8', errors='replace')
    a = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    a.add_argument('csv')
    a.add_argument('--sortie', default='.')
    o = a.parse_args()
    cfg = config()
    if not cfg['jeton']:
        sys.exit('REV_SERVER_TOKEN manque : environnement ou .env')
    nom = os.path.splitext(os.path.basename(o.csv))[0].replace('_produits', '')
    sortie = lambda s: os.path.join(o.sortie, f'{nom}_{s}')
    dossier_photos = sortie('photos')
    os.makedirs(dossier_photos, exist_ok=True)
    etat_chemin = sortie('recherche.json')  # pas <nom>_enrichi_etat.json : c'est l'état de l'import de <nom>_enrichi.csv
    if not os.path.exists(etat_chemin) and os.path.exists(sortie('enrichi_etat.json')):
        os.replace(sortie('enrichi_etat.json'), etat_chemin)
    etat =json.load(open(etat_chemin, encoding='utf-8')) if os.path.exists(etat_chemin) else {}
    etat.setdefault('catalogue', None)
    etat.setdefault('fiches', {})   # id catalogue → {marque, taille, photo}
    etat.setdefault('of', {})       # code → fiche Open Facts ou None
    garder = lambda: json.dump(etat, open(etat_chemin, 'w', encoding='utf-8'), ensure_ascii=False, indent=0)

    produits = lire_produits(o.csv)
    tous = [c for p in produits for c in p['codes']]
    print(f'{len(produits)} produits à code-barres EAN/UPC ({len(tous)} codes)')

    # 1. Le catalogue CaisseDZ
    if etat['catalogue'] is None:
        ids = {}
        for i, lot in lots(tous):
            for e in appel(cfg, 'POST', '/admin/comparer', {'codes': lot})['existants']:
                ids[e['code_barres']] = e['id']
            print(f'1. Catalogue : {min(i + len(lot), len(tous))}/{len(tous)} codes comparés', end='\r')
        etat['catalogue'] = ids
        garder()
        print()
    ids = etat['catalogue']
    a_lire = sorted({i for i in ids.values() if str(i) not in etat['fiches']})
    for n, i in enumerate(a_lire, 1):
        r = lire(f'{cfg["url"]}/products/{i}', 30)
        if r:
            p = json.loads(r[0]).get('data') or {}
            etat['fiches'][str(i)] = {'nom': p.get('nom') or '', 'marque': p.get('brand') or '',
                                      'taille': p.get('taille') or '', 'photo': p.get('photo') or ''}
            if n % 20 == 0:
                garder()
        print(f'1. Fiches du catalogue : {n}/{len(a_lire)}', end='\r')
    garder()
    print(f'\n1. Dans le catalogue : {sum(any(c in ids for c in p["codes"]) for p in produits)} produits')

    # 2. Open Facts, pour les produits auxquels il manque encore la photo ou la marque
    def fiche_catalogue(p):
        return next((etat['fiches'].get(str(ids[c])) for c in p['codes'] if c in ids), None) or {}

    a_chercher = []
    for p in produits:
        f = fiche_catalogue(p)
        if not (f.get('photo') and f.get('marque')):
            a_chercher += [c for c in p['codes'] if c not in etat['of']]
    sans_reponse = 0
    produit_de = {c: p for p in produits for c in p['codes']}

    def chercher(code):
        p = produit_de[code]
        if any(etat['of'].get(c) and etat['of'][c].get('image') and etat['of'][c].get('marque') for c in p['codes']):
            return None, True  # un autre code du produit a déjà tout donné
        return open_facts(code, SOURCES if code == p['codes'][0] else SOURCES[:1]), False  # un autre code : Food seul

    with ThreadPoolExecutor(2) as ex:  # 2 fils : sous les 100 lectures par minute d'Open Food Facts
        for n, (code, (r, passe)) in enumerate(zip(a_chercher, ex.map(chercher, a_chercher)), 1):
            if passe:
                continue
            if r is False:
                sans_reponse += 1
            else:
                etat['of'][code] = r
                garder()
            trouves = sum(1 for v in etat['of'].values() if v)
            print(f'2. Open Facts : {n}/{len(a_chercher)} codes, {trouves} fiches trouvées, {sans_reponse} sans réponse', end='\r')
    print()

    # 3. Les photos et le fichier final
    lignes, compte = [], {'photo': 0, 'marque': 0, 'quantite': 0, 'couleur': 0, 'catalogue': 0}
    for p in produits:
        f = fiche_catalogue(p)
        ofs = [etat['of'][c] for c in p['codes'] if etat['of'].get(c)]
        marque = f.get('marque') or next((x['marque'] for x in ofs if x['marque']), '')
        quantite = p['quantite'] or f.get('taille') or next((x['quantite'] for x in ofs if x['quantite']), '')
        photo, source = '', ''
        chemin = os.path.join(dossier_photos, f'{p["codes"][0]}.jpg')
        if os.path.exists(chemin):
            photo, source = os.path.basename(chemin), 'déjà téléchargée'
        else:
            for lien, src in [(f.get('photo'), 'catalogue CaisseDZ')] + [(x['image'], x['source']) for x in ofs]:
                if not lien:
                    continue
                image = lire(lien, 60)
                if image and image[1] in ('image/jpeg', 'image/png', 'image/webp'):
                    with open(chemin, 'wb') as fp:
                        fp.write(image[0])
                    photo, source = os.path.basename(chemin), src
                    break
        if photo and source == 'déjà téléchargée':
            source = 'catalogue CaisseDZ' if f.get('photo') else (ofs[0]['source'] if ofs else '')
        id_cat = next((ids[c] for c in p['codes'] if c in ids), '')
        c = couleur(p['nom'])
        for k, v in (('photo', photo), ('marque', marque), ('quantite', quantite), ('couleur', c), ('catalogue', id_cat)):
            compte[k] += bool(v)
        categorie = next((x.get('categorie') for x in ofs if x.get('categorie')), '')
        lignes.append([p['codes'][0], ' | '.join(p['codes'][1:]), p['nom'], marque, quantite, c, photo, source, id_cat,
                       categorie])
        print(f'3. Photos : {len(lignes)}/{len(produits)}, {compte["photo"]} enregistrées', end='\r')
    print()
    with open(sortie('enrichi.csv'), 'w', encoding='utf-8-sig', newline='') as fp:
        w = csv.writer(fp, delimiter=';')
        w.writerow(['Code-barres', 'Autres codes-barres', 'Nom du produit', 'Marque', 'Quantité', 'Couleur', 'Photo',
                    'Source photo', 'Id catalogue', 'Catégorie Open Facts'])
        w.writerows(lignes)
    print(f'Terminé : {len(lignes)} produits — {compte["catalogue"]} déjà au catalogue, {compte["photo"]} photos, '
          f'{compte["marque"]} marques, {compte["quantite"]} quantités, {compte["couleur"]} couleurs')
    if sans_reponse:
        print(f'{sans_reponse} codes sans réponse d\'Open Facts : relancer la même commande')
    print(f'Fichier : {sortie("enrichi.csv")}  Photos : {dossier_photos}')


if __name__ == '__main__':
    main()

"""Le nettoyage des noms du catalogue CaisseDZ (POST /admin/nettoyer de catalogue_core/Admin.php).

    python tool/nettoyer_noms.py --sortie DOSSIER               # plan seul : rien n'est modifié
    python tool/nettoyer_noms.py --sortie DOSSIER --appliquer   # renomme et supprime

1. Le catalogue est lu page par page (GET /products, 50 par page) dans DOSSIER/pages : une page déjà lue n'est
   pas relue (--relire pour tout relire).
2. Chaque nom est jugé :
   - texte perdu (« ????? », un nom arabe ou russe abîmé à un import qui n'était pas en UTF-8) : le vrai nom est
     cherché sur Open Beauty Facts / Open Food Facts d'après le code-barres → renommé ; introuvable → supprimé ;
   - espaces en double (seul défaut) → renommé, espaces réduits ;
   - moins de 4 caractères, code HTML, aucune lettre, code-barres ou long nombre dans le nom, nom bidon, produit de
     test, prix, description entière, nom générique (2 mots au plus, le même nom sur 5 produits ou plus)
     → supprimé ;
   - caractère répété, seulement une quantité, encodage abîmé : laissés (à revoir à la main).
   Le plan va dans DOSSIER/plan.csv.
3. --appliquer : les lots partent vers /admin/nettoyer. Le serveur met chaque produit supprimé dans
   catalog_corbeille (POST /admin/restaurer {ids} le remet) et garde les produits apportés par une boutique.
   DOSSIER/etat.json garde les noms trouvés et les produits envoyés : relancer reprend où il s'est arrêté.

Configuration : API_URL et REV_SERVER_TOKEN, lus dans l'environnement, sinon dans le .env de ce dossier.
"""
import argparse
import collections
import csv
import http.client
import json
import os
import re
import sys
import threading
import time
import unicodedata
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from import_boutique import config, ecrire  # noqa: E402

AGENT = 'CaisseDZ-Catalogue/1.0 (catalog-api.bensds.com)'
SOURCES = ['https://world.openbeautyfacts.org', 'https://world.openfoodfacts.org']
LOT = 200  # Admin::LOT_NETTOYAGE
OCTETS_MAX = 60000  # le corps d'une requête /admin : 64 Ko au plus
CHAMPS = ('id', 'code_produit', 'nom', 'contribue_par', 'tendance_forcee', 'rayon_code', 'photo')

PERDU = re.compile(r'\?{3,}')
PRIX = re.compile(r'(?<![\w.,])\d+(?:[.,]\d+)?\s*(?:da|dzd|dinars?|دج|د\.ج|€|eur|euros)(?![a-z])|\$\s?\d+(?:[.,]\d+)?|\bprix\s+\d', re.I)
NOMBRE_LONG = re.compile(r'\d{8,14}')
QUANTITE = re.compile(r'^\s*[\d.,]+\s*(?:g|gr|kg|ml|cl|l|lt|mg|pcs?|x)?\s*$', re.I)
BIDON = re.compile(r'^(?:produit|product|article|divers|inconnu|unknown|sans nom|no name|null|none|n/?a|xxx+|aaa+|abc|item'
                   r'|nouveau|new|\?+|-+|\.+)$', re.I)
TEST = re.compile(r'(?i:test)(?:$|[\s\d_A-Z])')
HTML = re.compile(r'&(?:amp|quot|gt|lt|apos|#\d+);')
MOJIBAKE = re.compile(r'[ÃÂ][\u0080-¿]|â€|�')
REPETE = re.compile(r'(.)\1{4,}')

A_SUPPRIMER = {'moins de 4 caractères', 'code HTML', 'aucune lettre', 'nom = son code-barres', 'nom = un autre code',
               'code-barres dans le nom', 'long nombre dans le nom', 'nom bidon', 'produit de test', 'prix dans le nom',
               'description entière', 'nom générique'}


def lettres(t):
    return sum(1 for c in t if unicodedata.category(c).startswith('L'))


def defauts(nom, code, generiques):
    """Les défauts du nom [nom] du produit au code-barres [code] (vide : le nom est bon)."""
    brut = nom or ''
    nom = brut.strip()
    if nom == '':
        return ['vide']
    if PERDU.search(nom):
        return ['texte perdu']
    d = []
    chiffres = re.sub(r'\D', '', nom)
    if lettres(nom) == 0:
        if code and chiffres == code:
            d.append('nom = son code-barres')
        elif len(chiffres) >= 8:
            d.append('nom = un autre code')
        elif QUANTITE.match(nom) and re.search(r'[a-z]', nom, re.I):
            d.append('seulement une quantité')
        else:
            d.append('aucune lettre')
    else:
        if code and code in nom.replace(' ', ''):
            d.append('code-barres dans le nom')
        elif NOMBRE_LONG.search(nom):
            d.append('long nombre dans le nom')
        if QUANTITE.match(nom):
            d.append('seulement une quantité')
    if len(nom) < 4:
        d.append('moins de 4 caractères')
    if PRIX.search(nom):
        d.append('prix dans le nom')
    if BIDON.match(nom):
        d.append('nom bidon')
    if MOJIBAKE.search(nom):
        d.append('encodage abîmé')
    if HTML.search(nom):
        d.append('code HTML')
    if TEST.match(nom):
        d.append('produit de test')
    if REPETE.search(nom):
        d.append('caractère répété')
    if generiques.get(nom.lower(), 0) >= 5 and len(nom.split()) <= 2:
        d.append('nom générique')
    if len(nom) > 150:
        d.append('description entière')
    if nom != brut or '  ' in nom:
        d.append('espaces en double')
    return d


def lire_page(dossier, n, relire):
    f = os.path.join(dossier, f'{n}.json')
    if os.path.exists(f) and not relire:
        return
    for essai in range(6):
        try:
            req = urllib.request.Request(f'{CFG["url"]}/products?page={n}', headers={'User-Agent': AGENT})
            with urllib.request.urlopen(req, timeout=90) as r:
                d = json.load(r)['data']
            json.dump([{k: p.get(k) for k in CHAMPS} for p in d['products']], open(f + '.tmp', 'w', encoding='utf-8'),
                      ensure_ascii=False)
            os.replace(f + '.tmp', f)
            return
        except (urllib.error.URLError, TimeoutError, ConnectionError, ValueError):
            time.sleep(5 * (essai + 1))
    sys.exit(f'page {n} illisible, relancer plus tard')


def lire_catalogue(dossier, relire):
    os.makedirs(dossier, exist_ok=True)
    for essai in range(5):
        try:
            with urllib.request.urlopen(urllib.request.Request(f'{CFG["url"]}/products?page=1', headers={'User-Agent': AGENT}), timeout=90) as r:
                pages = json.load(r)['data']['total_pages']
            break
        except (urllib.error.URLError, TimeoutError, ConnectionError, ValueError):
            if essai == 4:
                raise
            time.sleep(30 * (essai + 1))
    with ThreadPoolExecutor(4) as ex:
        for i, _ in enumerate(ex.map(lambda n: lire_page(dossier, n, relire), range(1, pages + 1)), 1):
            print(f'1. Catalogue lu : {i}/{pages} pages', end='\r')
    print()
    produits = {}
    for n in range(1, pages + 1):
        for p in json.load(open(os.path.join(dossier, f'{n}.json'), encoding='utf-8')):
            produits[p['id']] = p
    return sorted(produits.values(), key=lambda p: p['id'])


CONNEXIONS = threading.local()


def lire_json(hote, chemin):
    """GET https://[hote][chemin] sur une connexion gardée ouverte par fil (Open Facts ignore un flot de nouvelles
    connexions) : le JSON, {} si le produit n'y est pas (404), None si l'hôte ne répond pas."""
    connexions = CONNEXIONS.__dict__
    for essai in range(4):
        c = connexions.get(hote) or http.client.HTTPSConnection(hote, timeout=30)
        connexions[hote] = c
        try:
            c.request('GET', chemin, headers={'User-Agent': AGENT})
            r = c.getresponse()
            corps = r.read()
            if r.status == 404 or (r.status == 500 and essai >= 1):  # 500 : une fiche cassée chez eux, toujours
                return {}
            if r.status == 200:
                return json.loads(corps)
        except (OSError, http.client.HTTPException, ValueError):
            pass
        c.close()
        connexions.pop(hote, None)
        time.sleep(3 if essai == 0 else 10 * essai)
    return None


def nom_public(code):
    """Le nom du produit [code] sur Open Beauty / Food Facts (sa marque devant s'il ne la dit pas), None s'il n'y
    est pas, False si une source n'a pas répondu (à chercher au prochain lancement)."""
    champs = 'product_name_fr,product_name_en,product_name,generic_name_fr,generic_name,brands'
    for base in SOURCES:
        d = lire_json(base.split('//')[1], f'/api/v2/product/{code}.json?fields={champs}')
        if d is None:
            return False
        if d.get('status') != 1:
            continue
        p = d['product']
        for k in ('product_name_fr', 'product_name_en', 'product_name', 'generic_name_fr', 'generic_name'):
            n = re.sub(r'\s+', ' ', p.get(k) or '').strip()
            if n and not set(defauts(n, code, {})) - {'espaces en double'}:
                marque = (p.get('brands') or '').split(',')[0].strip()
                if marque and marque.lower() not in n.lower() and not PERDU.search(marque):
                    n = f'{marque} {n}'
                return n[:200]
    return None


def envoyer(cfg, renommer, supprimer):
    corps = {'renommer': renommer, 'supprimer': supprimer}
    req = urllib.request.Request(cfg['url'] + '/admin/nettoyer', method='POST', data=json.dumps(corps).encode('utf-8'),
                                 headers={'X-Server-Token': cfg['jeton'], 'Content-Type': 'application/json', 'User-Agent': AGENT})
    for essai in range(5):
        try:
            with urllib.request.urlopen(req, timeout=180) as r:
                return json.load(r)['data']
        except urllib.error.HTTPError as e:
            sys.exit(f'POST /admin/nettoyer : HTTP {e.code} {e.read().decode("utf-8", "replace")[:300]}')
        except (urllib.error.URLError, TimeoutError, ConnectionError):
            if essai == 4:
                raise
            time.sleep(30 * (essai + 1))


def paquets(actions):
    """Les actions en lots de LOT au plus et de moins de OCTETS_MAX une fois en JSON."""
    lot, taille = [], 0
    for a in actions:
        t = len(json.dumps(a[1], ensure_ascii=False).encode('utf-8')) + 2
        if lot and (len(lot) >= LOT or taille + t > OCTETS_MAX):
            yield lot
            lot, taille = [], 0
        lot.append(a)
        taille += t
    if lot:
        yield lot


def main():
    for flux in (sys.stdout, sys.stderr):
        flux.reconfigure(encoding='utf-8', errors='replace')
    a = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    a.add_argument('--sortie', required=True, help='dossier des pages lues, du plan et de l\'état')
    a.add_argument('--appliquer', action='store_true', help='modifier le catalogue (sinon : plan seul)')
    a.add_argument('--relire', action='store_true', help='relire tout le catalogue')
    o = a.parse_args()
    global CFG
    CFG = cfg = config()
    if o.appliquer and not cfg['jeton']:
        sys.exit('REV_SERVER_TOKEN manquant (.env ou environnement)')

    produits = lire_catalogue(os.path.join(o.sortie, 'pages'), o.relire)
    etat_chemin = os.path.join(o.sortie, 'etat.json')
    etat = json.load(open(etat_chemin, encoding='utf-8')) if os.path.exists(etat_chemin) else {}
    etat.setdefault('trouves', {})
    etat.setdefault('envoyes', [])
    garder = lambda: json.dump(etat, open(etat_chemin + '.tmp', 'w', encoding='utf-8'), ensure_ascii=False) or os.replace(etat_chemin + '.tmp', etat_chemin)

    generiques = collections.Counter((p['nom'] or '').strip().lower() for p in produits)
    juges = [(p, defauts(p['nom'], (p['code_produit'] or '').strip(), generiques)) for p in produits]
    juges = [(p, d) for p, d in juges if d]

    perdus = [p['code_produit'] for p, d in juges if d == ['texte perdu'] and p['code_produit'] not in etat['trouves']]
    with ThreadPoolExecutor(3) as ex:  # Open Facts : 100 lectures de produit par minute au plus
        for i, (code, nom) in enumerate(zip(perdus, ex.map(nom_public, perdus)), 1):
            if nom is not False:
                etat['trouves'][code] = nom
            if i % 20 == 0 or i == len(perdus):
                garder()
            print(f'2. Noms perdus cherchés : {i}/{len(perdus)}', end='\r')
    if perdus:
        print()
    pas_cherches = sum(1 for p, d in juges if d == ['texte perdu'] and p['code_produit'] not in etat['trouves'])

    plan, laisses = [], collections.Counter()
    for p, d in juges:
        code = p['code_produit']
        if d == ['texte perdu'] and code not in etat['trouves']:
            laisses['texte perdu, pas encore cherché'] += 1
            continue
        if d == ['texte perdu']:
            nom = etat['trouves'][code]
            action = ('renommer', nom, 'texte perdu, retrouvé') if nom else ('supprimer', None, 'texte perdu, introuvable')
        elif set(d) & A_SUPPRIMER:
            action = ('supprimer', None, ', '.join(x for x in d if x in A_SUPPRIMER))
        elif d == ['espaces en double']:
            action = ('renommer', re.sub(r'\s+', ' ', p['nom']).strip(), 'espaces en double')
        else:
            for x in d:
                laisses[x] += 1
            continue
        plan.append((p, action))
    ecrire(os.path.join(o.sortie, 'plan.csv'), ['Id', 'Code-barres', 'Nom actuel', 'Action', 'Nouveau nom', 'Raison', 'Photo'],
           [[p['id'], p['code_produit'], p['nom'], act, nom or '', raison, 'oui' if p['photo'] else 'non']
            for p, (act, nom, raison) in plan])
    compte = collections.Counter((act, raison.split(',')[0]) for p, (act, _, raison) in plan)
    print(f'{len(produits)} produits lus ; plan : {sum(1 for _, x in plan if x[0] == "renommer")} à renommer, '
          f'{sum(1 for _, x in plan if x[0] == "supprimer")} à supprimer')
    for (act, raison), n in sorted(compte.items()):
        print(f'   {act:9} {n:7}  {raison}')
    print(f'   laissés  {sum(laisses.values()):7}  ({", ".join(f"{k} {v}" for k, v in laisses.items())})')
    print(f'Plan : {os.path.join(o.sortie, "plan.csv")}')
    if pas_cherches:
        print(f'{pas_cherches} noms perdus pas encore cherchés (Open Facts sans réponse) : relancer pour les chercher.')
    if not o.appliquer:
        print('Rien n\'a été modifié ; --appliquer pour lancer.')
        return

    envoyes = set(etat['envoyes'])
    actions = [(act, {'id': p['id'], 'ancien': p['nom'], ('nom' if act == 'renommer' else 'raison'): nom if act == 'renommer' else raison[:60]})
               for p, (act, nom, raison) in plan if p['id'] not in envoyes]
    total = collections.Counter()
    gardes = []
    for i, lot in enumerate(paquets(actions), 1):
        r = envoyer(cfg, [x for act, x in lot if act == 'renommer'], [x for act, x in lot if act == 'supprimer'])
        total['renommes'] += r['renommes']
        total['supprimes'] += r['supprimes']
        for g in r['ignores']:
            total[g['raison']] += 1
            gardes.append(g)
        etat['envoyes'] += [x['id'] for _, x in lot]
        garder()
        print(f'3. Envoyés {len(etat["envoyes"])}/{len(plan)} : {total["renommes"]} renommés, {total["supprimes"]} supprimés, '
              f'{len(gardes)} laissés par le serveur', end='\r')
    print()
    ecrire(os.path.join(o.sortie, 'laisses_par_le_serveur.csv'), ['Id', 'Raison'], [[g['id'], g['raison']] for g in gardes])
    print(f'Terminé : {dict(total)}')


if __name__ == '__main__':
    main()

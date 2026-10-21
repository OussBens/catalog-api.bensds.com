<?php
/**
 * Tendance : ce qui se vend dans les boutiques CaisseDZ, et ce qu'elles apprennent au catalogue.
 *
 * Chaque poste CaisseDZ Desktop envoie (POST /tendance, authentifié par sa licence BENS signée) le nombre
 * de tickets de chaque jour où un code-barres a été vendu, et les fiches qu'il a saisies (Contributions.php :
 * produits inconnus créés, champs vides complétés).
 *
 *   catalog_vente_jour   (produit, poste, jour) → tickets, au plus VENTES_MAX_JOUR (un poste ne fait pas
 *                        la tendance à lui seul) ; renvoyer un jour le remplace (envoi rejouable).
 *   catalog_product (+)  postes_90j, ventes_90j : sur les JOURS derniers jours ;
 *                        points_tendance = postes × POINTS_POSTE + ventes × POINTS_VENTE (plafonnées),
 *                        ajoutés au score du rayon ;
 *                        tendance = vendu par au moins postesMin() boutiques ET dans au moins ticketsMin()
 *                        tickets (.env TENDANCE_POSTES_MIN, TENDANCE_TICKETS_MIN ; 3 et 20 par défaut) ;
 *                        tendance_le : depuis quand (les postes ne téléchargent que les nouveaux) ;
 *                        nom_ar : nom arabe (le nom lui-même s'il est en arabe, repris par Rayons::reclasser,
 *                        sinon le premier proposé par un poste, nom_ar_poste).
 *   catalog_poste        dernier envoi de chaque poste (tableau de bord BENS).
 *
 * Les produits touchés par un envoi sont recalculés tout de suite ; les autres glissent avec la fenêtre
 * (Tendance::rafraichir, quelques centaines à chaque envoi, ou tous depuis admin_rayons.php).
 */
class Tendance
{
    const JOURS = 90;
    const POSTES_MIN = 3;
    const TICKETS_MIN = 20;
    const VENTES_MAX_JOUR = 50;
    const POINTS_POSTE = 1000;
    const POINTS_VENTE = 10;
    const VENTES_MAX_COMPTEES = 1000;
    const LIGNES_MAX = 5000;
    const NOM_AR_MAX = 200;

    private static function env($cle, $defaut)
    {
        $v = $_ENV[$cle] ?? '';
        return ctype_digit((string)$v) && (int)$v > 0 ? (int)$v : $defaut;
    }

    /** Boutiques différentes qui doivent l'avoir vendu (.env TENDANCE_POSTES_MIN). */
    public static function postesMin()
    {
        return self::env('TENDANCE_POSTES_MIN', self::POSTES_MIN);
    }

    /** Tickets qui doivent le contenir, toutes boutiques ensemble (.env TENDANCE_TICKETS_MIN). */
    public static function ticketsMin()
    {
        return self::env('TENDANCE_TICKETS_MIN', self::TICKETS_MIN);
    }

    public static function estTendance($postes, $tickets)
    {
        return (int)$postes >= self::postesMin() && (int)$tickets >= self::ticketsMin();
    }

    public static function points($postes, $ventes)
    {
        return (int)$postes * self::POINTS_POSTE + min((int)$ventes, self::VENTES_MAX_COMPTEES) * self::POINTS_VENTE;
    }

    /** Le texte contient-il de l'arabe ? */
    public static function estArabe($texte)
    {
        return preg_match('/\p{Arabic}/u', (string)$texte) === 1;
    }

    private static function jour($aujourdhui)
    {
        return $aujourdhui ?: new DateTimeImmutable('today', new DateTimeZone('Africa/Algiers'));
    }

    private static function mysql(PDO $db)
    {
        return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /** Les produits de ces codes-barres (code principal ou table des codes-barres) : [code => id]. */
    public static function produitsParCodeBarres(PDO $db, array $codes)
    {
        $ids = [];
        foreach (array_chunk(array_values(array_unique($codes)), 500) as $lot) {
            $marques = implode(',', array_fill(0, count($lot), '?'));
            $stmt = $db->prepare("SELECT barcode, product_id FROM catalog_product_barcode WHERE barcode IN ($marques)");
            $stmt->execute($lot);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ids[$r['barcode']] = $ids[$r['barcode']] ?? (int)$r['product_id'];
            }
            $reste = array_values(array_filter($lot, function ($c) use ($ids) { return !isset($ids[$c]); }));
            if ($reste) {
                $marques = implode(',', array_fill(0, count($reste), '?'));
                $stmt = $db->prepare("SELECT code_produit, id FROM catalog_product WHERE code_produit IN ($marques)");
                $stmt->execute($reste);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $ids[$r['code_produit']] = $ids[$r['code_produit']] ?? (int)$r['id'];
                }
            }
        }
        return $ids;
    }

    /**
     * L'envoi d'un poste [poste] (son code CD-XXXX-XXXX) :
     *   {produits: [{code_barres, codes_barres, nom, nom_ar, marque, categorie}],
     *    ventes: [{jour: "AAAA-MM-JJ", code_barres, tickets}]}.
     * Les fiches passent d'abord (un produit créé par la boutique compte aussitôt ses ventes) ; les jours hors
     * fenêtre et les codes-barres toujours inconnus sont ignorés.
     * Renvoie {ventes, inconnus, crees, completes}.
     */
    public static function enregistrer(PDO $db, $poste, array $corps, DateTimeImmutable $aujourdhui = null)
    {
        $aujourdhui = self::jour($aujourdhui);
        $ventes = is_array($corps['ventes'] ?? null) ? $corps['ventes'] : [];
        $produits = is_array($corps['produits'] ?? null) ? $corps['produits'] : [];
        if (count($ventes) + count($produits) > self::LIGNES_MAX) {
            throw new Exception('Trop de lignes (' . self::LIGNES_MAX . ' au plus par envoi)', 413);
        }
        $contributions = Contributions::appliquer($db, $poste, $produits);

        $premier = $aujourdhui->modify('-' . self::JOURS . ' days')->format('Y-m-d');
        $dernier = $aujourdhui->modify('+1 day')->format('Y-m-d');
        $lignes = [];
        foreach ($ventes as $v) {
            $jour = is_array($v) ? (string)($v['jour'] ?? '') : '';
            $code = is_array($v) ? trim((string)($v['code_barres'] ?? '')) : '';
            $n = is_array($v) ? (int)($v['tickets'] ?? 0) : 0;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour) || $jour < $premier || $jour > $dernier
                || $code === '' || strlen($code) > 64 || $n <= 0) {
                continue;
            }
            $lignes[] = [$jour, $code, min($n, self::VENTES_MAX_JOUR)];
        }

        $ids = self::produitsParCodeBarres($db, array_column($lignes, 1));
        $sql = self::mysql($db)
            ? 'INSERT INTO catalog_vente_jour (product_id, poste, jour, tickets) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE tickets = VALUES(tickets)'
            : 'INSERT INTO catalog_vente_jour (product_id, poste, jour, tickets) VALUES (?, ?, ?, ?)
               ON CONFLICT (product_id, poste, jour) DO UPDATE SET tickets = excluded.tickets';
        $upsert = $db->prepare($sql);
        $touches = [];
        $inconnus = [];
        $enregistres = 0;
        $db->beginTransaction();
        try {
            // Plusieurs codes-barres d'un même produit le même jour : leurs tickets s'additionnent.
            $parProduit = [];
            foreach ($lignes as [$jour, $code, $n]) {
                if (!isset($ids[$code])) {
                    $inconnus[$code] = true;
                    continue;
                }
                $cle = $ids[$code] . '|' . $jour;
                $parProduit[$cle] = min(($parProduit[$cle] ?? 0) + $n, self::VENTES_MAX_JOUR);
            }
            foreach ($parProduit as $cle => $n) {
                [$id, $jour] = explode('|', $cle);
                $upsert->execute([(int)$id, $poste, $jour, $n]);
                $touches[(int)$id] = true;
                $enregistres++;
            }
            self::noterEnvoi($db, $poste, $aujourdhui);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        self::recalculer($db, array_keys($touches), $aujourdhui);
        self::rafraichir($db, 300, $aujourdhui);
        return [
            'ventes' => $enregistres,
            'inconnus' => count($inconnus),
            'crees' => $contributions['crees'],
            'completes' => $contributions['completes'],
        ];
    }

    private static function noterEnvoi(PDO $db, $poste, DateTimeImmutable $aujourdhui)
    {
        $le = $aujourdhui->format('Y-m-d') . ' ' . date('H:i:s');
        $sql = self::mysql($db)
            ? 'INSERT INTO catalog_poste (poste, premier_le, dernier_le, envois) VALUES (?, ?, ?, 1)
               ON DUPLICATE KEY UPDATE dernier_le = VALUES(dernier_le), envois = envois + 1'
            : 'INSERT INTO catalog_poste (poste, premier_le, dernier_le, envois) VALUES (?, ?, ?, 1)
               ON CONFLICT (poste) DO UPDATE SET dernier_le = excluded.dernier_le, envois = catalog_poste.envois + 1';
        $db->prepare($sql)->execute([$poste, $le, $le]);
    }

    /** Recalcule la tendance des produits [ids] sur les JOURS derniers jours (avant [aujourdhui] inclus). */
    public static function recalculer(PDO $db, array $ids, DateTimeImmutable $aujourdhui = null)
    {
        $aujourdhui = self::jour($aujourdhui);
        $debut = $aujourdhui->modify('-' . (self::JOURS - 1) . ' days')->format('Y-m-d');
        $maintenant = $aujourdhui->format('Y-m-d') . ' ' . date('H:i:s');
        $maj = $db->prepare('UPDATE catalog_product SET postes_90j = ?, ventes_90j = ?,
                score = score - points_tendance + ?, points_tendance = ?,
                tendance = ?, tendance_le = ?, tendance_maj = ? WHERE id = ?');
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $lot) {
            $liste = implode(',', $lot);
            $calcul = [];
            $stmt = $db->prepare("SELECT product_id, COUNT(DISTINCT poste) AS postes, SUM(tickets) AS ventes
                                  FROM catalog_vente_jour WHERE product_id IN ($liste) AND jour >= ? GROUP BY product_id");
            $stmt->execute([$debut]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $calcul[(int)$r['product_id']] = [(int)$r['postes'], (int)$r['ventes']];
            }
            $avant = [];
            foreach ($db->query("SELECT id, tendance, tendance_le FROM catalog_product WHERE id IN ($liste)")
                         ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $avant[(int)$r['id']] = $r;
            }
            $db->beginTransaction();
            try {
                foreach ($lot as $id) {
                    if (!isset($avant[$id])) {
                        continue;
                    }
                    [$postes, $ventes] = $calcul[$id] ?? [0, 0];
                    $points = self::points($postes, $ventes);
                    $tendance = self::estTendance($postes, $ventes) ? 1 : 0;
                    // tendance_le : le jour où il l'est devenu (gardé tant qu'il le reste).
                    $le = $tendance ? ((int)$avant[$id]['tendance'] === 1 && $avant[$id]['tendance_le'] ? $avant[$id]['tendance_le'] : $maintenant) : null;
                    $maj->execute([$postes, $ventes, $points, $points, $tendance, $le, $aujourdhui->format('Y-m-d'), $id]);
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }
    }

    /**
     * La fenêtre glisse : recalcule au plus [lot] produits qui ont eu des ventes et n'ont pas été recalculés
     * aujourd'hui, et oublie les ventes trop anciennes. Renvoie le nombre de produits recalculés.
     */
    public static function rafraichir(PDO $db, $lot = 300, DateTimeImmutable $aujourdhui = null)
    {
        $aujourdhui = self::jour($aujourdhui);
        $jour = $aujourdhui->format('Y-m-d');
        $stmt = $db->prepare('SELECT id FROM catalog_product WHERE points_tendance > 0 AND (tendance_maj IS NULL OR tendance_maj < ?)
                              LIMIT ' . (int)$lot);
        $stmt->execute([$jour]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            self::recalculer($db, $ids, $aujourdhui);
        }
        $db->prepare('DELETE FROM catalog_vente_jour WHERE jour < ?')
           ->execute([$aujourdhui->modify('-' . (self::JOURS + 30) . ' days')->format('Y-m-d')]);
        return count($ids);
    }

    /** Les produits vendus dans les boutiques, les mieux classés d'abord (administration, tableau de bord). */
    public static function meilleurs(PDO $db, $limite = 50)
    {
        return $db->query("SELECT p.id, p.nom, p.nom_ar, p.rayon_code, p.postes_90j, p.ventes_90j, p.points_tendance,
                                  p.tendance, p.tendance_le, p.contribue_par
                           FROM catalog_product p WHERE p.points_tendance > 0 AND p.exclu = 0
                           ORDER BY p.points_tendance DESC, p.id DESC LIMIT " . (int)$limite)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Le tableau de bord BENS (GET /tendance/stats) : seuils, totaux sur JOURS jours, produits en tendance par
     * rayon, les plus vendus, chaque poste (dernier envoi, ventes, produits créés et complétés) et les
     * dernières contributions des boutiques.
     */
    public static function stats(PDO $db, DateTimeImmutable $aujourdhui = null, $limite = 100)
    {
        $aujourdhui = self::jour($aujourdhui);
        $debut = $aujourdhui->modify('-' . (self::JOURS - 1) . ' days')->format('Y-m-d');
        $q = function ($sql, array $p = []) use ($db) {
            $s = $db->prepare($sql);
            $s->execute($p);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        };
        $ventes = $q('SELECT COUNT(DISTINCT poste) AS postes, COUNT(DISTINCT product_id) AS produits,
                             COALESCE(SUM(tickets), 0) AS tickets FROM catalog_vente_jour WHERE jour >= ?', [$debut])[0];
        $actions = ['cree' => 0, 'complete' => 0];
        foreach ($q('SELECT action, COUNT(*) AS n FROM catalog_contribution GROUP BY action') as $r) {
            $actions[$r['action']] = (int)$r['n'];
        }
        $parRayon = [];
        foreach ($q('SELECT rayon_code, COUNT(*) AS n FROM catalog_product WHERE tendance = 1 AND exclu = 0
                     AND rayon_code IS NOT NULL GROUP BY rayon_code') as $r) {
            $parRayon[$r['rayon_code']] = (int)$r['n'];
        }
        $rayons = [];
        foreach ($q('SELECT code, nom_fr FROM catalog_rayon WHERE actif = 1 ORDER BY ordre, code') as $r) {
            $rayons[] = ['code' => $r['code'], 'nom' => $r['nom_fr'], 'tendances' => $parRayon[$r['code']] ?? 0];
        }
        $postes = [];
        foreach ($q('SELECT poste, premier_le, dernier_le, envois FROM catalog_poste ORDER BY dernier_le DESC') as $r) {
            $postes[$r['poste']] = [
                'poste' => $r['poste'], 'premier_envoi' => $r['premier_le'], 'dernier_envoi' => $r['dernier_le'],
                'envois' => (int)$r['envois'], 'tickets' => 0, 'produits' => 0, 'crees' => 0, 'completes' => 0,
            ];
        }
        foreach ($q('SELECT poste, SUM(tickets) AS t, COUNT(DISTINCT product_id) AS p FROM catalog_vente_jour
                     WHERE jour >= ? GROUP BY poste', [$debut]) as $r) {
            if (isset($postes[$r['poste']])) {
                $postes[$r['poste']]['tickets'] = (int)$r['t'];
                $postes[$r['poste']]['produits'] = (int)$r['p'];
            }
        }
        foreach ($q('SELECT poste, action, COUNT(*) AS n FROM catalog_contribution GROUP BY poste, action') as $r) {
            if (isset($postes[$r['poste']])) {
                $postes[$r['poste']][$r['action'] === 'cree' ? 'crees' : 'completes'] = (int)$r['n'];
            }
        }
        $tendances = [];
        foreach (self::meilleurs($db, $limite) as $p) {
            $tendances[] = [
                'id' => (int)$p['id'], 'nom' => $p['nom'], 'nom_ar' => $p['nom_ar'] ?: null, 'rayon' => $p['rayon_code'],
                'postes' => (int)$p['postes_90j'], 'tickets' => (int)$p['ventes_90j'], 'points' => (int)$p['points_tendance'],
                'tendance' => (int)$p['tendance'] === 1, 'depuis' => $p['tendance_le'] ?: null,
                'contribue_par' => $p['contribue_par'] ?: null,
            ];
        }
        $contributions = [];
        foreach ($q('SELECT c.product_id, c.poste, c.action, c.champs, c.cree_le, p.nom
                     FROM catalog_contribution c LEFT JOIN catalog_product p ON p.id = c.product_id
                     ORDER BY c.cree_le DESC, c.id DESC LIMIT 50') as $c) {
            $contributions[] = [
                'id' => (int)$c['product_id'], 'nom' => $c['nom'], 'poste' => $c['poste'], 'action' => $c['action'],
                'champs' => $c['champs'] === '' ? [] : explode(',', $c['champs']), 'le' => $c['cree_le'],
            ];
        }
        return [
            'seuils' => ['postes' => self::postesMin(), 'tickets' => self::ticketsMin(), 'jours' => self::JOURS,
                         'plafond' => Rayons::PLAFOND],
            'totaux' => [
                'postes' => (int)$ventes['postes'], 'produits' => (int)$ventes['produits'], 'tickets' => (int)$ventes['tickets'],
                'tendances' => array_sum($parRayon),
                'crees' => $actions['cree'], 'completes' => $actions['complete'],
                'noms_ar' => (int)$db->query("SELECT COUNT(*) FROM catalog_product WHERE nom_ar IS NOT NULL AND nom_ar <> ''")->fetchColumn(),
            ],
            'rayons' => $rayons,
            'tendances' => $tendances,
            'postes' => array_values($postes),
            'contributions' => $contributions,
        ];
    }
}

<?php
/**
 * L'administration du catalogue pour le tableau de bord BENS (bens_ds → admin.bensds.com → Catalogue) :
 * ce que faisait admin_rayons.php, servi en JSON (GET/POST/PUT /admin/*, `X-Server-Token` = REV_SERVER_TOKEN,
 * catalogue_core/routes.php).
 *
 *   vue           chiffres du catalogue, rayons et leurs nombres (produits, proposés, tendances, à confirmer)
 *   rayon         un rayon : noms, icône, ordre, type, mots-clés et l'aperçu de ses 50 premiers produits
 *   enregistrer   un rayon modifié ; ajouter : un nouveau rayon
 *   reclasser     un lot de reclassement (≈ 15 s) à relancer depuis le dernier id jusqu'à la fin
 *   installer     tables et colonnes
 *   recalculer    les tendances de tous les produits vendus, même déjà recalculés aujourd'hui
 *   aConfirmer    les produits ajoutés par les boutiques en attente de confirmation, avec leurs votes
 *   moderer       confirmer ou supprimer à la main un produit à confirmer
 *   sansTendance  plus aucune tendance dans des rayons (tendance imposée à 0, gardée aux recalculs)
 *   comparer      quels codes-barres d'une boutique le catalogue a déjà (sans compter de recherche)
 *   importer      les produits d'une boutique : les absents sont créés (confirmés, rangés dans les rayons de la
 *                 boutique), tous mis en tendance
 *   nettoyer      des noms corrigés et des produits supprimés (mis dans catalog_corbeille) ; restaurer : les remettre
 */
class Admin
{
    const LOT_SECONDES = 15;
    /** Codes-barres ou produits par appel de comparer / importer (le corps d'une requête : 64 Ko au plus). */
    const LOT_BOUTIQUE = 300;
    /** Produits renommés ou supprimés par appel de nettoyer / restaurer (le corps : 64 Ko au plus). */
    const LOT_NETTOYAGE = 200;

    private static function q(PDO $db, $sql, array $p = [])
    {
        $s = $db->prepare($sql);
        $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Les chiffres du catalogue et ses rayons (actifs ou non), dans leur ordre. */
    public static function vue(PDO $db)
    {
        $t = self::q($db, "SELECT COUNT(*) AS total, COALESCE(SUM(exclu), 0) AS exclus,
                                  COALESCE(SUM(CASE WHEN rayon_code IS NULL AND exclu = 0 THEN 1 ELSE 0 END), 0) AS sans_rayon,
                                  COALESCE(SUM(CASE WHEN classe_le IS NULL THEN 1 ELSE 0 END), 0) AS non_classes,
                                  COALESCE(SUM(a_confirmer), 0) AS a_confirmer,
                                  COALESCE(SUM(CASE WHEN nom_ar IS NOT NULL AND nom_ar <> '' THEN 1 ELSE 0 END), 0) AS noms_ar,
                                  COALESCE(SUM(CASE WHEN photo IS NOT NULL AND photo <> '' THEN 1 ELSE 0 END), 0) AS photos,
                                  COALESCE(SUM(tendance), 0) AS tendances
                           FROM catalog_product")[0];
        $nb = [];
        foreach (self::q($db, 'SELECT rayon_code, SUM(CASE WHEN a_confirmer = 0 THEN 1 ELSE 0 END) AS n, SUM(tendance) AS t,
                                      SUM(a_confirmer) AS c
                               FROM catalog_product WHERE exclu = 0 AND rayon_code IS NOT NULL GROUP BY rayon_code') as $r) {
            $nb[$r['rayon_code']] = $r;
        }
        $rayons = [];
        foreach (self::q($db, 'SELECT * FROM catalog_rayon ORDER BY ordre, code') as $r) {
            $n = (int)($nb[$r['code']]['n'] ?? 0);
            $rayons[] = self::rayonJson($r) + [
                'produits' => $n,
                'proposes' => min($n, Rayons::PLAFOND),
                'tendances' => (int)($nb[$r['code']]['t'] ?? 0),
                'a_confirmer' => (int)($nb[$r['code']]['c'] ?? 0),
            ];
        }
        return [
            'plafond' => Rayons::PLAFOND,
            'totaux' => array_map('intval', $t),
            'rayons' => $rayons,
        ];
    }

    private static function rayonJson(array $r)
    {
        return [
            'code' => $r['code'],
            'nom_fr' => $r['nom_fr'],
            'nom_ar' => $r['nom_ar'],
            'nom_en' => $r['nom_en'],
            'icone' => $r['icone'],
            'ordre' => (int)$r['ordre'],
            'prioritaire' => (int)$r['prioritaire'] === 1,
            'secours' => (int)$r['secours'] === 1,
            'actif' => (int)$r['actif'] === 1,
        ];
    }

    private static function ligneRayon(PDO $db, $code)
    {
        $r = self::q($db, 'SELECT * FROM catalog_rayon WHERE code = ?', [(string)$code]);
        if (!$r) {
            throw new Exception('Rayon inconnu', 404);
        }
        return $r[0];
    }

    /** Un rayon : ses réglages, ses mots-clés (un par ligne) et l'aperçu de ses 50 premiers produits proposés. */
    public static function rayon(PDO $db, $code)
    {
        $r = self::ligneRayon($db, $code);
        $mots = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)$r['mots_cles'])), 'strlen'));
        $apercu = (int)$r['actif'] === 1 ? Rayons::produits($db, $r['code'], 1, 50) : ['total' => 0, 'products' => []];
        return self::rayonJson($r) + ['mots_cles' => $mots, 'proposes' => $apercu['total'], 'apercu' => $apercu['products']];
    }

    /** Enregistre le rayon [code] : noms, icône (Lucide), ordre, prioritaire, secours, actif, mots-clés. */
    public static function enregistrer(PDO $db, $code, array $b)
    {
        self::ligneRayon($db, $code);
        $texte = function ($cle, $max, $requis = false) use ($b) {
            $v = isset($b[$cle]) && is_string($b[$cle]) ? trim($b[$cle]) : '';
            if (($requis && $v === '') || mb_strlen($v) > $max) {
                throw new Exception("$cle : 1 à $max caractères", 400);
            }
            return $v;
        };
        $mots = $b['mots_cles'] ?? [];
        if (!is_array($mots) || count($mots) > 2000) {
            throw new Exception('mots_cles : une liste (2 000 au plus)', 400);
        }
        $mots = array_values(array_unique(array_filter(array_map(function ($m) {
            return is_string($m) ? mb_substr(trim($m), 0, 80) : '';
        }, $mots), 'strlen')));
        $icone = $texte('icone', 40) ?: 'package';
        if (!preg_match('/^[a-z0-9-]+$/', $icone)) {
            throw new Exception('icone : un nom Lucide (minuscules, chiffres, tirets)', 400);
        }
        $ordre = $b['ordre'] ?? 0;
        if (!is_int($ordre) || $ordre < 0 || $ordre > 100000) {
            throw new Exception('ordre : un entier positif', 400);
        }
        $db->prepare('UPDATE catalog_rayon SET nom_fr = ?, nom_ar = ?, nom_en = ?, icone = ?, ordre = ?, prioritaire = ?,
                      secours = ?, actif = ?, mots_cles = ? WHERE code = ?')
           ->execute([
               $texte('nom_fr', 80, true), $texte('nom_ar', 80), $texte('nom_en', 80), $icone, $ordre,
               !empty($b['prioritaire']) ? 1 : 0, !empty($b['secours']) ? 1 : 0, !empty($b['actif']) ? 1 : 0,
               implode("\n", $mots), (string)$code,
           ]);
        return self::rayon($db, $code);
    }

    /** Un nouveau rayon (inactif tant que ses noms et mots-clés ne sont pas remplis). */
    public static function ajouter(PDO $db, $code)
    {
        $code = is_string($code) ? $code : '';
        if (!preg_match('/^[a-z0-9_]{1,40}$/', $code)) {
            throw new Exception('code : minuscules, chiffres, « _ » (40 au plus)', 400);
        }
        if (self::q($db, 'SELECT code FROM catalog_rayon WHERE code = ?', [$code])) {
            throw new Exception('Ce rayon existe déjà', 409);
        }
        $db->prepare("INSERT INTO catalog_rayon (code, nom_fr, nom_ar, nom_en, icone, ordre, mots_cles, actif)
                      VALUES (?, ?, '', '', 'package', 999, '', 0)")->execute([$code, $code]);
        return self::rayon($db, $code);
    }

    /**
     * Un lot de reclassement à partir de l'id [depuis] (exclu), pendant LOT_SECONDES au plus. Renvoie
     * {depuis (dernier id traité), reste, total, fini} : relancer avec le nouveau depuis jusqu'à fini.
     */
    public static function reclasser(PDO $db, $depuis = 0)
    {
        $depuis = max(0, (int)$depuis);
        $rayons = Rayons::preparer($db);
        $fin = microtime(true) + self::LOT_SECONDES;
        $fini = false;
        while (microtime(true) < $fin) {
            $dernier = Rayons::reclasser($db, $depuis, 5000, $rayons);
            if ($dernier === null) {
                $fini = true;
                break;
            }
            $depuis = $dernier;
        }
        $reste = (int)self::q($db, 'SELECT COUNT(*) AS n FROM catalog_product WHERE id > ?', [$depuis])[0]['n'];
        $total = (int)self::q($db, 'SELECT COUNT(*) AS n FROM catalog_product')[0]['n'];
        return ['depuis' => $depuis, 'reste' => $fini ? 0 : $reste, 'total' => $total, 'fini' => $fini || $reste === 0];
    }

    public static function installer(PDO $db)
    {
        RayonsSchema::installer($db);
        return ['a_jour' => RayonsSchema::aJour($db)];
    }

    /** Les tendances de tous les produits vendus dans la fenêtre ou qui ont des points, recalculées tout de suite. */
    public static function recalculer(PDO $db, DateTimeImmutable $aujourdhui = null)
    {
        $ids = array_map('intval', array_column(self::q($db,
            'SELECT id FROM catalog_product WHERE points_tendance > 0
             UNION SELECT DISTINCT product_id FROM catalog_vente_jour'), 'id'));
        Tendance::recalculer($db, $ids, $aujourdhui);
        Tendance::rafraichir($db, 0, $aujourdhui); // la purge des vieilles ventes
        return ['produits' => count($ids)];
    }

    /** Les produits à confirmer, les plus récents d'abord, avec leurs votes. */
    public static function aConfirmer(PDO $db, $page = 1, $limite = 50)
    {
        $page = max(1, (int)$page);
        $limite = max(1, min(200, (int)$limite));
        $debut = ($page - 1) * $limite;
        $total = (int)self::q($db, 'SELECT COUNT(*) AS n FROM catalog_product WHERE a_confirmer = 1')[0]['n'];
        $produits = [];
        foreach (self::q($db, "SELECT p.id, p.code_produit, p.nom, p.nom_ar, p.rayon_code, p.exclu, p.confirmation, p.contribue_par,
                                      COALESCE(b.nom, '') AS marque, COALESCE(c.nom, '') AS categorie,
                                      (SELECT COUNT(*) FROM catalog_confirmation v WHERE v.product_id = p.id AND v.vote > 0) AS pour,
                                      (SELECT COUNT(*) FROM catalog_confirmation v WHERE v.product_id = p.id AND v.vote < 0) AS contre,
                                      (SELECT MIN(k.cree_le) FROM catalog_contribution k WHERE k.product_id = p.id AND k.action = 'cree') AS cree_le
                               FROM catalog_product p
                               LEFT JOIN catalog_brand b ON b.id = p.brand_id
                               LEFT JOIN catalog_category c ON c.id = p.category_id
                               WHERE p.a_confirmer = 1 ORDER BY p.id DESC LIMIT $limite OFFSET $debut") as $p) {
            $produits[] = [
                'id' => (int)$p['id'], 'code_barres' => $p['code_produit'], 'nom' => $p['nom'], 'nom_ar' => $p['nom_ar'] ?: null,
                'marque' => $p['marque'], 'categorie' => $p['categorie'], 'rayon' => $p['rayon_code'], 'exclu' => (int)$p['exclu'] === 1,
                'confirmation' => (int)$p['confirmation'], 'pour' => (int)$p['pour'], 'contre' => (int)$p['contre'],
                'contribue_par' => $p['contribue_par'], 'cree_le' => $p['cree_le'],
            ];
        }
        return [
            'total' => $total, 'page' => $page, 'limit' => $limite, 'total_pages' => (int)ceil($total / $limite),
            'seuil' => Confirmations::seuil(), 'suppression' => Confirmations::suppression(), 'produits' => $produits,
        ];
    }

    /** Confirme ou supprime à la main ([action] : `confirmer` | `supprimer`) le produit à confirmer [id]. */
    public static function moderer(PDO $db, $id, $action)
    {
        if (!in_array($action, ['confirmer', 'supprimer'], true)) {
            throw new Exception('action : confirmer ou supprimer', 400);
        }
        $p = self::q($db, 'SELECT id, nom, a_confirmer FROM catalog_product WHERE id = ?', [(int)$id]);
        if (!$p || (int)$p[0]['a_confirmer'] !== 1) {
            throw new Exception('Produit introuvable ou déjà confirmé', 404);
        }
        $db->beginTransaction();
        try {
            $action === 'confirmer'
                ? Confirmations::confirmer($db, (int)$id, 'ADMIN')
                : Confirmations::retirer($db, (int)$id, 'ADMIN', (string)$p[0]['nom']);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        return ['id' => (int)$id, 'action' => $action];
    }

    /**
     * Plus aucune tendance dans les rayons [rayons] (codes) : chaque produit y a sa tendance imposée à 0, que les
     * recalculs gardent (Tendance::recalculer). Renvoie {rayons, produits}.
     */
    public static function sansTendance(PDO $db, $rayons)
    {
        if (!is_array($rayons) || !$rayons || count($rayons) > 50) {
            throw new Exception('rayons : une liste de codes de rayons', 400);
        }
        $rayons = array_values(array_unique(array_map('strval', $rayons)));
        foreach ($rayons as $code) {
            self::ligneRayon($db, $code);
        }
        $marques = implode(',', array_fill(0, count($rayons), '?'));
        $n = (int)self::q($db, "SELECT COUNT(*) AS n FROM catalog_product WHERE rayon_code IN ($marques)", $rayons)[0]['n'];
        $db->prepare("UPDATE catalog_product SET tendance = 0, tendance_le = NULL, tendance_forcee = 0 WHERE rayon_code IN ($marques)")
           ->execute($rayons);
        return ['rayons' => $rayons, 'produits' => $n];
    }

    /** Les codes-barres [codes] lus ; exception 400 si ce n'est pas une liste de LOT_BOUTIQUE au plus. */
    private static function lot($liste, $nom)
    {
        if (!is_array($liste) || count($liste) > self::LOT_BOUTIQUE) {
            throw new Exception("$nom : une liste de " . self::LOT_BOUTIQUE . ' au plus', 400);
        }
        return array_values($liste);
    }

    /**
     * Ce que le catalogue a déjà parmi les codes-barres [codes] d'une boutique, sans compter de recherche (le
     * classement ne bouge pas) : {existants: [{code_barres, id, nom, photo, rayon, tendance}], absents, invalides}.
     */
    public static function comparer(PDO $db, $codes)
    {
        $codes = array_values(array_unique(array_map(function ($c) { return is_scalar($c) ? trim((string)$c) : ''; },
            self::lot($codes, 'codes'))));
        $valides = array_values(array_filter($codes, [Contributions::class, 'codeValide']));
        $ids = Tendance::produitsParCodeBarres($db, $valides);
        $produits = [];
        if ($ids) {
            $liste = implode(',', array_map('intval', array_unique(array_values($ids))));
            foreach (self::q($db, "SELECT id, nom, photo, rayon_code, tendance FROM catalog_product WHERE id IN ($liste)") as $p) {
                $produits[(int)$p['id']] = $p;
            }
        }
        $existants = [];
        $absents = [];
        foreach ($valides as $c) {
            $p = isset($ids[$c]) ? ($produits[$ids[$c]] ?? null) : null;
            if ($p === null) {
                $absents[] = $c;
                continue;
            }
            $existants[] = [
                'code_barres' => $c, 'id' => (int)$p['id'], 'nom' => $p['nom'], 'photo' => trim((string)$p['photo']) !== '',
                'rayon' => $p['rayon_code'], 'tendance' => (int)$p['tendance'] === 1,
            ];
        }
        return ['existants' => $existants, 'absents' => $absents, 'invalides' => array_values(array_diff($codes, $valides))];
    }

    /**
     * Les produits [produits] d'une boutique ({code_barres, nom}) : un code-barres absent du catalogue devient un
     * produit confirmé (créé par `ADMIN`, porc / alcool exclus) ; un produit déjà là n'est pas modifié. Tous ont leur
     * tendance imposée à 1. Renvoie {crees: [{code_barres, id}], tendances, ignores}.
     * [rayons] (codes, facultatif) : les rayons de la boutique. Un produit créé que son nom range ailleurs (ou nulle
     * part : « TENT ANDREA BN ») va dans le premier ; il reçoit pour catégorie le nom de son rayon, que le tri lit
     * avant le nom (Rayons::classer) : un reclassement le laisse où il est.
     */
    public static function importer(PDO $db, $produits, $rayons = null)
    {
        $categories = [];
        if ($rayons !== null) {
            if (!is_array($rayons) || !$rayons || count($rayons) > 50) {
                throw new Exception('rayons : une liste de codes de rayons', 400);
            }
            foreach (array_values(array_unique(array_map('strval', $rayons))) as $code) {
                $categories[$code] = self::ligneRayon($db, $code)['nom_fr'];
            }
        }
        $actifs = $categories ? Rayons::preparer($db) : null;
        $fiches = [];
        $ignores = [];
        foreach (self::lot($produits, 'produits') as $x) {
            $f = Contributions::lire(is_array($x) ? ['code_barres' => trim((string)($x['code_barres'] ?? '')), 'nom' => $x['nom'] ?? null] : null);
            if ($f === null) {
                $ignores[] = is_array($x) ? (string)($x['code_barres'] ?? '') : '';
                continue;
            }
            // Indexé par « c » + code : une clé PHP faite de chiffres deviendrait un entier.
            $fiches['c' . $f['codes'][0]] = $f;
        }
        $codes = array_map(function ($f) { return $f['codes'][0]; }, array_values($fiches));
        $ids = Tendance::produitsParCodeBarres($db, $codes);
        $nouveaux = [];
        foreach ($fiches as $f) {
            $code = $f['codes'][0];
            if (!isset($ids[$code])) {
                if ($f['nom'] === null) {
                    $ignores[] = $code;
                    continue;
                }
                $n = ['code_barres' => $code, 'nom' => $f['nom']];
                if ($categories) {
                    $rayon = Rayons::classer($f['nom'], '', $actifs);
                    $n['categorie'] = $categories[isset($categories[$rayon]) ? $rayon : array_key_first($categories)];
                }
                $nouveaux[] = $n;
            }
        }
        Contributions::appliquer($db, 'ADMIN', $nouveaux);
        $ids = Tendance::produitsParCodeBarres($db, $codes);
        $crees = [];
        $db->beginTransaction();
        try {
            foreach ($nouveaux as $n) {
                if (isset($ids[$n['code_barres']])) {
                    Confirmations::confirmer($db, $ids[$n['code_barres']], 'ADMIN');
                    $crees[] = ['code_barres' => $n['code_barres'], 'id' => $ids[$n['code_barres']]];
                }
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        $tous = array_values(array_unique(array_values($ids)));
        if ($tous) {
            $db->exec('UPDATE catalog_product SET tendance_forcee = 1 WHERE id IN (' . implode(',', array_map('intval', $tous)) . ')');
            Tendance::recalculer($db, $tous);
        }
        return ['crees' => $crees, 'tendances' => count($tous), 'ignores' => array_values(array_unique(array_map('strval', $ignores)))];
    }

    /** Les produits {id, ancien, …} de [liste] (LOT_NETTOYAGE au plus), indexés par id, avec leur ligne actuelle. */
    private static function lotNettoyage(PDO $db, $liste, $nom)
    {
        if (!is_array($liste) || count($liste) > self::LOT_NETTOYAGE) {
            throw new Exception("$nom : une liste de " . self::LOT_NETTOYAGE . ' au plus', 400);
        }
        $lot = [];
        foreach ($liste as $x) {
            if (!is_array($x) || !isset($x['id']) || !is_int($x['id']) || !array_key_exists('ancien', $x)) {
                throw new Exception("$nom : des {id, ancien, …}", 400);
            }
            $lot[$x['id']] = $x;
        }
        $lignes = [];
        if ($lot) {
            $ids = implode(',', array_map('intval', array_keys($lot)));
            foreach (self::q($db, "SELECT * FROM catalog_product WHERE id IN ($ids)") as $p) {
                $lignes[(int)$p['id']] = $p;
            }
        }
        return [$lot, $lignes];
    }

    /**
     * Le nettoyage des noms du catalogue (tool/nettoyer_noms.py). Chaque produit est donné avec son nom lu à
     * l'analyse (ancien) : un produit renommé ou supprimé depuis est laissé tel quel.
     *   renommer   [{id, ancien, nom}] : le nouveau nom (espaces réduits, 200 caractères au plus), le produit reclassé
     *   supprimer  [{id, ancien, raison}] : le produit va dans catalog_corbeille (sa ligne et ses codes-barres, pour
     *              restaurer), puis il est retiré avec ses codes-barres, ses ventes et ses votes. Un produit apporté
     *              par une boutique (contribue_par) ou à la tendance imposée par l'administration est gardé.
     * Renvoie {renommes, supprimes, ignores: [{id, raison}]}.
     */
    public static function nettoyer(PDO $db, $renommer, $supprimer)
    {
        [$aRenommer, $lignesR] = self::lotNettoyage($db, $renommer ?? [], 'renommer');
        [$aSupprimer, $lignesS] = self::lotNettoyage($db, $supprimer ?? [], 'supprimer');
        $ignores = [];
        $renommes = 0;
        $supprimes = 0;
        $rayons = $aRenommer ? Rayons::preparer($db) : null;
        $db->beginTransaction();
        try {
            foreach ($aRenommer as $id => $x) {
                $nom = is_string($x['nom'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $x['nom'])) : '';
                $p = $lignesR[$id] ?? null;
                if ($nom === '' || mb_strlen($nom) > Contributions::NOM_MAX) {
                    $ignores[] = ['id' => $id, 'raison' => 'nom invalide'];
                } elseif ($p === null || $p['nom'] !== $x['ancien']) {
                    $ignores[] = ['id' => $id, 'raison' => 'introuvable ou déjà modifié'];
                } else {
                    $db->prepare('UPDATE catalog_product SET nom = ? WHERE id = ?')->execute([$nom, $id]);
                    $categorie = $p['category_id'] === null ? [] : self::q($db, 'SELECT nom FROM catalog_category WHERE id = ?', [(int)$p['category_id']]);
                    $codes = array_column(self::q($db, 'SELECT barcode FROM catalog_product_barcode WHERE product_id = ?', [$id]), 'barcode');
                    Contributions::ranger($db, $id, $nom, (string)($categorie[0]['nom'] ?? ''), $p['photo'], $codes ?: [(string)$p['code_produit']], $rayons);
                    $renommes++;
                }
            }
            $corbeille = $db->prepare('INSERT INTO catalog_corbeille (product_id, raison, produit, codes, supprime_le) VALUES (?, ?, ?, ?, ?)');
            foreach ($aSupprimer as $id => $x) {
                $p = $lignesS[$id] ?? null;
                if ($p === null || $p['nom'] !== $x['ancien']) {
                    $ignores[] = ['id' => $id, 'raison' => 'introuvable ou déjà modifié'];
                } elseif ($p['contribue_par'] !== null || (string)$p['tendance_forcee'] === '1') {
                    $ignores[] = ['id' => $id, 'raison' => 'gardé : produit d\'une boutique ou en tendance imposée'];
                } else {
                    $codes = self::q($db, 'SELECT barcode, is_primary, source_id FROM catalog_product_barcode WHERE product_id = ?', [$id]);
                    $corbeille->execute([$id, mb_substr(is_string($x['raison'] ?? null) ? $x['raison'] : '', 0, 60),
                        json_encode($p, JSON_UNESCAPED_UNICODE), json_encode($codes, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
                    Confirmations::supprimer($db, $id);
                    $supprimes++;
                }
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        return ['renommes' => $renommes, 'supprimes' => $supprimes, 'ignores' => $ignores];
    }

    /**
     * Remet dans le catalogue les produits [ids] (LOT_NETTOYAGE au plus) de catalog_corbeille, avec leur id, leur
     * ligne et leurs codes-barres d'avant. Renvoie {restaures, absents (pas dans la corbeille)}.
     */
    public static function restaurer(PDO $db, $ids)
    {
        if (!is_array($ids) || count($ids) > self::LOT_NETTOYAGE) {
            throw new Exception('ids : une liste de ' . self::LOT_NETTOYAGE . ' au plus', 400);
        }
        $colonnes = array_keys(self::q($db, 'SELECT * FROM catalog_product LIMIT 1')[0] ?? []);
        $restaures = [];
        $absents = [];
        $db->beginTransaction();
        try {
            foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
                $c = self::q($db, 'SELECT produit, codes FROM catalog_corbeille WHERE product_id = ?', [$id]);
                if (!$c || self::q($db, 'SELECT id FROM catalog_product WHERE id = ?', [$id])) {
                    $absents[] = $id;
                    continue;
                }
                $p = json_decode($c[0]['produit'], true);
                if ($colonnes) {
                    $p = array_intersect_key($p, array_flip($colonnes));
                }
                $db->prepare('INSERT INTO catalog_product (' . implode(', ', array_keys($p)) . ') VALUES ('
                    . implode(', ', array_fill(0, count($p), '?')) . ')')->execute(array_values($p));
                $code = $db->prepare('INSERT INTO catalog_product_barcode (product_id, barcode, is_primary, source_id) VALUES (?, ?, ?, ?)');
                foreach (json_decode($c[0]['codes'], true) as $b) {
                    $code->execute([$id, $b['barcode'], $b['is_primary'], $b['source_id']]);
                }
                $db->prepare('DELETE FROM catalog_corbeille WHERE product_id = ?')->execute([$id]);
                $restaures[] = $id;
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        return ['restaures' => $restaures, 'absents' => $absents];
    }
}

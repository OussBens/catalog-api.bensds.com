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
 */
class Admin
{
    const LOT_SECONDES = 15;

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
}

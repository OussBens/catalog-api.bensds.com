<?php
/**
 * Ce que les boutiques apprennent au catalogue (POST /tendance → produits) : chaque poste CaisseDZ Desktop
 * envoie les fiches qu'il a saisies (code-barres, nom, nom arabe, marque, catégorie, autres codes-barres) ; l'import
 * d'une boutique (Admin::importer) y ajoute la quantité (colonne taille : « 500 g ») et la couleur.
 *
 *   code-barres inconnu  → le produit est créé tout de suite (source CaisseDZ, contribue_par = le poste),
 *                          rangé dans son rayon (porc / alcool exclus comme les autres), « à confirmer » par les
 *                          autres boutiques (Confirmations.php) avant d'être proposé dans les rayons ;
 *   code-barres connu    → seuls les champs vides sont complétés (nom, nom arabe, marque, catégorie, quantité,
 *                          couleur, codes-barres supplémentaires) ; une valeur déjà présente n'est jamais remplacée.
 * Chaque création ou complément est noté dans catalog_contribution (poste, champs) : le tableau de bord BENS
 * montre ce que chaque client apporte, l'administration peut corriger.
 */
class Contributions
{
    const NOM_MAX = 200;
    const MARQUE_MAX = 120;
    const CATEGORIE_MAX = 200;
    const CODES_MAX = 10;
    const TAILLE_MAX = 50;
    const COULEUR_MAX = 50;

    /** Un code-barres EAN / UPC (les codes internes d'une boutique ne disent rien aux autres). */
    public static function codeValide($code)
    {
        return is_string($code) && preg_match('/^\d{8,14}$/', $code) === 1;
    }

    private static function texte($v, $max)
    {
        if (!is_string($v)) {
            return null;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $v));
        return $t === '' || mb_strlen($t) > $max ? null : $t;
    }

    /** L'id de la marque ou de la catégorie de ce nom, créée au besoin. */
    private static function idParNom(PDO $db, $table, $nom)
    {
        $s = $db->prepare("SELECT id FROM $table WHERE nom = ? LIMIT 1");
        $s->execute([$nom]);
        $id = $s->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        $db->prepare("INSERT INTO $table (nom) VALUES (?)")->execute([$nom]);
        return (int)$db->lastInsertId();
    }

    public static function noter(PDO $db, $produitId, $poste, $action, array $champs)
    {
        $db->prepare('INSERT INTO catalog_contribution (product_id, poste, action, champs, cree_le) VALUES (?, ?, ?, ?, ?)')
           ->execute([$produitId, $poste, $action, implode(',', $champs), date('Y-m-d H:i:s')]);
    }

    /** Lit une fiche envoyée ; null si elle n'a pas de code-barres valable. */
    public static function lire($x)
    {
        if (!is_array($x) || !self::codeValide($x['code_barres'] ?? null)) {
            return null;
        }
        $codes = [$x['code_barres']];
        foreach (is_array($x['codes_barres'] ?? null) ? $x['codes_barres'] : [] as $c) {
            if (self::codeValide($c) && !in_array($c, $codes, true) && count($codes) < self::CODES_MAX) {
                $codes[] = $c;
            }
        }
        $nomAr = self::texte($x['nom_ar'] ?? null, Tendance::NOM_AR_MAX);
        return [
            'codes' => $codes,
            'nom' => self::texte($x['nom'] ?? null, self::NOM_MAX),
            'nom_ar' => $nomAr !== null && Tendance::estArabe($nomAr) ? $nomAr : null,
            'marque' => self::texte($x['marque'] ?? null, self::MARQUE_MAX),
            'categorie' => self::texte($x['categorie'] ?? null, self::CATEGORIE_MAX),
            'taille' => self::texte($x['taille'] ?? null, self::TAILLE_MAX),
            'couleur' => self::texte($x['couleur'] ?? null, self::COULEUR_MAX),
        ];
    }

    /**
     * Applique les fiches [produits] du poste [poste]. Renvoie {crees, completes}.
     * [rayons] : rayons préparés (Rayons::preparer), lus au premier produit à ranger.
     */
    public static function appliquer(PDO $db, $poste, array $produits, array $rayons = null)
    {
        $fiches = array_values(array_filter(array_map([self::class, 'lire'], $produits)));
        if (!$fiches) {
            return ['crees' => 0, 'completes' => 0];
        }
        $tous = [];
        foreach ($fiches as $f) {
            $tous = array_merge($tous, $f['codes']);
        }
        $ids = Tendance::produitsParCodeBarres($db, $tous);
        $crees = 0;
        $completes = 0;
        $db->beginTransaction();
        try {
            foreach ($fiches as $f) {
                $id = null;
                foreach ($f['codes'] as $c) {
                    if (isset($ids[$c])) {
                        $id = $ids[$c];
                        break;
                    }
                }
                if ($id === null) {
                    if ($f['nom'] === null) {
                        continue;
                    }
                    $rayons = $rayons ?? Rayons::preparer($db);
                    $id = self::creer($db, $poste, $f, $rayons);
                    foreach ($f['codes'] as $c) {
                        $ids[$c] = $id;
                    }
                    $crees++;
                } elseif (self::completer($db, $poste, $id, $f, $ids, $rayons)) {
                    $completes++;
                }
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        return ['crees' => $crees, 'completes' => $completes];
    }

    /** Range le produit [id] : rayon, exclu (porc / alcool), score, d'après son nom et sa catégorie. */
    public static function ranger(PDO $db, $id, $nom, $categorie, $photo, $codes, array $rayons)
    {
        $algerien = false;
        foreach ($codes as $c) {
            $algerien = $algerien || strpos($c, '613') === 0;
        }
        $p = $db->query('SELECT consultations, points_tendance FROM catalog_product WHERE id = ' . (int)$id)->fetch(PDO::FETCH_ASSOC);
        $db->prepare('UPDATE catalog_product SET rayon_code = ?, exclu = ?, score = ?, classe_le = ? WHERE id = ?')
           ->execute([
               Rayons::classer($nom, $categorie, $rayons),
               Rayons::estExclu($nom, $categorie) ? 1 : 0,
               Rayons::score($p['consultations'], $algerien, trim((string)$photo) !== '', $p['points_tendance']),
               date('Y-m-d H:i:s'),
               $id,
           ]);
    }

    private static function creer(PDO $db, $poste, array $f, array $rayons)
    {
        $marque = $f['marque'] === null ? null : self::idParNom($db, 'catalog_brand', $f['marque']);
        $categorie = $f['categorie'] === null ? null : self::idParNom($db, 'catalog_category', $f['categorie']);
        $db->prepare('INSERT INTO catalog_product (code_produit, nom, nom_ar, nom_ar_poste, brand_id, category_id, taille, couleur,
                      source_id, contribue_par, a_confirmer, confirmation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 1, ?)')
           ->execute([$f['codes'][0], $f['nom'], $f['nom_ar'], $f['nom_ar'] === null ? null : $poste, $marque, $categorie,
                      $f['taille'] ?? null, $f['couleur'] ?? null, $poste, Confirmations::depart()]);
        $id = (int)$db->lastInsertId();
        $code = $db->prepare('INSERT INTO catalog_product_barcode (product_id, barcode, is_primary, source_id) VALUES (?, ?, ?, 1)');
        foreach ($f['codes'] as $i => $c) {
            $code->execute([$id, $c, $i === 0 ? 1 : 0]);
        }
        self::ranger($db, $id, $f['nom'], (string)$f['categorie'], null, $f['codes'], $rayons);
        self::noter($db, $id, $poste, 'cree', array_keys(array_filter([
            'nom' => true, 'nom_ar' => $f['nom_ar'] !== null, 'marque' => $marque !== null,
            'categorie' => $categorie !== null, 'taille' => ($f['taille'] ?? null) !== null,
            'couleur' => ($f['couleur'] ?? null) !== null, 'codes_barres' => count($f['codes']) > 1,
        ])));
        return $id;
    }

    /** Complète les champs vides du produit [id] ; vrai si quelque chose a changé. */
    private static function completer(PDO $db, $poste, $id, array $f, array &$ids, array &$rayons = null)
    {
        $s = $db->prepare("SELECT p.nom, p.nom_ar, p.brand_id, p.category_id, p.taille, p.couleur, p.photo, p.rayon_code,
                                  COALESCE(c.nom, '') AS categorie
                           FROM catalog_product p LEFT JOIN catalog_category c ON c.id = p.category_id WHERE p.id = ?");
        $s->execute([$id]);
        $p = $s->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            return false;
        }
        $maj = [];
        $champs = [];
        if (trim((string)$p['nom']) === '' && $f['nom'] !== null) {
            $maj['nom'] = $f['nom'];
            $champs[] = 'nom';
        }
        if (trim((string)$p['nom_ar']) === '' && $f['nom_ar'] !== null) {
            $maj['nom_ar'] = $f['nom_ar'];
            $maj['nom_ar_poste'] = $poste;
            $champs[] = 'nom_ar';
        }
        if ($p['brand_id'] === null && $f['marque'] !== null) {
            $maj['brand_id'] = self::idParNom($db, 'catalog_brand', $f['marque']);
            $champs[] = 'marque';
        }
        // « Sans Categorie » vaut une catégorie vide.
        $sansCategorie = $p['category_id'] === null || $p['categorie'] === 'Sans Categorie';
        if ($sansCategorie && $f['categorie'] !== null) {
            $maj['category_id'] = self::idParNom($db, 'catalog_category', $f['categorie']);
            $champs[] = 'categorie';
        }
        foreach (['taille', 'couleur'] as $champ) {
            if (trim((string)$p[$champ]) === '' && ($f[$champ] ?? null) !== null) {
                $maj[$champ] = $f[$champ];
                $champs[] = $champ;
            }
        }
        $code = $db->prepare('INSERT INTO catalog_product_barcode (product_id, barcode, is_primary, source_id) VALUES (?, ?, 0, 1)');
        $ajoutes = 0;
        foreach ($f['codes'] as $c) {
            if (!isset($ids[$c])) {
                $code->execute([$id, $c]);
                $ids[$c] = $id;
                $ajoutes++;
            }
        }
        if ($ajoutes > 0) {
            $champs[] = 'codes_barres';
        }
        if (!$champs) {
            return false;
        }
        if ($maj) {
            $sets = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($maj)));
            $db->prepare("UPDATE catalog_product SET $sets WHERE id = ?")->execute(array_merge(array_values($maj), [$id]));
        }
        // Sans rayon jusque-là, un nom ou une catégorie reçus peuvent lui en donner un.
        if ($p['rayon_code'] === null && (isset($maj['nom']) || isset($maj['category_id']))) {
            $rayons = $rayons ?? Rayons::preparer($db);
            $nom = $maj['nom'] ?? (string)$p['nom'];
            $categorie = isset($maj['category_id']) ? $f['categorie'] : ($p['categorie'] === 'Sans Categorie' ? '' : $p['categorie']);
            self::ranger($db, $id, $nom, $categorie, $p['photo'], $f['codes'], $rayons);
        }
        self::noter($db, $id, $poste, 'complete', $champs);
        return true;
    }
}

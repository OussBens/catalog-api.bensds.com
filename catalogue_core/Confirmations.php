<?php
/**
 * Confirmation des produits créés par les boutiques (Contributions::creer) : un produit que le catalogue ne
 * connaissait pas commence « à confirmer », avec un score de départ (2). Les autres boutiques qui scannent ce
 * code-barres répondent « c'est bien ce produit » (+1) ou « ce n'est pas ça » (−1) :
 *
 *   score = départ + somme des votes (un vote par poste et par produit, le dernier remplace le précédent ;
 *           la boutique qui l'a créé ne vote pas) ;
 *   score ≥ seuil (5)       → confirmé pour de bon (a_confirmer = 0, confirme_le) : proposé dans les rayons ;
 *   score ≤ suppression (0) → supprimé du catalogue (codes-barres, ventes et votes avec).
 *
 * Tant qu'il est à confirmer, le produit n'est proposé ni dans les rayons ni dans les tendances ; il répond à
 * la recherche par code-barres (c'est là que les boutiques votent). Réglages du .env : CONFIRMATION_DEPART,
 * CONFIRMATION_SEUIL, CONFIRMATION_SUPPRESSION.
 */
class Confirmations
{
    const DEPART = 2;
    const SEUIL = 5;
    const SUPPRESSION = 0;

    private static function env($cle, $defaut)
    {
        $v = trim((string)($_ENV[$cle] ?? ''));
        return preg_match('/^-?\d+$/', $v) ? (int)$v : $defaut;
    }

    public static function depart()
    {
        return self::env('CONFIRMATION_DEPART', self::DEPART);
    }

    public static function seuil()
    {
        return self::env('CONFIRMATION_SEUIL', self::SEUIL);
    }

    public static function suppression()
    {
        return self::env('CONFIRMATION_SUPPRESSION', self::SUPPRESSION);
    }

    private static function mysql(PDO $db)
    {
        return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /**
     * Les votes du poste [poste] : [{code_barres, vote: 1 | -1}]. Ignorés : code-barres inconnu, produit déjà
     * confirmé (ou jamais à confirmer), vote de la boutique qui l'a créé. Renvoie {votes, confirmes, supprimes}.
     */
    public static function voter(PDO $db, $poste, array $votes)
    {
        $lus = [];
        foreach ($votes as $v) {
            $code = is_array($v) ? ($v['code_barres'] ?? null) : null;
            $vote = is_array($v) ? ($v['vote'] ?? null) : null;
            if (Contributions::codeValide($code) && ($vote === 1 || $vote === -1)) {
                $lus[$code] = $vote; // le dernier vote envoyé pour un code l'emporte
            }
        }
        $resultat = ['votes' => 0, 'confirmes' => 0, 'supprimes' => 0];
        if (!$lus) {
            return $resultat;
        }
        $ids = Tendance::produitsParCodeBarres($db, array_keys($lus));
        $upsert = $db->prepare(self::mysql($db)
            ? 'INSERT INTO catalog_confirmation (product_id, poste, vote, le) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE vote = VALUES(vote), le = VALUES(le)'
            : 'INSERT INTO catalog_confirmation (product_id, poste, vote, le) VALUES (?, ?, ?, ?)
               ON CONFLICT (product_id, poste) DO UPDATE SET vote = excluded.vote, le = excluded.le');
        $produit = $db->prepare('SELECT id, nom, a_confirmer, contribue_par FROM catalog_product WHERE id = ?');
        $maintenant = date('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            foreach ($lus as $code => $vote) {
                if (!isset($ids[$code])) {
                    continue;
                }
                $produit->execute([$ids[$code]]);
                $p = $produit->fetch(PDO::FETCH_ASSOC);
                if (!$p || (int)$p['a_confirmer'] !== 1 || $p['contribue_par'] === $poste) {
                    continue;
                }
                $id = (int)$p['id'];
                $upsert->execute([$id, $poste, $vote, $maintenant]);
                $resultat['votes']++;
                $score = self::depart() + (int)$db->query('SELECT COALESCE(SUM(vote), 0) FROM catalog_confirmation WHERE product_id = ' . $id)->fetchColumn();
                if ($score >= self::seuil()) {
                    self::confirmer($db, $id, $poste, $score);
                    $resultat['confirmes']++;
                } elseif ($score <= self::suppression()) {
                    self::retirer($db, $id, $poste, (string)$p['nom']);
                    $resultat['supprimes']++;
                } else {
                    $db->prepare('UPDATE catalog_product SET confirmation = ? WHERE id = ?')->execute([$score, $id]);
                }
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        return $resultat;
    }

    private static function noter(PDO $db, $produitId, $poste, $action, $champs)
    {
        $db->prepare('INSERT INTO catalog_contribution (product_id, poste, action, champs, cree_le) VALUES (?, ?, ?, ?, ?)')
           ->execute([$produitId, $poste, $action, $champs, date('Y-m-d H:i:s')]);
    }

    /**
     * Confirme pour de bon le produit [id] (par les votes, ou à la main : [poste] `ADMIN`) : proposé dans son
     * rayon dès maintenant. [score] : le score atteint (le score actuel à la main). À appeler dans une transaction.
     */
    public static function confirmer(PDO $db, $id, $poste, $score = null)
    {
        $db->prepare('UPDATE catalog_product SET confirmation = COALESCE(?, confirmation), a_confirmer = 0, confirme_le = ? WHERE id = ?')
           ->execute([$score, date('Y-m-d H:i:s'), (int)$id]);
        self::noter($db, (int)$id, $poste, 'confirme', $score === null ? 'admin' : (string)$score);
    }

    /** Supprime du catalogue le produit [id] (par les votes, ou à la main : [poste] `ADMIN`), noté avec son [nom]. */
    public static function retirer(PDO $db, $id, $poste, $nom)
    {
        self::supprimer($db, (int)$id);
        self::noter($db, (int)$id, $poste, 'supprime', mb_substr((string)$nom, 0, 190));
    }

    /** Retire le produit [id] du catalogue, avec ses codes-barres, ses ventes et ses votes. */
    public static function supprimer(PDO $db, $id)
    {
        foreach (['catalog_product_barcode', 'catalog_vente_jour', 'catalog_confirmation'] as $table) {
            $db->prepare("DELETE FROM $table WHERE product_id = ?")->execute([$id]);
        }
        $db->prepare('DELETE FROM catalog_product WHERE id = ?')->execute([$id]);
    }
}

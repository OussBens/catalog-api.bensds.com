<?php
/**
 * Rayons du catalogue (sections proposées à l'accueil de CaisseDZ Desktop).
 * Installation idempotente : bouton « Installer / mettre à jour » de admin_rayons.php,
 * et base SQLite des tests.
 *
 *   catalog_rayon            ~15 rayons : noms FR/AR/EN, icône Lucide, ordre, mots-clés de tri
 *   catalog_product (+)      rayon_code (rayon calculé), exclu (porc / alcool),
 *                            consultations (recherches par code-barres), score (classement), classe_le
 *
 * Les 50 000 catégories brutes (Open Food / Beauty Facts) restent telles quelles :
 * le rayon est calculé à partir de leur nom et du nom du produit (Rayons::classer).
 */
class RayonsSchema
{
    public static function installer(PDO $db)
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $moteur = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS catalog_rayon (
            code VARCHAR(40) PRIMARY KEY,
            nom_fr VARCHAR(80) NOT NULL,
            nom_ar VARCHAR(80) NOT NULL,
            nom_en VARCHAR(80) NOT NULL,
            icone VARCHAR(40) NOT NULL DEFAULT 'package',
            ordre INT NOT NULL DEFAULT 0,
            prioritaire TINYINT NOT NULL DEFAULT 0,
            secours TINYINT NOT NULL DEFAULT 0,
            mots_cles TEXT NOT NULL,
            actif TINYINT NOT NULL DEFAULT 1
        )$moteur");

        self::ajouterColonne($db, 'catalog_product', 'rayon_code VARCHAR(40) NULL');
        self::ajouterColonne($db, 'catalog_product', 'exclu TINYINT NOT NULL DEFAULT 0');
        self::ajouterColonne($db, 'catalog_product', 'consultations INT NOT NULL DEFAULT 0');
        self::ajouterColonne($db, 'catalog_product', 'score INT NOT NULL DEFAULT 0');
        self::ajouterColonne($db, 'catalog_product', 'classe_le DATETIME NULL');
        try {
            $db->exec('CREATE INDEX idx_product_rayon ON catalog_product (rayon_code, exclu, score)');
        } catch (Exception $e) {
            // Déjà créé.
        }

        $existe = $db->prepare('SELECT COUNT(*) FROM catalog_rayon WHERE code = ?');
        $insert = $db->prepare('INSERT INTO catalog_rayon
            (code, nom_fr, nom_ar, nom_en, icone, ordre, prioritaire, secours, mots_cles, actif)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
        foreach (Rayons::PAR_DEFAUT as $i => $r) {
            $existe->execute([$r['code']]);
            if ((int)$existe->fetchColumn() === 0) {
                $insert->execute([
                    $r['code'], $r['fr'], $r['ar'], $r['en'], $r['icone'], ($i + 1) * 10,
                    empty($r['prioritaire']) ? 0 : 1, empty($r['secours']) ? 0 : 1,
                    implode("\n", $r['mots']),
                ]);
            }
        }
    }

    private static function ajouterColonne(PDO $db, $table, $definition)
    {
        $colonne = strtok($definition, ' ');
        try {
            $db->query("SELECT $colonne FROM $table LIMIT 1");
            return false;
        } catch (Exception $e) {
            $db->exec("ALTER TABLE $table ADD COLUMN $definition");
            return true;
        }
    }
}

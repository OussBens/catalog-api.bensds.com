<?php
/**
 * Rayons du catalogue (sections proposées à l'accueil de CaisseDZ Desktop).
 * Installation idempotente : bouton « Installer / mettre à jour » de admin_rayons.php,
 * et base SQLite des tests.
 *
 *   catalog_rayon            ~15 rayons : noms FR/AR/EN, icône Lucide, ordre, mots-clés de tri
 *   catalog_product (+)      rayon_code (rayon calculé), exclu (porc / alcool),
 *                            consultations (recherches par code-barres), score (classement), classe_le,
 *                            nom_ar, tendance (postes_90j, ventes_90j, points_tendance, tendance, tendance_le…)
 *   catalog_vente_jour       tickets par produit, poste et jour (POST /tendance)
 *   catalog_contribution     produits créés ou complétés par les boutiques (Contributions.php)
 *   catalog_poste            dernier envoi de chaque poste
 *
 * Les 50 000 catégories brutes (Open Food / Beauty Facts) restent telles quelles :
 * le rayon est calculé à partir de leur nom et du nom du produit (Rayons::classer).
 */
class RayonsSchema
{
    const COLONNES_PRODUIT = [
        'rayon_code VARCHAR(40) NULL',
        'exclu TINYINT NOT NULL DEFAULT 0',
        'consultations INT NOT NULL DEFAULT 0',
        'score INT NOT NULL DEFAULT 0',
        'classe_le DATETIME NULL',
        'nom_ar VARCHAR(200) NULL',
        'nom_ar_poste VARCHAR(20) NULL',
        'postes_90j INT NOT NULL DEFAULT 0',
        'ventes_90j INT NOT NULL DEFAULT 0',
        'points_tendance INT NOT NULL DEFAULT 0',
        'tendance TINYINT NOT NULL DEFAULT 0',
        'tendance_le DATETIME NULL',
        'tendance_maj DATE NULL',
        'contribue_par VARCHAR(20) NULL',
    ];

    const TABLES = ['catalog_rayon', 'catalog_vente_jour', 'catalog_contribution', 'catalog_poste'];

    /** Toutes les tables et colonnes sont-elles là ? (Sinon : installer.) */
    public static function aJour(PDO $db)
    {
        try {
            $colonnes = implode(', ', array_map(function ($d) { return strtok($d, ' '); }, self::COLONNES_PRODUIT));
            $db->query("SELECT $colonnes FROM catalog_product LIMIT 1");
            foreach (self::TABLES as $t) {
                $db->query("SELECT 1 FROM $t LIMIT 1");
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

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

        // Colonnes de catalog_product (tri, tendance, nom arabe, contributions) : les manquantes sont ajoutées
        // d'un seul ALTER TABLE (une seule reconstruction de la table de ~615 000 produits).
        self::ajouterColonnes($db, 'catalog_product', self::COLONNES_PRODUIT);
        $auto = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->exec("CREATE TABLE IF NOT EXISTS catalog_contribution (
            id $auto,
            product_id INT NOT NULL,
            poste VARCHAR(20) NOT NULL,
            action VARCHAR(10) NOT NULL,
            champs VARCHAR(200) NOT NULL DEFAULT '',
            cree_le DATETIME NOT NULL
        )$moteur");
        $db->exec("CREATE TABLE IF NOT EXISTS catalog_poste (
            poste VARCHAR(20) PRIMARY KEY,
            premier_le DATETIME NOT NULL,
            dernier_le DATETIME NOT NULL,
            envois INT NOT NULL DEFAULT 0
        )$moteur");
        $db->exec("CREATE TABLE IF NOT EXISTS catalog_vente_jour (
            product_id INT NOT NULL,
            poste VARCHAR(20) NOT NULL,
            jour DATE NOT NULL,
            tickets INT NOT NULL,
            PRIMARY KEY (product_id, poste, jour)
        )$moteur");
        $index = [
            'idx_product_rayon ON catalog_product (rayon_code, exclu, score)',
            'idx_product_tendance ON catalog_product (rayon_code, tendance, tendance_le)',
            'idx_product_points ON catalog_product (points_tendance)',
            'idx_vente_jour ON catalog_vente_jour (jour)',
            'idx_contribution_poste ON catalog_contribution (poste, action)',
            'idx_contribution_le ON catalog_contribution (cree_le)',
        ];
        foreach ($index as $i) {
            try {
                $db->exec("CREATE INDEX $i");
            } catch (Exception $e) {
                // Déjà créé.
            }
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

    /** Ajoute à [table] les colonnes [definitions] qui manquent : un seul ALTER TABLE en MySQL. */
    private static function ajouterColonnes(PDO $db, $table, array $definitions)
    {
        $manquantes = [];
        foreach ($definitions as $definition) {
            $colonne = strtok($definition, ' ');
            try {
                $db->query("SELECT $colonne FROM $table LIMIT 1");
            } catch (Exception $e) {
                $manquantes[] = $definition;
            }
        }
        if (!$manquantes) {
            return;
        }
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $db->exec("ALTER TABLE $table " . implode(', ', array_map(function ($d) { return "ADD COLUMN $d"; }, $manquantes)));
        } else {
            foreach ($manquantes as $d) {
                $db->exec("ALTER TABLE $table ADD COLUMN $d");
            }
        }
    }
}

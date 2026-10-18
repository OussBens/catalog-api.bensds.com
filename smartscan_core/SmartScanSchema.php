<?php
/**
 * Tables Smart Scan (préfixe ss_) dans la base existante du catalogue.
 * Installation idempotente : bouton « Installer / mettre à jour » de
 * l'administration (admin_smartscan.php), et base SQLite des tests.
 *
 *   ss_forfaits     produits Smart Scan par plateforme : essai gratuit et offres
 *                   payantes (ESSAI_DESKTOP, DESKTOP_6M, ESSAI_MOBILE, MOBILE_12M…)
 *   ss_clients      clients (un compteur mensuel par client), desktop ou mobile
 *   ss_postes       installations Caisse DZ (licence) rattachées à un client
 *   ss_abonnements  forfaits payants souscrits (début, fin, statut)
 *   ss_scans        journal de chaque scan (sert aussi au décompte)
 *   ss_demandes     demandes d'offre envoyées depuis l'application
 *   ss_essais_utilises  essais des comptes mobiles supprimés (empreinte du numéro)
 */
class SmartScanSchema
{
    public static function installer(PDO $db)
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $moteur = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $tables = [
            "CREATE TABLE IF NOT EXISTS ss_forfaits (
                code VARCHAR(40) PRIMARY KEY,
                nom VARCHAR(80) NOT NULL,
                prix_da INT NOT NULL DEFAULT 0,
                quota_mensuel INT NOT NULL,
                duree_mois INT NOT NULL DEFAULT 0,
                actif TINYINT NOT NULL DEFAULT 1,
                plateforme VARCHAR(10) NOT NULL DEFAULT 'desktop',
                essai TINYINT NOT NULL DEFAULT 0
            )$moteur",
            "CREATE TABLE IF NOT EXISTS ss_clients (
                id $id,
                nom VARCHAR(120) NOT NULL,
                contact VARCHAR(120) NULL,
                statut VARCHAR(20) NOT NULL DEFAULT 'actif',
                quota_exception INT NULL,
                quota_exception_mois CHAR(7) NULL,
                cree_le DATETIME NOT NULL,
                plateforme VARCHAR(10) NOT NULL DEFAULT 'desktop',
                essai_debut DATE NULL
            )$moteur",
            "CREATE TABLE IF NOT EXISTS ss_postes (
                device_id VARCHAR(64) PRIMARY KEY,
                client_id INT NOT NULL,
                entreprise VARCHAR(120) NULL,
                palier_licence VARCHAR(20) NULL,
                revoque TINYINT NOT NULL DEFAULT 0,
                premier_appel DATETIME NOT NULL,
                dernier_appel DATETIME NOT NULL
            )$moteur",
            "CREATE TABLE IF NOT EXISTS ss_abonnements (
                id $id,
                client_id INT NOT NULL,
                forfait_code VARCHAR(40) NOT NULL,
                debut DATE NOT NULL,
                fin DATE NOT NULL,
                statut VARCHAR(20) NOT NULL DEFAULT 'active',
                prix_da INT NOT NULL DEFAULT 0,
                note VARCHAR(255) NULL,
                cree_le DATETIME NOT NULL
            )$moteur",
            "CREATE TABLE IF NOT EXISTS ss_scans (
                id $id,
                client_id INT NOT NULL,
                device_id VARCHAR(64) NOT NULL,
                cree_le DATETIME NOT NULL,
                statut VARCHAR(20) NOT NULL,
                duree_ms INT NULL,
                modele VARCHAR(60) NULL,
                taille_image INT NULL,
                empreinte_image CHAR(64) NULL,
                extractions INT NOT NULL DEFAULT 0,
                code_erreur VARCHAR(60) NULL,
                message_erreur VARCHAR(255) NULL,
                erreur_extraction VARCHAR(60) NULL
            )$moteur",
            // Comptes mobiles supprimés : empreinte du numéro et début de l'essai déjà utilisé,
            // pour qu'une réinscription avec le même numéro ne redonne pas d'essai gratuit.
            "CREATE TABLE IF NOT EXISTS ss_essais_utilises (
                empreinte CHAR(64) PRIMARY KEY,
                essai_debut DATE NOT NULL,
                supprime_le DATETIME NOT NULL
            )$moteur",
            "CREATE TABLE IF NOT EXISTS ss_demandes (
                id $id,
                client_id INT NOT NULL,
                device_id VARCHAR(64) NULL,
                forfait_code VARCHAR(40) NOT NULL,
                cree_le DATETIME NOT NULL,
                statut VARCHAR(20) NOT NULL DEFAULT 'nouvelle'
            )$moteur",
        ];
        foreach ($tables as $sql) {
            $db->exec($sql);
        }

        $index = [
            'CREATE INDEX idx_ss_scans_client ON ss_scans (client_id, statut, cree_le)',
            'CREATE INDEX idx_ss_abonnements_client ON ss_abonnements (client_id, statut)',
            'CREATE INDEX idx_ss_postes_client ON ss_postes (client_id)',
        ];
        foreach ($index as $sql) {
            try {
                $db->exec($sql);
            } catch (Exception $e) {
                // Index déjà présent (MySQL n'a pas de CREATE INDEX IF NOT EXISTS).
            }
        }

        // Colonnes ajoutées après la première version (bases déjà installées).
        $migration = self::ajouterColonne($db, 'ss_forfaits', "plateforme VARCHAR(10) NOT NULL DEFAULT 'desktop'");
        self::ajouterColonne($db, 'ss_forfaits', 'essai TINYINT NOT NULL DEFAULT 0');
        self::ajouterColonne($db, 'ss_clients', "plateforme VARCHAR(10) NOT NULL DEFAULT 'desktop'");
        self::ajouterColonne($db, 'ss_clients', 'essai_debut DATE NULL');
        if ($migration) {
            // Anciens forfaits (60 scans gratuits sans limite de durée, offre 200) : remplacés
            // par l'essai de 3 mois et les offres 6 / 12 mois. Les abonnements en cours restent valables.
            $db->exec("UPDATE ss_forfaits SET actif = 0 WHERE code IN ('STANDARD', 'SMART_SCAN_200')");
        }

        // Forfaits de départ — modifiables ensuite depuis l'administration.
        // code, nom, prix (DA), scans par mois, durée (mois), plateforme, essai
        $forfaits = [
            ['ESSAI_DESKTOP', 'Essai Smart Scan', 0, 60, 3, 'desktop', 1],
            ['DESKTOP_6M', 'Smart Scan 6 mois', 3000, 60, 6, 'desktop', 0],
            ['DESKTOP_12M', 'Smart Scan 1 an', 5000, 60, 12, 'desktop', 0],
            ['ESSAI_MOBILE', 'Essai Smart Scan', 0, 30, 3, 'mobile', 1],
            ['MOBILE_6M', 'Smart Scan 6 mois', 2000, 30, 6, 'mobile', 0],
            ['MOBILE_12M', 'Smart Scan 1 an', 3000, 30, 12, 'mobile', 0],
        ];
        $existe = $db->prepare('SELECT COUNT(*) FROM ss_forfaits WHERE code = ?');
        $insert = $db->prepare('INSERT INTO ss_forfaits (code, nom, prix_da, quota_mensuel, duree_mois, plateforme, essai, actif)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
        foreach ($forfaits as $f) {
            $existe->execute([$f[0]]);
            if ((int)$existe->fetchColumn() === 0) {
                $insert->execute($f);
            }
        }
    }

    /** Ajoute une colonne si elle manque. Renvoie true si elle vient d'être ajoutée. */
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

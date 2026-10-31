<?php
/**
 * Les photos de produits envoyées par les boutiques (POST /tendance/photo, CaisseDZ Mobile : raccourci « Photo
 * produit » et fiche d'un produit sans photo) :
 *
 *   produit sans photo   → la photo est la sienne tout de suite (notée dans catalog_contribution, champ photo) ;
 *   produit avec photo   → elle est proposée (catalog_photo_proposee) : le tableau de bord BENS l'accepte ou la
 *                          refuse (Admin::photos, Admin::photo) ; une nouvelle proposition du même poste pour le
 *                          même produit remplace la précédente ;
 *   code-barres inconnu  → le produit est créé avec le nom envoyé, « à confirmer » comme les autres contributions
 *                          (Contributions::appliquer), et prend la photo.
 *
 * Les fichiers vont dans uploads/photos/AAAA/MM, comme ceux de POST /upload-photo.
 */
class Photos
{
    /** Une photo de l'app fait 1000 px au plus (≈ 200 Ko) : 1,5 Mo laisse de la marge. */
    const OCTETS_MAX = 1572864;
    /** Photos envoyées par un poste en un jour (ajoutées ou proposées). */
    const PAR_JOUR_MAX = 300;
    const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Le poste d'un compte mobile : M et les chiffres du numéro (20 caractères au plus, comme ceux de Desktop). */
    public static function posteMobile(array $compte)
    {
        $chiffres = preg_replace('/\D/', '', (string)($compte['telephone'] ?? ''));
        return substr($chiffres !== '' ? 'M' . $chiffres : 'M' . preg_replace('/[^A-Za-z0-9]/', '', (string)$compte['uid']), 0, 20);
    }

    /**
     * Enregistre la photo envoyée [corps] {code_barres, nom, photo: base64 ou data URL} du poste [poste].
     * [racine] : le dossier uploads, [urlBase] : son adresse publique. Renvoie {resultat: ajoutee | proposee | cree,
     * photo: son adresse}.
     */
    public static function envoyer(PDO $db, $poste, $corps, $racine, $urlBase)
    {
        $code = is_array($corps) ? ($corps['code_barres'] ?? null) : null;
        if (!Contributions::codeValide($code)) {
            throw new Exception('code_barres : un code EAN / UPC', 400);
        }
        $octets = self::image($corps['photo'] ?? null);
        if (self::envoyeesAujourdhui($db, $poste) >= self::PAR_JOUR_MAX) {
            throw new Exception('Trop de photos envoyées aujourd\'hui', 429);
        }

        $id = Tendance::produitsParCodeBarres($db, [$code])[$code] ?? null;
        $cree = false;
        if ($id === null) {
            Contributions::appliquer($db, $poste, [['code_barres' => $code, 'nom' => $corps['nom'] ?? null]]);
            $id = Tendance::produitsParCodeBarres($db, [$code])[$code] ?? null;
            if ($id === null) {
                throw new Exception('Produit inconnu du catalogue : son nom est requis', 400);
            }
            $cree = true;
        }
        $s = $db->prepare('SELECT code_produit, photo, consultations, points_tendance FROM catalog_product WHERE id = ?');
        $s->execute([$id]);
        $p = $s->fetch(PDO::FETCH_ASSOC);
        $url = self::enregistrer($octets, $racine, $urlBase);

        if ($cree || trim((string)$p['photo']) === '') {
            $db->prepare('UPDATE catalog_product SET photo = ?, score = ? WHERE id = ?')->execute([
                $url,
                Rayons::score($p['consultations'], strpos($code, '613') === 0, true, $p['points_tendance']),
                $id,
            ]);
            Contributions::noter($db, $id, $poste, 'complete', ['photo']);
            return ['resultat' => $cree ? 'cree' : 'ajoutee', 'photo' => $url];
        }

        $avant = $db->prepare('SELECT id, photo FROM catalog_photo_proposee WHERE product_id = ? AND poste = ?');
        $avant->execute([$id, $poste]);
        foreach ($avant->fetchAll(PDO::FETCH_ASSOC) as $a) {
            self::supprimerFichier($a['photo'], $racine);
            $db->prepare('DELETE FROM catalog_photo_proposee WHERE id = ?')->execute([$a['id']]);
        }
        $db->prepare('INSERT INTO catalog_photo_proposee (product_id, poste, photo, cree_le) VALUES (?, ?, ?, ?)')
           ->execute([$id, $poste, $url, date('Y-m-d H:i:s')]);
        return ['resultat' => 'proposee', 'photo' => $url];
    }

    /** Les octets de l'image envoyée (base64, avec ou sans « data:image/…;base64, »), vérifiés. */
    private static function image($photo)
    {
        if (!is_string($photo) || $photo === '') {
            throw new Exception('photo : une image JPG, PNG ou WEBP en base64', 400);
        }
        $virgule = strpos($photo, ',');
        if (strpos($photo, 'data:') === 0 && $virgule !== false) {
            $photo = substr($photo, $virgule + 1);
        }
        $octets = base64_decode($photo, true);
        if ($octets === false || $octets === '') {
            throw new Exception('photo : une image JPG, PNG ou WEBP en base64', 400);
        }
        if (strlen($octets) > self::OCTETS_MAX) {
            throw new Exception('Photo trop volumineuse (1,5 Mo au plus)', 413);
        }
        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($octets);
        if (!isset(self::TYPES[$type]) || @getimagesizefromstring($octets) === false) {
            throw new Exception('Format non supporté. Utilisez JPG, PNG ou WEBP', 400);
        }
        return $octets;
    }

    private static function envoyeesAujourdhui(PDO $db, $poste)
    {
        $jour = date('Y-m-d') . ' 00:00:00';
        $s = $db->prepare("SELECT (SELECT COUNT(*) FROM catalog_contribution WHERE poste = ? AND champs = 'photo' AND cree_le >= ?)
                                + (SELECT COUNT(*) FROM catalog_photo_proposee WHERE poste = ? AND cree_le >= ?)");
        $s->execute([$poste, $jour, $poste, $jour]);
        return (int)$s->fetchColumn();
    }

    /** Écrit l'image dans [racine]/photos/AAAA/MM et renvoie son adresse publique. */
    private static function enregistrer($octets, $racine, $urlBase)
    {
        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($octets);
        $chemin = 'photos/' . date('Y') . '/' . date('m') . '/' . time() . '_' . bin2hex(random_bytes(8)) . '.' . self::TYPES[$type];
        $dossier = dirname($racine . '/' . $chemin);
        if (!is_dir($dossier) && !mkdir($dossier, 0755, true) && !is_dir($dossier)) {
            throw new Exception('Erreur lors de l\'enregistrement de la photo', 500);
        }
        if (file_put_contents($racine . '/' . $chemin, $octets) === false) {
            throw new Exception('Erreur lors de l\'enregistrement de la photo', 500);
        }
        return rtrim($urlBase, '/') . '/uploads/' . $chemin;
    }

    /** Supprime le fichier d'une photo, seulement s'il est dans notre dossier uploads/photos. */
    public static function supprimerFichier($url, $racine)
    {
        $i = strpos((string)$url, '/uploads/photos/');
        if ($i === false) {
            return;
        }
        $relatif = substr($url, $i + strlen('/uploads/'));
        if (strpos($relatif, '..') !== false) {
            return;
        }
        @unlink($racine . '/' . $relatif);
    }
}

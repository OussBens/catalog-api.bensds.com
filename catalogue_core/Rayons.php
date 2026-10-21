<?php
/**
 * Rayons du catalogue : tri des produits par rayon, exclusion porc / alcool, classement
 * (« tendance ») et requêtes servies à CaisseDZ Desktop (catalogue_core/routes.php).
 *
 * Tri d'un produit (Rayons::classer) : d'abord le nom de sa catégorie brute, sinon son nom.
 *   1. un rayon « prioritaire » (Bébé, Animalerie, Café & thé : rangés ailleurs par Open Food Facts) l'emporte
 *      dès qu'un de ses mots-clés apparaît ;
 *   2. sinon le rayon dont un mot-clé apparaît le plus tôt (les catégories Open Food Facts vont du
 *      plus général au plus précis : « Snacks, Biscuits, Madeleines au chocolat » → Biscuits) ;
 *   3. sinon un rayon « de secours » (Épicerie générale) ;
 *   4. sinon aucun rayon (le produit n'est pas proposé).
 * Un mot-clé correspond au début d'un mot (« biscuit » trouve « biscuits »), sans accents ni majuscules.
 *
 * Classement : consultations × 100 (recherches par code-barres de toutes les installations)
 * + 50 si code-barres algérien (613…) + 10 s'il a une photo. Chaque rayon propose ses
 * PLAFOND premiers produits.
 */
class Rayons
{
    const PLAFOND = 2000;
    const LOT_PAGE_MAX = 500;

    /**
     * Rayons créés à l'installation (modifiables ensuite dans admin_rayons.php). Mots-clés : mot entier,
     * sauf `*` final (début de mot : « biscuit* » trouve « biscuiterie ») ; un mot arabe est cherché partout
     * (l'article « ال » s'y colle).
     */
    const PAR_DEFAUT = [
        ['code' => 'bebe', 'fr' => 'Bébé', 'ar' => 'مستلزمات الرضع', 'en' => 'Baby', 'icone' => 'baby',
            'prioritaire' => true,
            'mots' => ['bebe', 'bebes', 'baby', 'babies', 'infant*', 'nourrisson*', 'lait infantile', 'laits infantiles', 'petit pot', 'petits pots', 'couche', 'couches', 'diaper*', 'puericulture', 'sauglings*', 'babynahrung', 'رضع', 'رضيع', 'حفاض']],
        ['code' => 'animalerie', 'fr' => 'Animalerie', 'ar' => 'مستلزمات الحيوانات', 'en' => 'Pet supplies', 'icone' => 'paw-print',
            'prioritaire' => true,
            'mots' => ['animalerie', 'aliments pour animaux', 'nourriture pour animaux', 'pet food', 'pet foods', 'petfood', 'croquette', 'croquettes', 'patee', 'patees', 'chat', 'chats', 'chaton*', 'chien', 'chiens', 'chiot*', 'cat', 'cats', 'dog', 'dogs', 'cat food', 'dog food', 'litiere*', 'katze', 'katzen', 'hund', 'hunde', 'perro', 'perros', 'gato', 'gatos', 'gatto', 'gatti', 'حيوانات', 'قطط', 'كلاب']],
        ['code' => 'parapharmacie', 'fr' => 'Parapharmacie', 'ar' => 'شبه صيدلية', 'en' => 'Health & supplements', 'icone' => 'pill',
            'mots' => ['complement alimentaire', 'complements alimentaires', 'supplement*', 'vitamin*', 'mineraux', 'protein*', 'proteine*', 'bodybuilding', 'nahrungserganzung*', 'complemento*', 'integrator*', 'parapharmacie', 'pharmacie', 'medical', 'medico', 'مكمل', 'فيتامين']],
        ['code' => 'cosmetiques', 'fr' => 'Cosmétiques & parfums', 'ar' => 'مستحضرات التجميل والعطور', 'en' => 'Cosmetics & fragrance', 'icone' => 'sparkles',
            'mots' => ['cosmeti*', 'parfum*', 'perfume*', 'fragrance*', 'eau de toilette', 'eau de parfum', 'maquillage*', 'makeup', 'make up', 'rouge a levres', 'lipstick*', 'mascara*', 'vernis', 'nail*', 'fond de teint', 'visage', 'face', 'corps', 'body', 'body care', 'laits pour le corps', 'skin care', 'skincare', 'moisturi*', 'serum*', 'beauty', 'beaute', 'anti age', 'anti rides', 'demaquill*', 'solaire*', 'sunscreen*', 'تجميل', 'عطر']],
        ['code' => 'hygiene', 'fr' => 'Hygiène & soins', 'ar' => 'النظافة والعناية', 'en' => 'Hygiene & personal care', 'icone' => 'hand-heart',
            'mots' => ['hygien*', 'shampo*', 'apres shampo*', 'conditioner*', 'douche*', 'shower*', 'bain moussant', 'savon*', 'soap*', 'dentifrice*', 'toothpaste*', 'brosse a dents', 'toothbrush*', 'bain de bouche', 'mouthwash*', 'deodor*', 'antitranspirant*', 'rasoir*', 'razor*', 'rasage', 'shaving', 'mouchoir*', 'papier toilette', 'toilet paper', 'serviette hygienique', 'serviettes hygieniques', 'tampon*', 'coton*', 'cotton', 'cheveu*', 'hair', 'body wash', 'lotion*', 'creme mains', 'hand cream', 'نظافة', 'صابون', 'شامبو', 'معجون']],
        ['code' => 'entretien', 'fr' => 'Entretien ménager', 'ar' => 'مواد التنظيف', 'en' => 'Household cleaning', 'icone' => 'spray-can',
            'mots' => ['entretien', 'menager*', 'household', 'lessive*', 'detergent*', 'laundry', 'nettoyant*', 'cleaner*', 'cleaning', 'javel', 'bleach*', 'vaisselle', 'dishwash*', 'desinfect*', 'disinfect*', 'antiseptic*', 'antiseptico*', 'assouplissant*', 'softener*', 'eponge*', 'sponge', 'sponges', 'insecticide*', 'desodorisant*', 'air freshener*', 'sac poubelle', 'sacs poubelle', 'serpilliere*', 'تنظيف', 'منظف', 'جافيل']],
        ['code' => 'cafe_the', 'fr' => 'Café & thé', 'ar' => 'القهوة والشاي', 'en' => 'Coffee & tea', 'icone' => 'coffee',
            'prioritaire' => true,
            'mots' => ['cafe', 'cafes', 'coffee*', 'kaffee', 'caffe', 'espresso*', 'cappuccino*', 'capsules de cafe', 'thes', 'the vert', 'the noir', 'the a la menthe', 'tea', 'teas', 'green tea', 'black tea', 'ice tea', 'ice teas', 'infusion*', 'tisane*', 'herbal tea*', 'chicoree', 'chocolat en poudre', 'cacao en poudre', 'قهوة', 'شاي']],
        ['code' => 'boissons', 'fr' => 'Boissons', 'ar' => 'المشروبات', 'en' => 'Drinks', 'icone' => 'cup-soda',
            'mots' => ['boisson*', 'beverage*', 'drink*', 'eau', 'eaux', 'water', 'waters', 'soda*', 'cola', 'colas', 'jus', 'juice*', 'nectar*', 'sirop*', 'syrup*', 'limonade*', 'lemonade*', 'getrank*', 'bebida*', 'bevand*', 'dranken', 'bauturi', 'مشروب', 'عصير', 'مياه']],
        ['code' => 'laitiers', 'fr' => 'Produits laitiers & œufs', 'ar' => 'مشتقات الحليب والبيض', 'en' => 'Dairy & eggs', 'icone' => 'milk',
            'mots' => ['produits laitiers', 'laitier*', 'dairies', 'dairy', 'lait', 'laits', 'milk', 'milks', 'fromage*', 'cheese*', 'yaourt*', 'yogurt*', 'yoghurt*', 'beurre', 'beurres', 'butter', 'butters', 'creme fraiche', 'cremes fraiches', 'oeuf', 'oeufs', 'egg', 'eggs', 'lben', 'leben', 'raib', 'kase', 'queso', 'quesos', 'formaggi', 'formaggio', 'milch', 'lacteo*', 'حليب', 'جبن', 'أجبان', 'ياغورت', 'زبدة', 'بيض']],
        ['code' => 'biscuits_confiserie', 'fr' => 'Biscuits, confiserie & snacks', 'ar' => 'البسكويت والحلويات', 'en' => 'Biscuits, sweets & snacks', 'icone' => 'cookie',
            'mots' => ['snack*', 'biscuit*', 'gateau*', 'cake', 'cakes', 'cookie*', 'chocolat*', 'cacao', 'cocoa', 'bonbon*', 'candy', 'candies', 'confiser*', 'confection*', 'sweets', 'chips', 'crisps', 'gaufrette*', 'wafer*', 'madeleine*', 'barre', 'barres', 'chewing gum*', 'sucrerie*', 'cracker*', 'galleta*', 'dolci', 'kekse', 'sussigkeiten', 'بسكويت', 'حلويات', 'شوكولا', 'شيبس']],
        ['code' => 'conserves_condiments', 'fr' => 'Conserves, sauces & épices', 'ar' => 'المعلبات والتوابل', 'en' => 'Canned food, sauces & spices', 'icone' => 'soup',
            'mots' => ['conserve*', 'canned', 'sauce*', 'condiment*', 'ketchup*', 'mayonnaise*', 'moutarde*', 'mustard*', 'harissa', 'epice*', 'spice*', 'vinaigre*', 'vinegar*', 'vinaigrette*', 'tomate concentree', 'concentre de tomate', 'double concentre', 'thon', 'tuna', 'sardine*', 'olive*', 'cornichon*', 'pickle*', 'bouillon*', 'soupe*', 'soup*', 'conservas', 'معلبات', 'توابل', 'صلصة', 'تونة', 'زيتون']],
        ['code' => 'surgeles', 'fr' => 'Surgelés & glaces', 'ar' => 'المجمدات والمثلجات', 'en' => 'Frozen food & ice cream', 'icone' => 'snowflake',
            'mots' => ['surgel*', 'frozen', 'glace', 'glaces', 'ice cream*', 'creme glacee', 'cremes glacees', 'sorbet*', 'tiefkuhl*', 'congelad*', 'surgelat*', 'gelato', 'gelati', 'مجمد', 'مثلجات']],
        ['code' => 'viandes_poissons', 'fr' => 'Viandes, volailles & poissons', 'ar' => 'اللحوم والأسماك', 'en' => 'Meat, poultry & fish', 'icone' => 'beef',
            'mots' => ['viande*', 'meat', 'meats', 'volaille*', 'poultry', 'poulet*', 'chicken*', 'dinde*', 'turkey', 'boeuf', 'beef', 'agneau*', 'lamb', 'veau', 'veal', 'merguez', 'cachir', 'kachir', 'charcuterie*', 'poisson*', 'fish', 'fishes', 'fruits de mer', 'seafood*', 'crevette*', 'shrimp*', 'prawn*', 'saumon*', 'salmon', 'لحم', 'لحوم', 'دجاج', 'سمك', 'أسماك']],
        ['code' => 'epicerie', 'fr' => 'Épicerie générale', 'ar' => 'البقالة العامة', 'en' => 'General grocery', 'icone' => 'shopping-basket',
            'secours' => true,
            'mots' => ['epicerie*', 'grocer*', 'aliment*', 'food', 'foods', 'plant based', 'cereal*', 'cereale*', 'pate', 'pates', 'pasta*', 'riz', 'rice', 'farine*', 'flour*', 'semoule*', 'couscous', 'sucre*', 'sugar*', 'sel', 'salt', 'huile*', 'oil', 'oils', 'legume*', 'vegetable*', 'fruit*', 'confiture*', 'jam', 'jams', 'miel', 'honey', 'pain', 'pains', 'bread*', 'lentille*', 'pois chiche*', 'haricot*', 'petit dejeuner', 'breakfast*', 'tartiner', 'spread*', 'lebensmittel*', 'alimento*', 'cibi', 'بقالة', 'سكر', 'دقيق', 'سميد', 'زيت', 'أرز', 'عجائن', 'مربى']],
        ['code' => 'papeterie', 'fr' => 'Papeterie & fournitures', 'ar' => 'القرطاسية واللوازم', 'en' => 'Stationery & supplies', 'icone' => 'pencil',
            'mots' => ['papeterie', 'stationery', 'fourniture scolaire', 'fournitures scolaires', 'cahier', 'cahiers', 'stylo', 'stylos', 'crayon', 'crayons', 'notebook', 'notebooks', 'pen', 'pens', 'pencil', 'pencils', 'classeur', 'classeurs', 'ramette*', 'cartable*', 'قرطاسية', 'كراس', 'قلم']],
        ['code' => 'quincaillerie', 'fr' => 'Quincaillerie & bricolage', 'ar' => 'الخردوات والأدوات', 'en' => 'Hardware & DIY', 'icone' => 'wrench',
            'mots' => ['quincaillerie', 'hardware', 'bricolage', 'diy', 'outil', 'outils', 'tool', 'tools', 'visserie', 'clou', 'clous', 'ampoule', 'ampoules', 'light bulb*', 'pile', 'piles', 'battery', 'batteries', 'electricite', 'electrical', 'peinture*', 'paint', 'paints', 'cadenas', 'خردوات', 'مصباح', 'بطارية']],
    ];

    /** Toujours exclus (porc, alcool). */
    const EXCLUS = [
        'porc', 'porcs', 'pork', 'cochon', 'bacon', 'lard', 'lardon', 'lardons', 'schwein', 'schweine', 'schweinefleisch',
        'cerdo', 'maiale', 'suino', 'prosciutto', 'pancetta', 'speck', 'rillettes', 'andouille', 'andouillette',
        'boudin noir', 'jamon serrano', 'jamon iberico', 'serrano', 'iberico', 'gelatine de porc', 'خنزير',
        'alcool', 'alcools', 'alcohol', 'alcoholic', 'alcoholic beverages', 'alcoolise', 'alcoolisee', 'alcoolisees', 'alcoolises',
        'spiritueux', 'spirits', 'vin', 'vins', 'wine', 'wines', 'wein', 'weine', 'vino', 'vini', 'biere', 'bieres', 'beer', 'beers',
        'bier', 'biere sans alcool', 'cerveza', 'cervezas', 'birra', 'birre', 'whisky', 'whiskey', 'whiskies', 'vodka', 'rhum', 'rum',
        'gin', 'liqueur', 'liqueurs', 'liquor', 'liquore', 'cidre', 'cidres', 'cider', 'champagne', 'champagnes', 'cremant', 'prosecco',
        'cognac', 'armagnac', 'calvados', 'tequila', 'mezcal', 'sake', 'pastis', 'vermouth', 'sangria', 'porto', 'brandy', 'kirsch',
        'خمر', 'خمور', 'كحول', 'كحولية', 'بيرة', 'نبيذ', 'ويسكي',
    ];

    /** Charcuterie : exclue sauf si une viande halal est nommée (« jambon de dinde », « saucisson halal »). */
    const CHARCUTERIE = [
        'jambon', 'jambons', 'ham', 'hams', 'saucisson', 'saucissons', 'saucisse', 'saucisses', 'sausage', 'sausages', 'salami',
        'salamis', 'chorizo', 'chorizos', 'mortadelle', 'mortadella', 'cervelas', 'rosette', 'pepperoni', 'knacki', 'hot dog',
        'wurst', 'wurstchen', 'salchicha', 'salchichas', 'salchichon', 'jamon', 'terrine', 'terrines', 'pate de campagne',
    ];
    const HALAL = [
        'halal', 'dinde', 'turkey', 'poulet', 'chicken', 'volaille', 'volailles', 'poultry', 'boeuf', 'beef', 'agneau', 'lamb',
        'veau', 'veal', 'pollo', 'pavo', 'tacchino', 'pute', 'huhn', 'rind', 'ternera', 'vacuno', 'حلال', 'دجاج', 'ديك رومي',
    ];

    /** Retirés avant tout tri : rubriques trop générales, et négations (« sans alcool » n'est pas de l'alcool). */
    const RETIRES = [
        'plant based foods and beverages', 'aliments et boissons a base de vegetaux', 'alimentos y bebidas de origen vegetal',
        'cibi e bevande a base vegetale', 'pflanzliche lebensmittel und getranke', 'aliments et boissons', 'foods and beverages',
        'non alcoholic beverages', 'boissons sans alcool', 'sans alcool', 'alcohol free', 'non alcoholic', 'sin alcohol',
        'analcolico', 'analcoliche', 'alkoholfrei', 'sans porc', 'pork free', 'no pork', 'sans gluten',
        'vinaigre d alcool', 'vinaigre blanc d alcool', 'vinaigre de vin', 'vinaigres de vin', 'wine vinegar', 'wine vinegars',
        'vinagre de vino', 'vinagre de alcohol', 'aceto di vino', 'aceti di vino', 'weinessig', 'gel hydroalcoolique',
        'alcool en gel', 'alcohol en gel', 'levure de biere', 'levures de biere', 'brewer s yeast',
    ];

    /** Minuscules, sans accents, sans préfixes de langue (« en: »), ponctuation → espaces. */
    public static function normaliser($texte)
    {
        $t = mb_strtolower((string)$texte, 'UTF-8');
        $t = strtr($t, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e',
            'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ó' => 'o', 'ò' => 'o',
            'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y', 'œ' => 'oe',
            'æ' => 'ae', 'ß' => 'ss', 'ـ' => '',
        ]);
        $t = preg_replace('/(?<![\p{L}\p{N}])[a-z]{2}:/u', ' ', $t);
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
        return ' ' . trim($t) . ' ';
    }

    /**
     * Une expression qui trouve l'un des [mots] : mot entier, ou début de mot s'il finit par `*` ;
     * un mot en arabe est cherché partout (l'article « ال » s'y colle).
     */
    public static function motif(array $mots)
    {
        $alternatives = [];
        foreach ($mots as $mot) {
            $debut = substr(trim($mot), -1) === '*';
            $mot = trim(self::normaliser(rtrim(trim($mot), '*')));
            if ($mot === '') {
                continue;
            }
            $alternatives[] = preg_match('/\p{Arabic}/u', $mot)
                ? preg_quote($mot, '/')
                : '(?<![\p{L}\p{N}])' . preg_quote($mot, '/') . ($debut ? '' : '(?![\p{L}\p{N}])');
        }
        if (!$alternatives) {
            return null;
        }
        // Les plus longs d'abord : « the vert » avant « the ».
        usort($alternatives, function ($a, $b) { return strlen($b) - strlen($a); });
        return '/(?:' . implode('|', $alternatives) . ')/u';
    }

    /** Position de la première correspondance de [motif] dans [t], ou -1. */
    private static function position($t, $motif)
    {
        if ($motif !== null && preg_match($motif, $t, $m, PREG_OFFSET_CAPTURE)) {
            return $m[0][1];
        }
        return -1;
    }

    private static $motifs = null;

    private static function motifsExclusion()
    {
        if (self::$motifs === null) {
            self::$motifs = [
                'exclus' => self::motif(self::EXCLUS),
                'charcuterie' => self::motif(self::CHARCUTERIE),
                'halal' => self::motif(self::HALAL),
            ];
        }
        return self::$motifs;
    }

    private static function nettoyer($texte)
    {
        $t = self::normaliser($texte);
        foreach (self::RETIRES as $r) {
            $t = str_replace(' ' . $r . ' ', ' ', $t);
        }
        return $t;
    }

    /** Porc ou alcool dans le nom du produit ou de sa catégorie. */
    public static function estExclu($nom, $categorie)
    {
        $t = self::nettoyer($categorie . ' , ' . $nom);
        $m = self::motifsExclusion();
        if (self::position($t, $m['exclus']) >= 0) {
            return true;
        }
        return self::position($t, $m['charcuterie']) >= 0 && self::position($t, $m['halal']) < 0;
    }

    /**
     * Code du rayon d'un produit, ou null. [rayons] : rayons actifs triés par ordre, chacun avec son
     * `motif` (voir Rayons::preparer).
     */
    public static function classer($nom, $categorie, array $rayons)
    {
        foreach ([$categorie, $nom] as $texte) {
            if (trim((string)$texte) === '') {
                continue;
            }
            $code = self::classerTexte(self::nettoyer($texte), $rayons);
            if ($code !== null) {
                return $code;
            }
        }
        return null;
    }

    private static function classerTexte($t, array $rayons)
    {
        foreach ($rayons as $r) {
            if ($r['prioritaire'] && self::position($t, $r['motif']) >= 0) {
                return $r['code'];
            }
        }
        // Le plus tôt ; à égalité, le mot-clé le plus long (« body wash » plutôt que « body »).
        $meilleur = null;
        $meilleurePos = PHP_INT_MAX;
        $meilleureLongueur = 0;
        foreach ($rayons as $r) {
            if ($r['prioritaire'] || $r['secours'] || $r['motif'] === null
                || !preg_match($r['motif'], $t, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            [$texte, $pos] = $m[0];
            if ($pos < $meilleurePos || ($pos === $meilleurePos && strlen($texte) > $meilleureLongueur)) {
                $meilleurePos = $pos;
                $meilleureLongueur = strlen($texte);
                $meilleur = $r['code'];
            }
        }
        if ($meilleur !== null) {
            return $meilleur;
        }
        foreach ($rayons as $r) {
            if ($r['secours'] && self::position($t, $r['motif']) >= 0) {
                return $r['code'];
            }
        }
        return null;
    }

    /** Rayons actifs (catalog_rayon), chacun avec le motif de ses mots-clés, pour classer(). */
    public static function preparer(PDO $db)
    {
        return self::preparerLignes($db->query('SELECT * FROM catalog_rayon WHERE actif = 1 ORDER BY ordre, code')
                                       ->fetchAll(PDO::FETCH_ASSOC));
    }

    /** [lignes] : code, prioritaire, secours, mots_cles (un par ligne ou séparés par des virgules). */
    public static function preparerLignes(array $lignes)
    {
        $rayons = [];
        foreach ($lignes as $r) {
            $rayons[] = [
                'code' => $r['code'],
                'prioritaire' => (int)$r['prioritaire'] === 1,
                'secours' => (int)$r['secours'] === 1,
                'motif' => self::motif(preg_split('/[\r\n,]+/', (string)$r['mots_cles'])),
            ];
        }
        return $rayons;
    }

    /** Les rayons par défaut (PAR_DEFAUT), prêts pour classer() : tests et essais hors base. */
    public static function preparerParDefaut()
    {
        $lignes = [];
        foreach (self::PAR_DEFAUT as $r) {
            $lignes[] = [
                'code' => $r['code'],
                'prioritaire' => empty($r['prioritaire']) ? 0 : 1,
                'secours' => empty($r['secours']) ? 0 : 1,
                'mots_cles' => implode("\n", $r['mots']),
            ];
        }
        return self::preparerLignes($lignes);
    }

    public static function score($consultations, $algerien, $photo)
    {
        return (int)$consultations * 100 + ($algerien ? 50 : 0) + ($photo ? 10 : 0);
    }

    /**
     * Classe [lot] produits à partir de l'id [depuis] (exclu). Renvoie le dernier id traité, ou null quand
     * tout est classé. À relancer jusqu'à null (admin_rayons.php, catalogue_core/reclasser.php).
     */
    public static function reclasser(PDO $db, $depuis = 0, $lot = 5000, array $rayons = null)
    {
        $rayons = $rayons ?? self::preparer($db);
        $stmt = $db->prepare("
            SELECT p.id, p.nom, p.code_produit, p.photo, p.consultations, COALESCE(c.nom, '') AS categorie,
                   (SELECT COUNT(*) FROM catalog_product_barcode b WHERE b.product_id = p.id AND b.barcode LIKE '613%') AS dz
            FROM catalog_product p
            LEFT JOIN catalog_category c ON c.id = p.category_id
            WHERE p.id > ? ORDER BY p.id LIMIT " . (int)$lot);
        $stmt->execute([(int)$depuis]);
        $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lignes) {
            return null;
        }
        $maj = $db->prepare('UPDATE catalog_product SET rayon_code = ?, exclu = ?, score = ?, classe_le = ? WHERE id = ?');
        $maintenant = date('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            foreach ($lignes as $p) {
                $categorie = $p['categorie'] === 'Sans Categorie' ? '' : $p['categorie'];
                $algerien = (int)$p['dz'] > 0 || strpos((string)$p['code_produit'], '613') === 0;
                $maj->execute([
                    self::classer($p['nom'], $categorie, $rayons),
                    self::estExclu($p['nom'], $categorie) ? 1 : 0,
                    self::score($p['consultations'], $algerien, trim((string)$p['photo']) !== ''),
                    $maintenant,
                    $p['id'],
                ]);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        return (int)end($lignes)['id'];
    }

    /** Rayons actifs et le nombre de produits que chacun propose (au plus PLAFOND). */
    public static function liste(PDO $db)
    {
        $nb = [];
        foreach ($db->query('SELECT rayon_code, COUNT(*) AS n FROM catalog_product
                             WHERE exclu = 0 AND rayon_code IS NOT NULL GROUP BY rayon_code')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nb[$r['rayon_code']] = (int)$r['n'];
        }
        $rayons = [];
        foreach ($db->query('SELECT code, nom_fr, nom_ar, nom_en, icone, ordre FROM catalog_rayon
                             WHERE actif = 1 ORDER BY ordre, code')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['ordre'] = (int)$r['ordre'];
            $r['produits'] = min($nb[$r['code']] ?? 0, self::PLAFOND);
            $rayons[] = $r;
        }
        return ['plafond' => self::PLAFOND, 'rayons' => $rayons];
    }

    /**
     * Une page des produits proposés par le rayon [code] (les PLAFOND mieux classés), avec leurs codes-barres.
     * Lance une exception 404 si le rayon n'existe pas.
     */
    public static function produits(PDO $db, $code, $page = 1, $limite = self::LOT_PAGE_MAX)
    {
        $rayon = $db->prepare('SELECT code FROM catalog_rayon WHERE code = ? AND actif = 1');
        $rayon->execute([$code]);
        if (!$rayon->fetchColumn()) {
            throw new Exception('Rayon inconnu', 404);
        }
        $page = max(1, (int)$page);
        $limite = max(1, min((int)$limite, self::LOT_PAGE_MAX));
        $compte = $db->prepare('SELECT COUNT(*) FROM catalog_product WHERE rayon_code = ? AND exclu = 0');
        $compte->execute([$code]);
        $total = min((int)$compte->fetchColumn(), self::PLAFOND);
        $debut = ($page - 1) * $limite;
        $nb = max(0, min($limite, $total - $debut));
        $produits = [];
        if ($nb > 0) {
            $stmt = $db->prepare("
                SELECT p.id, p.code_produit, p.nom, p.photo, COALESCE(b.nom, '') AS marque
                FROM catalog_product p LEFT JOIN catalog_brand b ON b.id = p.brand_id
                WHERE p.rayon_code = ? AND p.exclu = 0
                ORDER BY p.score DESC, p.id DESC LIMIT $nb OFFSET $debut");
            $stmt->execute([$code]);
            $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $codes = [];
            if ($lignes) {
                $ids = implode(',', array_map('intval', array_column($lignes, 'id')));
                foreach ($db->query("SELECT product_id, barcode FROM catalog_product_barcode WHERE product_id IN ($ids) ORDER BY id")
                             ->fetchAll(PDO::FETCH_ASSOC) as $b) {
                    $codes[(int)$b['product_id']][] = $b['barcode'];
                }
            }
            foreach ($lignes as $p) {
                $liste = $codes[(int)$p['id']] ?? [];
                $principal = trim((string)$p['code_produit']) !== '' ? (string)$p['code_produit'] : ($liste[0] ?? '');
                $produits[] = [
                    'id' => (int)$p['id'],
                    'code_barres' => $principal,
                    'codes_barres' => array_values(array_unique(array_filter(array_merge([$principal], $liste)))),
                    'nom' => $p['nom'],
                    'marque' => $p['marque'],
                    'photo' => $p['photo'] ?: null,
                ];
            }
        }
        return [
            'rayon' => $code,
            'total' => $total,
            'page' => $page,
            'limit' => $limite,
            'total_pages' => (int)ceil($total / $limite),
            'products' => $produits,
        ];
    }

    /** Une recherche par code-barres : le produit monte dans le classement de son rayon. */
    public static function compterConsultation(PDO $db, $produitId)
    {
        try {
            $db->prepare('UPDATE catalog_product SET consultations = consultations + 1, score = score + 100 WHERE id = ?')
               ->execute([(int)$produitId]);
        } catch (Exception $e) {
            // Tables pas encore mises à jour (admin_rayons.php → Installer) : sans effet.
        }
    }
}

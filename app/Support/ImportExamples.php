<?php

namespace App\Support;

use App\Models\Tax;
use App\Models\Tenant;

/**
 * The example sheets a shop downloads before an Excel import, in its own
 * trade.
 *
 * Every shop used to get a bookshop's: novels, ISBNs and « BORDAS », so a
 * butcher or a florist had to guess what their rows should look like. Each
 * activity now gets two rows of its own goods, its own units, the item type
 * names it actually uses and its own taxes (« TVA 14% » in Cairo).
 */
final class ImportExamples
{
    /**
     * Per activity: categories, brands, variants as [name, description];
     * items as [name, category, brand, unit, stock, alert, cost, price,
     * taxed, tags, description] (the first is the activity's main type, the
     * second a plain product); services as [name, cost, price, tags, description]
     * and, for the trades that price by length or duration, four more cells:
     * duration in minutes, « à partir de » (oui/empty), variants, options,
     * then « Produits utilisés » (the recipe, naming products of the items
     * list). An item may end with three cells: content, content unit and
     * container (« 200 », « ml », « flacon »).
     */
    private const DATA = [
        'library' => [
            'categories' => [['FOURNITURE SCOLAIRE', 'Cahiers, cartables, trousses et rentrée scolaire'], ['ROMANS', 'Livres de lecture']],
            'brands' => [['OXFORD', 'Papeterie et cahiers'], ['BORDAS', 'Éditeur scolaire']],
            'variants' => [['BLEU MARINE', 'Couleur'], ['RELIÉ', 'Format livre']],
            'items' => [
                ['Roman exemple relié', 'ROMANS', 'BORDAS', 'Pièce', 8, 2, 55, 85, true, 'roman, lecture', 'Roman relié, édition récente'],
                ['Cahier 96 pages grand format', 'FOURNITURE SCOLAIRE', 'OXFORD', 'Pièce', 50, 5, 8, 12, false, 'rentrée, scolaire', 'Cahier grand format 96 pages ligné'],
            ],
            'services' => [['Photocopie A4 noir et blanc', 0.2, 0.5, 'impression, rapide', 'Photocopie standard A4 noir et blanc'], ['Adhésion annuelle', 0, 100, 'adhésion, bibliothèque', 'Abonnement annuel membre']],
        ],
        'clothing' => [
            'categories' => [['FEMME', 'Robes, tops, pantalons'], ['ACCESSOIRES', 'Sacs, ceintures, foulards']],
            'brands' => [['MAISON LOCALE', 'Création de la boutique'], ['URBAN', 'Prêt-à-porter']],
            'variants' => [['TAILLE M', 'Taille'], ['NOIR', 'Couleur']],
            'items' => [
                ['Robe d’été fleurie', 'FEMME', 'MAISON LOCALE', 'Pièce', 12, 3, 140, 299, true, 'robe, été', 'Robe légère en coton, motif fleuri'],
                ['Ceinture cuir', 'ACCESSOIRES', 'URBAN', 'Pièce', 20, 4, 45, 99, true, 'ceinture, cuir', 'Ceinture en cuir véritable'],
            ],
            'services' => [['Retouche ourlet', 10, 30, 'retouche', 'Ourlet de pantalon ou de robe'], ['Emballage cadeau', 2, 10, 'cadeau', 'Papier et ruban']],
        ],
        'grocery' => [
            'categories' => [['ÉPICERIE', 'Produits de base'], ['BOISSONS', 'Eaux, jus et sodas']],
            'brands' => [['COSUMAR', 'Sucre'], ['SIDI ALI', 'Eau minérale']],
            'variants' => [['1 KG', 'Conditionnement'], ['PACK DE 6', 'Lot']],
            'items' => [
                ['Sucre en poudre 1 kg', 'ÉPICERIE', 'COSUMAR', 'Pièce', 240, 50, 9, 13, false, 'sucre, base', 'Paquet de sucre en poudre 1 kg'],
                ['Eau minérale 1,5 L', 'BOISSONS', 'SIDI ALI', 'Pièce', 300, 60, 4, 6, true, 'eau, boisson', 'Bouteille d’eau minérale 1,5 litre'],
            ],
            'services' => [['Livraison à domicile', 5, 15, 'livraison', 'Livraison dans le quartier'], ['Recharge téléphonique', 0, 20, 'recharge', 'Recharge de crédit']],
        ],
        'bakery' => [
            'categories' => [['PAINS', 'Pains du jour'], ['PÂTISSERIES', 'Gâteaux et tartes']],
            'brands' => [['MAISON', 'Fait sur place'], ['MOULINS', 'Farine']],
            'variants' => [['PART', 'Portion'], ['ENTIER', 'Format']],
            'items' => [
                ['Baguette tradition', 'PAINS', 'MAISON', 'Pièce', 120, 20, 0.8, 2, false, 'pain, jour', 'Baguette cuite sur place'],
                ['Farine T55 25 kg', 'PÂTISSERIES', 'MOULINS', 'Pièce', 10, 2, 180, 220, false, 'farine, ingrédient', 'Sac de farine pour la production'],
            ],
            'services' => [['Gâteau sur commande', 0, 250, 'commande, gâteau', 'Gâteau personnalisé, prix de base'], ['Livraison', 5, 20, 'livraison', 'Livraison en ville']],
        ],
        'restaurant' => [
            'categories' => [['PLATS', 'Plats principaux'], ['BOISSONS', 'Boissons fraîches et chaudes']],
            'brands' => [['MAISON', 'Préparé en cuisine'], ['COCA-COLA', 'Sodas']],
            'variants' => [['GRANDE', 'Taille de portion'], ['ÉPICÉ', 'Préparation']],
            'items' => [
                ['Tajine poulet citron', 'PLATS', 'MAISON', 'Portion', 0, 0, 25, 70, true, 'tajine, plat', 'Tajine de poulet aux citrons confits'],
                ['Coca-Cola 33 cl', 'BOISSONS', 'COCA-COLA', 'Pièce', 96, 24, 4, 12, true, 'soda', 'Canette 33 cl'],
            ],
            'services' => [['Service traiteur', 0, 500, 'traiteur, événement', 'Forfait traiteur, prix de base'], ['Livraison', 5, 15, 'livraison', 'Livraison à domicile']],
        ],
        'coffee' => [
            'categories' => [['CAFÉS', 'Boissons chaudes'], ['SNACKS', 'Viennoiseries et sandwichs']],
            'brands' => [['MAISON', 'Préparé au comptoir'], ['LAVAZZA', 'Café en grains']],
            'variants' => [['GRAND', 'Taille'], ['LAIT D’AVOINE', 'Option']],
            'items' => [
                ['Café crème', 'CAFÉS', 'MAISON', 'Pièce', 0, 0, 3, 14, true, 'café', 'Café crème servi au comptoir'],
                ['Café en grains 1 kg', 'CAFÉS', 'LAVAZZA', 'Pièce', 15, 3, 120, 180, true, 'café, grains', 'Sachet de café en grains 1 kg'],
            ],
            'services' => [['Privatisation de la salle', 0, 800, 'événement', 'Location de la salle, prix de base'], ['Livraison', 5, 15, 'livraison', 'Livraison au bureau']],
        ],
        'beauty' => [
            'categories' => [['COIFFURE', 'Coupes et colorations'], ['PRODUITS', 'Soins vendus en salon']],
            'brands' => [['L’ORÉAL', 'Professionnel'], ['ARGANE', 'Soins naturels']],
            'variants' => [['CHEVEUX LONGS', 'Longueur'], ['250 ML', 'Contenance']],
            'items' => [
                ['Masque cheveux 250 ml', 'PRODUITS', 'L’ORÉAL', 'Pièce', 15, 3, 70, 140, true, 'soin, cheveux', 'Masque nourrissant 250 ml', 250, 'ml', 'pot'],
                ['Huile d’argan 100 ml', 'PRODUITS', 'ARGANE', 'Pièce', 20, 4, 60, 120, true, 'argan, soin', 'Huile d’argan pure 100 ml', 200, 'ml', 'flacon'],
            ],
            'services' => [
                ['Shampoing', 0, 0, 'coiffure', 'Shampoing et soin selon la longueur des cheveux', 20, '', 'Court=20; Mi-long=40; Long=60', '', 'Masque cheveux 250 ml=10; Long > Masque cheveux 250 ml=25'],
                ['Coloration', 0, 300, 'coiffure, couleur', 'Coloration complète, le prix dépend de la longueur', 90, 'oui', '', ''],
                ['Massage', 0, 0, 'bien-être', 'Massage relaxant, le prix dépend de la durée', '', '', '30 min=200@30; 1 h=400@60', '', 'Huile d’argan 100 ml=30; 1 h > Huile d’argan 100 ml=60'],
                ['Pédicure', 0, 150, 'ongles', 'Pédicure classique, soins en option', 45, '', '', 'Paraffine=+50; Vernis=+20'],
            ],
        ],
        'barber' => [
            'categories' => [['COUPES', 'Coupes homme'], ['PRODUITS', 'Produits vendus']],
            'brands' => [['REUZEL', 'Cires et pommades'], ['PROraso', 'Rasage']],
            'variants' => [['ENFANT', 'Client'], ['100 G', 'Contenance']],
            'items' => [
                ['Cire coiffante 100 g', 'PRODUITS', 'REUZEL', 'Pièce', 12, 3, 60, 120, true, 'cire, coiffage', 'Cire tenue forte 100 g', 100, 'g', 'pot'],
                ['Baume après-rasage', 'PRODUITS', 'PROraso', 'Pièce', 10, 2, 50, 95, true, 'rasage', 'Baume apaisant 100 ml', 100, 'ml', 'tube'],
            ],
            'services' => [
                ['Coupe homme', 0, 60, 'coupe', 'Coupe classique ou dégradé', 30, '', 'Adulte=60; Enfant=40', '', 'Cire coiffante 100 g=5; Enfant > Cire coiffante 100 g=3'],
                ['Taille de barbe', 0, 40, 'barbe', 'Taille et contours de barbe', 20, 'oui', '', 'Soin à l’huile=+15', 'Baume après-rasage=4'],
            ],
        ],
        'cosmetics' => [
            'categories' => [['PARFUMS', 'Eaux de parfum et de toilette'], ['MAQUILLAGE', 'Teint, yeux, lèvres']],
            'brands' => [['DIOR', 'Parfums'], ['MAYBELLINE', 'Maquillage']],
            'variants' => [['50 ML', 'Contenance'], ['NUDE', 'Teinte']],
            'items' => [
                ['Eau de parfum 50 ml', 'PARFUMS', 'DIOR', 'Pièce', 6, 2, 650, 990, true, 'parfum', 'Eau de parfum 50 ml'],
                ['Mascara volume', 'MAQUILLAGE', 'MAYBELLINE', 'Pièce', 25, 5, 55, 110, true, 'mascara', 'Mascara noir effet volume'],
            ],
            'services' => [['Coffret cadeau', 5, 30, 'cadeau', 'Mise en coffret'], ['Séance maquillage', 0, 200, 'maquillage', 'Maquillage événement']],
        ],
        'pharmacy' => [
            'categories' => [['MÉDICAMENTS', 'Médicaments'], ['PARAPHARMACIE', 'Soins et hygiène']],
            'brands' => [['SANOFI', 'Laboratoire'], ['AVÈNE', 'Dermo-cosmétique']],
            'variants' => [['500 MG', 'Dosage'], ['BOÎTE DE 20', 'Conditionnement']],
            'items' => [
                ['Paracétamol 500 mg, boîte de 20', 'MÉDICAMENTS', 'SANOFI', 'Boîte', 60, 15, 8, 12, false, 'antalgique', 'Comprimés, boîte de 20'],
                ['Crème hydratante 50 ml', 'PARAPHARMACIE', 'AVÈNE', 'Pièce', 18, 4, 90, 150, true, 'soin, peau', 'Crème hydratante peaux sensibles'],
            ],
            'services' => [['Prise de tension', 0, 20, 'tension, santé', 'Mesure de la tension artérielle'], ['Test de glycémie', 3, 30, 'glycémie', 'Test rapide de glycémie']],
        ],
        'drugstore' => [
            'categories' => [['QUINCAILLERIE', 'Vis, outils, serrures'], ['NETTOYAGE', 'Produits ménagers']],
            'brands' => [['STANLEY', 'Outillage'], ['JAVEL LA CROIX', 'Entretien']],
            'variants' => [['5 L', 'Contenance'], ['LOT DE 100', 'Conditionnement']],
            'items' => [
                ['Marteau 500 g', 'QUINCAILLERIE', 'STANLEY', 'Pièce', 15, 3, 45, 85, true, 'outil', 'Marteau de menuisier 500 g'],
                ['Eau de Javel 5 L', 'NETTOYAGE', 'JAVEL LA CROIX', 'Pièce', 40, 8, 18, 29, true, 'nettoyage', 'Bidon d’eau de Javel 5 litres'],
            ],
            'services' => [['Reproduction de clé', 3, 20, 'clé', 'Double de clé standard'], ['Livraison', 5, 20, 'livraison', 'Livraison à domicile']],
        ],
        'electronics' => [
            'categories' => [['TÉLÉPHONES', 'Smartphones'], ['ACCESSOIRES', 'Chargeurs, coques, écouteurs']],
            'brands' => [['SAMSUNG', 'Téléphonie'], ['ANKER', 'Accessoires']],
            'variants' => [['128 GO', 'Stockage'], ['NOIR', 'Couleur']],
            'items' => [
                ['Smartphone 128 Go', 'TÉLÉPHONES', 'SAMSUNG', 'Pièce', 6, 2, 1800, 2399, true, 'smartphone', 'Smartphone 128 Go, garantie 1 an'],
                ['Chargeur rapide 20 W', 'ACCESSOIRES', 'ANKER', 'Pièce', 30, 6, 60, 129, true, 'chargeur', 'Chargeur USB-C 20 W'],
            ],
            'services' => [['Remplacement d’écran', 300, 600, 'réparation', 'Main-d’œuvre et pose, pièce comprise'], ['Transfert de données', 0, 50, 'données', 'Transfert vers un nouveau téléphone']],
        ],
        'chocolate' => [
            'categories' => [['CHOCOLATS', 'Pralinés, ganaches, tablettes'], ['COFFRETS', 'Boîtes et ballotins']],
            'brands' => [['MAISON', 'Fabrication artisanale'], ['VALRHONA', 'Couverture']],
            'variants' => [['NOIR 70 %', 'Chocolat'], ['500 G', 'Poids']],
            'items' => [
                ['Assortiment de pralinés', 'CHOCOLATS', 'MAISON', 'Kg', 12, 3, 180, 380, true, 'praliné, vrac', 'Pralinés assortis, vendus au poids'],
                ['Coffret 24 chocolats', 'COFFRETS', 'MAISON', 'Boîte', 20, 5, 70, 160, true, 'coffret, cadeau', 'Coffret de 24 chocolats assortis'],
            ],
            'services' => [['Personnalisation de coffret', 5, 40, 'personnalisation', 'Ruban et carte au nom du client'], ['Livraison', 5, 20, 'livraison', 'Livraison en ville']],
        ],
        'wholesale' => [
            'categories' => [['ALIMENTATION', 'Produits secs en gros'], ['ENTRETIEN', 'Produits ménagers en gros']],
            'brands' => [['LESIEUR', 'Huiles'], ['OMO', 'Lessives']],
            'variants' => [['CARTON DE 12', 'Colisage'], ['PALETTE', 'Conditionnement']],
            'items' => [
                ['Huile de table 1 L, carton de 12', 'ALIMENTATION', 'LESIEUR', 'Carton', 80, 15, 240, 285, true, 'huile, carton', 'Carton de 12 bouteilles d’1 litre'],
                ['Lessive en poudre 3 kg', 'ENTRETIEN', 'OMO', 'Pièce', 120, 20, 48, 58, true, 'lessive', 'Sac de lessive 3 kg'],
            ],
            'services' => [['Livraison en camion', 50, 150, 'livraison, gros', 'Livraison chez le revendeur'], ['Manutention palette', 10, 40, 'manutention', 'Chargement d’une palette']],
        ],
        'butcher' => [
            'categories' => [['BŒUF', 'Viande de bœuf'], ['VOLAILLE', 'Poulet et dinde']],
            'brands' => [['ABATTOIR LOCAL', 'Fournisseur viande'], ['KOUTOUBIA', 'Volaille et charcuterie']],
            'variants' => [['HACHÉ', 'Découpe'], ['ENTIER', 'Découpe']],
            'items' => [
                ['Viande hachée de bœuf', 'BŒUF', 'ABATTOIR LOCAL', 'Kg', 25, 5, 85, 110, false, 'bœuf, haché', 'Viande de bœuf hachée du jour'],
                ['Poulet entier', 'VOLAILLE', 'KOUTOUBIA', 'Pièce', 30, 6, 32, 45, false, 'poulet', 'Poulet fermier entier'],
            ],
            'services' => [['Découpe sur mesure', 0, 10, 'découpe', 'Découpe selon la demande du client'], ['Marinade', 2, 15, 'marinade', 'Viande marinée prête à cuire']],
        ],
        'greengrocer' => [
            'categories' => [['FRUITS', 'Fruits de saison'], ['LÉGUMES', 'Légumes frais']],
            'brands' => [['MARCHÉ DE GROS', 'Arrivage du jour'], ['PRODUCTEUR LOCAL', 'Circuit court']],
            'variants' => [['CALIBRE 1', 'Calibre'], ['BIO', 'Origine']],
            'items' => [
                ['Tomates', 'LÉGUMES', 'MARCHÉ DE GROS', 'Kg', 60, 10, 4, 7, false, 'tomate, légume', 'Tomates rondes, arrivage du jour'],
                ['Oranges à jus', 'FRUITS', 'PRODUCTEUR LOCAL', 'Kg', 80, 15, 3, 6, false, 'orange, fruit', 'Oranges à jus de saison'],
            ],
            'services' => [['Panier livré', 5, 15, 'livraison, panier', 'Livraison du panier à domicile'], ['Préparation de jus', 2, 10, 'jus', 'Jus pressé à la demande']],
        ],
        'jewelry' => [
            'categories' => [['BAGUES', 'Bagues et alliances'], ['MONTRES', 'Montres homme et femme']],
            'brands' => [['ATELIER MAISON', 'Création de la bijouterie'], ['CASIO', 'Montres']],
            'variants' => [['OR 18 CARATS', 'Métal'], ['TAILLE 54', 'Taille de doigt']],
            'items' => [
                ['Bague or 18 carats', 'BAGUES', 'ATELIER MAISON', 'Pièce', 4, 1, 2200, 3200, true, 'bague, or', 'Bague en or jaune 18 carats'],
                ['Montre classique', 'MONTRES', 'CASIO', 'Pièce', 8, 2, 350, 590, true, 'montre', 'Montre à quartz, bracelet acier'],
            ],
            'services' => [['Changement de pile', 5, 30, 'montre, pile', 'Pile de montre et étanchéité'], ['Mise à taille d’une bague', 20, 120, 'bague, réparation', 'Agrandissement ou rétrécissement']],
        ],
        'optician' => [
            'categories' => [['MONTURES', 'Montures optiques'], ['LENTILLES', 'Lentilles de contact']],
            'brands' => [['RAY-BAN', 'Montures'], ['ACUVUE', 'Lentilles']],
            'variants' => [['NOIR MAT', 'Couleur'], ['-2,50', 'Correction']],
            'items' => [
                ['Monture acétate', 'MONTURES', 'RAY-BAN', 'Pièce', 10, 2, 450, 990, true, 'monture', 'Monture en acétate, coloris noir'],
                ['Lentilles mensuelles, boîte de 6', 'LENTILLES', 'ACUVUE', 'Boîte', 20, 4, 160, 280, true, 'lentilles', 'Lentilles de contact mensuelles'],
            ],
            'services' => [['Examen de la vue', 0, 100, 'examen', 'Contrôle de la vue en magasin'], ['Montage des verres', 20, 80, 'montage', 'Montage des verres sur monture']],
        ],
        'florist' => [
            'categories' => [['BOUQUETS', 'Bouquets du jour'], ['PLANTES', 'Plantes d’intérieur']],
            'brands' => [['ATELIER', 'Compositions de la boutique'], ['PÉPINIÈRE', 'Plantes']],
            'variants' => [['ROUGE', 'Couleur'], ['GRAND', 'Taille']],
            'items' => [
                ['Bouquet de roses rouges', 'BOUQUETS', 'ATELIER', 'Bouquet', 10, 2, 90, 200, true, 'roses, bouquet', 'Bouquet de 12 roses rouges'],
                ['Orchidée en pot', 'PLANTES', 'PÉPINIÈRE', 'Pièce', 12, 3, 80, 160, true, 'orchidée, plante', 'Orchidée blanche, pot céramique'],
            ],
            'services' => [['Livraison de bouquet', 10, 30, 'livraison', 'Livraison en ville le jour même'], ['Décoration d’événement', 0, 1500, 'événement, mariage', 'Décoration florale, prix de base']],
        ],
        'retail' => [
            'categories' => [['PRODUITS', 'Articles en vente'], ['ACCESSOIRES', 'Petits articles']],
            'brands' => [['MARQUE A', 'Fournisseur principal'], ['MARQUE B', 'Second fournisseur']],
            'variants' => [['BLEU', 'Couleur'], ['GRAND', 'Taille']],
            'items' => [
                ['Article exemple', 'PRODUITS', 'MARQUE A', 'Pièce', 20, 4, 50, 90, true, 'exemple', 'Article vendu en boutique'],
                ['Accessoire exemple', 'ACCESSOIRES', 'MARQUE B', 'Pièce', 40, 8, 10, 25, true, 'accessoire', 'Petit accessoire'],
            ],
            'services' => [['Service exemple', 0, 50, 'service', 'Prestation sans stock'], ['Livraison', 5, 20, 'livraison', 'Livraison à domicile']],
        ],
    ];

    /** @return array{title: string, filename: string, headers: list<string>, rows: list<list<string>>} */
    public static function sheet(?Tenant $tenant, string $kind): array
    {
        $mode = BusinessMode::current($tenant)['key'];
        $data = self::DATA[$mode] ?? self::DATA['retail'];

        return match ($kind) {
            'categories' => self::simple('Liste des catégories', 'exemple-import-categories.xlsx', 'Nom de catégorie', $data['categories']),
            'brands' => self::simple('Liste des marques', 'exemple-import-marques.xlsx', 'Marque', $data['brands']),
            'variants' => self::simple('Liste des variantes', 'exemple-import-variantes.xlsx', 'Nom de la variante', $data['variants']),
            'services' => self::services($tenant, $data['services']),
            default => self::items($tenant, $mode, $data['items']),
        };
    }

    private static function simple(string $title, string $filename, string $nameHeader, array $rows): array
    {
        return [
            'title' => $title,
            'filename' => $filename,
            'headers' => [$nameHeader, 'La description', 'Statut'],
            'rows' => array_map(fn (array $r) => [$r[0], $r[1], 'Active'], $rows),
        ];
    }

    private static function services(?Tenant $tenant, array $services): array
    {
        $type = ItemTypes::label($tenant, 'service');

        return [
            'title' => 'Liste des services',
            'filename' => 'exemple-import-services.xlsx',
            'headers' => ['Code de barre', "Nom de l'article", "Catégorie/Type d'élément", 'Unité', "Prix d'achat", 'Prix de vente', 'Impôt', 'Statut', 'Tags', 'Description', "Type d'élément", 'Image', 'Durée (min)', 'Prix à partir de', 'Variantes', 'Options', 'Produits utilisés'],
            'rows' => array_map(fn (array $s) => [
                '', $s[0], 'Services[SERVICE]', 'Service', self::amount($s[1]),
                // A price given by variants is left empty: the cheapest becomes the article's.
                ($s[7] ?? '') !== '' && (float) $s[2] === 0.0 ? '' : self::amount($s[2]),
                self::tax($tenant, false), 'Active', $s[3], $s[4], $type, '',
                (string) ($s[5] ?? ''), (string) ($s[6] ?? ''), (string) ($s[7] ?? ''), (string) ($s[8] ?? ''), (string) ($s[9] ?? ''),
            ], $services),
        ];
    }

    private static function items(?Tenant $tenant, string $mode, array $items): array
    {
        $isBookshop = $mode === 'library';
        $physical = array_keys(ItemTypes::physicalForTenant($tenant));
        // The first row shows the activity's main type when it has one
        // (« Plat / menu », « Médicament »), the second the plain product.
        $main = array_values(array_diff($physical, ['supply']))[0] ?? 'supply';

        $headers = $isBookshop
            ? ['Code de barre', 'ISBN', "Nom de l'article", "Catégorie/Type d'élément", 'Marque', 'Auteur', 'Unité', 'Stock', "Quantité d'alerte", "Prix d'achat", 'Prix de vente', 'Impôt', 'Statut', 'Tags', 'Description', "Type d'élément", 'Image']
            : ['Code de barre', "Nom de l'article", "Catégorie/Type d'élément", 'Marque', 'Unité', 'Stock', "Quantité d'alerte", "Prix d'achat", 'Prix de vente', 'Impôt', 'Statut', 'Tags', 'Description', "Type d'élément", 'Image'];

        // The capacity columns only appear for trades that sell by content.
        $withCapacity = collect($items)->contains(fn (array $item) => isset($item[11]));
        if ($withCapacity) {
            array_push($headers, 'Contenu', 'Unité de contenu', 'Contenant');
        }

        $rows = [];
        foreach ($items as $i => [$name, $category, $brand, $unit, $stock, $alert, $cost, $price, $taxed, $tags, $description]) {
            $capacity = $items[$i];
            $type = $i === 0 ? $main : 'supply';
            $barcode = '61100000000'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $row = [$barcode];
            if ($isBookshop) {
                $isbn = $type === 'book' ? '9782000000002' : '';
                $row = [$isbn ?: $barcode, $isbn];
            }
            array_push($row, $name, $category.'[ITEM]', $brand);
            if ($isBookshop) {
                $row[] = $type === 'book' ? 'Auteur Exemple' : '';
            }
            array_push($row, $unit, (string) $stock, (string) $alert, self::amount($cost), self::amount($price),
                self::tax($tenant, $taxed), 'Active', $tags, $description, ItemTypes::label($tenant, $type), '');
            if ($withCapacity) {
                array_push($row, (string) ($capacity[11] ?? ''), (string) ($capacity[12] ?? ''), (string) ($capacity[13] ?? ''));
            }
            $rows[] = $row;
        }

        return ['title' => "Liste d'articles", 'filename' => 'exemple-import-articles.xlsx', 'headers' => $headers, 'rows' => $rows];
    }

    private static function amount(float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** One of the shop's own taxes, in the importer's « Nom(taux%) » form. */
    private static function tax(?Tenant $tenant, bool $taxed): string
    {
        $taxes = $tenant ? Tax::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('rate')->get(['name', 'rate']) : collect();
        $tax = $taxed ? $taxes->where('rate', '>', 0)->sortByDesc('rate')->first() : $taxes->firstWhere('rate', 0);

        return $tax ? $tax->name.'('.number_format((float) $tax->rate, 2, '.', '').'%)' : ($taxed ? 'TVA 20%(20.00%)' : 'Sans TVA(0.00%)');
    }
}

<?php

/**
 * Café / coffee shop: boissons chaudes et fraîches, viennoiseries, snacks et
 * stock comptoir.
 *
 * Drinks and snacks are the activity's PRIMARY type, which this product spells
 * `book` and labels "Boisson / snack". Counter stock (gobelets, grains, lait)
 * is `supply`.
 *
 * VAT: consommation sur place is 10%; packaged goods resold and the counter
 * supplies are at 20%.
 */

$item = fn (
    string $name,
    string $category,
    string $brand,
    float $cost,
    float $price,
    int $stock,
    int $alert,
    string $tags,
    string $description,
    string $kind = 'supply',
    string $author = '',
    string $unit = 'Pièce',
    string $isbn = '',
    int $vat = 20,
) => compact('name', 'category', 'brand', 'cost', 'price', 'stock', 'alert', 'tags', 'description', 'kind', 'author', 'unit', 'isbn', 'vat');

$service = fn (string $name, float $cost, float $price, string $tags, string $description, int $vat = 20)
    => compact('name', 'cost', 'price', 'tags', 'description', 'vat');

return [
    'activity' => 'cafe',
    'title' => 'Carte du café',
    'isbn_column' => false,

    'items' => [
        // ── Cafés ─────────────────────────────────────────────────────────────
        $item('Expresso', 'CAFÉS', '', 2.50, 10.00, 300, 40, 'café, expresso', 'Expresso serré, tasse courte', 'book', '', 'Pièce', '', 10),
        $item('Café allongé', 'CAFÉS', '', 2.50, 11.00, 250, 40, 'café, allongé', 'Café allongé, tasse moyenne', 'book', '', 'Pièce', '', 10),
        $item('Café nous-nous', 'CAFÉS', '', 3.50, 14.00, 280, 40, 'café, lait', 'Moitié café, moitié lait, le classique marocain', 'book', '', 'Pièce', '', 10),
        $item('Cappuccino', 'CAFÉS', '', 4.50, 18.00, 200, 30, 'café, cappuccino', 'Expresso, lait chaud et mousse de lait', 'book', '', 'Pièce', '', 10),
        $item('Café latte', 'CAFÉS', '', 5.00, 20.00, 180, 30, 'café, latte', 'Expresso allongé au lait chaud', 'book', '', 'Pièce', '', 10),
        $item('Café glacé', 'CAFÉS', '', 6.00, 24.00, 120, 25, 'café, glacé, été', 'Café froid, glace et lait', 'book', '', 'Pièce', '', 10),
        $item('Chocolat chaud', 'CAFÉS', '', 5.50, 20.00, 140, 25, 'chocolat, chaud', 'Chocolat chaud onctueux, chantilly en option', 'book', '', 'Pièce', '', 10),

        // ── Thés et infusions ─────────────────────────────────────────────────
        $item('Thé à la menthe (verre)', 'THÉS ET INFUSIONS', '', 2.00, 10.00, 320, 50, 'thé, menthe', 'Verre de thé vert à la menthe fraîche', 'book', '', 'Pièce', '', 10),
        $item('Thé à la menthe (théière)', 'THÉS ET INFUSIONS', '', 4.00, 20.00, 160, 30, 'thé, menthe, théière', 'Théière pour deux verres', 'book', '', 'Pièce', '', 10),
        $item('Thé aux épices', 'THÉS ET INFUSIONS', '', 4.50, 18.00, 100, 20, 'thé, épices', 'Thé vert, cannelle, gingembre et clou de girofle', 'book', '', 'Pièce', '', 10),
        $item('Verveine', 'THÉS ET INFUSIONS', '', 3.00, 14.00, 90, 20, 'infusion, verveine', 'Infusion de verveine fraîche', 'book', '', 'Pièce', '', 10),

        // ── Jus et boissons fraîches ──────────────────────────────────────────
        $item('Jus d\'orange pressé', 'JUS ET BOISSONS FRAÎCHES', '', 7.00, 22.00, 120, 20, 'jus, orange', 'Orange pressée minute', 'book', '', 'Pièce', '', 10),
        $item('Jus d\'avocat', 'JUS ET BOISSONS FRAÎCHES', '', 9.00, 28.00, 80, 15, 'jus, avocat', 'Avocat, lait, amandes et miel', 'book', '', 'Pièce', '', 10),
        $item('Jus de panaché', 'JUS ET BOISSONS FRAÎCHES', '', 10.00, 30.00, 70, 15, 'jus, panaché', 'Mélange de fruits de saison', 'book', '', 'Pièce', '', 10),
        $item('Smoothie fraise-banane', 'JUS ET BOISSONS FRAÎCHES', '', 11.00, 32.00, 60, 12, 'smoothie, fruits', 'Fraise, banane et yaourt', 'book', '', 'Pièce', '', 10),
        $item('Limonade maison', 'JUS ET BOISSONS FRAÎCHES', '', 5.00, 18.00, 90, 18, 'limonade, citron', 'Citron pressé, menthe et eau gazeuse', 'book', '', 'Pièce', '', 10),
        $item('Eau minérale 50 cl', 'JUS ET BOISSONS FRAÎCHES', 'SIDI ALI', 3.00, 10.00, 300, 50, 'eau, bouteille', 'Bouteille d\'eau minérale 50 cl', 'book', '', 'Pièce', '', 20),
        $item('Soda 33 cl', 'JUS ET BOISSONS FRAÎCHES', 'COCA-COLA', 4.50, 14.00, 240, 40, 'soda, canette', 'Canette de soda 33 cl', 'book', '', 'Pièce', '', 20),

        // ── Viennoiseries et pâtisseries ──────────────────────────────────────
        $item('Croissant au beurre', 'VIENNOISERIES', '', 2.50, 8.00, 120, 25, 'viennoiserie, croissant', 'Croissant pur beurre, cuit le matin', 'book', '', 'Pièce', '', 10),
        $item('Pain au chocolat', 'VIENNOISERIES', '', 3.00, 9.00, 120, 25, 'viennoiserie, chocolat', 'Pain au chocolat pur beurre', 'book', '', 'Pièce', '', 10),
        $item('Msemen nature', 'VIENNOISERIES', '', 2.00, 6.00, 150, 30, 'msemen, petit-déjeuner', 'Crêpe feuilletée marocaine', 'book', '', 'Pièce', '', 10),
        $item('Baghrir au miel', 'VIENNOISERIES', '', 3.00, 12.00, 100, 20, 'baghrir, miel', 'Crêpe mille-trous, miel et beurre', 'book', '', 'Pièce', '', 10),
        $item('Cornes de gazelle (3 pièces)', 'VIENNOISERIES', '', 8.00, 24.00, 70, 15, 'pâtisserie, amande', 'Trois cornes de gazelle', 'book', '', 'Pièce', '', 10),
        $item('Part de cake', 'VIENNOISERIES', '', 4.00, 14.00, 60, 12, 'gâteau, cake', 'Part de cake du jour', 'book', '', 'Pièce', '', 10),

        // ── Snacks salés ──────────────────────────────────────────────────────
        $item('Panini fromage-thon', 'SNACKS SALÉS', '', 9.00, 28.00, 70, 15, 'panini, thon', 'Panini grillé fromage et thon', 'book', '', 'Pièce', '', 10),
        $item('Panini poulet', 'SNACKS SALÉS', '', 10.00, 30.00, 70, 15, 'panini, poulet', 'Panini grillé poulet et crudités', 'book', '', 'Pièce', '', 10),
        $item('Croque-monsieur', 'SNACKS SALÉS', '', 8.00, 26.00, 55, 12, 'croque, jambon', 'Croque-monsieur grillé', 'book', '', 'Pièce', '', 10),
        $item('Omelette nature', 'SNACKS SALÉS', '', 6.00, 20.00, 50, 10, 'omelette, petit-déjeuner', 'Omelette trois œufs', 'book', '', 'Pièce', '', 10),
        $item('Petit-déjeuner complet', 'SNACKS SALÉS', '', 14.00, 42.00, 60, 12, 'formule, petit-déjeuner', 'Boisson chaude, jus, viennoiserie, beurre et confiture', 'book', '', 'Pièce', '', 10),

        // ── Stock comptoir ────────────────────────────────────────────────────
        $item('Café en grains 1 kg', 'STOCK COMPTOIR', 'LAVAZZA', 145.00, 145.00, 20, 5, 'café, grains', 'Paquet de café en grains 1 kg', 'supply', '', 'Pièce', '', 20),
        $item('Lait UHT 1 L (pack de 6)', 'STOCK COMPTOIR', 'CENTRALE', 62.00, 62.00, 30, 8, 'lait, pack', 'Pack de 6 briques de lait UHT', 'supply', '', 'Pack', '', 20),
        $item('Sucre en morceaux 1 kg', 'STOCK COMPTOIR', 'COSUMAR', 14.00, 14.00, 40, 10, 'sucre, comptoir', 'Boîte de sucre en morceaux', 'supply', '', 'Pièce', '', 20),
        $item('Gobelets carton 25 cl (lot de 100)', 'STOCK COMPTOIR', '', 55.00, 55.00, 25, 6, 'gobelet, emporter', 'Lot de 100 gobelets à emporter', 'supply', '', 'Pack', '', 20),
        $item('Couvercles gobelets (lot de 100)', 'STOCK COMPTOIR', '', 35.00, 35.00, 25, 6, 'couvercle, emporter', 'Lot de 100 couvercles', 'supply', '', 'Pack', '', 20),
        $item('Feuilles de menthe (botte)', 'STOCK COMPTOIR', '', 3.00, 3.00, 60, 15, 'menthe, thé', 'Botte de menthe fraîche du jour', 'supply', '', 'Pièce', '', 20),
    ],

    'services' => [
        $service('Wifi premium (journée)', 0.00, 20.00, 'wifi, journée', 'Accès wifi haut débit pour la journée', 20),
        $service('Location coin réunion (heure)', 0.00, 150.00, 'réunion, espace', 'Espace réunion privatisé, à l\'heure', 20),
        $service('Plateau traiteur 10 personnes', 180.00, 450.00, 'traiteur, plateau', 'Plateau viennoiseries et boissons pour 10', 10),
    ],
];

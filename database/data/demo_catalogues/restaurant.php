<?php

/**
 * Restaurant marocain: carte, boissons, stock cuisine et prestations.
 *
 * Dishes and drinks are the activity's PRIMARY type, which this product spells
 * `book` and labels "Plat / menu" — the same slot a librairie fills with a
 * novel. Kitchen stock (flour, oil, gas) is `supply`: it
 * is bought and counted but never sold, which is why it carries no sale
 * margin worth the name.
 *
 * VAT: restauration sur place is 10% in Morocco; the drinks a café resells
 * and the non-food supplies are at 20%.
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
    'activity' => 'restaurant',
    'title' => 'Carte du restaurant',
    'isbn_column' => false,

    'items' => [
        // ── Entrées ───────────────────────────────────────────────────────────
        $item('Harira maison', 'ENTRÉES', '', 6.00, 18.00, 60, 10, 'soupe, ramadan, maison', 'Soupe traditionnelle tomate, lentilles et pois chiches', 'book', '', 'Pièce', '', 10),
        $item('Soupe du jour', 'ENTRÉES', '', 5.00, 16.00, 40, 10, 'soupe, jour', 'Soupe de légumes de saison', 'book', '', 'Pièce', '', 10),
        $item('Salade marocaine', 'ENTRÉES', '', 7.00, 22.00, 50, 10, 'salade, fraîche', 'Tomates, concombres, oignons et coriandre', 'book', '', 'Pièce', '', 10),
        $item('Zaalouk d\'aubergines', 'ENTRÉES', '', 8.00, 25.00, 40, 8, 'salade, aubergine', 'Caviar d\'aubergines à la tomate et au cumin', 'book', '', 'Pièce', '', 10),
        $item('Taktouka', 'ENTRÉES', '', 7.00, 24.00, 40, 8, 'salade, poivron', 'Poivrons grillés et tomates confites', 'book', '', 'Pièce', '', 10),
        $item('Briouates au fromage (4 pièces)', 'ENTRÉES', '', 10.00, 32.00, 35, 8, 'briouate, entrée', 'Quatre briouates croustillantes au fromage', 'book', '', 'Pièce', '', 10),
        $item('Assortiment de salades (6 variétés)', 'ENTRÉES', '', 22.00, 65.00, 25, 5, 'salades, partage', 'Six salades marocaines à partager', 'book', '', 'Pièce', '', 10),

        // ── Tajines ───────────────────────────────────────────────────────────
        $item('Tajine de poulet aux olives', 'TAJINES', '', 28.00, 75.00, 40, 8, 'tajine, poulet, signature', 'Poulet fermier, olives violettes et citron confit', 'book', '', 'Pièce', '', 10),
        $item('Tajine d\'agneau aux pruneaux', 'TAJINES', '', 45.00, 110.00, 30, 6, 'tajine, agneau', 'Épaule d\'agneau, pruneaux et amandes grillées', 'book', '', 'Pièce', '', 10),
        $item('Tajine kefta aux œufs', 'TAJINES', '', 26.00, 70.00, 35, 8, 'tajine, kefta', 'Boulettes de viande hachée, sauce tomate et œufs', 'book', '', 'Pièce', '', 10),
        $item('Tajine de poisson chermoula', 'TAJINES', '', 38.00, 95.00, 25, 5, 'tajine, poisson', 'Poisson du jour mariné à la chermoula', 'book', '', 'Pièce', '', 10),
        $item('Tajine de légumes', 'TAJINES', '', 16.00, 48.00, 30, 6, 'tajine, végétarien', 'Légumes de saison mijotés aux épices douces', 'book', '', 'Pièce', '', 10),

        // ── Couscous ──────────────────────────────────────────────────────────
        $item('Couscous aux sept légumes', 'COUSCOUS', '', 24.00, 68.00, 35, 8, 'couscous, vendredi', 'Semoule roulée, sept légumes et bouillon safrané', 'book', '', 'Pièce', '', 10),
        $item('Couscous à l\'agneau', 'COUSCOUS', '', 45.00, 115.00, 25, 5, 'couscous, agneau', 'Couscous garni d\'agneau et de légumes', 'book', '', 'Pièce', '', 10),
        $item('Couscous tfaya', 'COUSCOUS', '', 32.00, 88.00, 20, 5, 'couscous, tfaya', 'Couscous aux oignons confits, raisins secs et cannelle', 'book', '', 'Pièce', '', 10),

        // ── Grillades ─────────────────────────────────────────────────────────
        $item('Brochettes de kefta (4 pièces)', 'GRILLADES', '', 30.00, 78.00, 40, 8, 'grillade, kefta', 'Quatre brochettes de viande hachée épicée', 'book', '', 'Pièce', '', 10),
        $item('Brochettes de poulet (4 pièces)', 'GRILLADES', '', 28.00, 72.00, 40, 8, 'grillade, poulet', 'Quatre brochettes de poulet mariné', 'book', '', 'Pièce', '', 10),
        $item('Côtelettes d\'agneau grillées', 'GRILLADES', '', 60.00, 145.00, 20, 4, 'grillade, agneau', 'Côtelettes d\'agneau grillées au charbon', 'book', '', 'Pièce', '', 10),
        $item('Mixed grill', 'GRILLADES', '', 70.00, 165.00, 18, 4, 'grillade, partage', 'Assortiment de grillades pour une personne', 'book', '', 'Pièce', '', 10),
        $item('Poulet rôti demi', 'GRILLADES', '', 26.00, 65.00, 30, 6, 'poulet, rôti', 'Demi-poulet rôti aux épices', 'book', '', 'Pièce', '', 10),

        // ── Sandwichs et snacks ───────────────────────────────────────────────
        $item('Sandwich kefta', 'SANDWICHS', '', 12.00, 32.00, 60, 12, 'sandwich, kefta', 'Pain maison, kefta grillée et salade', 'book', '', 'Pièce', '', 10),
        $item('Sandwich poulet', 'SANDWICHS', '', 11.00, 30.00, 60, 12, 'sandwich, poulet', 'Pain maison, poulet mariné et crudités', 'book', '', 'Pièce', '', 10),
        $item('Sandwich thon', 'SANDWICHS', '', 9.00, 26.00, 50, 10, 'sandwich, thon', 'Thon, olives, tomate et harissa', 'book', '', 'Pièce', '', 10),
        $item('Frites maison', 'SANDWICHS', '', 4.00, 15.00, 80, 15, 'frites, accompagnement', 'Portion de frites fraîches', 'book', '', 'Pièce', '', 10),
        $item('Msemen farci', 'SANDWICHS', '', 5.00, 16.00, 45, 10, 'msemen, snack', 'Crêpe feuilletée farcie au choix', 'book', '', 'Pièce', '', 10),

        // ── Desserts ──────────────────────────────────────────────────────────
        $item('Salade d\'oranges à la cannelle', 'DESSERTS', '', 5.00, 18.00, 40, 8, 'dessert, orange', 'Oranges fraîches, cannelle et fleur d\'oranger', 'book', '', 'Pièce', '', 10),
        $item('Cornes de gazelle (3 pièces)', 'DESSERTS', '', 8.00, 25.00, 50, 10, 'pâtisserie, amande', 'Trois cornes de gazelle à la pâte d\'amande', 'book', '', 'Pièce', '', 10),
        $item('Crème caramel', 'DESSERTS', '', 6.00, 22.00, 35, 8, 'dessert, crème', 'Crème caramel maison', 'book', '', 'Pièce', '', 10),
        $item('Fruits de saison', 'DESSERTS', '', 7.00, 20.00, 30, 6, 'dessert, fruits', 'Assiette de fruits frais de saison', 'book', '', 'Pièce', '', 10),

        // ── Boissons ──────────────────────────────────────────────────────────
        $item('Thé à la menthe (théière)', 'BOISSONS', '', 4.00, 18.00, 120, 20, 'thé, menthe', 'Théière de thé vert à la menthe fraîche', 'book', '', 'Pièce', '', 10),
        $item('Café noir', 'BOISSONS', '', 3.00, 12.00, 150, 25, 'café, expresso', 'Café noir serré', 'book', '', 'Pièce', '', 10),
        $item('Café nous-nous', 'BOISSONS', '', 4.00, 15.00, 120, 20, 'café, lait', 'Moitié café, moitié lait', 'book', '', 'Pièce', '', 10),
        $item('Jus d\'orange pressé', 'BOISSONS', '', 7.00, 22.00, 80, 15, 'jus, orange, frais', 'Orange pressée minute', 'book', '', 'Pièce', '', 10),
        $item('Jus d\'avocat', 'BOISSONS', '', 9.00, 28.00, 50, 10, 'jus, avocat', 'Avocat, lait et amandes', 'book', '', 'Pièce', '', 10),
        $item('Eau minérale 50 cl', 'BOISSONS', 'SIDI ALI', 3.00, 10.00, 240, 40, 'eau, bouteille', 'Bouteille d\'eau minérale 50 cl', 'book', '', 'Pièce', '', 20),
        $item('Eau gazeuse 50 cl', 'BOISSONS', 'OULMÈS', 4.00, 14.00, 120, 25, 'eau, gazeuse', 'Bouteille d\'eau gazeuse 50 cl', 'book', '', 'Pièce', '', 20),
        $item('Soda 33 cl', 'BOISSONS', 'COCA-COLA', 4.50, 14.00, 200, 40, 'soda, canette', 'Canette de soda 33 cl', 'book', '', 'Pièce', '', 20),

        // ── Stock cuisine (acheté, jamais vendu) ──────────────────────────────
        $item('Farine de blé sac 25 kg', 'STOCK CUISINE', '', 240.00, 240.00, 12, 3, 'farine, cuisine', 'Sac de farine de blé 25 kg', 'supply', '', 'Pack', '', 20),
        $item('Semoule fine sac 10 kg', 'STOCK CUISINE', '', 95.00, 95.00, 15, 4, 'semoule, couscous', 'Sac de semoule fine pour couscous', 'supply', '', 'Pack', '', 20),
        $item('Huile de table 5 L', 'STOCK CUISINE', 'LESIEUR', 110.00, 110.00, 20, 5, 'huile, cuisine', 'Bidon d\'huile de table 5 litres', 'supply', '', 'Pièce', '', 20),
        $item('Huile d\'olive 5 L', 'STOCK CUISINE', '', 380.00, 380.00, 8, 2, 'huile, olive', 'Bidon d\'huile d\'olive vierge 5 litres', 'supply', '', 'Pièce', '', 20),
        $item('Bouteille de gaz 12 kg', 'STOCK CUISINE', 'AFRIQUIA', 45.00, 45.00, 10, 3, 'gaz, cuisine', 'Bouteille de gaz butane 12 kg', 'supply', '', 'Pièce', '', 20),
        $item('Charbon de bois sac 10 kg', 'STOCK CUISINE', '', 70.00, 70.00, 14, 4, 'charbon, grillade', 'Sac de charbon pour les grillades', 'supply', '', 'Pack', '', 20),
        $item('Barquettes à emporter (lot de 100)', 'STOCK CUISINE', '', 85.00, 85.00, 18, 4, 'emballage, livraison', 'Lot de 100 barquettes avec couvercle', 'supply', '', 'Pack', '', 20),
        $item('Sachets papier kraft (lot de 500)', 'STOCK CUISINE', '', 60.00, 60.00, 20, 5, 'emballage, sachet', 'Lot de 500 sachets kraft', 'supply', '', 'Pack', '', 20),
        $item('Serviettes en papier (lot de 1000)', 'STOCK CUISINE', '', 55.00, 55.00, 25, 5, 'serviette, salle', 'Lot de 1000 serviettes blanches', 'supply', '', 'Pack', '', 20),
    ],

    'services' => [
        $service('Livraison à domicile', 0.00, 15.00, 'livraison', 'Livraison dans un rayon de 5 km', 10),
        $service('Couvert et pain', 0.50, 3.00, 'couvert, pain', 'Pain maison et couvert, par personne', 10),
        $service('Privatisation de salle (soirée)', 0.00, 1500.00, 'événement, salle', 'Privatisation de la salle pour une soirée', 10),
        $service('Menu groupe par personne', 45.00, 120.00, 'groupe, menu', 'Formule groupe : entrée, plat, dessert', 10),
    ],
];

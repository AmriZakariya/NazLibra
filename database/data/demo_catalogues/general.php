<?php

/**
 * Commerce général: épicerie de quartier, droguerie et bazar.
 *
 * The only activity whose item form offers a single physical type, so every
 * row here is `supply`. That is the point of the fixture: it exercises the
 * shop that has no second type to sort things into.
 *
 * VAT: 20% on most goods; staples that Morocco exempts (bread, milk, sugar,
 * flour, tea) are at 0.
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
    'activity' => 'general',
    'title' => 'Catalogue commerce général',
    'isbn_column' => false,

    'items' => [
        // ── Épicerie ──────────────────────────────────────────────────────────
        $item('Farine de blé 1 kg', 'ÉPICERIE', '', 7.00, 11.00, 200, 40, 'farine, base', 'Paquet de farine de blé 1 kg', 'supply', '', 'Pièce', '', 0),
        $item('Sucre en poudre 1 kg', 'ÉPICERIE', 'COSUMAR', 9.00, 13.00, 240, 50, 'sucre, base', 'Paquet de sucre en poudre 1 kg', 'supply', '', 'Pièce', '', 0),
        $item('Pain de sucre 2 kg', 'ÉPICERIE', 'COSUMAR', 22.00, 30.00, 90, 20, 'sucre, pain', 'Pain de sucre traditionnel', 'supply', '', 'Pièce', '', 0),
        $item('Thé vert gunpowder 200 g', 'ÉPICERIE', 'SULTAN', 26.00, 39.00, 120, 25, 'thé, gunpowder', 'Thé vert de Chine, boîte 200 g', 'supply', '', 'Pièce', '', 0),
        $item('Huile de table 1 L', 'ÉPICERIE', 'LESIEUR', 22.00, 29.00, 180, 35, 'huile, cuisine', 'Bouteille d\'huile de table 1 litre', 'supply', '', 'Pièce', '', 20),
        $item('Huile d\'olive 1 L', 'ÉPICERIE', '', 78.00, 110.00, 60, 12, 'huile, olive', 'Huile d\'olive vierge extra 1 litre', 'supply', '', 'Pièce', '', 20),
        $item('Riz long grain 1 kg', 'ÉPICERIE', '', 14.00, 21.00, 140, 30, 'riz, base', 'Paquet de riz long grain 1 kg', 'supply', '', 'Pièce', '', 20),
        $item('Pâtes spaghetti 500 g', 'ÉPICERIE', '', 7.00, 12.00, 160, 35, 'pâtes', 'Paquet de spaghetti 500 g', 'supply', '', 'Pièce', '', 20),
        $item('Lentilles 1 kg', 'ÉPICERIE', '', 18.00, 27.00, 100, 20, 'légumineuse', 'Sachet de lentilles vertes 1 kg', 'supply', '', 'Pièce', '', 20),
        $item('Concentré de tomate 400 g', 'ÉPICERIE', '', 9.00, 15.00, 150, 30, 'tomate, conserve', 'Boîte de concentré de tomate', 'supply', '', 'Boîte', '', 20),
        $item('Thon à l\'huile 160 g', 'ÉPICERIE', '', 11.00, 18.00, 180, 35, 'thon, conserve', 'Boîte de thon à l\'huile végétale', 'supply', '', 'Boîte', '', 20),
        $item('Sardines à la tomate 125 g', 'ÉPICERIE', '', 6.00, 10.00, 220, 45, 'sardine, conserve', 'Boîte de sardines sauce tomate', 'supply', '', 'Boîte', '', 20),
        $item('Lait UHT 1 L', 'ÉPICERIE', 'CENTRALE', 8.00, 11.00, 200, 40, 'lait', 'Brique de lait demi-écrémé 1 litre', 'supply', '', 'Pièce', '', 0),
        $item('Café moulu 250 g', 'ÉPICERIE', '', 32.00, 48.00, 90, 18, 'café, moulu', 'Paquet de café moulu 250 g', 'supply', '', 'Pièce', '', 20),
        $item('Biscuits assortis 300 g', 'ÉPICERIE', '', 14.00, 23.00, 130, 25, 'biscuits, goûter', 'Paquet de biscuits assortis', 'supply', '', 'Pièce', '', 20),

        // ── Boissons ──────────────────────────────────────────────────────────
        $item('Eau minérale 1,5 L', 'BOISSONS', 'SIDI ALI', 4.50, 7.00, 300, 60, 'eau, bouteille', 'Bouteille d\'eau minérale 1,5 litre', 'supply', '', 'Pièce', '', 20),
        $item('Eau gazeuse 1 L', 'BOISSONS', 'OULMÈS', 6.00, 10.00, 150, 30, 'eau, gazeuse', 'Bouteille d\'eau gazeuse 1 litre', 'supply', '', 'Pièce', '', 20),
        $item('Soda 1,5 L', 'BOISSONS', 'COCA-COLA', 10.00, 16.00, 180, 35, 'soda, bouteille', 'Bouteille de soda 1,5 litre', 'supply', '', 'Pièce', '', 20),
        $item('Jus de fruits 1 L', 'BOISSONS', '', 12.00, 19.00, 120, 25, 'jus, brique', 'Brique de jus de fruits 1 litre', 'supply', '', 'Pièce', '', 20),

        // ── Droguerie et entretien ────────────────────────────────────────────
        $item('Eau de javel 1 L', 'DROGUERIE', '', 7.00, 12.00, 160, 30, 'javel, entretien', 'Bouteille d\'eau de javel 1 litre', 'supply', '', 'Pièce', '', 20),
        $item('Lessive en poudre 3 kg', 'DROGUERIE', 'TIDE', 55.00, 85.00, 70, 15, 'lessive, linge', 'Baril de lessive en poudre 3 kg', 'supply', '', 'Pièce', '', 20),
        $item('Liquide vaisselle 1 L', 'DROGUERIE', '', 16.00, 26.00, 120, 25, 'vaisselle, entretien', 'Flacon de liquide vaisselle 1 litre', 'supply', '', 'Pièce', '', 20),
        $item('Savon de Marseille 300 g', 'DROGUERIE', '', 9.00, 15.00, 140, 30, 'savon, linge', 'Cube de savon de Marseille', 'supply', '', 'Pièce', '', 20),
        $item('Éponges grattantes (lot de 3)', 'DROGUERIE', '', 8.00, 14.00, 150, 30, 'éponge, vaisselle', 'Lot de trois éponges grattantes', 'supply', '', 'Pack', '', 20),
        $item('Papier hygiénique (lot de 6)', 'DROGUERIE', '', 22.00, 35.00, 110, 25, 'papier, hygiène', 'Lot de 6 rouleaux double épaisseur', 'supply', '', 'Pack', '', 20),
        $item('Sacs poubelle 50 L (lot de 20)', 'DROGUERIE', '', 14.00, 24.00, 100, 20, 'poubelle, sacs', 'Rouleau de 20 sacs poubelle 50 litres', 'supply', '', 'Pack', '', 20),
        $item('Insecticide aérosol 300 ml', 'DROGUERIE', '', 25.00, 42.00, 60, 12, 'insecticide', 'Aérosol insecticide multi-usages', 'supply', '', 'Pièce', '', 20),

        // ── Bazar et maison ───────────────────────────────────────────────────
        $item('Ampoule LED 9 W', 'BAZAR', '', 12.00, 22.00, 140, 30, 'ampoule, led', 'Ampoule LED culot E27, blanc chaud', 'supply', '', 'Pièce', '', 20),
        $item('Rallonge électrique 3 m', 'BAZAR', '', 35.00, 59.00, 45, 10, 'rallonge, électricité', 'Rallonge trois prises, câble 3 m', 'supply', '', 'Pièce', '', 20),
        $item('Piles AA (lot de 4)', 'BAZAR', 'DURACELL', 22.00, 38.00, 120, 25, 'piles', 'Lot de 4 piles alcalines AA', 'supply', '', 'Pack', '', 20),
        $item('Briquet', 'BAZAR', 'BIC', 3.00, 6.00, 250, 50, 'briquet', 'Briquet jetable', 'supply', '', 'Pièce', '', 20),
        $item('Bougies (lot de 6)', 'BAZAR', '', 10.00, 18.00, 90, 20, 'bougie', 'Lot de six bougies blanches', 'supply', '', 'Pack', '', 20),
        $item('Seau plastique 12 L', 'BAZAR', '', 18.00, 32.00, 60, 12, 'seau, ménage', 'Seau plastique gradué 12 litres', 'supply', '', 'Pièce', '', 20),
        $item('Serpillière coton', 'BAZAR', '', 14.00, 25.00, 80, 18, 'serpillière, ménage', 'Serpillière coton renforcée', 'supply', '', 'Pièce', '', 20),
        $item('Gants ménage taille M', 'BAZAR', '', 11.00, 20.00, 90, 20, 'gants, ménage', 'Paire de gants de ménage latex', 'supply', '', 'Pièce', '', 20),
    ],

    'services' => [
        $service('Recharge téléphonique', 0.00, 20.00, 'recharge, téléphone', 'Recharge de crédit téléphonique', 20),
        $service('Livraison de quartier', 0.00, 10.00, 'livraison', 'Livraison dans le quartier', 20),
        $service('Photocopie A4', 0.20, 0.50, 'photocopie', 'Photocopie A4 noir et blanc', 20),
    ],
];

<?php

/**
 * Habillement / textile: prêt-à-porter homme, femme et enfant, plus les
 * accessoires et la chaussure.
 *
 * Garments are ItemType::Clothing and accessories `supply`, which is the
 * split this activity's item form offers. Size and colour live in the item
 * name here rather than as variants: the importer creates one row per line,
 * and a demo catalogue reads better as a rail than as a variant matrix.
 *
 * VAT: 20% throughout, which is the standard Moroccan rate for textile.
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
    'activity' => 'clothing',
    'title' => 'Catalogue prêt-à-porter',
    'isbn_column' => false,

    'items' => [
        // ── Homme ─────────────────────────────────────────────────────────────
        $item('Chemise coton homme - blanc', 'HOMME', 'ATLAS TEXTILE', 110.00, 249.00, 45, 8, 'chemise, homme, coton', 'Chemise manches longues, coton peigné, coupe droite', 'clothing', '', 'Pièce', '', 20),
        $item('Chemise coton homme - bleu ciel', 'HOMME', 'ATLAS TEXTILE', 110.00, 249.00, 40, 8, 'chemise, homme, coton', 'Chemise manches longues, coton peigné, coupe droite', 'clothing', '', 'Pièce', '', 20),
        $item('Polo piqué homme - marine', 'HOMME', 'ATLAS TEXTILE', 75.00, 169.00, 60, 12, 'polo, homme', 'Polo piqué coton, col côtelé', 'clothing', '', 'Pièce', '', 20),
        $item('T-shirt col rond homme - noir', 'HOMME', 'ATLAS TEXTILE', 42.00, 99.00, 90, 18, 't-shirt, homme', 'T-shirt jersey coton, col rond', 'clothing', '', 'Pièce', '', 20),
        $item('Jean droit homme - brut', 'HOMME', 'DENIM CASA', 165.00, 349.00, 35, 8, 'jean, homme, denim', 'Jean cinq poches, coupe droite, denim brut', 'clothing', '', 'Pièce', '', 20),
        $item('Pantalon chino homme - beige', 'HOMME', 'ATLAS TEXTILE', 130.00, 289.00, 40, 8, 'chino, homme', 'Chino coton stretch, coupe ajustée', 'clothing', '', 'Pièce', '', 20),
        $item('Veste blazer homme - anthracite', 'HOMME', 'MAISON RABAT', 380.00, 799.00, 15, 3, 'blazer, homme, costume', 'Blazer deux boutons, doublure intégrale', 'clothing', '', 'Pièce', '', 20),
        $item('Pull col V homme - gris chiné', 'HOMME', 'ATLAS TEXTILE', 120.00, 259.00, 30, 6, 'pull, homme, hiver', 'Pull maille fine, col V', 'clothing', '', 'Pièce', '', 20),
        $item('Blouson matelassé homme', 'HOMME', 'MAISON RABAT', 290.00, 599.00, 20, 4, 'blouson, hiver', 'Blouson matelassé déperlant', 'clothing', '', 'Pièce', '', 20),
        $item('Djellaba homme brodée', 'HOMME', 'ARTISANAT FÈS', 220.00, 459.00, 25, 5, 'djellaba, traditionnel', 'Djellaba en laine légère, broderie sfifa', 'clothing', '', 'Pièce', '', 20),

        // ── Femme ─────────────────────────────────────────────────────────────
        $item('Blouse fluide femme - écru', 'FEMME', 'MAISON RABAT', 95.00, 219.00, 50, 10, 'blouse, femme', 'Blouse fluide manches longues, viscose', 'clothing', '', 'Pièce', '', 20),
        $item('Robe midi femme - imprimé', 'FEMME', 'MAISON RABAT', 180.00, 399.00, 30, 6, 'robe, femme', 'Robe midi ceinturée, imprimé fleuri', 'clothing', '', 'Pièce', '', 20),
        $item('Pantalon large femme - noir', 'FEMME', 'MAISON RABAT', 140.00, 299.00, 35, 8, 'pantalon, femme', 'Pantalon palazzo taille haute', 'clothing', '', 'Pièce', '', 20),
        $item('Jean slim femme - bleu délavé', 'FEMME', 'DENIM CASA', 160.00, 339.00, 40, 8, 'jean, femme', 'Jean slim taille haute, denim stretch', 'clothing', '', 'Pièce', '', 20),
        $item('Cardigan long femme - camel', 'FEMME', 'ATLAS TEXTILE', 150.00, 319.00, 25, 5, 'cardigan, femme', 'Cardigan long maille douce', 'clothing', '', 'Pièce', '', 20),
        $item('T-shirt basique femme - blanc', 'FEMME', 'ATLAS TEXTILE', 38.00, 89.00, 100, 20, 't-shirt, femme', 'T-shirt coton bio, coupe droite', 'clothing', '', 'Pièce', '', 20),
        $item('Kaftan moderne femme', 'FEMME', 'ARTISANAT FÈS', 420.00, 899.00, 12, 3, 'kaftan, traditionnel, cérémonie', 'Kaftan brodé main, ceinture assortie', 'clothing', '', 'Pièce', '', 20),
        $item('Veste en jean femme', 'FEMME', 'DENIM CASA', 175.00, 369.00, 22, 5, 'veste, denim, femme', 'Veste en jean coupe courte', 'clothing', '', 'Pièce', '', 20),
        $item('Jupe plissée femme - marine', 'FEMME', 'MAISON RABAT', 115.00, 249.00, 28, 6, 'jupe, femme', 'Jupe plissée mi-longue', 'clothing', '', 'Pièce', '', 20),

        // ── Enfant ────────────────────────────────────────────────────────────
        $item('T-shirt enfant coton - assorti', 'ENFANT', 'ATLAS TEXTILE', 28.00, 69.00, 120, 25, 't-shirt, enfant', 'T-shirt coton, coloris assortis', 'clothing', '', 'Pièce', '', 20),
        $item('Sweat à capuche enfant', 'ENFANT', 'ATLAS TEXTILE', 75.00, 159.00, 60, 12, 'sweat, enfant', 'Sweat molletonné à capuche', 'clothing', '', 'Pièce', '', 20),
        $item('Jean enfant - bleu', 'ENFANT', 'DENIM CASA', 85.00, 179.00, 55, 12, 'jean, enfant', 'Jean enfant taille réglable', 'clothing', '', 'Pièce', '', 20),
        $item('Tablier d\'école enfant', 'ENFANT', 'ATLAS TEXTILE', 45.00, 99.00, 90, 20, 'tablier, école, rentrée', 'Tablier d\'école boutonné, coton mélangé', 'clothing', '', 'Pièce', '', 20),
        $item('Survêtement enfant 2 pièces', 'ENFANT', 'ATLAS TEXTILE', 110.00, 229.00, 40, 8, 'survêtement, sport, enfant', 'Ensemble sweat et pantalon molleton', 'clothing', '', 'Pièce', '', 20),
        $item('Pyjama enfant coton', 'ENFANT', 'ATLAS TEXTILE', 55.00, 119.00, 65, 15, 'pyjama, enfant', 'Pyjama deux pièces en coton', 'clothing', '', 'Pièce', '', 20),

        // ── Chaussures ────────────────────────────────────────────────────────
        $item('Baskets homme toile - blanc', 'CHAUSSURES', 'CASA SHOES', 180.00, 379.00, 30, 6, 'baskets, homme', 'Baskets en toile, semelle caoutchouc', 'clothing', '', 'Pièce', '', 20),
        $item('Baskets femme cuir - blanc', 'CHAUSSURES', 'CASA SHOES', 220.00, 449.00, 25, 5, 'baskets, femme, cuir', 'Baskets cuir lisse, semelle légère', 'clothing', '', 'Pièce', '', 20),
        $item('Mocassins homme cuir', 'CHAUSSURES', 'CASA SHOES', 280.00, 579.00, 18, 4, 'mocassins, cuir', 'Mocassins cuir pleine fleur', 'clothing', '', 'Pièce', '', 20),
        $item('Babouches cuir traditionnelles', 'CHAUSSURES', 'ARTISANAT FÈS', 95.00, 199.00, 40, 8, 'babouches, traditionnel', 'Babouches en cuir cousu main', 'clothing', '', 'Pièce', '', 20),
        $item('Sandales enfant', 'CHAUSSURES', 'CASA SHOES', 70.00, 149.00, 45, 10, 'sandales, enfant, été', 'Sandales à scratch, semelle souple', 'clothing', '', 'Pièce', '', 20),

        // ── Accessoires ───────────────────────────────────────────────────────
        $item('Ceinture cuir homme', 'ACCESSOIRES', 'CASA SHOES', 65.00, 145.00, 50, 10, 'ceinture, cuir', 'Ceinture cuir boucle métal', 'supply', '', 'Pièce', '', 20),
        $item('Écharpe laine', 'ACCESSOIRES', 'ATLAS TEXTILE', 48.00, 109.00, 60, 12, 'écharpe, hiver', 'Écharpe en laine mélangée', 'supply', '', 'Pièce', '', 20),
        $item('Bonnet maille', 'ACCESSOIRES', 'ATLAS TEXTILE', 30.00, 69.00, 70, 15, 'bonnet, hiver', 'Bonnet maille doublé polaire', 'supply', '', 'Pièce', '', 20),
        $item('Sac à main femme', 'ACCESSOIRES', 'MAISON RABAT', 190.00, 399.00, 20, 4, 'sac, femme', 'Sac à main bandoulière amovible', 'supply', '', 'Pièce', '', 20),
        $item('Chaussettes coton (lot de 3)', 'ACCESSOIRES', 'ATLAS TEXTILE', 25.00, 59.00, 120, 25, 'chaussettes, lot', 'Lot de trois paires de chaussettes coton', 'supply', '', 'Pack', '', 20),
        $item('Cravate soie', 'ACCESSOIRES', 'MAISON RABAT', 85.00, 179.00, 25, 5, 'cravate, cérémonie', 'Cravate en soie, motif discret', 'supply', '', 'Pièce', '', 20),
        $item('Cintres (lot de 10)', 'ACCESSOIRES', '', 35.00, 35.00, 40, 10, 'cintre, magasin', 'Lot de 10 cintres de magasin', 'supply', '', 'Pack', '', 20),
        $item('Sacs boutique papier (lot de 100)', 'ACCESSOIRES', '', 120.00, 120.00, 15, 4, 'sac, emballage', 'Lot de 100 sacs boutique kraft', 'supply', '', 'Pack', '', 20),
    ],

    'services' => [
        $service('Retouche ourlet pantalon', 0.00, 50.00, 'retouche, ourlet', 'Ourlet simple, rendu sous 48 h', 20),
        $service('Retouche taille', 0.00, 80.00, 'retouche, taille', 'Reprise de taille sur pantalon ou jupe', 20),
        $service('Emballage cadeau', 3.00, 15.00, 'cadeau, emballage', 'Emballage cadeau soigné', 20),
        $service('Carte cadeau 300 MAD', 0.00, 300.00, 'cadeau, carte', 'Carte cadeau valable un an en boutique', 20),
    ],
];

<?php

/**
 * Pharmacie / parapharmacie.
 *
 * Medicines are ItemType::Medication and everything else `supply`, which is
 * the split the item form offers for this activity. Names are generic (DCI)
 * rather than brand names, because a demo catalogue should not read as advice
 * about a specific product.
 *
 * VAT: medicines are at the reduced 7% rate in Morocco, parapharmacy and
 * hygiene at 20%. The tenant seeds "TVA 7%" inactive; importing it here
 * matches that existing rate rather than creating a second one.
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
    'activity' => 'pharmacy',
    'title' => 'Catalogue pharmacie',
    'isbn_column' => false,

    'items' => [
        // ── Antalgiques et fièvre ─────────────────────────────────────────────
        $item('Paracétamol 500 mg - boîte de 20 comprimés', 'ANTALGIQUES', '', 12.00, 19.50, 180, 30, 'douleur, fièvre', 'Antalgique et antipyrétique, 20 comprimés', 'medication', '', 'Boîte', '', 7),
        $item('Paracétamol 1 g - boîte de 8 comprimés', 'ANTALGIQUES', '', 14.00, 22.00, 140, 25, 'douleur, fièvre', 'Antalgique dosé à 1 g, 8 comprimés', 'medication', '', 'Boîte', '', 7),
        $item('Ibuprofène 400 mg - boîte de 20', 'ANTALGIQUES', '', 18.00, 28.00, 120, 25, 'douleur, inflammation', 'Anti-inflammatoire non stéroïdien, 20 comprimés', 'medication', '', 'Boîte', '', 7),
        $item('Aspirine 500 mg - boîte de 20', 'ANTALGIQUES', '', 11.00, 18.00, 100, 20, 'douleur, fièvre', 'Acide acétylsalicylique, 20 comprimés', 'medication', '', 'Boîte', '', 7),
        $item('Paracétamol sirop enfant 125 ml', 'ANTALGIQUES', '', 16.00, 26.00, 90, 18, 'enfant, sirop, fièvre', 'Suspension buvable pour enfant, flacon 125 ml', 'medication', '', 'Pièce', '', 7),

        // ── Voies respiratoires ───────────────────────────────────────────────
        $item('Sirop antitussif 150 ml', 'VOIES RESPIRATOIRES', '', 24.00, 38.00, 80, 15, 'toux, sirop', 'Sirop contre la toux sèche, flacon 150 ml', 'medication', '', 'Pièce', '', 7),
        $item('Pastilles pour la gorge - boîte de 24', 'VOIES RESPIRATOIRES', '', 15.00, 25.00, 110, 20, 'gorge, pastilles', 'Pastilles adoucissantes pour la gorge', 'medication', '', 'Boîte', '', 7),
        $item('Spray nasal eau de mer 100 ml', 'VOIES RESPIRATOIRES', '', 28.00, 45.00, 95, 18, 'nez, lavage', 'Solution isotonique pour lavage nasal', 'medication', '', 'Pièce', '', 7),
        $item('Sirop expectorant 200 ml', 'VOIES RESPIRATOIRES', '', 26.00, 42.00, 70, 15, 'toux, expectorant', 'Sirop pour toux grasse, flacon 200 ml', 'medication', '', 'Pièce', '', 7),

        // ── Digestif ──────────────────────────────────────────────────────────
        $item('Pansement gastrique - 20 sachets', 'DIGESTIF', '', 30.00, 48.00, 85, 15, 'estomac, brûlures', 'Suspension buvable, 20 sachets', 'medication', '', 'Boîte', '', 7),
        $item('Antispasmodique - boîte de 20', 'DIGESTIF', '', 22.00, 35.00, 75, 15, 'spasmes, ventre', 'Comprimés antispasmodiques, boîte de 20', 'medication', '', 'Boîte', '', 7),
        $item('Solution de réhydratation orale - 10 sachets', 'DIGESTIF', '', 25.00, 40.00, 60, 12, 'diarrhée, réhydratation', 'Sachets de sels de réhydratation', 'medication', '', 'Boîte', '', 7),
        $item('Charbon végétal activé - 30 gélules', 'DIGESTIF', '', 32.00, 52.00, 55, 10, 'ballonnements, digestion', 'Gélules de charbon activé', 'medication', '', 'Boîte', '', 7),

        // ── Vitamines et compléments ──────────────────────────────────────────
        $item('Vitamine C 500 mg - 20 comprimés', 'VITAMINES ET COMPLÉMENTS', '', 20.00, 34.00, 130, 25, 'vitamine, fatigue', 'Comprimés effervescents de vitamine C', 'medication', '', 'Boîte', '', 7),
        $item('Vitamine D3 gouttes 10 ml', 'VITAMINES ET COMPLÉMENTS', '', 38.00, 62.00, 70, 15, 'vitamine, enfant', 'Solution buvable de vitamine D3', 'medication', '', 'Pièce', '', 7),
        $item('Magnésium + B6 - 45 comprimés', 'VITAMINES ET COMPLÉMENTS', '', 55.00, 89.00, 65, 12, 'magnésium, stress', 'Complément magnésium et vitamine B6', 'supply', '', 'Boîte', '', 20),
        $item('Fer + acide folique - 30 gélules', 'VITAMINES ET COMPLÉMENTS', '', 60.00, 95.00, 50, 10, 'fer, anémie', 'Complément en fer et acide folique', 'supply', '', 'Boîte', '', 20),
        $item('Complexe multivitamines - 30 comprimés', 'VITAMINES ET COMPLÉMENTS', '', 72.00, 115.00, 45, 10, 'multivitamines', 'Complément multivitaminé quotidien', 'supply', '', 'Boîte', '', 20),

        // ── Premiers soins ────────────────────────────────────────────────────
        $item('Antiseptique cutané 100 ml', 'PREMIERS SOINS', '', 18.00, 30.00, 110, 20, 'plaie, désinfectant', 'Solution antiseptique pour la peau', 'medication', '', 'Pièce', '', 7),
        $item('Compresses stériles (boîte de 20)', 'PREMIERS SOINS', '', 16.00, 27.00, 120, 25, 'compresse, plaie', 'Compresses stériles 10x10 cm', 'supply', '', 'Boîte', '', 20),
        $item('Pansements adhésifs (boîte de 20)', 'PREMIERS SOINS', '', 14.00, 24.00, 150, 30, 'pansement', 'Pansements adhésifs assortis', 'supply', '', 'Boîte', '', 20),
        $item('Bande de crêpe 7 cm', 'PREMIERS SOINS', '', 9.00, 16.00, 100, 20, 'bande, entorse', 'Bande de crêpe élastique 7 cm x 4 m', 'supply', '', 'Pièce', '', 20),
        $item('Sparadrap 2,5 cm', 'PREMIERS SOINS', '', 8.00, 14.00, 90, 18, 'sparadrap', 'Rouleau de sparadrap 2,5 cm x 5 m', 'supply', '', 'Pièce', '', 20),
        $item('Thermomètre digital', 'PREMIERS SOINS', '', 45.00, 79.00, 40, 8, 'thermomètre, fièvre', 'Thermomètre électronique embout souple', 'supply', '', 'Pièce', '', 20),

        // ── Hygiène et parapharmacie ──────────────────────────────────────────
        $item('Gel hydroalcoolique 100 ml', 'HYGIÈNE', '', 12.00, 22.00, 160, 30, 'hygiène, mains', 'Gel désinfectant pour les mains', 'supply', '', 'Pièce', '', 20),
        $item('Savon dermatologique 100 g', 'HYGIÈNE', '', 20.00, 35.00, 120, 25, 'savon, peau', 'Pain dermatologique sans savon', 'supply', '', 'Pièce', '', 20),
        $item('Shampooing doux 400 ml', 'HYGIÈNE', '', 38.00, 65.00, 80, 15, 'shampooing, cheveux', 'Shampooing usage fréquent', 'supply', '', 'Pièce', '', 20),
        $item('Crème hydratante visage 50 ml', 'HYGIÈNE', '', 75.00, 125.00, 55, 10, 'crème, visage', 'Crème hydratante pour peaux sensibles', 'supply', '', 'Pièce', '', 20),
        $item('Écran solaire SPF 50+ 50 ml', 'HYGIÈNE', '', 95.00, 159.00, 45, 10, 'solaire, protection', 'Protection solaire très haute, visage et corps', 'supply', '', 'Pièce', '', 20),
        $item('Dentifrice fluoré 75 ml', 'HYGIÈNE', '', 16.00, 28.00, 140, 25, 'dentifrice, dents', 'Dentifrice protection caries', 'supply', '', 'Pièce', '', 20),
        $item('Brosse à dents souple', 'HYGIÈNE', '', 12.00, 22.00, 130, 25, 'brosse, dents', 'Brosse à dents poils souples', 'supply', '', 'Pièce', '', 20),

        // ── Bébé et maternité ─────────────────────────────────────────────────
        $item('Couches taille 3 (paquet de 40)', 'BÉBÉ', '', 75.00, 115.00, 60, 12, 'bébé, couches', 'Paquet de 40 couches taille 3', 'supply', '', 'Pack', '', 20),
        $item('Lingettes bébé (paquet de 72)', 'BÉBÉ', '', 22.00, 38.00, 90, 18, 'bébé, lingettes', 'Lingettes douces sans parfum', 'supply', '', 'Pack', '', 20),
        $item('Lait infantile 1er âge 400 g', 'BÉBÉ', '', 95.00, 145.00, 40, 8, 'bébé, lait', 'Lait en poudre 1er âge, boîte 400 g', 'supply', '', 'Boîte', '', 20),
        $item('Crème change bébé 100 ml', 'BÉBÉ', '', 42.00, 69.00, 55, 10, 'bébé, change', 'Crème protectrice pour le change', 'supply', '', 'Pièce', '', 20),

        // ── Matériel médical ──────────────────────────────────────────────────
        $item('Tensiomètre électronique bras', 'MATÉRIEL MÉDICAL', '', 320.00, 495.00, 15, 3, 'tension, appareil', 'Tensiomètre automatique brassard', 'supply', '', 'Pièce', '', 20),
        $item('Lecteur de glycémie', 'MATÉRIEL MÉDICAL', '', 240.00, 379.00, 12, 3, 'glycémie, diabète', 'Lecteur de glycémie avec bandelettes de départ', 'supply', '', 'Pièce', '', 20),
        $item('Bandelettes glycémie (boîte de 50)', 'MATÉRIEL MÉDICAL', '', 180.00, 265.00, 25, 5, 'glycémie, bandelettes', 'Boîte de 50 bandelettes réactives', 'supply', '', 'Boîte', '', 20),
        $item('Masques chirurgicaux (boîte de 50)', 'MATÉRIEL MÉDICAL', '', 25.00, 45.00, 70, 15, 'masque, protection', 'Boîte de 50 masques trois plis', 'supply', '', 'Boîte', '', 20),
        $item('Gants latex taille M (boîte de 100)', 'MATÉRIEL MÉDICAL', '', 60.00, 95.00, 35, 8, 'gants, protection', 'Boîte de 100 gants d\'examen', 'supply', '', 'Boîte', '', 20),
    ],

    'services' => [
        $service('Prise de tension', 0.00, 10.00, 'tension, mesure', 'Mesure de la tension artérielle au comptoir', 20),
        $service('Test de glycémie', 5.00, 25.00, 'glycémie, test', 'Test capillaire de glycémie', 20),
        $service('Préparation magistrale', 0.00, 60.00, 'préparation, ordonnance', 'Préparation sur ordonnance', 7),
        $service('Livraison de médicaments', 0.00, 20.00, 'livraison', 'Livraison à domicile dans la journée', 20),
    ],
];

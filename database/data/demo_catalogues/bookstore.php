<?php

/**
 * Librairie-papeterie: books, school supplies, stationery and counter
 * services.
 *
 * Modelled on a Moroccan neighbourhood librairie. Supplies carry the stock
 * and the turnover, books carry the margin and the long tail.
 *
 * VAT follows Moroccan practice: books are exempt, everything else is at the
 * standard 20%.
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
    'activity' => 'bookstore',
    'title' => 'Catalogue librairie-papeterie',
    'isbn_column' => true,
    'items' => [
        // ── Fourniture scolaire ───────────────────────────────────────────────
        $item('Cahier 96 pages grand format seyès', 'FOURNITURE SCOLAIRE', 'OXFORD', 6.50, 11.00, 240, 40, 'rentrée, cahier, seyès', 'Cahier piqué 96 pages, grands carreaux, couverture polypropylène'),
        $item('Cahier 200 pages grand format', 'FOURNITURE SCOLAIRE', 'OXFORD', 12.00, 19.00, 160, 30, 'rentrée, cahier', 'Cahier piqué 200 pages, grands carreaux'),
        $item('Cahier de travaux pratiques 96 pages', 'FOURNITURE SCOLAIRE', 'CLAIREFONTAINE', 8.00, 14.00, 120, 25, 'rentrée, TP, dessin', 'Une page blanche, une page seyès, pour les travaux pratiques'),
        $item('Cahier petit format 48 pages', 'FOURNITURE SCOLAIRE', 'CLAIREFONTAINE', 3.00, 5.50, 300, 60, 'rentrée, cahier', 'Cahier de brouillon 17x22, 48 pages'),
        $item('Protège-cahier grand format', 'FOURNITURE SCOLAIRE', 'CALLIGRAPHE', 2.00, 4.00, 400, 80, 'rentrée, protection', 'Protège-cahier PVC 24x32, coloris assortis'),
        $item('Trousse scolaire 2 compartiments', 'FOURNITURE SCOLAIRE', 'MAPED', 22.00, 39.00, 90, 15, 'rentrée, trousse', 'Trousse deux compartiments, tissu résistant'),
        $item('Cartable primaire 38 cm', 'FOURNITURE SCOLAIRE', 'MAPED', 95.00, 165.00, 35, 8, 'rentrée, cartable', 'Cartable primaire, bretelles rembourrées, 38 cm'),
        $item('Sac à dos collège 45 cm', 'FOURNITURE SCOLAIRE', 'MAPED', 150.00, 249.00, 24, 6, 'rentrée, sac', 'Sac à dos collège deux compartiments, dos aéré'),
        $item('Ardoise blanche effaçable', 'FOURNITURE SCOLAIRE', 'MAPED', 11.00, 19.00, 70, 15, 'ardoise, primaire', 'Ardoise blanche double face avec feutre et chiffon'),
        $item('Règle plate 30 cm incassable', 'FOURNITURE SCOLAIRE', 'MAPED', 4.00, 8.00, 180, 30, 'géométrie, règle', 'Règle plate 30 cm en plastique incassable'),
        $item('Kit de géométrie 4 pièces', 'FOURNITURE SCOLAIRE', 'MAPED', 18.00, 32.00, 85, 15, 'géométrie, compas', 'Règle, équerre, rapporteur et compas en étui'),
        $item('Compas scolaire métal', 'FOURNITURE SCOLAIRE', 'STAEDTLER', 16.00, 29.00, 60, 12, 'géométrie, compas', 'Compas métal avec adaptateur crayon'),

        // ── Écriture ──────────────────────────────────────────────────────────
        $item('Stylo bille bleu pointe moyenne', 'ÉCRITURE', 'BIC', 1.40, 3.00, 900, 150, 'stylo, bleu', 'Stylo bille classique, encre bleue, pointe moyenne'),
        $item('Stylo bille noir pointe moyenne', 'ÉCRITURE', 'BIC', 1.40, 3.00, 600, 120, 'stylo, noir', 'Stylo bille classique, encre noire, pointe moyenne'),
        $item('Stylo bille rouge pointe moyenne', 'ÉCRITURE', 'BIC', 1.40, 3.00, 420, 90, 'stylo, rouge', 'Stylo bille classique, encre rouge, pointe moyenne'),
        $item('Stylo à encre gel 0.7 mm', 'ÉCRITURE', 'PILOT', 6.00, 12.00, 200, 40, 'stylo, gel', 'Stylo gel encre liquide 0.7 mm, écriture douce'),
        $item('Stylo plume scolaire', 'ÉCRITURE', 'STABILO', 28.00, 49.00, 45, 10, 'plume, écriture', 'Stylo plume ergonomique pour apprentissage, avec cartouches'),
        $item('Cartouches d\'encre bleue x6', 'ÉCRITURE', 'STABILO', 7.00, 13.00, 130, 25, 'plume, cartouche', 'Boîte de 6 cartouches d\'encre bleue effaçable', 'supply', '', 'Boîte'),
        $item('Crayon graphite HB', 'ÉCRITURE', 'STAEDTLER', 1.20, 2.50, 800, 150, 'crayon, HB', 'Crayon graphite HB bois certifié'),
        $item('Boîte de 12 crayons de couleur', 'ÉCRITURE', 'FABER-CASTELL', 17.00, 30.00, 140, 25, 'couleur, dessin', 'Étui de 12 crayons de couleur, mine résistante', 'supply', '', 'Boîte'),
        $item('Surligneur fluo jaune', 'ÉCRITURE', 'STABILO', 5.00, 10.00, 260, 50, 'surligneur, fluo', 'Surligneur pointe biseautée, encre jaune fluo'),
        $item('Pochette 4 surligneurs fluo', 'ÉCRITURE', 'STABILO', 18.00, 33.00, 95, 20, 'surligneur, fluo', 'Pochette de 4 surligneurs assortis', 'supply', '', 'Pack'),
        $item('Marqueur permanent noir', 'ÉCRITURE', 'PILOT', 6.50, 13.00, 180, 35, 'marqueur, permanent', 'Marqueur permanent pointe ogive, encre noire'),
        $item('Feutre tableau blanc bleu', 'ÉCRITURE', 'PILOT', 7.00, 14.00, 120, 25, 'tableau, effaçable', 'Feutre effaçable à sec pour tableau blanc'),
        $item('Gomme blanche plastique', 'ÉCRITURE', 'MAPED', 1.80, 4.00, 350, 70, 'gomme', 'Gomme plastique blanche sans PVC'),
        $item('Taille-crayon 2 trous avec réservoir', 'ÉCRITURE', 'MAPED', 3.50, 7.00, 210, 40, 'taille-crayon', 'Taille-crayon deux trous avec réservoir'),
        $item('Correcteur roller 5 mm', 'ÉCRITURE', 'BIC', 9.00, 17.00, 110, 20, 'correcteur', 'Roller correcteur latéral, ruban 5 mm x 6 m'),

        // ── Papeterie ─────────────────────────────────────────────────────────
        $item('Ramette papier A4 80 g 500 feuilles', 'PAPETERIE', 'NAVIGATOR', 42.00, 62.00, 150, 25, 'papier, A4, ramette', 'Ramette A4 80 g/m², 500 feuilles, blancheur 161 CIE', 'supply', '', 'Pack'),
        $item('Ramette papier A4 couleur 500 feuilles', 'PAPETERIE', 'CLAIREFONTAINE', 58.00, 89.00, 40, 8, 'papier, couleur', 'Ramette A4 80 g/m² couleurs vives assorties', 'supply', '', 'Pack'),
        $item('Ramette papier A3 80 g 500 feuilles', 'PAPETERIE', 'NAVIGATOR', 85.00, 129.00, 25, 5, 'papier, A3', 'Ramette A3 80 g/m², 500 feuilles', 'supply', '', 'Pack'),
        $item('Bloc-notes A5 spirale 100 pages', 'PAPETERIE', 'OXFORD', 9.00, 16.00, 130, 25, 'bloc, notes', 'Bloc-notes A5 à spirale, 100 pages quadrillées'),
        $item('Post-it 76x76 mm jaune', 'PAPETERIE', 'POST-IT', 11.00, 20.00, 120, 25, 'post-it, mémo', 'Bloc de 100 feuillets repositionnables jaunes'),
        $item('Colle bâton 21 g', 'PAPETERIE', 'UHU', 5.50, 11.00, 220, 40, 'colle', 'Bâton de colle 21 g, sans solvant, lavable'),
        $item('Ruban adhésif transparent 19 mm', 'PAPETERIE', 'SCOTCH', 4.00, 9.00, 190, 35, 'adhésif, scotch', 'Ruban adhésif transparent 19 mm x 33 m'),
        $item('Paire de ciseaux 17 cm', 'PAPETERIE', 'MAPED', 12.00, 22.00, 95, 20, 'ciseaux', 'Ciseaux 17 cm lames inox, anneaux souples'),
        $item('Agrafeuse demi-format', 'PAPETERIE', 'RAPID', 32.00, 58.00, 45, 10, 'agrafeuse, bureau', 'Agrafeuse métal demi-format, capacité 25 feuilles'),
        $item('Boîte 1000 agrafes 24/6', 'PAPETERIE', 'RAPID', 5.00, 10.00, 160, 30, 'agrafes', 'Boîte de 1000 agrafes galvanisées 24/6', 'supply', '', 'Boîte'),
        $item('Perforateur 2 trous', 'PAPETERIE', 'RAPID', 38.00, 69.00, 35, 8, 'perforateur, bureau', 'Perforateur deux trous, capacité 20 feuilles'),
        $item('Calculatrice scientifique collège', 'PAPETERIE', 'CASIO', 145.00, 229.00, 40, 8, 'calculatrice, collège', 'Calculatrice scientifique 240 fonctions, écran naturel'),
        $item('Calculatrice de bureau 12 chiffres', 'PAPETERIE', 'CASIO', 75.00, 125.00, 30, 6, 'calculatrice, bureau', 'Calculatrice de bureau 12 chiffres, alimentation solaire'),

        // ── Classement et archivage ───────────────────────────────────────────
        $item('Classeur à levier dos 8 cm', 'CLASSEMENT ET ARCHIVAGE', 'ELBA', 24.00, 42.00, 90, 18, 'classeur, archivage', 'Classeur à levier A4, dos 8 cm, plastifié'),
        $item('Chemise à élastique 3 rabats', 'CLASSEMENT ET ARCHIVAGE', 'ELBA', 6.00, 12.00, 200, 40, 'chemise, dossier', 'Chemise carte avec élastique, trois rabats'),
        $item('Paquet 100 pochettes perforées', 'CLASSEMENT ET ARCHIVAGE', 'ELBA', 22.00, 39.00, 70, 15, 'pochette, classeur', 'Pochettes perforées A4 cristal, paquet de 100', 'supply', '', 'Pack'),
        $item('Protège-documents 40 vues', 'CLASSEMENT ET ARCHIVAGE', 'ELBA', 18.00, 32.00, 85, 18, 'protège-documents', 'Protège-documents A4, 40 vues, couverture souple'),
        $item('Boîte à archives dos 10 cm', 'CLASSEMENT ET ARCHIVAGE', 'ELBA', 9.00, 17.00, 110, 20, 'archives, carton', 'Boîte à archives carton, dos 10 cm', 'supply', '', 'Boîte'),
        $item('Intercalaires 12 positions', 'CLASSEMENT ET ARCHIVAGE', 'ELBA', 8.00, 15.00, 95, 20, 'intercalaires', 'Jeu de 12 intercalaires carte, format A4', 'supply', '', 'Pack'),

        // ── Dessin et beaux-arts ──────────────────────────────────────────────
        $item('Bloc dessin A4 180 g 20 feuilles', 'DESSIN ET BEAUX-ARTS', 'CANSON', 16.00, 29.00, 80, 15, 'dessin, canson', 'Bloc de dessin A4, papier 180 g/m², 20 feuilles'),
        $item('Bloc dessin A3 224 g 12 feuilles', 'DESSIN ET BEAUX-ARTS', 'CANSON', 26.00, 45.00, 45, 10, 'dessin, canson', 'Bloc de dessin A3, papier 224 g/m², 12 feuilles'),
        $item('Boîte 12 tubes de gouache', 'DESSIN ET BEAUX-ARTS', 'PEBEO', 34.00, 59.00, 50, 10, 'gouache, peinture', 'Boîte de 12 tubes de gouache 12 ml', 'supply', '', 'Boîte'),
        $item('Pochette 10 pinceaux', 'DESSIN ET BEAUX-ARTS', 'PEBEO', 20.00, 36.00, 55, 12, 'pinceaux, peinture', 'Assortiment de 10 pinceaux écoliers', 'supply', '', 'Pack'),
        $item('Boîte 24 feutres de coloriage', 'DESSIN ET BEAUX-ARTS', 'FABER-CASTELL', 28.00, 49.00, 70, 15, 'feutres, coloriage', 'Boîte de 24 feutres lavables pointe moyenne', 'supply', '', 'Boîte'),
        $item('Pâte à modeler 10 couleurs', 'DESSIN ET BEAUX-ARTS', 'MAPED', 15.00, 27.00, 65, 15, 'modelage, enfant', 'Pâte à modeler souple, 10 couleurs'),

        // ── Informatique et bureautique ───────────────────────────────────────
        $item('Clé USB 32 Go', 'INFORMATIQUE ET BUREAUTIQUE', 'SANDISK', 55.00, 89.00, 60, 12, 'usb, stockage', 'Clé USB 3.0 32 Go, boîtier rétractable'),
        $item('Clé USB 64 Go', 'INFORMATIQUE ET BUREAUTIQUE', 'SANDISK', 85.00, 135.00, 40, 8, 'usb, stockage', 'Clé USB 3.0 64 Go, boîtier rétractable'),
        $item('Souris optique filaire', 'INFORMATIQUE ET BUREAUTIQUE', 'HP', 48.00, 79.00, 35, 8, 'souris, informatique', 'Souris optique USB 1000 dpi, trois boutons'),
        $item('Cartouche encre noire compatible', 'INFORMATIQUE ET BUREAUTIQUE', 'HP', 95.00, 159.00, 25, 5, 'encre, imprimante', 'Cartouche d\'encre noire compatible jet d\'encre'),
        $item('Rouleau papier thermique 57x40', 'INFORMATIQUE ET BUREAUTIQUE', 'ATLAS', 3.50, 7.00, 300, 60, 'thermique, caisse', 'Rouleau thermique 57x40 mm pour terminal de caisse'),
        $item('Rouleau papier thermique 80x80', 'INFORMATIQUE ET BUREAUTIQUE', 'ATLAS', 7.00, 13.00, 200, 40, 'thermique, caisse', 'Rouleau thermique 80x80 mm pour imprimante ticket'),

        // ── Livres scolaires ──────────────────────────────────────────────────
        $item('Manuel de mathématiques 6e année', 'LIVRES SCOLAIRES', 'LIBRAIRIE DES ÉCOLES', 48.00, 75.00, 60, 12, 'manuel, maths, primaire', 'Manuel de mathématiques, 6e année primaire, programme marocain', 'book', 'Collectif', 'Pièce', '', 0),
        $item('Manuel de français 6e année', 'LIVRES SCOLAIRES', 'NATHAN', 52.00, 82.00, 55, 12, 'manuel, français, primaire', 'Manuel de français, 6e année primaire', 'book', 'Collectif', 'Pièce', '', 0),
        $item('Manuel de physique-chimie 3e collège', 'LIVRES SCOLAIRES', 'HACHETTE', 60.00, 95.00, 45, 10, 'manuel, physique, collège', 'Manuel de physique-chimie, 3e année collège', 'book', 'Collectif', 'Pièce', '', 0),
        $item('Manuel de SVT tronc commun', 'LIVRES SCOLAIRES', 'BORDAS', 65.00, 105.00, 35, 8, 'manuel, SVT, lycée', 'Manuel de sciences de la vie et de la terre, tronc commun', 'book', 'Collectif', 'Pièce', '', 0),
        $item('Manuel d\'anglais 1re année baccalauréat', 'LIVRES SCOLAIRES', 'HACHETTE', 58.00, 92.00, 40, 8, 'manuel, anglais, lycée', 'Manuel d\'anglais, première année du baccalauréat', 'book', 'Collectif', 'Pièce', '', 0),

        // ── Parascolaire ──────────────────────────────────────────────────────
        $item('Annales corrigées baccalauréat mathématiques', 'PARASCOLAIRE', 'AL MADARISS', 45.00, 72.00, 50, 10, 'annales, bac, maths', 'Sujets et corrigés du baccalauréat, mathématiques', 'book', 'Collectif', 'Pièce', '', 0),
        $item('Cahier d\'exercices de conjugaison', 'PARASCOLAIRE', 'MAGNARD', 28.00, 45.00, 70, 15, 'exercices, conjugaison', 'Cahier d\'exercices de conjugaison avec corrigés', 'book', 'Collectif', 'Pièce', '', 0),
        $item('Bescherelle - La conjugaison pour tous', 'PARASCOLAIRE', 'HATIER', 55.00, 89.00, 45, 10, 'conjugaison, référence', 'Ouvrage de référence de la conjugaison française', 'book', 'Collectif', 'Pièce', '9782401052352', 0),
        $item('Dictionnaire Le Robert de poche', 'PARASCOLAIRE', 'LE ROBERT', 60.00, 95.00, 55, 12, 'dictionnaire, français', 'Dictionnaire de poche, 40 000 mots et définitions', 'book', 'Collectif', 'Pièce', '', 0),
        $item('Dictionnaire français-arabe', 'PARASCOLAIRE', 'DAR EL KOTOB', 75.00, 119.00, 35, 8, 'dictionnaire, arabe', 'Dictionnaire bilingue français-arabe', 'book', 'Collectif', 'Pièce', '', 0),

        // ── Romans ────────────────────────────────────────────────────────────
        $item('Le Petit Prince', 'ROMANS', 'GALLIMARD', 42.00, 69.00, 40, 8, 'roman, classique', 'Édition poche illustrée du classique d\'Antoine de Saint-Exupéry', 'book', 'Antoine de Saint-Exupéry', 'Pièce', '9782070612758', 0),
        $item('L\'Étranger', 'ROMANS', 'GALLIMARD', 45.00, 72.00, 30, 6, 'roman, classique', 'Roman, collection Folio', 'book', 'Albert Camus', 'Pièce', '', 0),
        $item('La Boîte à merveilles', 'ROMANS', 'LIBRAIRIE DES ÉCOLES', 35.00, 58.00, 65, 15, 'roman, marocain, programme', 'Roman au programme du lycée marocain', 'book', 'Ahmed Sefrioui', 'Pièce', '', 0),
        $item('Le Dernier Jour d\'un condamné', 'ROMANS', 'HACHETTE', 32.00, 52.00, 60, 12, 'roman, programme', 'Roman court au programme scolaire', 'book', 'Victor Hugo', 'Pièce', '', 0),
        $item('Antigone', 'ROMANS', 'LA TABLE RONDE', 38.00, 62.00, 50, 10, 'théâtre, programme', 'Pièce de théâtre au programme du lycée', 'book', 'Jean Anouilh', 'Pièce', '', 0),
        $item('Les Misérables - édition abrégée', 'ROMANS', 'HACHETTE', 48.00, 79.00, 25, 5, 'roman, classique', 'Édition abrégée pour la lecture scolaire', 'book', 'Victor Hugo', 'Pièce', '', 0),

        // ── Livres jeunesse ───────────────────────────────────────────────────
        $item('Imagier des animaux', 'LIVRES JEUNESSE', 'NATHAN', 38.00, 65.00, 45, 10, 'jeunesse, imagier', 'Imagier cartonné pour les tout-petits', 'book', 'Collectif', 'Pièce', '', 0),
        $item('Contes des mille et une nuits illustrés', 'LIVRES JEUNESSE', 'DAR EL KOTOB', 55.00, 89.00, 30, 6, 'jeunesse, contes', 'Recueil illustré de contes traditionnels', 'book', 'Collectif', 'Pièce', '', 0),
        $item('Album jeunesse - Mon premier alphabet', 'LIVRES JEUNESSE', 'NATHAN', 32.00, 55.00, 50, 12, 'jeunesse, alphabet', 'Album d\'apprentissage de l\'alphabet', 'book', 'Collectif', 'Pièce', '', 0),
        $item('Bande dessinée jeunesse tome 1', 'LIVRES JEUNESSE', 'GLENAT', 45.00, 75.00, 35, 8, 'jeunesse, BD', 'Bande dessinée cartonnée, premier tome', 'book', 'Collectif', 'Pièce', '', 0),

        // ── Livres religieux ──────────────────────────────────────────────────
        $item('Coran format moyen couverture rigide', 'LIVRES RELIGIEUX', 'DAR EL KOTOB', 65.00, 99.00, 40, 8, 'coran, religion', 'Coran, format moyen, couverture rigide dorée', 'book', '', 'Pièce', '', 0),
        $item('Coran de poche', 'LIVRES RELIGIEUX', 'DAR EL KOTOB', 28.00, 49.00, 70, 15, 'coran, poche', 'Coran format poche, couverture souple', 'book', '', 'Pièce', '', 0),
        $item('Recueil d\'invocations', 'LIVRES RELIGIEUX', 'DAR EL KOTOB', 18.00, 32.00, 60, 12, 'invocations, religion', 'Recueil d\'invocations quotidiennes, bilingue', 'book', '', 'Pièce', '', 0),

        // ── Jeux et loisirs créatifs ──────────────────────────────────────────
        $item('Jeu de cartes 54 cartes', 'JEUX ET LOISIRS CRÉATIFS', 'ATLAS', 6.00, 13.00, 120, 25, 'jeu, cartes', 'Jeu de 54 cartes plastifiées'),
        $item('Puzzle 500 pièces', 'JEUX ET LOISIRS CRÉATIFS', 'RAVENSBURGER', 55.00, 95.00, 25, 5, 'puzzle, jeu', 'Puzzle 500 pièces, paysage'),
        $item('Jeu d\'échecs plateau pliant', 'JEUX ET LOISIRS CRÉATIFS', 'ATLAS', 48.00, 85.00, 20, 5, 'échecs, jeu', 'Jeu d\'échecs avec plateau pliant et pièces'),
        $item('Kit de perles à repasser', 'JEUX ET LOISIRS CRÉATIFS', 'MAPED', 35.00, 62.00, 30, 8, 'créatif, perles', 'Kit créatif de perles à repasser avec plaques'),
    ],

    'services' => [
        $service('Photocopie A4 noir et blanc', 0.20, 0.50, 'photocopie, A4', 'Photocopie A4 noir et blanc, à l\'unité'),
        $service('Photocopie A4 couleur', 0.80, 2.00, 'photocopie, couleur', 'Photocopie A4 en couleur, à l\'unité'),
        $service('Photocopie A3 noir et blanc', 0.40, 1.00, 'photocopie, A3', 'Photocopie A3 noir et blanc, à l\'unité'),
        $service('Impression depuis clé USB', 0.30, 1.00, 'impression, usb', 'Impression d\'un document depuis une clé USB, la page'),
        $service('Reliure spirale jusqu\'à 100 pages', 4.00, 12.00, 'reliure', 'Reliure spirale plastique avec couvertures, jusqu\'à 100 pages'),
        $service('Plastification A4', 2.00, 6.00, 'plastification', 'Plastification à chaud d\'un document A4'),
        $service('Scan et envoi par e-mail', 0.00, 3.00, 'scan, email', 'Numérisation d\'un document et envoi par e-mail'),
        $service('Photo d\'identité x4', 3.00, 15.00, 'photo, identité', 'Quatre photos d\'identité aux normes, tirage immédiat'),
    ],

];

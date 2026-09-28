@extends('castlit.layout')

@section('title', 'Guide d’utilisation — Castl-it-POS')
@section('meta_description', 'Manuel complet de Castl-it-POS : caisse, catalogue, déclinaisons, stock, transferts, achats, clients, facturation, rapports et application mobile.')

@section('content')
<style>
    .guide { --gutter: 24px; max-width: 1180px; margin: 40px auto 0; padding: 0 var(--gutter) 64px; }
    .guide-head h1 { font-size: clamp(30px, 4.4vw, 44px); font-weight: 800; letter-spacing: -.025em; }
    .guide-head .lead { color: var(--muted); font-size: 17px; line-height: 1.65; margin-top: 12px; max-width: 70ch; }
    .guide-head .updated { color: var(--muted); font-size: 13.5px; margin-top: 10px; }

    .guide-body { display: grid; gap: 40px; margin-top: 40px; }
    @media (min-width: 1000px) { .guide-body { grid-template-columns: 236px minmax(0, 1fr); gap: 48px; } }

    .toc { align-self: start; }
    @media (min-width: 1000px) { .toc { position: sticky; top: 24px; max-height: calc(100dvh - 48px); overflow-y: auto; } }
    .toc-title { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
    .toc ol { list-style: none; margin: 12px 0 0; padding: 0; counter-reset: chap; }
    .toc li { counter-increment: chap; }
    .toc a { display: flex; gap: 10px; padding: 7px 10px; border-radius: 9px; color: var(--ink); font-size: 14.5px; font-weight: 600; text-decoration: none; line-height: 1.35; }
    .toc a::before { content: counter(chap); color: var(--muted); font-variant-numeric: tabular-nums; min-width: 1.3em; }
    .toc a:hover { background: var(--surface); color: var(--brand); }

    .guide article { scroll-margin-top: 24px; }
    .guide article + article { margin-top: 52px; padding-top: 44px; border-top: 1px solid var(--sand); }
    .guide h2 { font-size: clamp(22px, 2.6vw, 28px); font-weight: 800; letter-spacing: -.02em; }
    .guide h2 .num { color: var(--brand); font-variant-numeric: tabular-nums; margin-inline-end: 10px; }
    .guide h3 { font-size: 17px; font-weight: 800; margin: 28px 0 8px; }
    .guide p, .guide li { color: var(--ink); font-size: 15.5px; line-height: 1.72; }
    .guide p { margin-top: 10px; max-width: 74ch; }
    .guide ul, .guide ol.steps { margin: 12px 0 0; padding-inline-start: 22px; max-width: 74ch; }
    .guide li { margin-top: 7px; }
    .guide .intro { color: var(--muted); font-size: 16px; margin-top: 10px; max-width: 74ch; }
    .guide strong { font-weight: 700; }
    .guide code { background: var(--surface); border: 1px solid var(--sand); border-radius: 6px; padding: 1px 6px; font-size: 13.5px; }

    .path { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; background: var(--surface); border: 1px solid var(--sand); border-radius: 999px; padding: 4px 12px; font-size: 13.5px; font-weight: 600; color: var(--ink); }
    .path .sep { color: var(--muted); }

    .note { margin-top: 16px; border-radius: 13px; padding: 14px 16px; border: 1px solid var(--sand); background: var(--surface); max-width: 74ch; }
    .note p { margin-top: 0; font-size: 14.5px; }
    .note p + p { margin-top: 8px; }
    .note .tag { display: block; font-size: 11.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 5px; }
    /* Teintes posées SUR le fond du thème, jamais un blanc cassé en dur :
       le site marketing est sombre, et un encadré clair y rendait son propre
       texte illisible. */
    .note.warn { border-color: color-mix(in srgb, #F59E0B 55%, var(--sand)); background: color-mix(in srgb, #F59E0B 9%, var(--surface)); }
    .note.warn .tag { color: #F0A92C; }
    .note.key { border-color: color-mix(in srgb, var(--brand) 55%, var(--sand)); background: color-mix(in srgb, var(--brand) 9%, var(--surface)); }
    .note.key .tag { color: color-mix(in srgb, var(--brand) 70%, white); }

    .cards { display: grid; gap: 14px; margin-top: 16px; }
    @media (min-width: 680px) { .cards.two { grid-template-columns: 1fr 1fr; } }
    .card { border: 1px solid var(--sand); border-radius: 13px; padding: 16px 18px; background: var(--surface); }
    .card h4 { font-size: 15px; font-weight: 800; margin: 0; }
    .card p { font-size: 14.5px; margin-top: 7px; }
    .card ul { margin-top: 9px; padding-inline-start: 19px; }
    .card li { font-size: 14.5px; margin-top: 5px; }

    .flow { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 16px; }
    .flow .step { border: 1px solid var(--sand); background: var(--surface); border-radius: 999px; padding: 6px 14px; font-size: 14px; font-weight: 700; }
    .flow .step.on { border-color: var(--brand); color: var(--brand); }
    .flow .arrow { color: var(--muted); font-weight: 700; }

    table.ref { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 14.5px; }
    table.ref th, table.ref td { text-align: start; padding: 9px 12px; border-bottom: 1px solid var(--sand); vertical-align: top; }
    table.ref th { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); font-weight: 800; }
    table.ref td:first-child { font-weight: 700; white-space: nowrap; }
    .table-wrap { overflow-x: auto; }

    [dir="rtl"] .guide ul, [dir="rtl"] .guide ol.steps { padding-inline-start: 22px; }
</style>

@php
    $chapters = [
        'demarrer'      => 'Démarrer',
        'caisse'        => 'Vendre à la caisse',
        'tiroir'        => 'Tiroir-caisse',
        'catalogue'     => 'Catalogue',
        'declinaisons'  => 'Déclinaisons & options',
        'stock'         => 'Stock',
        'transferts'    => 'Transferts',
        'achats'        => 'Achats & fournisseurs',
        'clients'       => 'Clients & encours',
        'facturation'   => 'Facturation & devis',
        'commandes'     => 'Commandes & livraisons',
        'finance'       => 'Comptes & dépenses',
        'rapports'      => 'Rapports',
        'mobile'        => 'Application mobile',
        'parametres'    => 'Paramètres & équipe',
    ];
@endphp

<div class="guide">
    <div class="guide-head">
        <h1>Guide d’utilisation</h1>
        <p class="lead">
            Tout ce que fait Castl-it-POS, module par module : de la première connexion à la clôture
            du tiroir, sur le web comme sur l’application mobile. Gardez cette page ouverte pendant
            vos premières journées — elle suit l’ordre dans lequel on découvre le logiciel.
        </p>
        <p class="updated">Dernière mise à jour : 28 septembre 2026 · <a href="mailto:{{ config('castlit.brand.email') }}">{{ config('castlit.brand.email') }}</a></p>
    </div>

    <div class="guide-body">
        <nav class="toc" aria-label="Sommaire">
            <p class="toc-title">Sommaire</p>
            <ol>
                @foreach ($chapters as $anchor => $label)
                    <li><a href="#{{ $anchor }}">{{ $label }}</a></li>
                @endforeach
            </ol>
        </nav>

        <div>
            {{-- ─── 1 ─────────────────────────────────────────────────────── --}}
            <article id="demarrer">
                <h2><span class="num">1</span>Démarrer</h2>
                <p class="intro">Ce qu’il faut régler une seule fois, avant la première vente.</p>

                <h3>Se connecter</h3>
                <p>
                    Votre boutique a sa propre adresse, de la forme <code>votre-nom.{{ $mainDomain }}</code>.
                    Ouvrez-la dans un navigateur, saisissez l’email et le mot de passe reçus, et vous
                    arrivez sur le <strong>Tableau de bord</strong>.
                </p>

                <h3>Vérifier le magasin courant</h3>
                <p>
                    En haut de l’écran, à côté du tiroir, figure le <strong>magasin courant</strong>.
                    C’est l’emplacement sur lequel vos ventes seront enregistrées et sur lequel le
                    stock sera décompté. Si vous avez plusieurs points de vente, vérifiez-le avant de
                    vendre.
                </p>
                <p><span class="path">Paramètres <span class="sep">›</span> Store &amp; activité <span class="sep">›</span> Emplacements</span></p>
                <p>
                    Cette page liste vos magasins, dépôts, rayons et succursales. Chaque carte porte
                    son type, son état, ses coordonnées, et un bouton <strong>Définir comme
                    courant</strong>.
                </p>

                <div class="note key">
                    <span class="tag">À retenir</span>
                    <p>
                        Un <strong>emplacement</strong> est une identité de stock, pas une étiquette.
                        Le stock est compté par emplacement : les mêmes articles à la boutique et au
                        dépôt sont deux quantités distinctes. C’est ce qui permet les transferts et un
                        inventaire qui tombe juste.
                    </p>
                </div>

                <h3>Créer votre équipe</h3>
                <p><span class="path">Paramètres <span class="sep">›</span> Compte &amp; équipe <span class="sep">›</span> Utilisateurs</span></p>
                <p>
                    Chaque personne a son compte, son rôle et, si vous le souhaitez, un <strong>code
                    PIN</strong> à quatre chiffres pour reprendre la main en caisse sans ressaisir un
                    mot de passe. Les rôles décident de ce que chacun peut faire — voyez le
                    <a href="#parametres">chapitre 15</a>.
                </p>

                <h3>Choisir vos modules</h3>
                <p><span class="path">Paramètres <span class="sep">›</span> Store &amp; activité <span class="sep">›</span> Modules</span></p>
                <p>
                    Castl-it-POS couvre beaucoup de métiers. Désactivez ce qui ne vous concerne pas :
                    les entrées disparaissent du menu et l’application devient plus simple pour votre
                    équipe. Vous pourrez les réactiver à tout moment.
                </p>
            </article>

            {{-- ─── 2 ─────────────────────────────────────────────────────── --}}
            <article id="caisse">
                <h2><span class="num">2</span>Vendre à la caisse</h2>
                <p class="intro">L’écran que votre caissier utilisera toute la journée.</p>

                <h3>Trouver un article</h3>
                <ul>
                    <li><strong>Scanner</strong> le code-barres : l’article part directement au panier.</li>
                    <li><strong>Chercher</strong> par titre, ISBN ou référence dans la barre de recherche.</li>
                    <li><strong>Cliquer</strong> sur une vignette du catalogue, filtrée par famille ou catégorie.</li>
                </ul>
                <p>
                    Un article vendu en plusieurs tailles ou couleurs ouvre un choix avant d’entrer au
                    panier ; un article qui propose des suppléments ouvre la liste des options. Les
                    deux sont expliqués au <a href="#declinaisons">chapitre 5</a>.
                </p>

                <h3>Le panier</h3>
                <p>
                    Chaque ligne se modifie sur place : quantité, remise de ligne, note. Le total, la
                    TVA et le reste à payer se recalculent immédiatement. Vous pouvez rattacher un
                    <strong>client</strong> à la vente pour l’historique, la fidélité ou un règlement
                    différé.
                </p>

                <h3>Encaisser</h3>
                <p>
                    Saisissez le montant reçu par mode de règlement — <strong>espèces</strong>,
                    <strong>carte</strong>, <strong>virement</strong>, <strong>avance client</strong>.
                    Un même ticket peut en combiner plusieurs : c’est le paiement mixte. La monnaie à
                    rendre s’affiche dès que l’espèce dépasse le dû.
                </p>

                <h3>Mettre en attente</h3>
                <p>
                    <strong>Mettre en attente</strong> met le panier de côté et libère la caisse pour
                    le client suivant. Les tickets en attente se retrouvent dans la liste, se
                    rattachent à un client, s’archivent, et se reprennent exactement dans l’état où ils
                    ont été laissés — taille et options comprises.
                </p>

                <h3>Retours</h3>
                <p>
                    Depuis l’historique des ventes, ouvrez le ticket et choisissez les lignes à
                    rendre. Vous décidez si la marchandise revient en stock vendable ou si elle est
                    <strong>abîmée</strong>, et l’impact sur le tiroir est enregistré avec le retour.
                </p>

                <div class="note">
                    <span class="tag">Raccourci</span>
                    <p>
                        La barre de recherche globale (en haut, <code>⌘K</code> ou <code>Ctrl+K</code>)
                        trouve une section, une action ou un paramètre sans passer par le menu.
                    </p>
                </div>
            </article>

            {{-- ─── 3 ─────────────────────────────────────────────────────── --}}
            <article id="tiroir">
                <h2><span class="num">3</span>Tiroir-caisse</h2>
                <p class="intro">Ouvrir le matin, justifier le soir.</p>

                <div class="flow">
                    <span class="step on">Ouverture</span><span class="arrow">→</span>
                    <span class="step on">Mouvements de la journée</span><span class="arrow">→</span>
                    <span class="step on">Clôture</span>
                </div>

                <h3>Ouvrir</h3>
                <p>
                    Déclarez le <strong>fond de caisse</strong> — les espèces présentes avant la
                    première vente. Tant que le tiroir est fermé, l’indicateur en haut de l’écran le
                    signale.
                </p>

                <h3>Pendant la journée</h3>
                <p>
                    Chaque vente en espèces alimente le tiroir automatiquement. Vous pouvez enregistrer
                    à la main les <strong>entrées et sorties</strong> qui n’ont rien à voir avec une
                    vente : un achat de fournitures, un appoint, un prélèvement.
                </p>

                <h3>Clôturer</h3>
                <p>
                    Comptez physiquement les espèces et saisissez le montant. Castl-it-POS affiche le
                    <strong>solde attendu</strong>, calculé à partir du fond de caisse et des
                    mouvements, et l’<strong>écart</strong> s’il y en a un. L’écart est conservé : c’est
                    une information, pas une faute à cacher.
                </p>
            </article>

            {{-- ─── 4 ─────────────────────────────────────────────────────── --}}
            <article id="catalogue">
                <h2><span class="num">4</span>Catalogue</h2>
                <p class="intro">Vos articles, vos services et les listes qui les rangent.</p>

                <h3>Créer un article</h3>
                <p><span class="path">Catalogue <span class="sep">›</span> Nouvel article</span></p>
                <p>
                    Le minimum est un <strong>titre</strong> et un <strong>prix de vente</strong>.
                    Renseignez en plus, quand vous les avez : prix d’achat (pour connaître votre
                    marge), code-barres, référence interne, catégorie, marque, unité et taux de TVA.
                </p>
                <p>
                    Un <strong>service</strong> — une réparation, une impression, une prestation — se
                    crée à part : il se vend, mais ne se compte pas en stock.
                </p>

                <h3>Catégories, marques, unités</h3>
                <p>
                    Trois listes de référence, chacune avec sa propre page. Elles servent à filtrer le
                    catalogue, à organiser l’écran de caisse et à regrouper vos rapports. Une catégorie
                    peut avoir une sous-catégorie.
                </p>

                <h3>Importer en masse</h3>
                <p><span class="path">Catalogue <span class="sep">›</span> Importer</span></p>
                <p>
                    Pour démarrer avec un catalogue existant, téléchargez le <strong>fichier
                    d’exemple</strong>, remplissez-le, puis importez-le. Le format attendu y est déjà :
                    ne changez pas les en-têtes de colonnes. Les catégories, marques et fournisseurs
                    s’importent de la même façon.
                </p>

                <h3>Étiquettes</h3>
                <p>
                    <strong>Imprimer les étiquettes</strong> génère des planches de codes-barres avec
                    le prix, au format de votre choix. Utile après un import ou une réception.
                </p>
            </article>

            {{-- ─── 5 ─────────────────────────────────────────────────────── --}}
            <article id="declinaisons">
                <h2><span class="num">5</span>Déclinaisons &amp; options de ligne</h2>
                <p class="intro">
                    Le même article vendu sous plusieurs formes. C’est le point qui demande le plus
                    d’attention, parce que le mauvais choix ne se voit qu’à l’inventaire.
                </p>

                <div class="note key">
                    <span class="tag">La seule question à se poser</span>
                    <p><strong>Ai-je besoin de compter ce stock séparément ?</strong></p>
                    <p>Oui → une <strong>déclinaison</strong>. Non → une <strong>option de ligne</strong>.</p>
                </div>

                <div class="cards two">
                    <div class="card">
                        <h4>Déclinaison — stock propre</h4>
                        <p>Le même article existe en plusieurs formes, chacune comptée à part.</p>
                        <ul>
                            <li>Chemise en <strong>S / M / L</strong></li>
                            <li>Stylo <strong>bleu / noir / rouge</strong></li>
                        </ul>
                        <p>
                            Chaque forme a son stock, et peut avoir son prix et son code-barres. C’est
                            ce qui permet de répondre à « combien de L ai-je vendu ce mois-ci ? ».
                        </p>
                    </div>
                    <div class="card">
                        <h4>Option de ligne — pas de stock propre</h4>
                        <p>Un choix fait au moment de la vente, qui change le prix et le ticket.</p>
                        <ul>
                            <li><strong>Supplément fromage</strong> +5 DH</li>
                            <li><strong>Sans oignon</strong>, <strong>emballage cadeau</strong></li>
                        </ul>
                        <p>
                            Une option peut consommer le stock d’un <em>autre</em> article — le fromage
                            — mais elle n’en a pas pour elle-même.
                        </p>
                    </div>
                </div>

                <h3>Créer des déclinaisons</h3>
                <p><span class="path">Catalogue <span class="sep">›</span> Variantes</span></p>
                <ol class="steps">
                    <li>
                        Créez l’<strong>axe</strong> et ses valeurs : une option nommée « Taille », avec
                        les valeurs S, M, L. Rangez-les dans l’ordre de la boutique, pas dans l’ordre
                        alphabétique — c’est celui que verra le caissier.
                    </li>
                    <li>
                        Choisissez l’<strong>article</strong>, puis cochez les valeurs qui existent
                        réellement pour lui. Un axe se réutilise sur tous vos articles.
                    </li>
                    <li>
                        <strong>Générez</strong> : chaque combinaison cochée devient une déclinaison
                        avec son propre stock. Relancer la génération n’ajoute que ce qui manque, sans
                        toucher aux stocks et aux prix déjà saisis.
                    </li>
                </ol>
                <p>
                    Ensuite, entrez le stock de chaque déclinaison comme pour n’importe quel article,
                    par un ajustement ou une réception d’achat.
                </p>

                <h3>Créer des options de ligne</h3>
                <p>
                    Sur la même page, plus bas. Créez un <strong>groupe</strong> — « Suppléments »,
                    « Cuisson » — puis ses options. Le groupe porte deux règles :
                </p>
                <ul>
                    <li><strong>Minimum</strong> : à 1, le caissier <em>doit</em> choisir (une cuisson, par exemple).</li>
                    <li><strong>Maximum</strong> : laissé vide, il n’y a pas de limite ; à 1, le groupe se comporte comme un choix unique.</li>
                </ul>
                <p>
                    Chaque option porte un <strong>écart de prix</strong> signé : <code>+5</code> pour
                    un supplément, <code>-2</code> pour un retrait. Elle peut être liée à un article du
                    catalogue, et consommera alors son stock à chaque vente — trois burgers avec
                    supplément fromage retirent trois portions de fromage.
                </p>
                <p>Enfin, cochez les articles auxquels le groupe s’applique.</p>

                <div class="note warn">
                    <span class="tag">Erreur fréquente</span>
                    <p>
                        Créer les tailles en <strong>options de ligne</strong>. Le ticket sera juste,
                        mais aucun stock ne sera compté par taille : vous ne saurez jamais combien de L
                        il vous reste, et l’erreur n’apparaît qu’au moment de l’inventaire.
                    </p>
                </div>
            </article>

            {{-- ─── 6 ─────────────────────────────────────────────────────── --}}
            <article id="stock">
                <h2><span class="num">6</span>Stock</h2>
                <p class="intro">Ce que vous avez, où, et pourquoi cela a bougé.</p>

                <h3>Vue principale</h3>
                <p>
                    L’état du stock par article et par emplacement, avec la valorisation d’achat et les
                    <strong>alertes</strong> pour tout ce qui passe sous son seuil. Le seuil se règle
                    sur la fiche article.
                </p>

                <h3>Ajustements</h3>
                <p><span class="path">Stock <span class="sep">›</span> Ajouter ajustement</span></p>
                <p>
                    C’est la porte d’entrée et de sortie manuelle. Cherchez les articles, puis pour
                    chaque ligne choisissez :
                </p>
                <ul>
                    <li><strong>Ajouter</strong> — une entrée : un réassort, une correction à la hausse.</li>
                    <li><strong>Retirer</strong> — une sortie : une casse, une perte, un vol.</li>
                    <li><strong>Définir stock</strong> — vous donnez la quantité finale, le système calcule l’écart.</li>
                </ul>
                <p>
                    Indiquez une <strong>raison</strong> : c’est elle qui rendra l’historique lisible
                    dans six mois.
                </p>

                <h3>Inventaires</h3>
                <p>
                    Un inventaire vous donne la liste à compter, ligne par ligne — chaque taille
                    apparaît séparément — et enregistre en une fois tous les écarts constatés.
                </p>

                <h3>Historique des mouvements</h3>
                <p>
                    Chaque entrée et chaque sortie est conservée avec sa date, son motif, son
                    emplacement, la personne et le document qui l’a provoquée. Rien n’est modifié après
                    coup : une erreur se corrige par un mouvement inverse, jamais par une gomme.
                </p>
            </article>

            {{-- ─── 7 ─────────────────────────────────────────────────────── --}}
            <article id="transferts">
                <h2><span class="num">7</span>Transferts entre emplacements</h2>
                <p class="intro">Déplacer de la marchandise d’un magasin ou d’un dépôt vers un autre.</p>

                <div class="flow">
                    <span class="step">Brouillon</span><span class="arrow">→</span>
                    <span class="step">En transit</span><span class="arrow">→</span>
                    <span class="step">Reçu</span>
                </div>

                <p>
                    Un transfert suit les marchandises : elles quittent la source au moment de
                    l’envoi, et n’arrivent à destination qu’à la réception. Entre les deux, elles sont
                    <strong>en transit</strong> — sorties d’un côté, pas encore comptées de l’autre.
                </p>

                <h3>Préparer</h3>
                <p><span class="path">Stock <span class="sep">›</span> Ajouter transfert</span></p>
                <p>
                    Choisissez la <strong>source</strong> et la <strong>destination</strong> — elles ne
                    peuvent pas être identiques — puis cherchez vos articles. Les quantités disponibles
                    affichées sont celles de la source, pas votre stock total.
                </p>
                <p>Trois façons de conclure, selon votre organisation :</p>
                <div class="table-wrap">
                    <table class="ref">
                        <thead><tr><th>Bouton</th><th>Ce qui se passe</th><th>Quand l’utiliser</th></tr></thead>
                        <tbody>
                            <tr><td>Enregistrer le brouillon</td><td>Rien ne bouge. Le document reste modifiable.</td><td>Quelqu’un d’autre chargera la camionnette plus tard.</td></tr>
                            <tr><td>Créer et envoyer</td><td>Le stock quitte la source. En transit.</td><td>Les marchandises partent maintenant, on les comptera à l’arrivée.</td></tr>
                            <tr><td>Transférer maintenant</td><td>Départ et arrivée en une fois.</td><td>Les deux emplacements sont dans le même local.</td></tr>
                        </tbody>
                    </table>
                </div>

                <h3>Réceptionner</h3>
                <p>
                    À l’arrivée, ouvrez le transfert et confirmez la réception. Si tout est arrivé,
                    laissez les quantités telles quelles. <strong>Si une partie manque</strong>,
                    corrigez la ligne concernée : seule la quantité réellement reçue entre en stock, et
                    l’<strong>écart</strong> reste inscrit sur le transfert avec votre note.
                </p>

                <h3>Annuler, dupliquer</h3>
                <p>
                    Une annulation défait ce qui a réellement eu lieu : rien pour un brouillon, un
                    retour à la source pour un transfert en transit, et après réception uniquement la
                    quantité <em>arrivée</em> — ce qui s’est perdu en route reste perdu.
                    <strong>Dupliquer</strong> recopie les lignes dans un nouveau brouillon, aux
                    quantités envoyées : pratique pour un réassort qui se répète.
                </p>

                <div class="note">
                    <span class="tag">Droits</span>
                    <p>
                        Envoyer et réceptionner sont deux droits distincts. Vous pouvez confier l’envoi
                        au magasin d’origine et la réception à celui qui reçoit — voyez le
                        <a href="#parametres">chapitre 15</a>.
                    </p>
                </div>
            </article>

            {{-- ─── 8 ─────────────────────────────────────────────────────── --}}
            <article id="achats">
                <h2><span class="num">8</span>Achats &amp; fournisseurs</h2>
                <p class="intro">Faire entrer la marchandise et suivre ce que vous devez.</p>

                <h3>Commande fournisseur</h3>
                <p>
                    Créez la commande avec ses lignes et ses prix d’achat. Tant qu’elle n’est pas
                    réceptionnée, elle n’a aucun effet sur votre stock.
                </p>

                <h3>Réception</h3>
                <p>
                    À la livraison, réceptionnez la commande : le stock entre à l’emplacement choisi et
                    le <strong>prix d’achat moyen</strong> de chaque article est mis à jour. C’est ce
                    prix qui sert ensuite à calculer vos marges et la valorisation de votre stock.
                </p>

                <h3>Règlements et retours</h3>
                <p>
                    Enregistrez les règlements au fur et à mesure, partiels si besoin : le solde
                    fournisseur se met à jour. Un <strong>retour d’achat</strong> renvoie la
                    marchandise et ajuste le stock en conséquence.
                </p>
            </article>

            {{-- ─── 9 ─────────────────────────────────────────────────────── --}}
            <article id="clients">
                <h2><span class="num">9</span>Clients &amp; encours</h2>
                <p class="intro">Qui achète chez vous, et qui vous doit quelque chose.</p>

                <h3>Fiches clients</h3>
                <p>
                    Nom, téléphone, email, adresse, type de client et étiquettes. La fiche affiche
                    l’historique complet des achats et le <strong>solde</strong> : ce qui reste dû, ou
                    l’avance disponible.
                </p>

                <h3>Avances</h3>
                <p>
                    Un acompte versé d’avance devient un crédit utilisable en caisse comme un mode de
                    règlement. Pratique pour les commandes spéciales et les clients réguliers.
                </p>

                <h3>Coupons et remises</h3>
                <p>
                    Les <strong>coupons</strong> sont des codes à usage suivi. Les <strong>remises</strong>
                    sont des règles — un pourcentage ou un montant, éventuellement limité à certains
                    modes de paiement. Une remise de ligne ou de ticket reste toujours possible à la
                    main en caisse, si votre rôle l’autorise.
                </p>

                <h3>Fidélité</h3>
                <p>
                    Si le module est activé, chaque achat crédite des points au client, et ces points
                    se convertissent en remise au moment de l’encaissement.
                </p>
            </article>

            {{-- ─── 10 ────────────────────────────────────────────────────── --}}
            <article id="facturation">
                <h2><span class="num">10</span>Facturation &amp; devis</h2>
                <p class="intro">Les documents que vos clients professionnels attendent.</p>

                <h3>Devis</h3>
                <p>
                    Un devis se prépare, s’envoie, puis s’accepte ou se refuse. Il porte une date
                    d’expiration et se <strong>convertit en facture</strong> en un clic une fois
                    accepté, sans ressaisie.
                </p>

                <h3>Factures</h3>
                <p>
                    Une facture se crée depuis une vente, depuis un devis, ou de zéro. Elle suit une
                    numérotation continue, s’imprime en PDF, et se règle en une ou plusieurs fois. Les
                    factures dont l’échéance est dépassée passent automatiquement en
                    <strong>retard</strong>.
                </p>
                <p>
                    Une facture émise ne se supprime pas : elle s’<strong>annule</strong>, ce qui laisse
                    une trace. C’est ce que demandera votre comptable.
                </p>
            </article>

            {{-- ─── 11 ────────────────────────────────────────────────────── --}}
            <article id="commandes">
                <h2><span class="num">11</span>Commandes en ligne &amp; livraisons</h2>
                <p class="intro">Ce qui se vend hors du comptoir.</p>
                <p>
                    Les <strong>précommandes</strong> arrivent de votre page en ligne ou se saisissent à
                    la main après un appel ou un message WhatsApp. Chacune suit un statut, du
                    reçu au préparé puis au retiré ou livré, et se transforme en vente lorsqu’elle est
                    encaissée.
                </p>
                <p>
                    Les <strong>bons de livraison</strong> accompagnent la marchandise et permettent de
                    suivre la préparation puis la remise au client.
                </p>
            </article>

            {{-- ─── 12 ────────────────────────────────────────────────────── --}}
            <article id="finance">
                <h2><span class="num">12</span>Comptes &amp; dépenses</h2>
                <p class="intro">Où est l’argent, et ce qu’il finance.</p>
                <p>
                    Les <strong>comptes</strong> représentent vos caisses et vos comptes bancaires.
                    Vous y enregistrez les dépôts, les retraits et les transferts d’argent entre
                    comptes, et chaque mouvement reste consultable.
                </p>
                <p>
                    Les <strong>dépenses</strong> — loyer, fournitures, transport — se rangent par
                    catégorie et se règlent depuis un compte. Elles apparaissent dans vos rapports de
                    résultat, ce qui évite de confondre chiffre d’affaires et bénéfice.
                </p>
            </article>

            {{-- ─── 13 ────────────────────────────────────────────────────── --}}
            <article id="rapports">
                <h2><span class="num">13</span>Rapports</h2>
                <p class="intro">Répondre à une question précise, pas contempler des graphiques.</p>
                <ul>
                    <li><strong>Ventes</strong> — par jour, par caissier, par magasin, par mode de règlement.</li>
                    <li><strong>Articles</strong> — les meilleures ventes, et ce qui ne tourne pas. Les déclinaisons y figurent séparément : combien de L, combien de M.</li>
                    <li><strong>Stock</strong> — valorisation, mouvements, alertes.</li>
                    <li><strong>Achats</strong> — par fournisseur, et ce qui reste à régler.</li>
                    <li><strong>Résultat</strong> — chiffre d’affaires, coût d’achat des marchandises vendues, dépenses, marge.</li>
                </ul>
                <p>Chaque rapport se filtre par période et par emplacement, et s’exporte.</p>
            </article>

            {{-- ─── 14 ────────────────────────────────────────────────────── --}}
            <article id="mobile">
                <h2><span class="num">14</span>Application mobile</h2>
                <p class="intro">La même caisse, dans la main, et qui continue de vendre sans internet.</p>

                <h3>Installer et connecter</h3>
                <p>
                    Installez l’application, saisissez l’adresse de votre boutique, puis connectez-vous
                    avec le même compte que sur le web. Donnez à chaque appareil un <strong>nom
                    distinct</strong> : c’est ce nom qui identifie le terminal.
                </p>

                <div class="note warn">
                    <span class="tag">Important</span>
                    <p>
                        Deux appareils ne doivent pas porter le même nom. Une connexion sur le second
                        déconnecterait le premier, en pleine journée.
                    </p>
                </div>

                <h3>Travailler hors ligne</h3>
                <p>
                    L’application garde votre catalogue, vos clients et votre stock sur l’appareil.
                    Coupez internet : vous continuez à vendre. Les ventes s’empilent et partent
                    d’elles-mêmes dès que la connexion revient. L’indicateur de synchronisation en haut
                    de l’écran vous dit où en sont les envois en attente.
                </p>

                <h3>Terminaux</h3>
                <p>
                    Chaque appareil est rattaché à un <strong>terminal</strong>, ce qui permet de savoir
                    quelle caisse a fait quelle vente. Un terminal n’accueille qu’une installation à la
                    fois ; il se libère depuis l’appareil lui-même ou par un administrateur.
                </p>

                <h3>Imprimantes et tiroir</h3>
                <p>
                    Déclarez vos imprimantes dans les paramètres : ticket, cuisine, ou étiquettes. Sur
                    les terminaux qui en possèdent une intégrée, elle est détectée. L’ouverture du
                    tiroir-caisse se déclenche à l’encaissement ou à la main.
                </p>

                <h3>Cuisine (KDS)</h3>
                <p>
                    Pour la restauration : les plats commandés s’affichent sur un écran en cuisine,
                    regroupés par poste, et passent de <em>à préparer</em> à <em>prêt</em> d’une
                    touche. Envoyer une nouvelle tournée sur une table déjà servie n’envoie que ce qui
                    vient d’être ajouté.
                </p>

                <h3>Synchronisation locale</h3>
                <p>
                    Plusieurs appareils dans la même boutique partagent leurs tickets en attente par le
                    réseau local, sans passer par internet. Un ticket ouvert sur une tablette est
                    verrouillé pour les autres, le temps qu’il soit encaissé.
                </p>
            </article>

            {{-- ─── 15 ────────────────────────────────────────────────────── --}}
            <article id="parametres">
                <h2><span class="num">15</span>Paramètres &amp; équipe</h2>
                <p class="intro">Ce que vous réglez une fois, puis oubliez.</p>

                <h3>Société</h3>
                <p>
                    Raison sociale, ICE, adresse, téléphone, logo. Ces informations apparaissent sur vos
                    tickets, vos factures et vos devis.
                </p>

                <h3>Emplacements</h3>
                <p>
                    Vos magasins, dépôts, rayons et succursales. Un emplacement qui détient encore du
                    stock ne peut pas être supprimé — transférez-le d’abord. Gardez toujours au moins un
                    emplacement actif.
                </p>

                <h3>Rôles et permissions</h3>
                <p>
                    Un rôle est une liste de droits. Donnez à chacun ce dont il a besoin, et rien de
                    plus : un caissier n’a pas à modifier les prix d’achat.
                </p>
                <div class="table-wrap">
                    <table class="ref">
                        <thead><tr><th>Rôle type</th><th>Ce qu’il peut faire</th></tr></thead>
                        <tbody>
                            <tr><td>Propriétaire</td><td>Tout, y compris les paramètres et les rôles.</td></tr>
                            <tr><td>Gérant</td><td>Catalogue, ventes, achats, clients, rapports.</td></tr>
                            <tr><td>Caissier</td><td>Vendre, encaisser, consulter les articles.</td></tr>
                            <tr><td>Stockiste</td><td>Catalogue, ajustements, transferts, réceptions.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p>
                    Chaque utilisateur peut aussi être limité à certains magasins. Les droits sont fins :
                    par exemple, <em>envoyer</em> un transfert et le <em>réceptionner</em> sont deux
                    droits séparés, ce qui vous permet de confier chaque étape à l’emplacement concerné.
                </p>

                <h3>Caisse &amp; stock</h3>
                <p>
                    Les règles du comptoir : autoriser ou non la vente à découvert, rendre la raison
                    d’un ajustement obligatoire, autoriser la modification des prix en caisse, masquer
                    les articles en rupture.
                </p>

                <h3>Documents</h3>
                <p>
                    L’aspect de vos tickets et de vos PDF : en-tête, mentions légales, largeur du papier,
                    préfixes de numérotation.
                </p>
            </article>

            <article id="aide" style="border-top: 1px solid var(--sand);">
                <h2>Besoin d’aide ?</h2>
                <p>
                    Une question à laquelle ce guide ne répond pas ? Écrivez-nous à
                    <a href="mailto:{{ config('castlit.brand.email') }}">{{ config('castlit.brand.email') }}</a>.
                    Dites-nous la page où vous étiez et ce que vous vouliez faire : c’est ce qui nous
                    permet de répondre vite, et d’améliorer ce guide.
                </p>
            </article>
        </div>
    </div>
</div>
@endsection

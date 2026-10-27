<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Str;

class BusinessMode
{
    public static function all(): array
    {
        return [
            'library' => [
                'label' => 'Librairie / bibliothèque',
                'short_label' => 'Librairie',
                'subtitle' => 'Livres, fournitures, abonnements et emprunts',
                'hero_title' => 'Votre commerce prêt pour les journées chargées.',
                'hero_text' => 'Pilotez catalogue, caisse, stock, clients et rapports avec une interface rapide, claire et pensée pour les pics d’activité.',
                'catalog_label' => 'Catalogue livres & articles',
                'primary_item' => 'Article / livre',
                'type_labels' => ['book' => 'Livre', 'supply' => 'Produit', 'service' => 'Service'],
                'search_placeholder' => 'Rechercher titre, ISBN, code-barres...',
                'keywords' => ['ISBN', 'auteur', 'éditeur', 'emprunts'],
            ],
            'clothing' => [
                'label' => 'Vêtements & mode',
                'short_label' => 'Mode',
                'subtitle' => 'Vêtements, chaussures, accessoires, tailles et couleurs',
                'hero_title' => 'Votre boutique prête à vendre.',
                'hero_text' => 'Gérez tailles et couleurs comme des déclinaisons avec leur propre stock, encaissez vite et suivez ce qui part le mieux.',
                'catalog_label' => 'Collection & accessoires',
                'primary_item' => 'Vêtement',
                'type_labels' => ['book' => 'Vêtement', 'supply' => 'Accessoire', 'service' => 'Service'],
                'search_placeholder' => 'Rechercher article, taille, couleur, code-barres...',
                'keywords' => ['tailles', 'couleurs', 'collection', 'soldes'],
            ],
            'grocery' => [
                'label' => 'Épicerie / supérette',
                'short_label' => 'Épicerie',
                'subtitle' => 'Alimentation, boissons, hygiène et produits du quotidien',
                'hero_title' => 'Votre épicerie prête pour le flux.',
                'hero_text' => 'Scannez, encaissez et réapprovisionnez sans perdre de temps, avec une alerte dès qu’un produit vient à manquer.',
                'catalog_label' => 'Rayons & produits',
                'primary_item' => 'Produit',
                'type_labels' => ['book' => 'Produit', 'supply' => 'Produit', 'service' => 'Service'],
                'search_placeholder' => 'Rechercher produit, marque, code-barres...',
                'keywords' => ['rayons', 'alimentation', 'boissons', 'hygiène'],
            ],
            'bakery' => [
                'label' => 'Boulangerie / pâtisserie',
                'short_label' => 'Boulangerie',
                'subtitle' => 'Pains, viennoiseries, gâteaux et commandes',
                'hero_title' => 'Votre boulangerie prête pour le matin.',
                'hero_text' => 'Encaissez au comptoir en quelques secondes, prenez les commandes de gâteaux et suivez vos ventes produit par produit.',
                'catalog_label' => 'Pains & pâtisseries',
                'primary_item' => 'Pain / pâtisserie',
                'type_labels' => ['book' => 'Pain / pâtisserie', 'supply' => 'Ingrédient', 'service' => 'Service'],
                'search_placeholder' => 'Rechercher pain, gâteau, viennoiserie...',
                'keywords' => ['pains', 'viennoiseries', 'gâteaux', 'commandes'],
            ],
            'restaurant' => [
                'label' => 'Restaurant',
                'short_label' => 'Restaurant',
                'subtitle' => 'Tables, menus, tickets cuisine et encaissement service',
                'hero_title' => 'Votre restaurant prêt pour le service.',
                'hero_text' => 'Gérez menus, caisse, achats, stock cuisine, clients et rapports avec un flux adapté aux rushs.',
                'catalog_label' => 'Menu & articles restaurant',
                'primary_item' => 'Plat / menu',
                'type_labels' => ['book' => 'Plat / menu', 'supply' => 'Produit cuisine', 'service' => 'Service'],
                'search_placeholder' => 'Rechercher plat, formule, code...',
                'keywords' => ['tables', 'menus', 'cuisine', 'service'],
            ],
            'coffee' => [
                'label' => 'Café / coffee shop',
                'short_label' => 'Café',
                'subtitle' => 'Boissons, snacks, tickets rapides et stock comptoir',
                'hero_title' => 'Votre café prêt pour le comptoir.',
                'hero_text' => 'Encaissez rapidement boissons et snacks, gardez vos stocks à jour et préparez vos démonstrations sans friction.',
                'catalog_label' => 'Menu café & snacks',
                'primary_item' => 'Boisson / snack',
                'type_labels' => ['book' => 'Boisson / snack', 'supply' => 'Produit comptoir', 'service' => 'Service'],
                'search_placeholder' => 'Rechercher boisson, snack, formule...',
                'keywords' => ['boissons', 'snacks', 'comptoir', 'tickets'],
            ],
            'beauty' => [
                'label' => 'Salon de beauté / spa / coiffure',
                'short_label' => 'Beauté & spa',
                'subtitle' => 'Prestations, soins, clientèle fidèle et produits revendus',
                'hero_title' => 'Votre salon prêt à recevoir.',
                'hero_text' => 'Encaissez prestations et produits, connaissez vos clientes et leurs habitudes, et gardez vos produits de soin à jour.',
                'catalog_label' => 'Prestations & produits',
                'primary_item' => 'Prestation / soin',
                'type_labels' => ['book' => 'Produit', 'supply' => 'Produit de soin', 'service' => 'Prestation'],
                'search_placeholder' => 'Rechercher prestation, soin, produit...',
                'keywords' => ['soins', 'coiffure', 'esthétique', 'spa'],
            ],
            // Its own activity rather than "beauty": a barber sells chairs'
            // time by the barber, and the till asks who did each cut.
            'barber' => [
                'label' => 'Barbier / coiffure homme',
                'short_label' => 'Barbier',
                'subtitle' => 'Coupes, barbe, soins, pourboires et commissions par barbier',
                'hero_title' => 'Votre salon prêt pour la file d’attente.',
                'hero_text' => 'Encaissez coupe et barbe en deux gestes, notez qui a fait la coupe, et suivez le chiffre, les commissions et les pourboires de chaque barbier.',
                'catalog_label' => 'Prestations & produits',
                'primary_item' => 'Prestation',
                'type_labels' => ['book' => 'Produit', 'supply' => 'Produit', 'service' => 'Prestation'],
                'search_placeholder' => 'Rechercher coupe, barbe, produit...',
                'keywords' => ['coupes', 'barbe', 'barbiers', 'pourboires'],
            ],
            'cosmetics' => [
                'label' => 'Cosmétique / parfumerie',
                'short_label' => 'Parfumerie',
                'subtitle' => 'Parfums, maquillage, soins et coffrets',
                'hero_title' => 'Votre parfumerie prête à conseiller.',
                'hero_text' => 'Retrouvez chaque référence en un scan, suivez vos clientes fidèles et préparez coffrets et offres sans effort.',
                'catalog_label' => 'Parfums & cosmétiques',
                'primary_item' => 'Produit',
                'type_labels' => ['book' => 'Parfum / soin', 'supply' => 'Produit cosmétique', 'service' => 'Service'],
                'search_placeholder' => 'Rechercher parfum, marque, référence...',
                'keywords' => ['parfums', 'maquillage', 'soins', 'coffrets'],
            ],
            'pharmacy' => [
                'label' => 'Pharmacie / parapharmacie',
                'short_label' => 'Pharmacie',
                'subtitle' => 'Médicaments, parapharmacie, lots et ventes comptoir',
                'hero_title' => 'Votre pharmacie prête pour le comptoir.',
                'hero_text' => 'Retrouvez rapidement les produits, suivez le stock sensible, encaissez sans friction et gardez une traçabilité claire.',
                'catalog_label' => 'Catalogue produits santé',
                'primary_item' => 'Produit pharmacie',
                'type_labels' => ['book' => 'Produit santé', 'supply' => 'Produit parapharmacie', 'service' => 'Service'],
                'search_placeholder' => 'Rechercher produit, code-barres, référence...',
                'keywords' => ['lots', 'péremption', 'ordonnance', 'parapharmacie'],
            ],
            'drugstore' => [
                'label' => 'Droguerie',
                'short_label' => 'Droguerie',
                'subtitle' => 'Quincaillerie, droguerie, maison et bricolage',
                'hero_title' => 'Votre droguerie prête pour la vente rapide.',
                'hero_text' => 'Gérez les familles d’articles, les stocks, les achats fournisseurs et les ventes comptoir depuis un seul espace.',
                'catalog_label' => 'Catalogue droguerie',
                'primary_item' => 'Produit droguerie',
                'type_labels' => ['book' => 'Produit droguerie', 'supply' => 'Article', 'service' => 'Service'],
                'search_placeholder' => 'Rechercher produit, marque, code-barres...',
                'keywords' => ['rayons', 'marques', 'stock', 'fournisseurs'],
            ],
            'electronics' => [
                'label' => 'Téléphonie / électronique',
                'short_label' => 'Électronique',
                'subtitle' => 'Téléphones, accessoires, réparations et garanties',
                'hero_title' => 'Votre boutique high-tech prête à vendre.',
                'hero_text' => 'Suivez chaque appareil par code-barres, vendez accessoires et réparations, et gardez vos marges à l’œil.',
                'catalog_label' => 'Appareils & accessoires',
                'primary_item' => 'Appareil',
                'type_labels' => ['book' => 'Appareil', 'supply' => 'Accessoire', 'service' => 'Réparation'],
                'search_placeholder' => 'Rechercher modèle, marque, code-barres...',
                'keywords' => ['téléphones', 'accessoires', 'réparations', 'garantie'],
            ],
            'retail' => [
                'label' => 'Autre commerce',
                'short_label' => 'Retail',
                'subtitle' => 'Boutique, accessoires, services et ventes rapides',
                'hero_title' => 'Votre point de vente prêt à vendre.',
                'hero_text' => 'Centralisez catalogue, caisse, remises, clients et reporting dans une interface moderne et adaptable.',
                'catalog_label' => 'Catalogue produits & services',
                'primary_item' => 'Produit',
                'type_labels' => ['book' => 'Produit', 'supply' => 'Article', 'service' => 'Service'],
                'search_placeholder' => 'Rechercher produit, service, code-barres...',
                'keywords' => ['boutique', 'accessoires', 'services', 'tickets'],
            ],
        ];
    }

    public static function defaultKey(): string
    {
        return 'library';
    }

    public static function accepts(?string $key): bool
    {
        $key = self::inputKey($key);

        if ($key === '') {
            return false;
        }

        return array_key_exists($key, self::aliases()) || array_key_exists($key, self::all());
    }

    public static function current(?Tenant $tenant): array
    {
        $key = (string) (
            data_get($tenant?->settings, 'company_profile.business_mode')
            ?? $tenant?->business_mode
            ?? $tenant?->mode
            ?? self::defaultKey()
        );

        return self::get($key);
    }

    public static function normalize(?string $key): string
    {
        $key = self::inputKey($key);
        $key = self::aliases()[$key] ?? $key;

        return array_key_exists($key, self::all()) ? $key : self::defaultKey();
    }

    public static function get(?string $key): array
    {
        $modes = self::all();
        $key = self::normalize($key);

        return ['key' => $key] + $modes[$key];
    }

    public static function typeLabels(?string $key): array
    {
        return self::get($key)['type_labels'];
    }

    private static function inputKey(?string $key): string
    {
        return Str::of((string) $key)->lower()->trim()->replace([' ', '-'], '_')->toString();
    }

    private static function aliases(): array
    {
        return [
            '' => self::defaultKey(),
            'bookstore' => 'library',
            'books' => 'library',
            'book' => 'library',
            'livrairie' => 'library',
            'bibliotheque' => 'library',
            'library' => 'library',
            'restaurant' => 'restaurant',
            'resto' => 'restaurant',
            'coffee' => 'coffee',
            'cafe' => 'coffee',
            'coffee_shop' => 'coffee',
            'pharmacy' => 'pharmacy',
            'pharmacie' => 'pharmacy',
            'drugstore' => 'drugstore',
            'droguerie' => 'drugstore',
            'clothing' => 'clothing',
            'vetements' => 'clothing',
            'vêtements' => 'clothing',
            'mode' => 'clothing',
            'habillement' => 'clothing',
            'grocery' => 'grocery',
            'epicerie' => 'grocery',
            'épicerie' => 'grocery',
            'superette' => 'grocery',
            'supérette' => 'grocery',
            'alimentation' => 'grocery',
            'bakery' => 'bakery',
            'boulangerie' => 'bakery',
            'patisserie' => 'bakery',
            'pâtisserie' => 'bakery',
            'beauty' => 'beauty',
            'beaute' => 'beauty',
            'beauté' => 'beauty',
            'spa' => 'beauty',
            'coiffure' => 'beauty',
            'salon' => 'beauty',
            'barber' => 'barber',
            'barbier' => 'barber',
            'barbershop' => 'barber',
            'barber_shop' => 'barber',
            'coiffeur' => 'barber',
            'coiffure_homme' => 'barber',
            'cosmetics' => 'cosmetics',
            'cosmetique' => 'cosmetics',
            'cosmétique' => 'cosmetics',
            'parfumerie' => 'cosmetics',
            'electronics' => 'electronics',
            'electronique' => 'electronics',
            'électronique' => 'electronics',
            'telephonie' => 'electronics',
            'téléphonie' => 'electronics',
            'retail' => 'retail',
            'commerce' => 'retail',
            'service' => 'retail',
            'services' => 'retail',
            // NOTE: 'hybrid' is deliberately absent. It is the pre-configuration
            // default of the `tenants.mode` column (cloud + local), not a
            // business description — the settings screen later overwrites that
            // same column with a real business mode. Aliasing it to 'retail'
            // made every tenant that had not been through setup a general
            // store, whose only item type is 'supply': a new bookshop could not
            // create a book, and a new restaurant could not create a dish.
            // Unrecognised values now fall through to defaultKey().
        ];
    }
}

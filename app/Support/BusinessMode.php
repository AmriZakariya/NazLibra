<?php

namespace App\Support;

use App\Models\Tenant;

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
                'search_placeholder' => 'Rechercher titre, ISBN, code-barres...',
                'keywords' => ['ISBN', 'auteur', 'éditeur', 'emprunts'],
            ],
            'restaurant' => [
                'label' => 'Restaurant',
                'short_label' => 'Restaurant',
                'subtitle' => 'Tables, menus, tickets cuisine et encaissement service',
                'hero_title' => 'Votre restaurant prêt pour le service.',
                'hero_text' => 'Gérez menus, caisse, achats, stock cuisine, clients et rapports avec un flux adapté aux rushs.',
                'catalog_label' => 'Menu & articles restaurant',
                'primary_item' => 'Plat / menu',
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
                'search_placeholder' => 'Rechercher boisson, snack, formule...',
                'keywords' => ['boissons', 'snacks', 'comptoir', 'tickets'],
            ],
            'pharmacy' => [
                'label' => 'Pharmacie / parapharmacie',
                'short_label' => 'Pharmacie',
                'subtitle' => 'Médicaments, parapharmacie, lots et ventes comptoir',
                'hero_title' => 'Votre pharmacie prête pour le comptoir.',
                'hero_text' => 'Retrouvez rapidement les produits, suivez le stock sensible, encaissez sans friction et gardez une traçabilité claire.',
                'catalog_label' => 'Catalogue produits santé',
                'primary_item' => 'Produit pharmacie',
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
                'search_placeholder' => 'Rechercher produit, marque, code-barres...',
                'keywords' => ['rayons', 'marques', 'stock', 'fournisseurs'],
            ],
            'retail' => [
                'label' => 'Retail général',
                'short_label' => 'Retail',
                'subtitle' => 'Boutique, accessoires, services et ventes rapides',
                'hero_title' => 'Votre point de vente prêt à vendre.',
                'hero_text' => 'Centralisez catalogue, caisse, remises, clients et reporting dans une interface moderne et adaptable.',
                'catalog_label' => 'Catalogue produits & services',
                'primary_item' => 'Produit',
                'search_placeholder' => 'Rechercher produit, service, code-barres...',
                'keywords' => ['boutique', 'accessoires', 'services', 'tickets'],
            ],
        ];
    }

    public static function defaultKey(): string
    {
        return 'library';
    }

    public static function current(?Tenant $tenant): array
    {
        $key = (string) data_get($tenant?->settings, 'company_profile.business_mode', self::defaultKey());

        return self::get($key);
    }

    public static function get(?string $key): array
    {
        $modes = self::all();
        $key = $key && isset($modes[$key]) ? $key : self::defaultKey();

        return ['key' => $key] + $modes[$key];
    }
}

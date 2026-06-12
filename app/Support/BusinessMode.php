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

<?php

return [

    /*
    | demo:   simulated subscriptions, no Stripe credentials or network calls.
    | stripe: Laravel Cashier and Stripe Checkout.
    */
    'provider' => env('BILLING_PROVIDER', 'demo'),

    // With the stripe provider: "test" or "live". It must match the type of STRIPE_SECRET.
    'mode' => env('BILLING_MODE', 'demo'),

    // Live billing needs this as well, so adding live keys can never turn it on alone.
    'allow_live' => (bool) env('BILLING_ALLOW_LIVE', false),

    // Only for a deployment that exists to be a public demo. Otherwise, demo billing
    // is switched off in production, so nobody can give themselves a paid plan.
    'allow_demo_in_production' => (bool) env('BILLING_ALLOW_DEMO_IN_PRODUCTION', false),

    // The plan of anyone with no active subscription.
    'default_plan' => env('BILLING_DEFAULT_PLAN', 'free'),

    'currency' => env('CASHIER_CURRENCY', 'eur'),

    // Days a plan stays usable after a failed payment.
    'past_due_grace_days' => (int) env('BILLING_PAST_DUE_GRACE_DAYS', 7),

    /*
    | "available" => false shows the feature as Coming Soon and never grants it.
    */
    'features' => [
        'ai.generate' => ['label' => 'AI content generation', 'available' => true],
        'ai.analyze' => ['label' => 'AI product analysis', 'available' => true],
        'ai.bulk' => ['label' => 'Bulk AI generation', 'available' => true],
        'products.create' => ['label' => 'Product management', 'available' => true],
        'catalog.import' => ['label' => 'CSV and Excel import', 'available' => true],
        'catalog.export' => ['label' => 'CSV and Excel export', 'available' => true],
        'integrations.woocommerce' => ['label' => 'WooCommerce', 'available' => true],
        'integrations.shopify' => ['label' => 'Shopify', 'available' => false],
        'api.access' => ['label' => 'REST API', 'available' => true],
        'agency.tools' => ['label' => 'Agency features', 'available' => false],
    ],

    'comparison_rows' => [
        'languages' => 'Languages',
        'catalog' => 'CSV and Excel',
        'bulk' => 'Bulk AI',
        'woocommerce' => 'WooCommerce',
        'shopify' => 'Shopify',
        'api' => 'REST API',
        'agency' => 'Agency features',
    ],

    /*
    | Illustrative prices. In the stripe provider, the amount charged comes from the
    | Stripe Price, and the page should be updated to match it.
    */
    'plans' => [
        'free' => [
            'name' => 'Free',
            'rank' => 0,
            'price_cents' => 0,
            'stripe_price' => null,
            'description' => 'Try the catalog and a little AI.',
            'limits' => ['ai_generations' => 10, 'products' => 20, 'languages' => 3, 'bulk_items_per_batch' => 0, 'import_rows_per_file' => 50],
            'features' => ['ai.generate', 'ai.analyze', 'products.create', 'catalog.import', 'catalog.export'],
            'comparison' => ['languages' => '3', 'catalog' => 'Basic (50 rows per import)', 'bulk' => 'No', 'woocommerce' => 'No', 'shopify' => 'No', 'api' => 'No', 'agency' => 'No'],
        ],
        'starter' => [
            'name' => 'Starter',
            'rank' => 1,
            'price_cents' => 900,
            'stripe_price' => env('STRIPE_STARTER_MONTHLY_PRICE_ID'),
            'description' => 'For a small shop writing regularly.',
            'limits' => ['ai_generations' => 50, 'products' => 200, 'languages' => 3, 'bulk_items_per_batch' => 10, 'import_rows_per_file' => 10000],
            'features' => ['ai.generate', 'ai.analyze', 'ai.bulk', 'products.create', 'catalog.import', 'catalog.export'],
            'comparison' => ['languages' => '3', 'catalog' => 'Included', 'bulk' => 'Limited (10 per batch)', 'woocommerce' => 'No', 'shopify' => 'No', 'api' => 'No', 'agency' => 'No'],
        ],
        'pro' => [
            'name' => 'Pro',
            'rank' => 2,
            'price_cents' => 2400,
            'stripe_price' => env('STRIPE_PRO_MONTHLY_PRICE_ID'),
            'description' => 'For stores connected to their platform.',
            'limits' => ['ai_generations' => 300, 'products' => 2000, 'languages' => 3, 'bulk_items_per_batch' => 200, 'import_rows_per_file' => 10000],
            'features' => ['ai.generate', 'ai.analyze', 'ai.bulk', 'products.create', 'catalog.import', 'catalog.export', 'integrations.woocommerce', 'integrations.shopify', 'api.access'],
            'comparison' => ['languages' => '3', 'catalog' => 'Included', 'bulk' => 'Included', 'woocommerce' => 'Included', 'shopify' => 'Coming Soon', 'api' => 'Included', 'agency' => 'No'],
        ],
        'agency' => [
            'name' => 'Agency',
            'rank' => 3,
            'price_cents' => 5900,
            'stripe_price' => env('STRIPE_AGENCY_MONTHLY_PRICE_ID'),
            'description' => 'For large catalogs and high volume.',
            'limits' => ['ai_generations' => 1000, 'products' => 10000, 'languages' => 3, 'bulk_items_per_batch' => 200, 'import_rows_per_file' => 10000],
            'features' => ['ai.generate', 'ai.analyze', 'ai.bulk', 'products.create', 'catalog.import', 'catalog.export', 'integrations.woocommerce', 'integrations.shopify', 'api.access', 'agency.tools'],
            'comparison' => ['languages' => '3', 'catalog' => 'Included', 'bulk' => 'Included', 'woocommerce' => 'Included', 'shopify' => 'Coming Soon', 'api' => 'Included', 'agency' => 'Coming Soon'],
        ],
    ],

];

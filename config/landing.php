<?php

/*
| Landing page. Rows in `landing_variants` (seed them with LandingVariantSeeder or LandingService::upsert())
| override this default. With no active variant the `default` content below is served.
| Replace the copy below with your own (it is also the A/B control).
*/
return [
    'cookies' => [
        'visitor' => 'bv_id',        // random visitor id, httpOnly, drives deterministic bucketing
        'variant' => 'bv_variant',   // assigned slug, readable by JS for Plausible props and signup attribution
        'days' => 90,
    ],

    'default' => [
        'slug' => 'default',
        'hero_headline' => 'The simplest way to get it done',
        'hero_sub' => 'India SaaS Starter Kit gives you one focused tool that does its job well. Start free, upgrade when it pays for itself.',
        'cta_label' => 'Start now',
        'pricing_blurb' => 'Simple monthly pricing. Cancel any time.',
        'features' => [
            ['title' => 'Fast to start', 'body' => 'Sign up and get value in under five minutes.'],
            ['title' => 'Built to last', 'body' => 'Backed by monitoring, backups and real support.'],
            ['title' => 'Fair pricing', 'body' => 'Pay monthly in INR. No hidden fees.'],
        ],
        'faq' => [
            ['q' => 'Can I cancel any time?', 'a' => 'Yes. Cancel from the Billing page; you keep access until the end of the period.'],
            ['q' => 'Is my data safe?', 'a' => 'Your data stays in this product\'s own database and is backed up daily.'],
            ['q' => 'How do I get help?', 'a' => 'Use the Support page after signing in, or email us.'],
        ],
        'meta_title' => null,          // falls back to APP_NAME + headline
        'meta_description' => null,    // falls back to hero_sub
    ],
];

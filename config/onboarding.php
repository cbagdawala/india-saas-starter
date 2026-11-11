<?php

/*
| Onboarding shown right after signup (/welcome). Replace these three placeholder steps with your
| product's real first-run steps. `to` is an SPA route path.
*/
return [
    'steps' => [
        ['key' => 'profile', 'title' => 'Tell us about you', 'body' => 'Placeholder: the one setting that personalises the product.', 'cta_label' => 'Open dashboard', 'to' => '/'],
        ['key' => 'first-item', 'title' => 'Create your first item', 'body' => 'Placeholder: the product\'s core action, done once with guidance.', 'cta_label' => 'Get started', 'to' => '/'],
        ['key' => 'plan', 'title' => 'Pick a plan', 'body' => 'Placeholder: upgrade when the product pays for itself.', 'cta_label' => 'See plans', 'to' => '/billing'],
    ],
];

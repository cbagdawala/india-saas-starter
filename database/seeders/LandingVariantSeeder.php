<?php

namespace Database\Seeders;

use App\Modules\Landing\Services\LandingServiceInterface;
use Illuminate\Database\Seeder;

/** Sample A/B landing variants. Edit the copy here (or call LandingService::upsert() yourself) and re-run the seeder. */
class LandingVariantSeeder extends Seeder
{
    public function run(LandingServiceInterface $landing): void
    {
        $landing->upsert('gst-ready', [
            'hero_headline' => 'Launch your Indian SaaS in days, not months',
            'hero_sub' => 'Auth, Razorpay billing, support inbox and a blog, already wired. Start from a working product.',
            'cta_label' => 'Get started',
            'pricing_blurb' => 'Simple monthly pricing in INR.',
            'features' => [
                ['title' => 'Razorpay billing', 'body' => 'Subscriptions and webhooks, tested in test mode.'],
                ['title' => 'Production habits', 'body' => 'Health checks, queues, scheduler and error reporting.'],
            ],
            'faq' => [['q' => 'Can I change this copy?', 'a' => 'Yes, edit database/seeders/LandingVariantSeeder.php.']],
            'weight' => 1,
            'active' => true,
        ]);
    }
}

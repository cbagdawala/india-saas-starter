<?php

namespace App\Modules\Support\Services;

use Illuminate\Support\Str;

/** Coarse keyword category for routing and stats. */
class TicketClassifier
{
    public const CATEGORIES = ['billing', 'account', 'bug', 'feature', 'other'];

    /** First match wins, in this order. */
    private const KEYWORDS = [
        'billing' => ['refund', 'charge', 'charged', 'invoice', 'payment', 'billing', 'subscription', 'price', 'pricing', 'card', 'upgrade', 'downgrade', 'cancel', 'receipt', 'gst'],
        'account' => ['login', 'log in', 'sign in', 'signin', 'password', 'account', 'locked', '2fa', 'two-factor', 'delete my', 'email address', 'verify my'],
        'bug' => ['error', 'bug', 'broken', 'crash', 'not working', "doesn't work", 'does not work', 'fails', 'failed', 'failing', '500', 'blank page', 'issue'],
        'feature' => ['feature', 'request', 'suggest', 'suggestion', 'would be nice', 'wish', 'could you add', 'please add', 'roadmap', 'integration'],
    ];

    public function classify(string $subject, string $body): string
    {
        $text = ' '.Str::lower($subject.' '.Str::limit($body, 2000, '')).' ';

        foreach (self::KEYWORDS as $category => $words) {
            foreach ($words as $word) {
                if (preg_match('/(?<![a-z0-9])'.preg_quote($word, '/').'(?![a-z0-9])/u', $text)) {
                    return $category;
                }
            }
        }

        return 'other';
    }
}

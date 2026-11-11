<?php

namespace App\Modules\Support\Services;

use Illuminate\Support\Str;

/**
 * Decides whether an inbound email's From address is authenticated, from the Authentication-Results headers in
 * the Brevo payload (`Headers`). A sender can forge its own Authentication-Results header, so only headers whose
 * authserv-id equals config('support.trusted_authserv_id') (the receiving MX, e.g. Brevo's) count; with it unset
 * nothing is verified. Verified = dmarc=pass, or dkim=pass / spf=pass with a domain aligned to the From domain.
 */
class SenderVerifier
{
    /** @param array<string, mixed> $item Brevo inbound item */
    public function verified(array $item, string $fromAddress): bool
    {
        $trusted = Str::lower(trim((string) config('support.trusted_authserv_id')));
        $fromDomain = Str::lower(Str::afterLast($fromAddress, '@'));
        if ($trusted === '' || $fromDomain === '' || ! is_array($item['Headers'] ?? null)) {
            return false;
        }

        foreach ($this->authResults($item['Headers']) as $header) {
            [$authservId, $results] = array_pad(explode(';', $header, 2), 2, '');
            if (Str::lower(trim(strtok($authservId, " \t") ?: '')) !== $trusted) {
                continue;
            }

            foreach (explode(';', $results) as $result) {
                $result = Str::lower(trim($result));
                if (preg_match('/^dmarc=pass\b/', $result) && $this->aligned($this->property($result, 'header.from'), $fromDomain, true)) {
                    return true;
                }
                if (preg_match('/^dkim=pass\b/', $result) && $this->aligned($this->property($result, 'header.d'), $fromDomain)) {
                    return true;
                }
                if (preg_match('/^spf=pass\b/', $result) && $this->aligned(Str::afterLast((string) $this->property($result, 'smtp.mailfrom'), '@'), $fromDomain)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function authResults(array $headers): array
    {
        $values = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && Str::lower($name) === 'authentication-results') {
                foreach ((array) $value as $v) {
                    if (is_string($v)) {
                        $values[] = $v;
                    }
                }
            }
        }

        return $values;
    }

    private function property(string $result, string $name): ?string
    {
        return preg_match('/(?:^|\s)'.preg_quote($name, '/').'=([^\s;]+)/', $result, $m) ? trim($m[1], '"<>') : null;
    }

    /** Relaxed alignment: equal, or one is a subdomain of the other. dmarc without header.from is accepted. */
    private function aligned(?string $domain, string $fromDomain, bool $missingOk = false): bool
    {
        if ($domain === null || $domain === '') {
            return $missingOk;
        }

        return $domain === $fromDomain || str_ends_with($fromDomain, '.'.$domain) || str_ends_with($domain, '.'.$fromDomain);
    }
}

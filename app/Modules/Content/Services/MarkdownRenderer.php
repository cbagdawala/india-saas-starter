<?php

namespace App\Modules\Content\Services;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown -> HTML for untrusted text (articles written by an agent). Raw HTML is stripped, javascript:/vbscript:/
 * file:/data: links are dropped, external links get rel="nofollow noopener noreferrer". The output is the only
 * HTML a Blade view may print unescaped.
 */
class MarkdownRenderer
{
    private ?MarkdownConverter $converter = null;

    public function toHtml(string $markdown): string
    {
        return $this->converter()->convert($markdown)->getContent();
    }

    private function converter(): MarkdownConverter
    {
        if ($this->converter) {
            return $this->converter;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'max_delimiters_per_line' => 1000,
            'external_link' => [
                'internal_hosts' => [$host],
                'open_in_new_window' => true,
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new ExternalLinkExtension);

        return $this->converter = new MarkdownConverter($environment);
    }
}

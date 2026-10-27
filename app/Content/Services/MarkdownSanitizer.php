<?php

namespace App\Content\Services;

use Illuminate\Support\Str;

class MarkdownSanitizer
{
    /**
     * Parse markdown to safe HTML, stripping any dangerous tags and attributes.
     */
    public function render(string $markdown): string
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return $this->sanitizeHtml($html);
    }

    /**
     * Additional safeguard to strip script, iframe, object, and malicious javascript event handlers.
     */
    public function sanitizeHtml(string $html): string
    {
        // Remove <script> tags and contents
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html) ?? $html;

        // Remove <iframe> tags and contents
        $html = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $html) ?? $html;

        // Remove <object>, <embed>, <applet>
        $html = preg_replace('/<(object|embed|applet)\b[^>]*>(.*?)<\/\1>/is', '', $html) ?? $html;

        // Remove on* event handlers (e.g. onerror=, onclick=)
        $html = preg_replace('/\s*on\w+\s*=\s*(["\']).*?\1/i', '', $html) ?? $html;
        $html = preg_replace('/\s*on\w+\s*=\s*[^ >]+/i', '', $html) ?? $html;

        // Remove javascript: pseudo-protocols in href/src
        $html = preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1="#"', $html) ?? $html;

        return $html;
    }
}

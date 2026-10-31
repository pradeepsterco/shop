<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        if (app()->environment('production')) {
            $sitemapUrl = rtrim((string) config('app.url', url('/')), '/') . '/sitemap.xml';

            $lines = [
                'User-agent: *',
                'Disallow: /admin/',
                'Disallow: /products?*q=*',
                'Disallow: /products?*sort=*',
                'Disallow: /products?*min_price=*',
                'Disallow: /products?*max_price=*',
                'Disallow: /products?*min_discount=*',
                'Disallow: /products?*in_stock=*',
                'Disallow: /products?*attrs=*',
                'Disallow: /products?*page=*',
                '',
                "Sitemap: {$sitemapUrl}",
            ];
        } else {
            $lines = [
                'User-agent: *',
                'Disallow: /',
            ];
        }

        $content = implode("\n", $lines) . "\n";

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}


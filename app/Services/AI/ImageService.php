<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImageService
{
    private const CACHE_TTL = 86400;

    private const CACHE_PREFIX = 'atlaas_img:';

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(string $keyword, ?string $districtSource = null): ?array
    {
        if ($keyword === '') {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX.md5(strtolower($keyword));

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($keyword, $districtSource) {
            $source = $districtSource ?? config('atlaas.image_source', 'wikimedia');

            $result = match ($source) {
                'unsplash' => $this->fetchUnsplash($keyword),
                'pexels' => $this->fetchPexels($keyword),
                default => $this->fetchWikimedia($keyword),
            };

            if ($result === null && $source !== 'wikimedia') {
                $result = $this->fetchWikimedia($keyword);
            }

            return $result;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchWikimedia(string $keyword): ?array
    {
        try {
            $searchResponse = Http::timeout(5)->get('https://en.wikipedia.org/w/api.php', [
                'action' => 'query',
                'list' => 'search',
                'srsearch' => $keyword,
                'srlimit' => 3,
                'srnamespace' => 0,
                'format' => 'json',
            ]);

            if (! $searchResponse->ok()) {
                return null;
            }

            $pages = $searchResponse->json('query.search', []);
            if ($pages === [] || $pages === null) {
                return null;
            }

            foreach ($pages as $page) {
                $result = $this->getWikimediaPageImage((int) $page['pageid'], (string) $page['title']);
                if ($result !== null) {
                    return $result;
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('Wikimedia image fetch failed', ['keyword' => $keyword, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getWikimediaPageImage(int $pageId, string $pageTitle): ?array
    {
        $response = Http::timeout(5)->get('https://en.wikipedia.org/w/api.php', [
            'action' => 'query',
            'pageids' => $pageId,
            'prop' => 'pageimages|pageterms',
            'pithumbsize' => 800,
            'pilimit' => 1,
            'format' => 'json',
        ]);

        if (! $response->ok()) {
            return null;
        }

        $page = $response->json("query.pages.{$pageId}");
        if (! is_array($page)) {
            return null;
        }

        $thumb = $page['thumbnail'] ?? null;
        if (! is_array($thumb) || ! isset($thumb['source'])) {
            return null;
        }

        if (($thumb['width'] ?? 0) < 200 || ($thumb['height'] ?? 0) < 150) {
            return null;
        }

        return [
            'url' => $thumb['source'],
            'width' => $thumb['width'],
            'height' => $thumb['height'],
            'alt' => $pageTitle,
            'credit' => 'Wikipedia / Wikimedia Commons',
            'credit_url' => 'https://en.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $pageTitle)),
            'license' => 'CC BY-SA',
            'source' => 'wikimedia',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchUnsplash(string $keyword): ?array
    {
        $key = config('services.unsplash.access_key');
        if (! $key) {
            return null;
        }

        try {
            $response = Http::timeout(5)
                ->withHeader('Authorization', "Client-ID {$key}")
                ->get('https://api.unsplash.com/search/photos', [
                    'query' => $keyword,
                    'per_page' => 1,
                    'orientation' => 'landscape',
                    'content_filter' => 'high',
                ]);

            if (! $response->ok()) {
                return null;
            }

            $photo = $response->json('results.0');
            if (! is_array($photo)) {
                return null;
            }

            return [
                'url' => $photo['urls']['regular'],
                'width' => $photo['width'],
                'height' => $photo['height'],
                'alt' => $photo['alt_description'] ?? $keyword,
                'credit' => 'Photo by '.$photo['user']['name'].' on Unsplash',
                'credit_url' => ($photo['links']['html'] ?? '').'?utm_source=atlaas&utm_medium=referral',
                'license' => 'Unsplash License',
                'source' => 'unsplash',
            ];
        } catch (\Throwable $e) {
            Log::warning('Unsplash fetch failed', ['keyword' => $keyword, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPexels(string $keyword): ?array
    {
        $key = config('services.pexels.api_key');
        if (! $key) {
            return null;
        }

        try {
            $response = Http::timeout(5)
                ->withHeader('Authorization', $key)
                ->get('https://api.pexels.com/v1/search', [
                    'query' => $keyword,
                    'per_page' => 1,
                    'orientation' => 'landscape',
                ]);

            if (! $response->ok()) {
                return null;
            }

            $photo = $response->json('photos.0');
            if (! is_array($photo)) {
                return null;
            }

            return [
                'url' => $photo['src']['large'],
                'width' => $photo['width'],
                'height' => $photo['height'],
                'alt' => $photo['alt'] ?? $keyword,
                'credit' => 'Photo by '.$photo['photographer'].' on Pexels',
                'credit_url' => $photo['url'],
                'license' => 'Pexels License',
                'source' => 'pexels',
            ];
        } catch (\Throwable $e) {
            Log::warning('Pexels fetch failed', ['keyword' => $keyword, 'error' => $e->getMessage()]);

            return null;
        }
    }
}

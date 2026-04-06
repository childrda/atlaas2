<?php

namespace App\Services\AI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImageService
{
    private const CACHE_TTL_HIT = 86400;

    private const CACHE_TTL_MISS = 300;

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

        $wrapped = Cache::get($cacheKey);
        if (is_array($wrapped)) {
            if (array_key_exists('_hit', $wrapped)) {
                return $wrapped['_hit'] ? $wrapped['data'] : null;
            }
            if (isset($wrapped['url'])) {
                return $wrapped;
            }
        }

        $source = $districtSource ?? config('atlaas.image_source', 'wikimedia');

        $result = match ($source) {
            'unsplash' => $this->fetchUnsplash($keyword),
            'pexels' => $this->fetchPexels($keyword),
            default => $this->fetchWikimedia($keyword),
        };

        if ($result === null && $source !== 'wikimedia') {
            $result = $this->fetchWikimedia($keyword);
        }

        if ($result !== null) {
            Cache::put($cacheKey, ['_hit' => true, 'data' => $result], self::CACHE_TTL_HIT);
        } else {
            Cache::put($cacheKey, ['_hit' => false], self::CACHE_TTL_MISS);
        }

        return $result;
    }

    /**
     * Wikimedia requires a descriptive User-Agent; anonymous requests may get empty or blocked responses without it.
     */
    private function wikimediaHttp(): PendingRequest
    {
        $url = config('app.url') ?: 'https://example.invalid';

        return Http::timeout(8)
            ->withHeaders([
                'User-Agent' => 'ATLAAS/1.0 ('.$url.'; student-learning) Laravel/HTTP',
            ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @return list<string>
     */
    private function wikimediaSearchVariants(string $keyword): array
    {
        $k = trim((string) preg_replace('/\s+/', ' ', $keyword));
        $stripped = trim((string) preg_replace(
            '/\b(diagram|illustration|picture|image|photo|drawing|chart)\b/iu',
            ' ',
            $k
        ));
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $stripped));

        $variants = array_values(array_unique(array_filter(
            [$k, $collapsed],
            fn (string $s) => $s !== ''
        )));

        return $variants !== [] ? $variants : [trim($keyword)];
    }

    private function fetchWikimedia(string $keyword): ?array
    {
        try {
            foreach ($this->wikimediaSearchVariants($keyword) as $q) {
                $searchResponse = $this->wikimediaHttp()->get('https://en.wikipedia.org/w/api.php', [
                    'action' => 'query',
                    'list' => 'search',
                    'srsearch' => $q,
                    'srlimit' => 8,
                    'srnamespace' => 0,
                    'format' => 'json',
                ]);

                if (! $searchResponse->ok()) {
                    continue;
                }

                $pages = $searchResponse->json('query.search', []);
                if (! is_array($pages) || $pages === []) {
                    continue;
                }

                foreach ($pages as $page) {
                    $pageId = (int) ($page['pageid'] ?? 0);
                    $title = (string) ($page['title'] ?? '');
                    if ($pageId <= 0 || $title === '') {
                        continue;
                    }
                    $result = $this->getWikimediaPageImage($pageId, $title);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }

            return $this->fetchWikimediaCommons($keyword);
        } catch (\Throwable $e) {
            Log::warning('Wikimedia image fetch failed', ['keyword' => $keyword, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * When enwiki articles have no usable pageimage, search Wikimedia Commons files (diagrams, maps, etc.).
     */
    private function fetchWikimediaCommons(string $keyword): ?array
    {
        foreach ($this->wikimediaSearchVariants($keyword) as $q) {
            $response = $this->wikimediaHttp()->get('https://commons.wikimedia.org/w/api.php', [
                'action' => 'query',
                'generator' => 'search',
                'gsrsearch' => $q,
                'gsrnamespace' => 6,
                'gsrlimit' => 10,
                'prop' => 'imageinfo',
                'iiprop' => 'url|size|mime|thumburl',
                'iiurlwidth' => 800,
                'format' => 'json',
            ]);

            if (! $response->ok()) {
                continue;
            }

            $pages = $response->json('query.pages', []);
            if (! is_array($pages)) {
                continue;
            }

            foreach ($pages as $page) {
                if (! is_array($page) || ($page['missing'] ?? false) === true) {
                    continue;
                }
                $title = (string) ($page['title'] ?? '');
                $info = $page['imageinfo'][0] ?? null;
                if (! is_array($info)) {
                    continue;
                }
                $url = $info['thumburl'] ?? $info['url'] ?? null;
                if (! is_string($url) || $url === '') {
                    continue;
                }
                $mime = (string) ($info['mime'] ?? '');
                if ($mime !== '' && ! str_starts_with($mime, 'image/')) {
                    continue;
                }
                $w = (int) ($info['thumbwidth'] ?? $info['width'] ?? 0);
                $h = (int) ($info['thumbheight'] ?? $info['height'] ?? 0);
                if ($w > 0 && $h > 0 && ($w < 80 || $h < 80)) {
                    continue;
                }

                $fileSlug = rawurlencode(str_replace(' ', '_', $title));

                return [
                    'url' => $url,
                    'width' => $w ?: null,
                    'height' => $h ?: null,
                    'alt' => $title,
                    'credit' => 'Wikimedia Commons',
                    'credit_url' => 'https://commons.wikimedia.org/wiki/'.$fileSlug,
                    'license' => 'See file page',
                    'source' => 'wikimedia_commons',
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getWikimediaPageImage(int $pageId, string $pageTitle): ?array
    {
        $response = $this->wikimediaHttp()->get('https://en.wikipedia.org/w/api.php', [
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

        $pages = $response->json('query.pages');
        if (! is_array($pages)) {
            return null;
        }

        $page = reset($pages);
        if (! is_array($page) || ($page['missing'] ?? false) === true) {
            return null;
        }

        $thumb = $page['thumbnail'] ?? null;
        if (! is_array($thumb) || ! isset($thumb['source'])) {
            return null;
        }

        $tw = (int) ($thumb['width'] ?? 0);
        $th = (int) ($thumb['height'] ?? 0);
        if ($tw > 0 && $th > 0 && ($tw < 120 || $th < 120)) {
            return null;
        }

        return [
            'url' => $thumb['source'],
            'width' => $thumb['width'] ?? null,
            'height' => $thumb['height'] ?? null,
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

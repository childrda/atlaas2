# ATLAAS — Phase 3b: Rich Responses (Images, Diagrams, Fun Facts)
## Prerequisite: Phase 3 checklist fully passing — ATLAAS chats in plain text
## Stop when this works: ATLAAS shows real photos, SVG diagrams, and fun fact cards alongside text

---

## What you're building in this phase
- Four rich content tags ATLAAS can emit: [IMAGE:], [DIAGRAM:], [FUN_FACT:], [QUIZ:]
- ResponseParser: splits ATLAAS's raw output into typed segments
- ImageService: resolves image keywords to real URLs via Wikimedia (default) or district-configured source
- Redis image cache (24h TTL — Wikimedia requests are free but slow)
- RichMessage React component: renders each segment type appropriately
- Updated PromptBuilder: teaches ATLAAS what tags exist and when to use them

---

**Branding:** This phase assumes the product name **ATLAAS** and UI paths under **`resources/js/Components/Atlaas/`** (e.g. `AtlaasAvatar`). If you still see legacy codenames (**Bridger**, **LearnBridge**, or other pre-ATLAAS assistant/product names), search the repo and replace them with **ATLAAS** / **Atlaas** as appropriate.

Ensure **`TestDataSeeder`** (and space `system_prompt` values) say **ATLAAS**, not an older name. Re-run seeds only when appropriate — `php artisan migrate:fresh --seed` is destructive.

---

## Step 1 — The four rich tags

These are the only tags ATLAAS is allowed to emit. The system prompt teaches it
exactly when and how to use each one.

| Tag | Example | What happens |
|-----|---------|-------------|
| `[IMAGE: keyword]` | `[IMAGE: water evaporation lake]` | Server fetches a real photo from Wikimedia/configured source |
| `[DIAGRAM: type \| description]` | `[DIAGRAM: cycle \| water cycle steps]` | Server generates an SVG diagram |
| `[FUN_FACT: text]` | `[FUN_FACT: A cloud can weigh over a million pounds!]` | Renders as a styled callout card |
| `[QUIZ: question \| optionA \| optionB \| optionC \| answer]` | `[QUIZ: What causes rain? \| Evaporation \| Condensation \| Precipitation \| Precipitation]` | Renders as an interactive mini-quiz |

**Critical rule enforced in the system prompt:**
ATLAAS NEVER invents image URLs. It only writes the tag keyword.
The server resolves keywords to real, safe, licensed images.

---

## Step 2 — Update PromptBuilder

In `app/Services/AI/PromptBuilder.php`, update the `identityBlock()` method:

```php
private function identityBlock(string $tone): string
{
    $toneInstruction = match ($tone) {
        'socratic'  => 'Ask guiding questions rather than giving direct answers.',
        'direct'    => 'Be clear and concise. Give straightforward explanations.',
        'playful'   => 'Be warm, enthusiastic, and make learning fun.',
        default     => 'Be patient, warm, and encouraging.',
    };

    return <<<PROMPT
You are ATLAAS, a learning assistant built by this school district to support K-12 students.
{$toneInstruction}

You can make your responses more engaging by using special display tags.
Use them naturally — not on every message, only when they genuinely help.

AVAILABLE TAGS:

[IMAGE: keyword phrase]
Use when showing a real photo would help understanding.
Write a short, specific, descriptive search phrase as the keyword.
Examples:
  [IMAGE: water evaporating from lake surface]
  [IMAGE: cumulus clouds forming storm]
  [IMAGE: rain falling on leaves]
NEVER write a URL. Only write the keyword phrase.

[DIAGRAM: type | description]
Use for concepts with steps, cycles, or structure. Types available:
  cycle    — circular process (water cycle, carbon cycle, life cycle)
  steps    — linear sequence (rock formation steps, photosynthesis steps)
  compare  — two things side by side
  label    — a thing with labeled parts
Example: [DIAGRAM: cycle | water cycle showing evaporation condensation precipitation]

[FUN_FACT: one interesting sentence]
Use for surprising or delightful facts that make students go "woah!"
Keep it to one sentence. Make it feel exciting, not textbook.
Example: [FUN_FACT: A single storm cloud can hold 500,000 kg of water — heavier than a Boeing 747!]

[QUIZ: question | option A | option B | option C | correct answer]
Use after explaining a concept to check understanding.
Always 3 options. The correct answer must exactly match one of the options.
Example: [QUIZ: What makes water turn into vapor? | Freezing | Heating | Mixing with air | Heating]

RULES:
- Mix tags naturally with your text. Don't dump multiple tags in a row.
- Never use more than 2 tags per response.
- Tags must appear on their own line, not inside a sentence.
- If you are not sure a tag is helpful, just write plain text.
PROMPT;
}
```

---

## Step 3 — ResponseParser service

Create `app/Services/AI/ResponseParser.php`.

This runs on the **complete** LLM response after streaming finishes.
It splits the raw text into an ordered array of typed segments.

```php
<?php

namespace App\Services\AI;

class ResponseParser
{
    // Matches any of our four tags on their own line
    private const TAG_PATTERN = '/\[(IMAGE|DIAGRAM|FUN_FACT|QUIZ):([^\]]+)\]/';

    /**
     * Parse raw LLM output into an ordered array of typed segments.
     *
     * Returns array of:
     *   ['type' => 'text',     'content' => '...']
     *   ['type' => 'image',    'keyword' => '...']
     *   ['type' => 'diagram',  'diagram_type' => '...', 'description' => '...']
     *   ['type' => 'fun_fact', 'content' => '...']
     *   ['type' => 'quiz',     'question' => '...', 'options' => [...], 'answer' => '...']
     */
    public function parse(string $raw): array
    {
        $segments = [];
        $lines    = explode("\n", $raw);
        $textBuffer = '';

        foreach ($lines as $line) {
            if (preg_match(self::TAG_PATTERN, trim($line), $matches)) {
                // Flush any accumulated text before this tag
                if (trim($textBuffer) !== '') {
                    $segments[]  = ['type' => 'text', 'content' => trim($textBuffer)];
                    $textBuffer  = '';
                }

                $tagType = $matches[1];
                $tagBody = trim($matches[2]);

                $segment = $this->parseTag($tagType, $tagBody);
                if ($segment !== null) {
                    $segments[] = $segment;
                }
            } else {
                $textBuffer .= $line . "\n";
            }
        }

        // Flush remaining text
        if (trim($textBuffer) !== '') {
            $segments[] = ['type' => 'text', 'content' => trim($textBuffer)];
        }

        // Safety: enforce max 2 non-text segments per response
        return $this->enforceTagLimit($segments);
    }

    private function parseTag(string $type, string $body): ?array
    {
        return match ($type) {
            'IMAGE' => [
                'type'    => 'image',
                'keyword' => $this->sanitizeKeyword($body),
            ],

            'DIAGRAM' => $this->parseDiagramTag($body),

            'FUN_FACT' => [
                'type'    => 'fun_fact',
                'content' => strip_tags($body),
            ],

            'QUIZ' => $this->parseQuizTag($body),

            default => null,
        };
    }

    private function parseDiagramTag(string $body): ?array
    {
        $parts = array_map('trim', explode('|', $body, 2));
        if (count($parts) < 2) return null;

        $validTypes = ['cycle', 'steps', 'compare', 'label'];
        $type       = strtolower($parts[0]);

        if (!in_array($type, $validTypes)) $type = 'steps';

        return [
            'type'         => 'diagram',
            'diagram_type' => $type,
            'description'  => strip_tags($parts[1]),
        ];
    }

    private function parseQuizTag(string $body): ?array
    {
        $parts = array_map('trim', explode('|', $body));

        // Expects: question | optA | optB | optC | answer
        if (count($parts) < 5) return null;

        $question = strip_tags($parts[0]);
        $options  = [strip_tags($parts[1]), strip_tags($parts[2]), strip_tags($parts[3])];
        $answer   = strip_tags($parts[4]);

        // Validate answer matches an option
        if (!in_array($answer, $options)) return null;

        return [
            'type'     => 'quiz',
            'question' => $question,
            'options'  => $options,
            'answer'   => $answer,
        ];
    }

    private function sanitizeKeyword(string $keyword): string
    {
        // Remove any URLs the model might have accidentally included
        $keyword = preg_replace('/https?:\/\/\S+/', '', $keyword);

        // Keep only alphanumeric, spaces, hyphens
        $keyword = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $keyword);

        return trim(substr($keyword, 0, 100));
    }

    private function enforceTagLimit(array $segments): array
    {
        $tagCount = 0;
        $result   = [];

        foreach ($segments as $segment) {
            if ($segment['type'] !== 'text') {
                $tagCount++;
                if ($tagCount > 2) continue; // drop extras silently
            }
            $result[] = $segment;
        }

        return $result;
    }
}
```

---

## Step 4 — ImageService

Create `app/Services/AI/ImageService.php`.

Resolves image keywords to real, licensed, safe image URLs.
Default source: Wikimedia Commons (free, educational, CC-licensed, no API key).
District can configure Unsplash or Pexels as alternative sources.

```php
<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImageService
{
    private const CACHE_TTL  = 86400; // 24 hours
    private const CACHE_PREFIX = 'atlaas_img:';

    public function resolve(string $keyword, ?string $districtSource = null): ?array
    {
        $cacheKey = self::CACHE_PREFIX . md5(strtolower($keyword));

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($keyword, $districtSource) {
            $source = $districtSource ?? 'wikimedia';

            $result = match ($source) {
                'unsplash' => $this->fetchUnsplash($keyword),
                'pexels'   => $this->fetchPexels($keyword),
                default    => $this->fetchWikimedia($keyword),
            };

            // Fallback chain: if primary source fails, try Wikimedia
            if ($result === null && $source !== 'wikimedia') {
                $result = $this->fetchWikimedia($keyword);
            }

            return $result;
        });
    }

    // ─── Wikimedia Commons ─────────────────────────────────────────────────

    private function fetchWikimedia(string $keyword): ?array
    {
        try {
            // Step 1: Search for pages matching the keyword
            $searchResponse = Http::timeout(5)->get('https://en.wikipedia.org/w/api.php', [
                'action'      => 'query',
                'list'        => 'search',
                'srsearch'    => $keyword,
                'srlimit'     => 3,
                'srnamespace' => 0,
                'format'      => 'json',
            ]);

            if (!$searchResponse->ok()) return null;

            $pages = $searchResponse->json('query.search', []);
            if (empty($pages)) return null;

            // Step 2: Get the best image from the top result
            foreach ($pages as $page) {
                $result = $this->getWikimediaPageImage($page['pageid'], $page['title']);
                if ($result !== null) return $result;
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('Wikimedia image fetch failed', ['keyword' => $keyword, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function getWikimediaPageImage(int $pageId, string $pageTitle): ?array
    {
        $response = Http::timeout(5)->get('https://en.wikipedia.org/w/api.php', [
            'action'      => 'query',
            'pageids'     => $pageId,
            'prop'        => 'pageimages|pageterms',
            'pithumbsize' => 800,
            'pilimit'     => 1,
            'format'      => 'json',
        ]);

        if (!$response->ok()) return null;

        $page  = $response->json("query.pages.{$pageId}");
        $thumb = $page['thumbnail'] ?? null;

        if (!$thumb || !isset($thumb['source'])) return null;

        // Only return images that are a reasonable size
        if (($thumb['width'] ?? 0) < 200 || ($thumb['height'] ?? 0) < 150) return null;

        return [
            'url'         => $thumb['source'],
            'width'       => $thumb['width'],
            'height'      => $thumb['height'],
            'alt'         => $pageTitle,
            'credit'      => 'Wikipedia / Wikimedia Commons',
            'credit_url'  => "https://en.wikipedia.org/wiki/" . urlencode(str_replace(' ', '_', $pageTitle)),
            'license'     => 'CC BY-SA',
            'source'      => 'wikimedia',
        ];
    }

    // ─── Unsplash (district-configured) ────────────────────────────────────

    private function fetchUnsplash(string $keyword): ?array
    {
        $key = config('services.unsplash.access_key');
        if (!$key) return null;

        try {
            $response = Http::timeout(5)
                ->withHeader('Authorization', "Client-ID {$key}")
                ->get('https://api.unsplash.com/search/photos', [
                    'query'       => $keyword,
                    'per_page'    => 1,
                    'orientation' => 'landscape',
                    'content_filter' => 'high', // safe content only
                ]);

            $photo = $response->json('results.0');
            if (!$photo) return null;

            return [
                'url'        => $photo['urls']['regular'],
                'width'      => $photo['width'],
                'height'     => $photo['height'],
                'alt'        => $photo['alt_description'] ?? $keyword,
                'credit'     => 'Photo by ' . $photo['user']['name'] . ' on Unsplash',
                'credit_url' => $photo['links']['html'] . '?utm_source=atlaas&utm_medium=referral',
                'license'    => 'Unsplash License',
                'source'     => 'unsplash',
            ];
        } catch (\Throwable $e) {
            Log::warning('Unsplash fetch failed', ['keyword' => $keyword]);
            return null;
        }
    }

    // ─── Pexels (district-configured) ──────────────────────────────────────

    private function fetchPexels(string $keyword): ?array
    {
        $key = config('services.pexels.api_key');
        if (!$key) return null;

        try {
            $response = Http::timeout(5)
                ->withHeader('Authorization', $key)
                ->get('https://api.pexels.com/v1/search', [
                    'query'       => $keyword,
                    'per_page'    => 1,
                    'orientation' => 'landscape',
                ]);

            $photo = $response->json('photos.0');
            if (!$photo) return null;

            return [
                'url'        => $photo['src']['large'],
                'width'      => $photo['width'],
                'height'     => $photo['height'],
                'alt'        => $photo['alt'] ?? $keyword,
                'credit'     => 'Photo by ' . $photo['photographer'] . ' on Pexels',
                'credit_url' => $photo['url'],
                'license'    => 'Pexels License',
                'source'     => 'pexels',
            ];
        } catch (\Throwable $e) {
            Log::warning('Pexels fetch failed', ['keyword' => $keyword]);
            return null;
        }
    }
}
```

Add optional service keys to `config/services.php`:
```php
'unsplash' => [
    'access_key' => env('UNSPLASH_ACCESS_KEY'),
],
'pexels' => [
    'api_key' => env('PEXELS_API_KEY'),
],
```

And to `.env.example`:
```
# Image sources (Wikimedia is used by default — no key needed)
# Uncomment and add keys to use these instead:
# UNSPLASH_ACCESS_KEY=
# PEXELS_API_KEY=
# IMAGE_SOURCE=wikimedia   # wikimedia|unsplash|pexels
```

---

## Step 5 — DiagramGenerator service

Create `app/Services/AI/DiagramGenerator.php`.

Generates simple SVG diagrams server-side based on diagram type + description.
The LLM provides the *content* (labels, steps) via the description string.
This service turns that into actual SVG markup.

```php
<?php

namespace App\Services\AI;

class DiagramGenerator
{
    /**
     * Generate an SVG string for a given diagram type and description.
     * Returns null if the type is unknown or description is unusable.
     */
    public function generate(string $type, string $description): ?string
    {
        $labels = $this->extractLabels($description);
        if (empty($labels)) return null;

        return match ($type) {
            'cycle'   => $this->renderCycle($labels, $description),
            'steps'   => $this->renderSteps($labels, $description),
            'compare' => $this->renderCompare($labels, $description),
            'label'   => $this->renderLabel($labels, $description),
            default   => $this->renderSteps($labels, $description),
        };
    }

    /**
     * Extract meaningful labels from the description string.
     * Tries comma-separated, then keyword extraction.
     */
    private function extractLabels(string $description): array
    {
        // If description has comma-separated items, use those
        if (str_contains($description, ',')) {
            $labels = array_map('trim', explode(',', $description));
            return array_filter($labels, fn($l) => strlen($l) > 1);
        }

        // Otherwise extract noun phrases (simplified: split on common connectors)
        $labels = preg_split('/\b(and|then|to|into|through|showing|with|from)\b/i', $description);
        return array_filter(array_map('trim', $labels ?? []), fn($l) => strlen($l) > 2);
    }

    // ─── Cycle diagram (e.g. water cycle) ──────────────────────────────────

    private function renderCycle(array $labels, string $description): string
    {
        $labels = array_values(array_slice($labels, 0, 5));
        $count  = count($labels);
        if ($count < 2) return $this->renderSteps($labels, $description);

        $cx = 340; $cy = 210; $r = 130;
        $colors = ['#1D9E75', '#378ADD', '#534AB7', '#D85A30', '#BA7517'];

        $nodes = [];
        for ($i = 0; $i < $count; $i++) {
            $angle = (2 * M_PI * $i / $count) - M_PI / 2;
            $nodes[] = [
                'x'     => $cx + $r * cos($angle),
                'y'     => $cy + $r * sin($angle),
                'label' => $labels[$i],
                'color' => $colors[$i % count($colors)],
                'angle' => $angle,
            ];
        }

        $arrows = '';
        foreach ($nodes as $i => $node) {
            $next = $nodes[($i + 1) % $count];

            // Arrow midpoint offset to curve outward slightly
            $mx = ($node['x'] + $next['x']) / 2;
            $my = ($node['y'] + $next['y']) / 2;

            // Shorten to not overlap node circles (r=28)
            $dx = $next['x'] - $node['x'];
            $dy = $next['y'] - $node['y'];
            $len = sqrt($dx * $dx + $dy * $dy);
            $sx = $node['x'] + ($dx / $len) * 32;
            $sy = $node['y'] + ($dy / $len) * 32;
            $ex = $next['x'] - ($dx / $len) * 32;
            $ey = $next['y'] - ($dy / $len) * 32;

            $arrows .= "<path d=\"M{$sx},{$sy} Q{$mx},{$my} {$ex},{$ey}\" fill=\"none\" stroke=\"#888780\" stroke-width=\"1.5\" marker-end=\"url(#arrow)\"/>";
        }

        $circles = '';
        foreach ($nodes as $node) {
            $x = round($node['x']); $y = round($node['y']);
            $label = htmlspecialchars($this->truncate($node['label'], 14));
            $color = $node['color'];

            // Split label into two lines if needed
            $words = explode(' ', $node['label']);
            $line1 = implode(' ', array_slice($words, 0, 2));
            $line2 = implode(' ', array_slice($words, 2));
            $l1 = htmlspecialchars($this->truncate($line1, 12));
            $l2 = htmlspecialchars($this->truncate($line2, 12));

            $circles .= "<circle cx=\"{$x}\" cy=\"{$y}\" r=\"28\" fill=\"{$color}\" opacity=\"0.15\" stroke=\"{$color}\" stroke-width=\"1.5\"/>";
            if ($l2) {
                $circles .= "<text x=\"{$x}\" y=\"" . ($y - 7) . "\" text-anchor=\"middle\" font-size=\"11\" fill=\"{$color}\" font-weight=\"500\">{$l1}</text>";
                $circles .= "<text x=\"{$x}\" y=\"" . ($y + 8) . "\" text-anchor=\"middle\" font-size=\"11\" fill=\"{$color}\" font-weight=\"500\">{$l2}</text>";
            } else {
                $circles .= "<text x=\"{$x}\" y=\"" . ($y + 4) . "\" text-anchor=\"middle\" font-size=\"11\" fill=\"{$color}\" font-weight=\"500\">{$l1}</text>";
            }
        }

        $title = htmlspecialchars($this->truncate(ucfirst($description), 50));

        return <<<SVG
<svg width="100%" viewBox="0 0 680 420" xmlns="http://www.w3.org/2000/svg">
<defs>
  <marker id="arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
    <path d="M2 1L8 5L2 9" fill="none" stroke="#888780" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
  </marker>
</defs>
<text x="340" y="28" text-anchor="middle" font-size="14" font-weight="500" fill="#444441">{$title}</text>
{$arrows}
{$circles}
</svg>
SVG;
    }

    // ─── Steps diagram (linear sequence) ───────────────────────────────────

    private function renderSteps(array $labels, string $description): string
    {
        $labels  = array_values(array_slice($labels, 0, 6));
        $count   = count($labels);
        $colors  = ['#1D9E75', '#378ADD', '#534AB7', '#D85A30', '#BA7517', '#0F6E56'];
        $boxW    = 90;
        $boxH    = 50;
        $gap     = 20;
        $totalW  = $count * $boxW + ($count - 1) * $gap;
        $startX  = (680 - $totalW) / 2;
        $y       = 80;

        $boxes  = '';
        $arrows = '';

        for ($i = 0; $i < $count; $i++) {
            $x     = $startX + $i * ($boxW + $gap);
            $color = $colors[$i % count($colors)];
            $label = htmlspecialchars($this->truncate($labels[$i], 11));
            $num   = $i + 1;

            $boxes .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$boxW}\" height=\"{$boxH}\" rx=\"8\" fill=\"{$color}\" opacity=\"0.12\" stroke=\"{$color}\" stroke-width=\"1.5\"/>";
            $boxes .= "<text x=\"" . ($x + $boxW / 2) . "\" y=\"" . ($y + 16) . "\" text-anchor=\"middle\" font-size=\"11\" fill=\"{$color}\" font-weight=\"600\">{$num}</text>";
            $boxes .= "<text x=\"" . ($x + $boxW / 2) . "\" y=\"" . ($y + 34) . "\" text-anchor=\"middle\" font-size=\"11\" fill=\"{$color}\" font-weight=\"500\">{$label}</text>";

            if ($i < $count - 1) {
                $ax = $x + $boxW + 2;
                $ay = $y + $boxH / 2;
                $ex = $ax + $gap - 4;
                $arrows .= "<line x1=\"{$ax}\" y1=\"{$ay}\" x2=\"{$ex}\" y2=\"{$ay}\" stroke=\"#888780\" stroke-width=\"1.5\" marker-end=\"url(#arrow)\"/>";
            }
        }

        $title = htmlspecialchars($this->truncate(ucfirst($description), 50));

        return <<<SVG
<svg width="100%" viewBox="0 0 680 200" xmlns="http://www.w3.org/2000/svg">
<defs>
  <marker id="arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
    <path d="M2 1L8 5L2 9" fill="none" stroke="#888780" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
  </marker>
</defs>
<text x="340" y="28" text-anchor="middle" font-size="14" font-weight="500" fill="#444441">{$title}</text>
{$boxes}
{$arrows}
</svg>
SVG;
    }

    // ─── Compare diagram (two columns) ─────────────────────────────────────

    private function renderCompare(array $labels, string $description): string
    {
        // Split labels roughly in half
        $mid    = (int) ceil(count($labels) / 2);
        $left   = array_slice($labels, 0, $mid);
        $right  = array_slice($labels, $mid);

        $leftTitle  = array_shift($left)  ?? 'A';
        $rightTitle = array_shift($right) ?? 'B';

        $rowH   = 36;
        $maxRows = max(count($left), count($right));
        $height  = 60 + $maxRows * $rowH + 40;

        $leftRows = $rightRows = '';
        for ($i = 0; $i < $maxRows; $i++) {
            $y = 90 + $i * $rowH;
            if (isset($left[$i])) {
                $l = htmlspecialchars($this->truncate($left[$i], 22));
                $leftRows .= "<rect x=\"40\" y=\"{$y}\" width=\"260\" height=\"30\" rx=\"4\" fill=\"#1D9E75\" opacity=\"0.08\" stroke=\"#1D9E75\" stroke-width=\"0.5\"/>";
                $leftRows .= "<text x=\"170\" y=\"" . ($y + 20) . "\" text-anchor=\"middle\" font-size=\"12\" fill=\"#085041\">{$l}</text>";
            }
            if (isset($right[$i])) {
                $r = htmlspecialchars($this->truncate($right[$i], 22));
                $rightRows .= "<rect x=\"380\" y=\"{$y}\" width=\"260\" height=\"30\" rx=\"4\" fill=\"#378ADD\" opacity=\"0.08\" stroke=\"#378ADD\" stroke-width=\"0.5\"/>";
                $rightRows .= "<text x=\"510\" y=\"" . ($y + 20) . "\" text-anchor=\"middle\" font-size=\"12\" fill=\"#0C447C\">{$r}</text>";
            }
        }

        $lt = htmlspecialchars($this->truncate($leftTitle, 20));
        $rt = htmlspecialchars($this->truncate($rightTitle, 20));

        return <<<SVG
<svg width="100%" viewBox="0 0 680 {$height}" xmlns="http://www.w3.org/2000/svg">
<rect x="40" y="50" width="260" height="34" rx="8" fill="#1D9E75" opacity="0.2" stroke="#1D9E75" stroke-width="1.5"/>
<text x="170" y="72" text-anchor="middle" font-size="14" font-weight="600" fill="#085041">{$lt}</text>
<rect x="380" y="50" width="260" height="34" rx="8" fill="#378ADD" opacity="0.2" stroke="#378ADD" stroke-width="1.5"/>
<text x="510" y="72" text-anchor="middle" font-size="14" font-weight="600" fill="#0C447C">{$rt}</text>
<line x1="340" y1="50" x2="340" y2="{$height}" stroke="#D3D1C7" stroke-width="1" stroke-dasharray="4 4"/>
{$leftRows}
{$rightRows}
</svg>
SVG;
    }

    // ─── Label diagram (a thing with labeled parts) ─────────────────────────

    private function renderLabel(array $labels, string $description): string
    {
        // For now, render as steps — a proper label diagram requires
        // subject-specific geometry. Future enhancement: pass to LLM to
        // generate SVG directly for known subjects (human body, cell, etc.)
        return $this->renderSteps($labels, $description);
    }

    private function truncate(string $str, int $len): string
    {
        $str = trim($str);
        return mb_strlen($str) > $len ? mb_substr($str, 0, $len - 1) . '…' : $str;
    }
}
```

---

## Step 6 — Wire into LLMService

Update `app/Services/AI/LLMService.php`:

Add the new services to the constructor:
```php
public function __construct(
    private SafetyFilter     $safety,
    private PrivacyFilter    $privacy,
    private PromptBuilder    $promptBuilder,
    private ResponseParser   $parser,     // NEW
    private ImageService     $images,     // NEW
    private DiagramGenerator $diagrams,   // NEW
) {}
```

After streaming completes and safety check passes, parse and enrich:
```php
// After: $this->safety->check($fullResponse) check
// Replace the plain storeMessages call with:

$segments = $this->parser->parse($fullResponse);
$enriched = $this->enrichSegments($segments, $session);

// Store the raw text version (for teacher transcript, safety review)
$this->storeMessages($session, $userMessage, $fullResponse, $flag);

// Pass enriched segments to the complete callback
$onComplete($enriched);
```

Update `streamResponse` signature — `onComplete` now receives the enriched segments:
```php
public function streamResponse(
    StudentSession $session,
    string         $userMessage,
    callable       $onChunk,
    callable       $onComplete, // now called with: $onComplete(array $segments)
): void
```

Add the enrichment method:
```php
private function enrichSegments(array $segments, StudentSession $session): array
{
    $districtSource = $session->district->settings['image_source'] ?? 'wikimedia';

    foreach ($segments as &$segment) {
        if ($segment['type'] === 'image') {
            $resolved = $this->images->resolve($segment['keyword'], $districtSource);
            $segment['resolved'] = $resolved; // null = image unavailable, frontend shows nothing
        }

        if ($segment['type'] === 'diagram') {
            $svg = $this->diagrams->generate($segment['diagram_type'], $segment['description']);
            $segment['svg'] = $svg;
        }
    }

    return $segments;
}
```

Register in `AppServiceProvider`:
```php
$this->app->singleton(\App\Services\AI\ResponseParser::class);
$this->app->singleton(\App\Services\AI\ImageService::class);
$this->app->singleton(\App\Services\AI\DiagramGenerator::class);
$this->app->singleton(\App\Services\AI\LLMService::class);
```

---

## Step 7 — Update MessageController SSE output

The `done` event now carries the enriched segments:
```php
onComplete: function (array $segments) {
    echo "data: " . json_encode([
        'type'     => 'done',
        'segments' => $segments,
    ]) . "\n\n";
    ob_flush();
    flush();
},
```

---

## Step 8 — React: RichMessage component

### `resources/js/Components/Atlaas/RichMessage.tsx`

This is the main rendering component. It receives an array of segments and
renders each one appropriately.

```tsx
import { useState } from 'react';

interface TextSegment    { type: 'text';     content: string }
interface ImageSegment   { type: 'image';    keyword: string; resolved?: ImageData | null }
interface DiagramSegment { type: 'diagram';  diagram_type: string; description: string; svg?: string }
interface FunFactSegment { type: 'fun_fact'; content: string }
interface QuizSegment    { type: 'quiz';     question: string; options: string[]; answer: string }

interface ImageData {
    url: string; alt: string; credit: string; credit_url: string;
}

type Segment = TextSegment | ImageSegment | DiagramSegment | FunFactSegment | QuizSegment;

interface Props {
    segments: Segment[];
    isStreaming?: boolean;
}

export function RichMessage({ segments, isStreaming = false }: Props) {
    return (
        <div className="space-y-3">
            {segments.map((segment, i) => (
                <SegmentRenderer key={i} segment={segment} />
            ))}
            {isStreaming && (
                <span className="inline-block h-3.5 w-0.5 bg-gray-400 animate-pulse ml-0.5" />
            )}
        </div>
    );
}

function SegmentRenderer({ segment }: { segment: Segment }) {
    switch (segment.type) {
        case 'text':     return <TextBlock content={segment.content} />;
        case 'image':    return <ImageBlock segment={segment} />;
        case 'diagram':  return <DiagramBlock segment={segment} />;
        case 'fun_fact': return <FunFactBlock content={segment.content} />;
        case 'quiz':     return <QuizBlock segment={segment} />;
        default:         return null;
    }
}
```

### Text block — renders markdown-lite (bold, line breaks)
```tsx
function TextBlock({ content }: { content: string }) {
    // Simple markdown: **bold**, newlines → <br>
    const html = content
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\n/g, '<br />');

    return (
        <p
            className="text-sm leading-relaxed text-gray-900"
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}
```

### Image block — photo with attribution
```tsx
function ImageBlock({ segment }: { segment: ImageSegment }) {
    if (!segment.resolved) return null; // silently skip if image unavailable

    const img = segment.resolved;

    return (
        <figure className="my-1 overflow-hidden rounded-xl">
            <img
                src={img.url}
                alt={img.alt}
                className="w-full object-cover"
                style={{ maxHeight: '260px' }}
                loading="lazy"
                onError={(e) => {
                    // Hide the figure if the image fails to load
                    (e.currentTarget.closest('figure') as HTMLElement).style.display = 'none';
                }}
            />
            <figcaption className="px-3 py-1.5 text-xs text-gray-400 flex items-center justify-between">
                <span>{img.alt}</span>
                <a
                    href={img.credit_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="hover:text-gray-600 transition-colors"
                >
                    {img.credit}
                </a>
            </figcaption>
        </figure>
    );
}
```

### Diagram block — inline SVG
```tsx
function DiagramBlock({ segment }: { segment: DiagramSegment }) {
    if (!segment.svg) return null;

    return (
        <div
            className="my-1 overflow-hidden rounded-xl border border-gray-100 bg-gray-50 p-3"
            dangerouslySetInnerHTML={{ __html: segment.svg }}
        />
    );
}
```

> **Security note:** The SVG is generated entirely server-side by `DiagramGenerator`.
> It never contains user input or LLM-generated markup — only our own controlled SVG templates.
> `dangerouslySetInnerHTML` is safe here because the source is our own code, not user content.

### Fun fact block — styled callout card
```tsx
function FunFactBlock({ content }: { content: string }) {
    return (
        <div className="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
            <span className="mt-0.5 text-lg" aria-hidden>💡</span>
            <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-amber-700 mb-0.5">
                    Did you know?
                </p>
                <p className="text-sm text-amber-900 leading-relaxed">{content}</p>
            </div>
        </div>
    );
}
```

### Quiz block — interactive mini-quiz
```tsx
function QuizBlock({ segment }: { segment: QuizSegment }) {
    const [selected, setSelected] = useState<string | null>(null);
    const answered = selected !== null;

    return (
        <div className="rounded-xl border border-blue-100 bg-blue-50 px-4 py-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-blue-600 mb-2">
                Quick check ✓
            </p>
            <p className="text-sm font-medium text-blue-900 mb-3">{segment.question}</p>
            <div className="space-y-2">
                {segment.options.map(option => {
                    const isCorrect  = option === segment.answer;
                    const isSelected = option === selected;

                    let classes = 'w-full rounded-lg border px-3 py-2 text-sm text-left transition-all ';

                    if (!answered) {
                        classes += 'border-blue-200 bg-white text-blue-800 hover:bg-blue-100 cursor-pointer';
                    } else if (isCorrect) {
                        classes += 'border-green-300 bg-green-50 text-green-800 font-medium';
                    } else if (isSelected) {
                        classes += 'border-red-200 bg-red-50 text-red-700';
                    } else {
                        classes += 'border-blue-100 bg-white text-blue-400 opacity-60';
                    }

                    return (
                        <button
                            key={option}
                            className={classes}
                            onClick={() => !answered && setSelected(option)}
                            disabled={answered}
                        >
                            {answered && isCorrect && '✓ '}
                            {answered && isSelected && !isCorrect && '✗ '}
                            {option}
                        </button>
                    );
                })}
            </div>
            {answered && (
                <p className={`mt-3 text-xs font-medium ${selected === segment.answer ? 'text-green-700' : 'text-blue-700'}`}>
                    {selected === segment.answer
                        ? 'Great job! That\'s correct! 🎉'
                        : `Not quite — the answer is: ${segment.answer}`}
                </p>
            )}
        </div>
    );
}
```

---

## Step 9 — Update Session.tsx to use RichMessage

Replace the plain `ChatBubble` for assistant messages with `RichMessage`:

```tsx
// In the messages state, store either plain text (during streaming)
// or segments array (after done event)
interface AssistantMessage {
    id: string;
    role: 'assistant';
    segments: Segment[];  // ← was: content: string
    created_at: string;
}

// During streaming: show plain text with blinking cursor
{isStreaming && streamingContent && (
    <div className="flex justify-start">
        <div className="max-w-lg rounded-2xl rounded-bl-sm bg-gray-100 px-4 py-3">
            <p className="text-sm leading-relaxed text-gray-900">
                {streamingContent}
                <span className="inline-block h-3.5 w-0.5 bg-gray-400 animate-pulse ml-0.5" />
            </p>
        </div>
    </div>
)}

// After done: replace streaming buffer with RichMessage
// In the SSE done handler:
if (data.type === 'done') {
    const segments: Segment[] = data.segments ?? [{ type: 'text', content: accumulated }];
    setMessages(prev => [...prev, {
        id:         crypto.randomUUID(),
        role:       'assistant',
        segments,
        created_at: new Date().toISOString(),
    }]);
    setStreamingContent('');
    setIsStreaming(false);
}

// In the render:
{msg.role === 'assistant' && (
    <div className="flex justify-start">
        <div className="max-w-lg">
            <div className="flex items-start gap-2 mb-1">
                <AtlaasAvatar state="idle" size="sm" />
                <p className="text-xs text-gray-400 mt-1">ATLAAS</p>
            </div>
            <div className="rounded-2xl rounded-bl-sm bg-gray-100 px-4 py-3">
                <RichMessage segments={msg.segments} />
            </div>
        </div>
    </div>
)}
```

---

## Step 10 — Update test seeder system prompt

Update the Water Cycle space in `TestDataSeeder` to demonstrate all four tags:
```php
'system_prompt' =>
    'You are ATLAAS, a friendly and enthusiastic science tutor for Grade 5 students. ' .
    'Explain the water cycle using simple language, lots of energy, and real examples. ' .
    'Use images to show what things look like in real life. ' .
    'Use diagrams when explaining sequences or cycles. ' .
    'Add a fun fact when you share something surprising. ' .
    'Use a quiz after explaining each main concept to check understanding. ' .
    'Keep students engaged — make learning feel like an adventure, not a textbook.',
```

---

## Step 11 — Verify

```bash
php artisan migrate:fresh --seed
npm run dev
php artisan serve
```

**Checklist:**

ATLAAS identity:
- [ ] No legacy codenames (Bridger, LearnBridge, or non-ATLAAS product names) in the UI (check browser, page source)
- [ ] ATLAAS avatar shows in chat header

Image tag:
- [ ] Student types "What does evaporation look like?" → ATLAAS responds with `[IMAGE: ...]` in raw output
- [ ] A real photo from Wikimedia appears in the chat bubble with attribution
- [ ] Photo has a credit link in the caption
- [ ] Check Redis: `php artisan tinker` → `Cache::get('atlaas_img:' . md5('water evaporation lake'))` should return image data after first fetch
- [ ] If the image keyword finds nothing → the image slot is silently skipped (no broken image)
- [ ] `onError` on the `<img>` tag hides the figure if the URL is broken

Diagram tag:
- [ ] Student asks "Can you show me the water cycle steps?" → ATLAAS responds with `[DIAGRAM: cycle | ...]`
- [ ] An SVG diagram appears inline in the chat bubble
- [ ] Diagram has colored nodes and arrows (cycle or steps layout)

Fun fact tag:
- [ ] ATLAAS sends a `[FUN_FACT: ...]` during the water cycle lesson
- [ ] It renders as an amber callout card with "Did you know?" label

Quiz tag:
- [ ] ATLAAS sends a `[QUIZ: ...]` after explaining a concept
- [ ] Three answer buttons appear
- [ ] Clicking the correct answer → green ✓ + "Great job!" message
- [ ] Clicking wrong → red ✗ + correct answer revealed
- [ ] Cannot change answer after selecting

Safety + parsing:
- [ ] Plain text messages still work exactly as before
- [ ] Safety filter still fires on crisis phrases (images/tags do not bypass it)
- [ ] ResponseParser enforces max 2 tags per response — test by prompting ATLAAS to use many tags

Teacher view:
- [ ] Teacher's Compass View still shows the plain text transcript (not segments)
- [ ] Session summary still generates correctly from plain text messages

---

## Phase 3b complete.

The session page now looks like a real interactive learning experience:
real photos from Wikimedia, color-coded cycle and step diagrams, amber
"Did you know?" callout cards, and instant-feedback mini quizzes — all
flowing naturally between ATLAAS's conversational text.

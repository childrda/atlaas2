<?php

namespace App\Services\AI;

class DiagramGenerator
{
    public function generate(string $type, string $description): ?string
    {
        $labels = $this->extractLabels($description);
        if ($labels === []) {
            return null;
        }

        return match ($type) {
            'cycle' => $this->renderCycle($labels, $description),
            'steps' => $this->renderSteps($labels, $description),
            'compare' => $this->renderCompare($labels, $description),
            'label' => $this->renderLabel($labels, $description),
            default => $this->renderSteps($labels, $description),
        };
    }

    /**
     * @return list<string>
     */
    private function extractLabels(string $description): array
    {
        if (str_contains($description, ',')) {
            $labels = array_map('trim', explode(',', $description));

            return array_values(array_filter($labels, fn (string $l) => strlen($l) > 1));
        }

        $labels = preg_split('/\b(and|then|to|into|through|showing|with|from)\b/i', $description);

        return array_values(array_filter(array_map('trim', $labels ?? []), fn (string $l) => strlen($l) > 2));
    }

    /**
     * @param  list<string>  $labels
     */
    private function renderCycle(array $labels, string $description): string
    {
        $labels = array_values(array_slice($labels, 0, 5));
        $count = count($labels);
        if ($count < 2) {
            return $this->renderSteps($labels, $description);
        }

        $cx = 340;
        $cy = 210;
        $r = 130;
        $colors = ['#1D9E75', '#378ADD', '#534AB7', '#D85A30', '#BA7517'];

        $nodes = [];
        for ($i = 0; $i < $count; $i++) {
            $angle = (2 * M_PI * $i / $count) - M_PI / 2;
            $nodes[] = [
                'x' => $cx + $r * cos($angle),
                'y' => $cy + $r * sin($angle),
                'label' => $labels[$i],
                'color' => $colors[$i % count($colors)],
            ];
        }

        $arrows = '';
        foreach ($nodes as $i => $node) {
            $next = $nodes[($i + 1) % $count];
            $mx = ($node['x'] + $next['x']) / 2;
            $my = ($node['y'] + $next['y']) / 2;
            $dx = $next['x'] - $node['x'];
            $dy = $next['y'] - $node['y'];
            $len = sqrt($dx * $dx + $dy * $dy) ?: 1;
            $sx = $node['x'] + ($dx / $len) * 32;
            $sy = $node['y'] + ($dy / $len) * 32;
            $ex = $next['x'] - ($dx / $len) * 32;
            $ey = $next['y'] - ($dy / $len) * 32;
            $arrows .= "<path d=\"M{$sx},{$sy} Q{$mx},{$my} {$ex},{$ey}\" fill=\"none\" stroke=\"#888780\" stroke-width=\"1.5\" marker-end=\"url(#arrow)\"/>";
        }

        $circles = '';
        foreach ($nodes as $node) {
            $x = round($node['x']);
            $y = round($node['y']);
            $color = $node['color'];
            $words = explode(' ', $node['label']);
            $line1 = implode(' ', array_slice($words, 0, 2));
            $line2 = implode(' ', array_slice($words, 2));
            $l1 = htmlspecialchars($this->truncate($line1, 12), ENT_QUOTES | ENT_XML1);
            $l2 = htmlspecialchars($this->truncate($line2, 12), ENT_QUOTES | ENT_XML1);

            $circles .= "<circle cx=\"{$x}\" cy=\"{$y}\" r=\"28\" fill=\"{$color}\" opacity=\"0.15\" stroke=\"{$color}\" stroke-width=\"1.5\"/>";
            if ($l2 !== '') {
                $circles .= "<text x=\"{$x}\" y=\"".($y - 7)."\" text-anchor=\"middle\" font-size=\"11\" fill=\"{$color}\" font-weight=\"500\">{$l1}</text>";
                $circles .= "<text x=\"{$x}\" y=\"".($y + 8)."\" text-anchor=\"middle\" font-size=\"11\" fill=\"{$color}\" font-weight=\"500\">{$l2}</text>";
            } else {
                $circles .= "<text x=\"{$x}\" y=\"".($y + 4)."\" text-anchor=\"middle\" font-size=\"11\" fill=\"{$color}\" font-weight=\"500\">{$l1}</text>";
            }
        }

        $title = htmlspecialchars($this->truncate(ucfirst($description), 50), ENT_QUOTES | ENT_XML1);

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

    /**
     * @param  list<string>  $labels
     */
    private function renderSteps(array $labels, string $description): string
    {
        $labels = array_values(array_slice($labels, 0, 6));
        $count = count($labels);
        $colors = ['#1D9E75', '#378ADD', '#534AB7', '#D85A30', '#BA7517', '#0F6E56'];
        $boxW = 90;
        $boxH = 50;
        $gap = 20;
        $totalW = $count * $boxW + ($count - 1) * $gap;
        $startX = (680 - $totalW) / 2;
        $y = 80;

        $boxes = '';
        $arrows = '';

        for ($i = 0; $i < $count; $i++) {
            $x = $startX + $i * ($boxW + $gap);
            $color = $colors[$i % count($colors)];
            $label = htmlspecialchars($this->truncate($labels[$i], 11), ENT_QUOTES | ENT_XML1);
            $num = $i + 1;

            $boxes .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$boxW}\" height=\"{$boxH}\" rx=\"8\" fill=\"{$color}\" opacity=\"0.12\" stroke=\"{$color}\" stroke-width=\"1.5\"/>";
            $boxes .= '<text x="'.($x + $boxW / 2).'" y="'.($y + 16).'" text-anchor="middle" font-size="11" fill="'.$color.'" font-weight="600">'.$num.'</text>';
            $boxes .= '<text x="'.($x + $boxW / 2).'" y="'.($y + 34).'" text-anchor="middle" font-size="11" fill="'.$color.'" font-weight="500">'.$label.'</text>';

            if ($i < $count - 1) {
                $ax = $x + $boxW + 2;
                $ay = $y + $boxH / 2;
                $ex = $ax + $gap - 4;
                $arrows .= "<line x1=\"{$ax}\" y1=\"{$ay}\" x2=\"{$ex}\" y2=\"{$ay}\" stroke=\"#888780\" stroke-width=\"1.5\" marker-end=\"url(#arrow)\"/>";
            }
        }

        $title = htmlspecialchars($this->truncate(ucfirst($description), 50), ENT_QUOTES | ENT_XML1);

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

    /**
     * @param  list<string>  $labels
     */
    private function renderCompare(array $labels, string $description): string
    {
        if ($labels === []) {
            return $this->renderSteps([$description], $description);
        }

        $mid = (int) ceil(count($labels) / 2);
        $left = array_slice($labels, 0, $mid);
        $right = array_slice($labels, $mid);

        $leftTitle = array_shift($left) ?? 'A';
        $rightTitle = array_shift($right) ?? 'B';

        $rowH = 36;
        $maxRows = max(count($left), count($right));
        $height = 60 + $maxRows * $rowH + 40;

        $leftRows = '';
        $rightRows = '';
        for ($i = 0; $i < $maxRows; $i++) {
            $y = 90 + $i * $rowH;
            if (isset($left[$i])) {
                $l = htmlspecialchars($this->truncate($left[$i], 22), ENT_QUOTES | ENT_XML1);
                $leftRows .= "<rect x=\"40\" y=\"{$y}\" width=\"260\" height=\"30\" rx=\"4\" fill=\"#1D9E75\" opacity=\"0.08\" stroke=\"#1D9E75\" stroke-width=\"0.5\"/>";
                $leftRows .= '<text x="170" y="'.($y + 20).'" text-anchor="middle" font-size="12" fill="#085041">'.$l.'</text>';
            }
            if (isset($right[$i])) {
                $r = htmlspecialchars($this->truncate($right[$i], 22), ENT_QUOTES | ENT_XML1);
                $rightRows .= "<rect x=\"380\" y=\"{$y}\" width=\"260\" height=\"30\" rx=\"4\" fill=\"#378ADD\" opacity=\"0.08\" stroke=\"#378ADD\" stroke-width=\"0.5\"/>";
                $rightRows .= '<text x="510" y="'.($y + 20).'" text-anchor="middle" font-size="12" fill="#0C447C">'.$r.'</text>';
            }
        }

        $lt = htmlspecialchars($this->truncate($leftTitle, 20), ENT_QUOTES | ENT_XML1);
        $rt = htmlspecialchars($this->truncate($rightTitle, 20), ENT_QUOTES | ENT_XML1);

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

    /**
     * @param  list<string>  $labels
     */
    private function renderLabel(array $labels, string $description): string
    {
        return $this->renderSteps($labels, $description);
    }

    private function truncate(string $str, int $len): string
    {
        $str = trim($str);

        return mb_strlen($str) > $len ? mb_substr($str, 0, $len - 1).'…' : $str;
    }
}

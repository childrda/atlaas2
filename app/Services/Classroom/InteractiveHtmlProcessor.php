<?php

namespace App\Services\Classroom;

class InteractiveHtmlProcessor
{
    public const FORBIDDEN_PATTERNS = [
        '/\bfetch\s*\(/i',
        '/\bXMLHttpRequest\b/i',
        '/\bnavigator\.sendBeacon\b/i',
        '/\bnew\s+WebSocket\b/i',
        '/\beval\s*\(/i',
        '/\bnew\s+Function\s*\(/i',
    ];

    public function process(string $html): ?string
    {
        $html = $this->stripExternalResources($html);
        $html = $this->convertLatexDelimiters($html);

        if (stripos($html, 'katex') === false) {
            $html = $this->injectKatex($html);
        }

        $html = $this->injectIframeCss($html);

        if (! $this->validate($html)) {
            return null;
        }

        return $html;
    }

    private function stripExternalResources(string $html): string
    {
        $html = preg_replace(
            '/<script[^>]+src=["\'](?!https:\/\/cdn\.jsdelivr\.net)[^"\']+["\'][^>]*>.*?<\/script>/is',
            '',
            $html
        );
        $html = preg_replace(
            '/<link[^>]+href=["\'](?!https:\/\/cdn\.jsdelivr\.net)[^"\']*\.(css)["\'][^>]*\/?>/i',
            '',
            $html
        );

        return $html ?? '';
    }

    private function convertLatexDelimiters(string $html): string
    {
        $scriptBlocks = [];
        $html = preg_replace_callback(
            '/<script[^>]*>.*?<\/script>/is',
            function ($matches) use (&$scriptBlocks) {
                $placeholder = '__SCRIPT_BLOCK_'.count($scriptBlocks).'__';
                $scriptBlocks[] = $matches[0];

                return $placeholder;
            },
            $html
        );

        $html = preg_replace('/\$\$([^$]+)\$\$/s', '\\[$1\\]', $html ?? '');
        $html = preg_replace('/\$([^$\n]+?)\$/', '\\($1\\)', $html ?? '');

        foreach ($scriptBlocks as $i => $block) {
            $html = str_replace('__SCRIPT_BLOCK_'.$i.'__', $block, $html);
        }

        return $html;
    }

    private function injectKatex(string $html): string
    {
        $katex = "\n".'<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">'."\n"
            .'<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>'."\n"
            .'<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js"></script>'."\n"
            .'<script>document.addEventListener("DOMContentLoaded",function(){renderMathInElement(document.body,{delimiters:[{left:"\\\\[",right:"\\\\]",display:true},{left:"\\\\(",right:"\\\\)",display:false}]})});</script>'."\n";

        $pos = stripos($html, '</head>');
        if ($pos !== false) {
            return substr($html, 0, $pos).$katex.substr($html, $pos);
        }

        return $katex.$html;
    }

    private function injectIframeCss(string $html): string
    {
        $css = "\n<style data-iframe-patch>html,body{width:100%;height:100%;margin:0;padding:0;overflow-x:hidden;overflow-y:auto;}body{min-height:100vh;}</style>\n";
        $pos = stripos($html, '<head>');
        if ($pos !== false) {
            $insertAt = $pos + 6;

            return substr($html, 0, $insertAt).$css.substr($html, $insertAt);
        }

        return $css.$html;
    }

    private function validate(string $html): bool
    {
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $html)) {
                return false;
            }
        }

        return true;
    }
}

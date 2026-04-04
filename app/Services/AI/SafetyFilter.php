<?php

namespace App\Services\AI;

class SafetyFilter
{
    private array $patterns = [
        'self_harm' => [
            'severity' => 'critical',
            'patterns' => [
                '/\b(kill\s+myself|end\s+my\s+life|want\s+to\s+die|suicide|cut\s+myself|hurt\s+myself)\b/i',
                '/\b(don.?t\s+want\s+to\s+(be\s+here|live|exist))\b/i',
                '/\b(thinking\s+about\s+(hurting|killing)\s+(myself|me))\b/i',
            ],
        ],
        'abuse_disclosure' => [
            'severity' => 'critical',
            'patterns' => [
                '/\b(someone\s+is\s+(hitting|hurting|touching|abusing)\s+me)\b/i',
                '/\b(my\s+(mom|dad|parent|step|uncle|aunt|teacher|coach)\s+(hits|hurts|touches|beats)\s+me)\b/i',
                '/\b(being\s+(abused|hurt|touched)\s+(at\s+home|by\s+an?\s+adult))\b/i',
            ],
        ],
        'bullying' => [
            'severity' => 'high',
            'patterns' => [
                '/\b(they\s+(keep|always)\s+(calling\s+me|making\s+fun|hitting|pushing|excluding))\b/i',
                '/\b(nobody\s+(likes|wants)\s+me|everyone\s+hates\s+me)\b/i',
                '/\b(being\s+bullied|they\s+won.?t\s+stop)\b/i',
            ],
        ],
        'substance_synthesis' => [
            'severity' => 'high',
            'patterns' => [
                '/\bhow\s+to\s+(make|cook|synthesize|extract)\b.{0,40}\b(meth|fentanyl|DMT|LSD|acid|crack)\b/i',
                '/\b(whip-?its|nitrous)\b.{0,20}\b(high|how to)\b/i',
                '/\bhow\s+to\s+get\s+high\b.{0,30}\b(household|cleaning|medicine)\b/i',
            ],
        ],
        'weapons_synthesis' => [
            'severity' => 'critical',
            'patterns' => [
                '/\bhow\s+to\s+(make|build)\b.{0,30}\b(thermite|zip\s*gun|ghost\s*gun|IED|pipe\s*bomb)\b/i',
                '/\b(untraceable|undetectable)\b.{0,20}\b(gun|weapon|firearm)\b/i',
            ],
        ],
        'dangerous_challenge' => [
            'severity' => 'high',
            'patterns' => [
                '/\b(choking\s+game|huffing\s+glue|tide\s+pod)\b/i',
            ],
        ],
        'sexual_content' => [
            'severity' => 'high',
            'patterns' => [
                '/\b(send\s+nudes?|trade\s+pics|sexting|naked\s+photos?)\b/i',
                '/\b(describe\s+(a\s+)?sex\s+act|how\s+to\s+have\s+sex)\b/i',
                '/\b(porn|xxx|onlyfans)\b.{0,20}\b(show|send|link)\b/i',
            ],
        ],
        'hate_speech' => [
            'severity' => 'high',
            'patterns' => [
                '/\b(kill\s+all\s+(the\s+)?\w+|genocide\s+the)\b/i',
                '/\b(ethnic\s+cleansing|gas\s+the\s+\w+|send\s+them\s+back\s+to)\b/i',
                '/\b(lynch|exterminate)\b.{0,25}\b(people|them|those)\b/i',
            ],
        ],
        'cheating_request' => [
            'severity' => 'medium',
            'patterns' => [
                '/\bhow\s+(do|can)\s+i\s+cheat\b/i',
                '/\b(cheat|cheating)\s+on\s+(a\s+)?(test|quiz|exam|homework)\b/i',
                '/\b(give|tell)\s+me\s+the\s+answers?\s+to\s+(the\s+)?(test|quiz|exam)\b/i',
                '/\bwhat\'?s\s+the\s+answer\s+key\b/i',
            ],
        ],
        'profanity_severe' => [
            'severity' => 'medium',
            'patterns' => [],
        ],
    ];

    public function check(string $content): ?FlagResult
    {
        foreach ($this->patterns as $category => $config) {
            foreach ($config['patterns'] as $pattern) {
                if (! empty($pattern) && preg_match($pattern, $content)) {
                    return new FlagResult(
                        flagged: true,
                        category: $category,
                        severity: $config['severity'],
                    );
                }
            }
        }

        return null;
    }

    public function safeAtlaasResponse(string $category): string
    {
        return match ($category) {
            'self_harm', 'abuse_disclosure' => 'It sounds like you might be going through something really difficult right now. '.
                "You don't have to face that alone. Please talk to a trusted adult — your teacher, ".
                'a school counselor, or a parent — as soon as you can. They care about you and want to help.',

            'bullying' => "That sounds really hard, and I'm glad you felt comfortable sharing that. ".
                "It's important to talk to a trusted adult about what's happening — ".
                'your teacher or school counselor can help make it stop.',

            'substance_synthesis', 'dangerous_challenge' => "I can't help with that. ".
                "If you're curious about health or science in a safe way, ask about what you're learning in class.",

            'weapons_synthesis' => "I can't help with anything that could hurt people. ".
                'If something feels unsafe at school, tell a trusted adult right away.',

            'sexual_content' => "That's not something I can talk about here. ".
                'If you have health questions, your school nurse or a trusted adult is the right person to ask.',

            'hate_speech' => "I can't help with hurtful or hateful ideas. ".
                "Let's keep our chat respectful and focused on learning.",

            'cheating_request' => "I can't help with anything that breaks school honesty rules. ".
                'I can help you study or understand the material fairly — what topic are you working on?',

            default => "Let's keep our conversation focused on your learning today. ".
                'Is there something about the lesson I can help you with?',
        };
    }
}

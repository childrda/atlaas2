<?php

namespace App\Services\AI;

class PrivacyFilter
{
    public function clean(string $content): string
    {
        $content = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/', '[email]', $content);

        $content = preg_replace(
            '/(\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/',
            '[phone]',
            $content
        );

        return $content;
    }

    public function anonymizeName(string $content, string $realName): string
    {
        if ($realName === '') {
            return $content;
        }

        return str_ireplace($realName, 'the student', $content);
    }
}

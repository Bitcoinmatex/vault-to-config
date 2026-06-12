<?php

declare(strict_types=1);

namespace App\Latte;

use Latte\Loaders\FileLoader;

/**
 * Extended FileLoader that prepends {contentType text} to every template
 * if it is not already there. This disables HTML escaping - otherwise Latte
 * would corrupt values such as passwords or DSNs containing & or " (e.g.
 * & => &amp;), producing invalid NEON.
 */
final class PlainTextFileLoader extends FileLoader
{
    public function getContent(string $fileName): string
    {
        $content = parent::getContent($fileName);

        if (!str_starts_with(ltrim($content), '{contentType')) {
            $content = "{contentType text}\n" . $content;
        }

        return $content;
    }
}

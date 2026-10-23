<?php

namespace Tests\Feature\Filament;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class ThemeUtilityCoverageTest extends TestCase
{
    public function test_filament_views_and_classes_only_use_compiled_utilities(): void
    {
        $css = '';
        foreach (glob(public_path('css/soul-teal-*.css')) as $file) {
            $css .= file_get_contents($file);
        }

        $manifestPath = public_path('build/manifest.json');
        $this->assertFileExists($manifestPath);
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest as $entry) {
            $this->assertFileExists(public_path('build/'.$entry['file']));
        }
        $themeFile = $manifest['resources/css/filament/admin/theme.css']['file'] ?? null;
        $this->assertNotNull($themeFile, 'The panel theme must be present in the Vite manifest.');
        $themePath = public_path('build/'.$themeFile);
        $this->assertFileExists($themePath);
        $css .= file_get_contents($themePath);

        $missing = [];
        foreach ([resource_path('views/filament'), app_path('Filament')] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
                if (! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                foreach ($this->undefinedUtilities(file_get_contents($file->getPathname()), $css) as $class) {
                    $missing[$class] = true;
                }
            }
        }

        $this->assertSame([], array_keys($missing), 'Undefined CMS utility classes: '.implode(', ', array_keys($missing)));
    }

    public function test_unknown_utility_family_is_caught_in_mixed_blade_markup(): void
    {
        $source = '<div class="bsi-cms-stat {{ $flagged ? \'place-items-madeup\' : \'\' }}">';

        $this->assertSame(['bsi-cms-stat', 'place-items-madeup'], $this->undefinedUtilities($source, ''));
    }

    /** @return array<int, string> */
    private function undefinedUtilities(string $source, string $css): array
    {
        preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/s', $source, $attributes, PREG_SET_ORDER);
        preg_match_all('/["\']class["\']\s*=>\s*(["\'])(.*?)\1/s', $source, $phpAttributes, PREG_SET_ORDER);
        $classValues = array_map(static fn (array $match): string => $match[2], array_merge($attributes, $phpAttributes));
        preg_match_all('/@class\s*\(\s*\[(.*?)\]\s*\)/s', $source, $bladeClasses);
        foreach ($bladeClasses[1] as $classes) {
            preg_match_all('/["\']([^"\']+)["\']/', $classes, $conditionalClasses);
            array_push($classValues, ...$conditionalClasses[1]);
        }

        $missing = [];
        foreach ($classValues as $classValue) {
            foreach (preg_split('/\s+/', trim($classValue)) as $class) {
                $class = trim($class, "'\"");
                if (! $this->isUtility($class)) {
                    continue;
                }

                $selector = '.'.addcslashes($class, ':./[]()%#');
                if (! preg_match('/'.preg_quote($selector, '/').'(?![\w-])/', $css)) {
                    $missing[$class] = true;
                }
            }
        }

        return array_keys($missing);
    }

    private function isUtility(string $class): bool
    {
        if ($class === '' || str_contains($class, '{') || str_contains($class, '$') || str_starts_with($class, 'fi-')) {
            return false;
        }

        if (str_starts_with($class, 'bsi-')) {
            return true;
        }

        return (bool) preg_match('/^(?:(?:sm|md|lg|xl|2xl|dark|hover|focus|disabled):)*(?:-?(?:m[trblxy]?|p[trblxy]?|gap|space-[xy]|w|h|min-w|min-h|max-w|max-h|size|basis|inset|translate|scale|rotate|grid|col|row|flex|inline|block|hidden|items|justify|self|place|text|font|leading|tracking|bg|border|rounded|shadow|overflow|whitespace|break|truncate|opacity|ring|z|relative|absolute|sticky|top|right|bottom|left|cursor|underline|uppercase|lowercase|capitalize|italic|not-italic|sr-only|antialiased|pointer-events|align|table|divide|order|grow|shrink|object|aspect|transition|duration|ease|decoration|fill|stroke|animate)(?:$|-))/', $class);
    }
}

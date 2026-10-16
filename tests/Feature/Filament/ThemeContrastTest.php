<?php

namespace Tests\Feature\Filament;

use Tests\TestCase;

class ThemeContrastTest extends TestCase
{
    public function test_semantic_text_pairs_meet_wcag_aa_in_both_modes(): void
    {
        $css = file_get_contents(public_path('css/soul-teal-signature.css'));
        $this->assertNotFalse($css);
        $this->assertSame(1, preg_match('/^:root\s*\{(.*?)^\}/ms', $css, $light));
        $this->assertSame(1, preg_match('/\.dark,\s*\[data-theme=\'dark\'\]\s*\{(.*?)^\}/ms', $css, $dark));

        $pairs = [
            ['bg-canvas', 'text-primary'], ['bg-canvas', 'text-secondary'], ['bg-canvas', 'text-muted'],
            ['bg-surface', 'text-primary'], ['bg-surface', 'text-secondary'], ['bg-surface', 'text-muted'],
            ['bg-surface', 'text-brand'], ['bg-surface-raised', 'text-primary'],
            ['bg-surface-raised', 'text-secondary'], ['bg-surface-raised', 'text-muted'],
            ['bg-sidebar', 'text-on-sidebar'], ['bg-sidebar', 'text-on-sidebar-muted'],
            ['action-primary', 'text-on-primary'], ['bg-brand-soft', 'text-brand'],
            ['bg-accent-soft', 'text-accent'], ['bg-success', 'text-success'],
            ['bg-warning', 'text-warning'], ['bg-danger', 'text-danger'],
            ['bg-surface', 'text-accent'],
        ];

        foreach (['light' => $this->variables($light[1]), 'dark' => array_replace($this->variables($light[1]), $this->variables($dark[1]))] as $mode => $variables) {
            foreach ($pairs as [$background, $foreground]) {
                $ratio = $this->contrast(
                    $this->resolve($variables, $background),
                    $this->resolve($variables, $foreground),
                );

                $this->assertGreaterThanOrEqual(4.5, $ratio, "{$mode}: {$foreground} on {$background} is {$ratio}:1");
            }
        }
    }

    /** @return array<string, string> */
    private function variables(string $section): array
    {
        preg_match_all('/--sc-([\w-]+):\s*(#[0-9A-Fa-f]{6}|var\(--sc-[\w-]+\))\s*;/', $section, $matches, PREG_SET_ORDER);

        $variables = [];
        foreach ($matches as $match) {
            $variables[$match[1]] = $match[2];
        }

        return $variables;
    }

    /** @param array<string, string> $variables */
    private function resolve(array $variables, string $name): string
    {
        $value = $variables[$name];

        while (str_starts_with($value, 'var(')) {
            preg_match('/^var\(--sc-([\w-]+)\)$/', $value, $match);
            $value = $variables[$match[1]];
        }

        return $value;
    }

    private function contrast(string $first, string $second): float
    {
        $luminance = static function (string $hex): float {
            $channels = array_map(static function (string $channel): float {
                $value = hexdec($channel) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split(substr($hex, 1), 2));

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        $a = $luminance($first);
        $b = $luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}

<?php

namespace Tests\Feature\Integration;

use Tests\TestCase;

/** A merge once dropped a closing brace from pages.css and `npm run build` failed only at deploy time. */
class CssSourceTest extends TestCase
{
    public function test_every_stylesheet_has_balanced_braces(): void
    {
        $files = glob(resource_path('css/*.css'));
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $css = preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file));
            $this->assertSame(substr_count($css, '{'), substr_count($css, '}'), basename($file).' has unbalanced braces');
        }
    }
}

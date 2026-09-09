<?php

namespace Tests\Unit;

use Tests\TestCase;

class TranslateHelperTest extends TestCase
{
    public function test_missing_keys_do_not_rewrite_messages_php(): void
    {
        $path = resource_path('lang/en/messages.php');
        $before = file_get_contents($path);
        $missing = 'munch_translate_missing_'.bin2hex(random_bytes(4));

        $this->assertSame($missing, translate($missing));
        $this->assertSame($missing, \App\CentralLogics\translate($missing));
        $this->assertSame($before, file_get_contents($path));
        $this->assertStringNotContainsString($missing, file_get_contents($path));
    }

    public function test_existing_keys_still_resolve_from_messages(): void
    {
        $this->assertSame('Online', translate('Online'));
        $this->assertSame('POS', translate('POS'));
        $this->assertSame('Online', \App\CentralLogics\translate('Online'));
    }

    public function test_admin_language_editor_still_writes_messages_files(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/LanguageController.php'));
        $this->assertStringContainsString('function translateSubmit', $controller);
        $this->assertStringContainsString('function translateKeyRemove', $controller);
        $this->assertStringContainsString("file_put_contents(base_path('resources/lang/' . \$lang . '/messages.php')", $controller);
    }

    public function test_runtime_translate_helpers_no_longer_write_lang_files(): void
    {
        $helpers = file_get_contents(app_path('CentralLogics/helpers.php'));
        $translation = file_get_contents(app_path('CentralLogics/Translation.php'));
        $this->assertStringNotContainsString('file_put_contents', substr($helpers, strrpos($helpers, 'function translate($key)')));
        $this->assertStringNotContainsString('file_put_contents', $translation);
    }
}

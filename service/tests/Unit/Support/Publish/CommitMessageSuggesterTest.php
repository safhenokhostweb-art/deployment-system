<?php

namespace Tests\Unit\Support\Publish;

use App\Support\Publish\CommitMessageSuggester;
use Tests\TestCase;

class CommitMessageSuggesterTest extends TestCase
{
    public function test_suggests_a_message_covering_every_bucket_present(): void
    {
        $suggester = new CommitMessageSuggester();

        $message = $suggester->suggest([
            'app/Http/Controllers/FooController.php',
            'resources/views/foo/show.blade.php',
            'database/migrations/2026_01_01_000000_create_foo_table.php',
        ]);

        $this->assertStringContainsString('Laravel service', $message);
        $this->assertStringContainsString('UI', $message);
        $this->assertStringContainsString('database migrations', $message);
        $this->assertStringStartsWith('Auto update:', $message);
    }

    public function test_suggests_a_single_bucket_message(): void
    {
        $suggester = new CommitMessageSuggester();

        $message = $suggester->suggest(['app/Models/Foo.php']);

        $this->assertSame('Auto update: Laravel service changes', $message);
    }

    public function test_frontend_assets_are_detected(): void
    {
        $suggester = new CommitMessageSuggester();

        $message = $suggester->suggest(['resources/js/app.js', 'resources/css/app.css']);

        $this->assertStringContainsString('frontend assets', $message);
    }

    public function test_unrecognized_extensions_fall_back_to_other(): void
    {
        $suggester = new CommitMessageSuggester();

        $message = $suggester->suggest(['README.md']);

        $this->assertSame('Auto update: other changes', $message);
    }

    public function test_empty_file_list_returns_a_generic_message(): void
    {
        $suggester = new CommitMessageSuggester();

        $this->assertSame('Auto update: project changes', $suggester->suggest([]));
    }

    public function test_a_rename_is_bucketed_by_its_new_path(): void
    {
        $suggester = new CommitMessageSuggester();

        $message = $suggester->suggest(['old/Foo.txt -> resources/views/foo.blade.php']);

        $this->assertStringContainsString('UI', $message);
    }
}

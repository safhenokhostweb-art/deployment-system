<?php

namespace Tests\Feature\Dashboard;

use App\Models\GitHubAccount;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Delete a GitHub account" — disconnect() only ever flipped status
 * (recoverable by reconnecting the same installation); this is the hard
 * delete removing the row entirely. projects.github_account_id
 * nullOnDelete()'s this, so a linked project just loses that one link,
 * never blocked and never cascaded away.
 */
class GitHubAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_a_github_account(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $account = GitHubAccount::factory()->create();

        $response = $this->actingAs($admin)->delete(route('github.accounts.destroy', $account));

        $response->assertRedirect(route('github.accounts.index'));
        $this->assertDatabaseMissing('github_accounts', ['id' => $account->id]);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'github_account.deleted']);
    }

    public function test_deleting_a_github_account_only_unlinks_a_project_using_it(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $account = GitHubAccount::factory()->create();
        $project = Project::factory()->create(['github_account_id' => $account->id]);

        $this->actingAs($admin)->delete(route('github.accounts.destroy', $account))
            ->assertRedirect(route('github.accounts.index'));

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'github_account_id' => null]);
    }

    public function test_non_admin_cannot_delete_a_github_account(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $account = GitHubAccount::factory()->create();

        $this->actingAs($operator)->delete(route('github.accounts.destroy', $account))
            ->assertForbidden();

        $this->assertDatabaseHas('github_accounts', ['id' => $account->id]);
    }

    public function test_delete_button_is_visible_to_admin_on_the_github_accounts_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        GitHubAccount::factory()->create();

        $response = $this->actingAs($admin)->get(route('github.accounts.index'));

        $response->assertOk();
        $response->assertSee(__('ui.github.delete_button'));
    }
}

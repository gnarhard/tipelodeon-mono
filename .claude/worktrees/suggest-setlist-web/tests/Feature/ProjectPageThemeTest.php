<?php

declare(strict_types=1);

use App\Enums\SongTheme;
use App\Models\AudienceProfile;
use App\Models\Project;
use App\Models\ProjectSong;
use App\Models\Request as SongRequest;
use App\Models\RewardThreshold;
use App\Models\Setlist;
use App\Models\SetlistSet;
use App\Models\SetlistSong;
use App\Models\Song;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('renders light mode defaults while preserving dark mode styles', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => false,
        'is_accepting_original_requests' => false,
    ]);

    $this->get(route('project.page', ['projectSlug' => $project->slug]))
        ->assertSuccessful()
        ->assertSee('min-h-screen bg-canvas-light font-sans antialiased text-ink dark:bg-canvas-dark dark:text-ink-inverse', false)
        ->assertSee('relative isolate min-h-screen overflow-hidden bg-canvas-light text-ink dark:bg-canvas-dark dark:text-ink-inverse', false)
        ->assertSee('bg-ink-inverse dark:bg-ink/60 to-brand-50 dark:border-ink-border-dark', false)
        ->assertSee('to-brand-50', false)
        ->assertSee('from-accent-100 via-canvas-light to-canvas-light', false)
        ->assertSee('dark:from-surface-elevated dark:via-canvas-dark dark:to-canvas-dark', false)
        ->assertDontSee('background-color: #020617;', false);
});

it('renders a grammatically correct project page meta title', function (string $projectName, string $expectedTitle) {
    $this->withoutVite();

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'name' => $projectName,
    ]);

    $this->get(route('project.page', ['projectSlug' => $project->slug]))
        ->assertSuccessful()
        ->assertSeeText($expectedTitle);
})->with([
    'standard possessive' => ['Midnight Echo', "Midnight Echo's Song Tipper"],
    'trailing s apostrophe' => ['James', "James' Song Tipper"],
]);

it('renders compact song rows with title, artist, genre, era, and request action', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
    ]);

    $song = Song::factory()->create([
        'title' => 'Valerie',
        'artist' => 'Amy Winehouse',
        'era' => '1990s',
        'genre' => 'Pop',
    ]);

    $project->projectSongs()->create([
        'song_id' => $song->id,
        'genre' => 'Soul',
    ]);

    $this->get(route('project.page', ['projectSlug' => $project->slug]))
        ->assertSuccessful()
        ->assertSee('Title', false)
        ->assertSee('Artist', false)
        ->assertSee('Genre', false)
        ->assertSee('Era', false)
        ->assertSee('All Eras', false)
        ->assertSee('All Genres', false)
        ->assertSee('All Themes', false)
        ->assertSee('Surprise Me', false)
        ->assertSee('Request', false)
        ->assertSee('Valerie')
        ->assertSee('Amy Winehouse')
        ->assertSee('Soul')
        ->assertSee('90s')
        ->assertDontSee('1990s')
        ->assertSee(route('request.page', [
            'projectSlug' => $project->slug,
            'song' => $song->id,
        ]), false);
});

it('renders pinned action rows before the repertoire on the default page', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_tips' => true,
        'is_accepting_original_requests' => true,
    ]);

    $song = Song::factory()->create([
        'title' => 'Valerie',
        'artist' => 'Amy Winehouse',
    ]);

    $project->projectSongs()->create([
        'song_id' => $song->id,
    ]);

    $this->get(route('project.page', ['projectSlug' => $project->slug]))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Only Tip',
            'Artist Original',
            'Valerie',
        ], false)
        ->assertSee('Support the performer without adding a song to the queue.')
        ->assertSee('Hear one the artist wrote.')
        ->assertSee(route('request.page', [
            'projectSlug' => $project->slug,
            'song' => Song::tipJarSupportSong()->id,
        ]), false)
        ->assertSee(route('request.page', [
            'projectSlug' => $project->slug,
            'song' => Song::originalRequestSong()->id,
        ]), false);
});

it('mounts the project page Livewire component with a single rendered root', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
    ]);

    $song = Song::factory()->create([
        'title' => 'Vienna',
        'artist' => 'Billy Joel',
        'era' => '1970s',
        'genre' => 'Pop',
    ]);

    $project->projectSongs()->create([
        'song_id' => $song->id,
    ]);

    Livewire::test('project-page', ['projectSlug' => $project->slug])
        ->assertSee('Vienna')
        ->assertSee('Billy Joel')
        ->assertSee('Request');
});

it('shows the queue explainer only when requests and tips are both enabled', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $projectWithTips = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_tips' => true,
    ]);
    $projectWithoutTips = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_tips' => false,
    ]);

    $this->get(route('project.page', ['projectSlug' => $projectWithTips->slug]))
        ->assertSuccessful()
        ->assertSee('Requests and tips go directly to the performer and show up on their device. Higher tips move songs up the queue.');

    $this->get(route('project.page', ['projectSlug' => $projectWithoutTips->slug]))
        ->assertSuccessful()
        ->assertDontSee('Requests and tips go directly to the performer and show up on their device. Higher tips move songs up the queue.');
});

it('applies title artist era genre and theme filters with genre sorting without errors', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
    ]);

    $matchingSong = Song::factory()->create([
        'title' => 'Wonderwall',
        'artist' => 'Oasis',
        'era' => '1990s',
        'genre' => 'Rock',
        'theme' => SongTheme::Love->value,
    ]);
    $otherSong = Song::factory()->create([
        'title' => 'Piano Man',
        'artist' => 'Billy Joel',
        'era' => '70s',
        'genre' => 'Pop',
        'theme' => SongTheme::Party->value,
    ]);

    $project->projectSongs()->create([
        'song_id' => $matchingSong->id,
        'genre' => 'Rock',
    ]);
    $project->projectSongs()->create([
        'song_id' => $otherSong->id,
    ]);

    $this->get(route('project.page', [
        'projectSlug' => $project->slug,
        'title' => 'Wonder',
        'artist' => 'Oasis',
        'era' => '90s',
        'genre' => 'Rock',
        'theme' => SongTheme::Love->value,
        'sortField' => 'genre',
        'sortDirection' => 'asc',
    ]))
        ->assertSuccessful()
        ->assertSee('Wonderwall')
        ->assertDontSee('Piano Man');
});

it('renders surprise me above the repertoire filter controls', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
    ]);

    $song = Song::factory()->create([
        'theme' => SongTheme::Love->value,
    ]);

    $project->projectSongs()->create([
        'song_id' => $song->id,
    ]);

    $this->get(route('project.page', ['projectSlug' => $project->slug]))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'wire:click="surpriseMe"',
            'placeholder="Search Title"',
            'placeholder="Search Artist"',
            '<option value="">All Eras</option>',
            '<option value="">All Genres</option>',
            '<option value="">All Themes</option>',
        ], false);
});

it('hides the christmas theme filter outside december', function () {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 3, 7), function (): void {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'owner_user_id' => $owner->id,
            'is_accepting_requests' => true,
            'is_accepting_original_requests' => false,
        ]);

        $project->projectSongs()->create([
            'song_id' => Song::factory()->create([
                'theme' => SongTheme::Christmas->value,
            ])->id,
        ]);
        $project->projectSongs()->create([
            'song_id' => Song::factory()->create([
                'theme' => SongTheme::Love->value,
            ])->id,
        ]);

        $this->get(route('project.page', ['projectSlug' => $project->slug]))
            ->assertSuccessful()
            ->assertSee('<option value="love">Love</option>', false)
            ->assertDontSee('<option value="christmas">Christmas</option>', false);
    });
});

it('shows the christmas theme filter during december', function () {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 12, 7), function (): void {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'owner_user_id' => $owner->id,
            'is_accepting_requests' => true,
            'is_accepting_original_requests' => false,
        ]);

        $project->projectSongs()->create([
            'song_id' => Song::factory()->create([
                'theme' => SongTheme::Christmas->value,
            ])->id,
        ]);

        $this->get(route('project.page', ['projectSlug' => $project->slug]))
            ->assertSuccessful()
            ->assertSee('<option value="christmas">Christmas</option>', false);
    });
});

it('shows only christmas themed repertoire on the project page during december', function () {
    $this->withoutVite();

    Carbon::setTestNow('2026-12-15 12:00:00');

    try {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'owner_user_id' => $owner->id,
            'is_accepting_requests' => true,
            'is_accepting_original_requests' => false,
        ]);

        $visibleSong = Song::factory()->create([
            'title' => 'Winter Song',
            'artist' => 'Seasonal Duo',
            'theme' => SongTheme::Party->value,
        ]);
        $hiddenSong = Song::factory()->create([
            'title' => 'Everyday Anthem',
            'artist' => 'Regular Band',
            'theme' => SongTheme::Christmas->value,
        ]);

        $project->projectSongs()->create([
            'song_id' => $visibleSong->id,
            'theme' => SongTheme::Christmas->value,
        ]);
        $project->projectSongs()->create([
            'song_id' => $hiddenSong->id,
            'theme' => SongTheme::Party->value,
        ]);

        $this->get(route('project.page', ['projectSlug' => $project->slug]))
            ->assertSuccessful()
            ->assertSee('Winter Song')
            ->assertDontSee('Everyday Anthem');
    } finally {
        Carbon::setTestNow();
    }
});

it('sorts era options in reverse chronological order with canonical eras pre-filled', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
    ]);

    foreach (['2020s', '1990s', '60s'] as $era) {
        $song = Song::factory()->create([
            'title' => "Song {$era}",
            'artist' => "Artist {$era}",
            'era' => $era,
        ]);

        $project->projectSongs()->create([
            'song_id' => $song->id,
        ]);
    }

    $this->get(route('project.page', ['projectSlug' => $project->slug]))
        ->assertSuccessful()
        ->assertSeeInOrder([
            '<option value="">All Eras</option>',
            '<option value="2020s">2020s</option>',
            '<option value="2010s">2010s</option>',
            '<option value="2000s">2000s</option>',
            '<option value="90s">90s</option>',
            '<option value="80s">80s</option>',
            '<option value="70s">70s</option>',
            '<option value="60s">60s</option>',
            '<option value="50s">50s</option>',
        ], false)
        ->assertDontSee('<option value="1990s">1990s</option>', false);
});

it('includes non-canonical era values from data alongside the pre-filled list', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
    ]);

    // 30s and 40s are not in the canonical Era enum but may appear in
    // legacy or AI-imported data. They should still be filterable, sorted
    // alongside the pre-filled canonical eras as 1930s/1940s.
    foreach (['30s', '40s'] as $era) {
        $song = Song::factory()->create([
            'title' => "Song {$era}",
            'artist' => "Artist {$era}",
            'era' => $era,
        ]);

        $project->projectSongs()->create([
            'song_id' => $song->id,
        ]);
    }

    $this->get(route('project.page', ['projectSlug' => $project->slug]))
        ->assertSuccessful()
        ->assertSeeInOrder([
            '<option value="">All Eras</option>',
            '<option value="2020s">2020s</option>',
            '<option value="50s">50s</option>',
            '<option value="40s">40s</option>',
            '<option value="30s">30s</option>',
        ], false);
});

it('clears theme and surprise me selection when filters are cleared', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
    ]);

    $song = Song::factory()->create();
    $project->projectSongs()->create([
        'song_id' => $song->id,
    ]);

    Livewire::test('project-page', ['projectSlug' => $project->slug])
        ->set('theme', SongTheme::Love->value)
        ->set('surpriseSongId', $song->id)
        ->call('clearFilters')
        ->assertSet('theme', '')
        ->assertSet('sortField', 'request_count')
        ->assertSet('sortDirection', 'desc')
        ->assertSet('surpriseSongId', null);
});

it('defaults to most requested sorting on initial load', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_tips' => true,
        'is_accepting_original_requests' => true,
    ]);

    $song = Song::factory()->create();
    $project->projectSongs()->create([
        'song_id' => $song->id,
    ]);

    Livewire::test('project-page', ['projectSlug' => $project->slug])
        ->assertSet('sortField', 'request_count')
        ->assertSet('sortDirection', 'desc')
        ->assertSet('showPinnedActionRows', true);
});

it('keeps pinned action rows visible when surprise me is active', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_tips' => true,
        'is_accepting_original_requests' => true,
    ]);

    $surpriseSong = Song::factory()->create([
        'title' => 'Surprise Song',
        'artist' => 'Lucky Artist',
    ]);
    $otherSong = Song::factory()->create([
        'title' => 'Other Song',
        'artist' => 'Other Artist',
    ]);

    $project->projectSongs()->create([
        'song_id' => $surpriseSong->id,
    ]);
    $project->projectSongs()->create([
        'song_id' => $otherSong->id,
    ]);

    Livewire::test('project-page', ['projectSlug' => $project->slug])
        ->set('surpriseSongId', $surpriseSong->id)
        ->assertSee('Only Tip')
        ->assertSee('Artist Original')
        ->assertSee('Surprise Song')
        ->assertDontSee('Other Song');
});

it('hides pinned action rows when search or sort controls are active', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_tips' => true,
        'is_accepting_original_requests' => true,
    ]);

    $song = Song::factory()->create([
        'title' => 'Valerie',
        'artist' => 'Amy Winehouse',
    ]);

    $project->projectSongs()->create([
        'song_id' => $song->id,
    ]);

    $component = Livewire::test('project-page', ['projectSlug' => $project->slug])
        ->set('title', 'Valerie')
        ->assertSet('showPinnedActionRows', false);

    expect($component->get('pinnedActionRows'))->toHaveCount(0);

    $component
        ->set('title', '')
        ->set('sortField', 'artist')
        ->assertSet('showPinnedActionRows', false);

    expect($component->get('pinnedActionRows'))->toHaveCount(0);
});

it('shows pinned action rows in the repertoire without the old quick-actions strip', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
        'performer_info_url' => 'https://example.com/performer',
    ]);

    $response = $this->get(route('project.page', ['projectSlug' => $project->slug]));
    $response->assertSuccessful()
        ->assertSee('Learn More About the Performer', false)
        ->assertSee('Only Tip', false)
        ->assertDontSee('Quick Actions', false)
        ->assertDontSee('Artist Original', false)
        ->assertSee('Requests and tips go directly to the performer and show up on their device. Higher tips move songs up the queue.')
        ->assertDontSee('Top tipped', false)
        ->assertDontSee('Your Profile', false)
        ->assertDontSee('My Achievements', false)
        ->assertDontSee('Who\'s Here Leaderboard', false);

    $this->assertDatabaseEmpty('audience_profiles');
});

it('hides the tip-only pinned action row when tips are disabled', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_tips' => false,
        'is_accepting_original_requests' => true,
    ]);

    $this->get(route('project.page', ['projectSlug' => $project->slug]))
        ->assertSuccessful()
        ->assertDontSee('Tip Only', false)
        ->assertSee('Artist Original', false);
});

it('shows current audience requests in queue order with a one-minute poll section', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
    ]);

    $profile = AudienceProfile::factory()->create([
        'project_id' => $project->id,
        'visitor_token' => 'aud-test-current-requests',
    ]);

    $secondQueueSong = Song::factory()->create([
        'title' => 'Second Queue Song',
        'artist' => 'Queue Artist Two',
    ]);
    $fourthQueueSong = Song::factory()->create([
        'title' => 'Fourth Queue Song',
        'artist' => 'Queue Artist Four',
    ]);

    SongRequest::factory()->active()->create([
        'project_id' => $project->id,
        'song_id' => Song::factory()->create()->id,
        'tip_amount_cents' => 5000,
        'score_cents' => 5000,
        'created_at' => now()->subMinutes(4),
    ]);
    SongRequest::factory()->active()->create([
        'project_id' => $project->id,
        'audience_profile_id' => $profile->id,
        'song_id' => $secondQueueSong->id,
        'tip_amount_cents' => 3000,
        'score_cents' => 3000,
        'created_at' => now()->subMinutes(3),
    ]);
    SongRequest::factory()->active()->create([
        'project_id' => $project->id,
        'song_id' => Song::factory()->create()->id,
        'tip_amount_cents' => 2000,
        'score_cents' => 2000,
        'created_at' => now()->subMinutes(2),
    ]);
    SongRequest::factory()->active()->create([
        'project_id' => $project->id,
        'audience_profile_id' => $profile->id,
        'song_id' => $fourthQueueSong->id,
        'tip_amount_cents' => 1000,
        'score_cents' => 1000,
        'created_at' => now()->subMinute(),
    ]);

    $this->withCookie('songtipper_audience_token', 'aud-test-current-requests')
        ->get(route('project.page', ['projectSlug' => $project->slug]))
        ->assertSuccessful()
        ->assertSee('Your Requests')
        ->assertSee('wire:poll.60s.visible', false)
        ->assertSeeInOrder([
            'Your Requests',
            'Second Queue Song',
            '#2',
            'Fourth Queue Song',
            '#4',
        ], false)
        ->assertDontSee("Request submitted. You're currently #2 in the queue.");
});

it('shows a one-time success panel with the exact queue position above current requests', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
    ]);
    $song = Song::factory()->create([
        'title' => 'Fresh Request Song',
    ]);

    $songRequest = SongRequest::factory()->active()->create([
        'project_id' => $project->id,
        'song_id' => $song->id,
        'tip_amount_cents' => 2000,
        'score_cents' => 2000,
    ]);

    $this->withSession([
        'request_success' => [
            'message' => "Request submitted. You're currently #1 in the queue.",
            'queue_position' => 1,
            'request_id' => $songRequest->id,
        ],
    ])->get(route('project.page', ['projectSlug' => $project->slug]))
        ->assertSuccessful()
        ->assertSeeInOrder([
            "Request submitted. You're currently #1 in the queue.",
            'Your Requests',
            'Queue position',
            '#1',
        ], false);
});

it('ignores legacy audience achievements on the project page', function () {
    $this->withoutVite();

    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
    ]);

    $profile = AudienceProfile::factory()->create([
        'project_id' => $project->id,
        'visitor_token' => 'aud-test-project-page-achievement',
    ]);

    DB::table('audience_achievements')->insert([
        'audience_profile_id' => $profile->id,
        'project_id' => $project->id,
        'request_id' => null,
        'code' => 'lorekeeper',
        'title' => 'Lorekeeper',
        'description' => 'You learned more about the performer.',
        'earned_at' => now(),
        'notified_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withCookie(
        'songtipper_audience_token',
        'aud-test-project-page-achievement',
    )
        ->get(route('project.page', ['projectSlug' => $project->slug]))
        ->assertSuccessful()
        ->assertDontSee('Achievement Unlocked')
        ->assertDontSee('Lorekeeper');
});

it('shows a free request banner when the audience has earned one', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_tips' => true,
        'free_request_threshold_cents' => 4000,
    ]);

    RewardThreshold::factory()->create([
        'project_id' => $project->id,
        'threshold_cents' => 4000,
        'reward_type' => 'free_request',
        'reward_label' => 'Free Song Request',
        'is_repeating' => true,
    ]);

    AudienceProfile::factory()->create([
        'project_id' => $project->id,
        'visitor_token' => 'aud-free-request-banner',
        'cumulative_tip_cents' => 5000,
    ]);

    Livewire::withCookies(['songtipper_audience_token' => 'aud-free-request-banner'])
        ->test('project-page', ['projectSlug' => $project->slug])
        ->assertSee("You've earned a free request!", false)
        ->assertSee('Pick any song below and submit it without a tip.', false);
});

it('hides the free request banner when the audience has not earned one', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_tips' => true,
        'free_request_threshold_cents' => 4000,
    ]);

    AudienceProfile::factory()->create([
        'project_id' => $project->id,
        'visitor_token' => 'aud-no-free-request',
        'cumulative_tip_cents' => 2000,
    ]);

    Livewire::withCookies(['songtipper_audience_token' => 'aud-no-free-request'])
        ->test('project-page', ['projectSlug' => $project->slug])
        ->assertDontSee("You've earned a free request!", false);
});

it('sorts by genre without erroring when the project uses a public repertoire set', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
    ]);

    $setlist = Setlist::factory()->create(['project_id' => $project->id]);
    $set = SetlistSet::factory()->create(['setlist_id' => $setlist->id, 'order_index' => 0]);
    $project->forceFill(['public_repertoire_set_id' => $set->id])->save();

    $rockSong = Song::factory()->create([
        'title' => 'Thunderstruck',
        'artist' => 'AC/DC',
        'genre' => 'Rock',
    ]);
    $jazzSong = Song::factory()->create([
        'title' => 'Take Five',
        'artist' => 'Dave Brubeck',
        'genre' => 'Jazz',
    ]);

    $rockProjectSong = ProjectSong::factory()->create([
        'project_id' => $project->id,
        'song_id' => $rockSong->id,
    ]);
    $jazzProjectSong = ProjectSong::factory()->create([
        'project_id' => $project->id,
        'song_id' => $jazzSong->id,
    ]);

    SetlistSong::factory()->create([
        'set_id' => $set->id,
        'project_song_id' => $rockProjectSong->id,
        'order_index' => 0,
    ]);
    SetlistSong::factory()->create([
        'set_id' => $set->id,
        'project_song_id' => $jazzProjectSong->id,
        'order_index' => 1,
    ]);

    Livewire::test('project-page', ['projectSlug' => $project->slug])
        ->set('sortField', 'genre')
        ->set('sortDirection', 'asc')
        ->assertSuccessful()
        ->assertSeeInOrder(['Take Five', 'Thunderstruck'])
        ->set('sortDirection', 'desc')
        ->assertSeeInOrder(['Thunderstruck', 'Take Five']);
});

it('sorts by era without erroring when the project uses a public repertoire set', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
    ]);

    $setlist = Setlist::factory()->create(['project_id' => $project->id]);
    $set = SetlistSet::factory()->create(['setlist_id' => $setlist->id, 'order_index' => 0]);
    $project->forceFill(['public_repertoire_set_id' => $set->id])->save();

    $seventiesSong = Song::factory()->create([
        'title' => 'Hotel California',
        'artist' => 'Eagles',
        'era' => '1970s',
    ]);
    $ninetiesSong = Song::factory()->create([
        'title' => 'Wonderwall',
        'artist' => 'Oasis',
        'era' => '1990s',
    ]);

    $seventiesProjectSong = ProjectSong::factory()->create([
        'project_id' => $project->id,
        'song_id' => $seventiesSong->id,
    ]);
    $ninetiesProjectSong = ProjectSong::factory()->create([
        'project_id' => $project->id,
        'song_id' => $ninetiesSong->id,
    ]);

    SetlistSong::factory()->create([
        'set_id' => $set->id,
        'project_song_id' => $seventiesProjectSong->id,
        'order_index' => 0,
    ]);
    SetlistSong::factory()->create([
        'set_id' => $set->id,
        'project_song_id' => $ninetiesProjectSong->id,
        'order_index' => 1,
    ]);

    Livewire::test('project-page', ['projectSlug' => $project->slug])
        ->set('sortField', 'era')
        ->set('sortDirection', 'asc')
        ->assertSuccessful()
        ->assertSeeInOrder(['Hotel California', 'Wonderwall'])
        ->set('sortDirection', 'desc')
        ->assertSeeInOrder(['Wonderwall', 'Hotel California']);
});

it('sorts by era chronologically when 2-digit and 4-digit decades are mixed', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_user_id' => $owner->id,
        'is_accepting_requests' => true,
        'is_accepting_original_requests' => false,
    ]);

    // '80s' (two-digit, means 1980s) must sort BEFORE '2020s' (four-digit),
    // even though alphabetically '2' < '8'.
    $eightiesSong = Song::factory()->create(['title' => 'Africa', 'artist' => 'Toto', 'era' => '80s']);
    $ninetiesSong = Song::factory()->create(['title' => 'Wonderwall', 'artist' => 'Oasis', 'era' => '90s']);
    $twentyTwentiesSong = Song::factory()->create(['title' => 'Flowers', 'artist' => 'Miley Cyrus', 'era' => '2020s']);
    $seventiesSong = Song::factory()->create(['title' => 'Hotel California', 'artist' => 'Eagles', 'era' => '1970s']);

    foreach ([$eightiesSong, $ninetiesSong, $twentyTwentiesSong, $seventiesSong] as $song) {
        ProjectSong::factory()->create(['project_id' => $project->id, 'song_id' => $song->id]);
    }

    Livewire::test('project-page', ['projectSlug' => $project->slug])
        ->set('sortField', 'era')
        ->set('sortDirection', 'asc')
        ->assertSeeInOrder(['Hotel California', 'Africa', 'Wonderwall', 'Flowers'])
        ->set('sortDirection', 'desc')
        ->assertSeeInOrder(['Flowers', 'Wonderwall', 'Africa', 'Hotel California']);
});

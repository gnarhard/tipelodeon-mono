<?php

use App\Enums\Era;
use App\Enums\RequestStatus;
use App\Enums\SongTheme;
use App\Models\Project;
use App\Models\RewardThreshold;
use App\Services\AudienceIdentityService;
use App\Services\RewardThresholdService;
use App\Models\Request as SongRequest;
use App\Models\Song;
use Illuminate\Database\Query\JoinClause;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public Project $project;

    public string $mode = 'request';

    public bool $embed = false;

    #[Url]
    public string $title = '';

    #[Url]
    public string $artist = '';

    #[Url]
    public string $era = '';

    #[Url]
    public string $genre = '';

    #[Url]
    public string $theme = '';

    #[Url]
    public string $sortField = 'request_count';

    #[Url]
    public string $sortDirection = 'desc';

    public ?int $surpriseSongId = null;

    public string $audienceVisitorToken = '';

    #[Computed]
    public function isRepertoireMode(): bool
    {
        return $this->mode === 'repertoire';
    }

    public function mount(string $projectSlug, string $mode = 'request', bool $embed = false): void
    {
        $this->mode = $mode;
        $this->embed = $embed;

        $this->project = Project::query()
            ->where('slug', $projectSlug)
            ->with(['owner', 'publicRepertoireSet.setlist'])
            ->firstOrFail();

        if ($this->isRepertoireMode && $this->sortField === 'request_count') {
            $this->sortField = 'title';
            $this->sortDirection = 'asc';
        }

        $this->era = $this->formatEraForDisplay($this->era) ?? '';
        $this->theme = SongTheme::normalize($this->theme) ?? '';

        if (!$this->shouldShowChristmasTheme() && $this->theme === SongTheme::Christmas->value) {
            $this->theme = '';
        }

        $this->audienceVisitorToken = app(AudienceIdentityService::class)->ensureVisitorToken(request());
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['title', 'artist', 'era', 'genre', 'theme', 'sortField', 'sortDirection'], true)) {
            $this->resetPage();
        }
    }

    public function updatedSortField(string $value): void
    {
        if ($value === 'request_count' && $this->sortDirection === 'asc') {
            $this->sortDirection = 'desc';
        }
    }

    #[Computed]
    public function repertoire()
    {
        $query = $this->filteredRepertoireQuery();

        $allowedSortFields = $this->isRepertoireMode
            ? ['title', 'artist', 'era', 'genre']
            : ['title', 'artist', 'era', 'genre', 'request_count'];
        $defaultSort = $this->isRepertoireMode ? 'title' : 'request_count';

        $sortField = in_array($this->sortField, $allowedSortFields, true) ? $this->sortField : $defaultSort;
        $sortDirection = in_array($this->sortDirection, ['asc', 'desc'], true) ? $this->sortDirection : 'desc';

        if (in_array($sortField, ['title', 'artist'], true)) {
            $query->orderBy("project_songs.{$sortField}", $sortDirection);
        } elseif ($sortField === 'era') {
            $query->orderBy('sort_era', $sortDirection)->orderBy('project_songs.title');
        } elseif ($sortField === 'genre') {
            $query->orderBy('sort_genre', $sortDirection)->orderBy('project_songs.title');
        } elseif ($sortField === 'request_count') {
            $query->orderBy('request_count', $sortDirection)->orderBy('project_songs.title');
        }

        return $query->paginate(50);
    }

    #[Computed]
    public function eras(): array
    {
        $rawEras = $this->seasonalRepertoireQuery()->whereNotNull('songs.era')->distinct()->pluck('songs.era')->values()->toArray();

        $deduplicatedByLabel = [];

        // Pre-fill with canonical era values so the dropdown is consistent
        // even when no song in the current repertoire uses a given decade.
        foreach (Era::labels() as $canonicalEra) {
            $deduplicatedByLabel[strtolower($canonicalEra)] = $canonicalEra;
        }

        foreach ($rawEras as $rawEra) {
            $formattedEra = $this->formatEraForDisplay($rawEra);
            if ($formattedEra === null || $formattedEra === '') {
                continue;
            }

            $deduplicatedByLabel[strtolower($formattedEra)] = $formattedEra;
        }

        $eras = array_values($deduplicatedByLabel);

        usort($eras, function (string $left, string $right): int {
            $leftWeight = $this->eraSortWeight($left);
            $rightWeight = $this->eraSortWeight($right);

            if ($leftWeight === $rightWeight) {
                return strcasecmp($left, $right);
            }

            // Reverse chronological: newest decade first.
            return $rightWeight <=> $leftWeight;
        });

        return array_values($eras);
    }

    #[Computed]
    public function genres(): array
    {
        return $this->seasonalRepertoireQuery()->whereRaw('coalesce(project_songs.genre, songs.genre) is not null')->selectRaw('coalesce(project_songs.genre, songs.genre) as resolved_genre')->distinct()->orderByRaw('coalesce(project_songs.genre, songs.genre)')->pluck('resolved_genre')->values()->toArray();
    }

    #[Computed]
    public function themes(): array
    {
        $rawThemes = $this->seasonalRepertoireQuery()->whereRaw('coalesce(project_songs.theme, songs.theme) is not null')->selectRaw('coalesce(project_songs.theme, songs.theme) as resolved_theme')->distinct()->pluck('resolved_theme')->all();

        $themesByValue = [];

        foreach ($rawThemes as $rawTheme) {
            $theme = SongTheme::fromInput($rawTheme);

            if ($theme === null || (!$this->shouldShowChristmasTheme() && $theme === SongTheme::Christmas)) {
                continue;
            }

            $themesByValue[$theme->value] = $theme->label();
        }

        uasort($themesByValue, static fn(string $left, string $right): int => strcasecmp($left, $right));

        return $themesByValue;
    }

    #[Computed]
    public function originalRequestSongId(): int
    {
        return Song::originalRequestSong()->id;
    }

    #[Computed]
    public function tipJarSupportSongId(): int
    {
        return Song::tipJarSupportSong()->id;
    }

    #[Computed]
    public function showPinnedActionRows(): bool
    {
        if ($this->isRepertoireMode) {
            return false;
        }

        return $this->project->is_accepting_requests
            && $this->title === ''
            && $this->artist === ''
            && $this->era === ''
            && $this->genre === ''
            && $this->theme === ''
            && $this->sortField === 'request_count'
            && $this->sortDirection === 'desc';
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     title: string,
     *     description: string,
     *     artist: string,
     *     genre: string,
     *     era: string,
     *     action_label: string,
     *     href: string,
     *     row_classes: string,
     *     button_classes: string
     * }>
     */
    #[Computed]
    public function pinnedActionRows(): array
    {
        if (! $this->showPinnedActionRows) {
            return [];
        }

        $rows = [];

        if ($this->project->is_accepting_tips) {
            $rows[] = [
                'key' => 'tip-only',
                'title' => 'Only Tip',
                'description' => 'Support the performer without adding a song to the queue.',
                'artist' => '',
                'genre' => '',
                'era' => '',
                'action_label' => 'Tip',
                'href' => route('request.page', ['projectSlug' => $this->project->slug, 'song' => $this->tipJarSupportSongId]),
                'row_classes' => 'bg-accent-400/90 dark:bg-accent-900/20',
                'button_classes' => 'bg-accent-100 text-accent-800 hover:bg-brand hover:text-ink focus:ring-accent-200 dark:bg-accent-800/80 dark:text-accent-50 dark:hover:bg-brand dark:hover:text-ink',
            ];
        }

        if ($this->project->is_accepting_original_requests) {
            $rows[] = [
                'key' => 'request-original',
                'title' => 'Artist Original',
                'description' => 'Hear one the artist wrote.',
                'artist' => '',
                'genre' => '',
                'era' => '',
                'action_label' => 'Request',
                'href' => route('request.page', ['projectSlug' => $this->project->slug, 'song' => $this->originalRequestSongId]),
                'row_classes' => 'bg-accent-400/90 dark:bg-accent-900/20',
                'button_classes' => 'bg-accent-100 text-accent-800 hover:bg-brand hover:text-ink focus:ring-accent-200 dark:bg-accent-800/80 dark:text-accent-50 dark:hover:bg-brand dark:hover:text-ink',
            ];
        }

        return $rows;
    }

    #[Computed]
    public function queueRulesExplainer(): string
    {
        return 'Requests and tips go directly to the performer and show up on their device. Higher tips move songs up the queue.';
    }

    #[Computed]
    public function hasEarnedFreeRequest(): bool
    {
        $profile = app(AudienceIdentityService::class)->findProfile($this->project, $this->audienceVisitorToken);

        if ($profile === null) {
            return false;
        }

        $this->project->loadMissing('rewardThresholds');
        $service = app(RewardThresholdService::class);

        return $this->project->rewardThresholds
            ->filter(fn (RewardThreshold $t) => $t->reward_type === RewardThreshold::TYPE_FREE_REQUEST)
            ->contains(fn (RewardThreshold $t) => $service->hasClaimableReward($profile, $t));
    }

    #[Computed]
    public function earnedNonClaimableRewards(): array
    {
        $profile = app(AudienceIdentityService::class)->findProfile($this->project, $this->audienceVisitorToken);

        if ($profile === null) {
            return [];
        }

        $this->project->loadMissing('rewardThresholds');
        $service = app(RewardThresholdService::class);

        return $this->project->rewardThresholds
            ->filter(fn (RewardThreshold $t) => $t->reward_type !== RewardThreshold::TYPE_FREE_REQUEST)
            ->filter(fn (RewardThreshold $t) => $service->hasClaimableReward($profile, $t))
            ->map(fn (RewardThreshold $t) => $t->reward_label)
            ->values()
            ->all();
    }

    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->title !== '' || $this->artist !== '' || $this->era !== '' || $this->genre !== '' || $this->theme !== '' || $this->sortField !== 'request_count' || $this->sortDirection !== 'desc' || $this->surpriseSongId !== null;
    }

    public function clearFilters(): void
    {
        $this->title = '';
        $this->artist = '';
        $this->era = '';
        $this->genre = '';
        $this->theme = '';
        $this->sortField = 'request_count';
        $this->sortDirection = 'desc';
        $this->surpriseSongId = null;
        $this->resetPage();
    }

    public function surpriseMe(): void
    {
        if ($this->isRepertoireMode) {
            return;
        }

        $songId = $this->filteredRepertoireQuery(false)->inRandomOrder()->value('project_songs.song_id');

        $this->surpriseSongId = $songId ? (int) $songId : null;
        $this->resetPage();
    }

    private function filteredRepertoireQuery(bool $applySurprise = true)
    {
        $query = $this->baseRepertoireQuery()->with(['song', 'project']);

        if ($this->title !== '') {
            $query->where('project_songs.title', 'like', '%' . $this->title . '%');
        }

        if ($this->artist !== '') {
            $query->where('project_songs.artist', 'like', '%' . $this->artist . '%');
        }

        if ($this->era !== '') {
            $query->whereIn('songs.era', $this->eraFilterCandidates($this->era));
        }

        if ($this->genre !== '') {
            $query->whereRaw('coalesce(project_songs.genre, songs.genre) = ?', [$this->genre]);
        }

        if ($this->theme !== '') {
            $query->whereRaw('coalesce(project_songs.theme, songs.theme) = ?', [$this->theme]);
        }

        if ($applySurprise && $this->surpriseSongId !== null) {
            $query->where('project_songs.song_id', $this->surpriseSongId);
        }

        return $query;
    }

    private function baseRepertoireQuery()
    {
        return $this->seasonalRepertoireQuery()
            ->leftJoinSub($this->songRequestCountSubquery(), 'song_request_counts', function (JoinClause $join): void {
                $join->on('song_request_counts.song_id', '=', 'project_songs.song_id');
            })
            ->select('project_songs.*')
            ->selectRaw('coalesce(song_request_counts.request_count, 0) as request_count')
            ->selectRaw(
                "case
                    when songs.era is null then -1
                    when length(songs.era) = 5 and songs.era like '%s' then (substr(songs.era, 1, 4) + 0)
                    when length(songs.era) = 3 and songs.era like '%s' then 1900 + (substr(songs.era, 1, 2) + 0)
                    else -1
                end as sort_era"
            )
            ->selectRaw('coalesce(project_songs.genre, songs.genre) as sort_genre');
    }

    private function seasonalRepertoireQuery()
    {
        if ($this->project->public_repertoire_set_id !== null) {
            $setId = $this->project->public_repertoire_set_id;
            $query = $this->project->projectSongs()
                ->join('setlist_songs', function (JoinClause $join) use ($setId): void {
                    $join->on('setlist_songs.project_song_id', '=', 'project_songs.id')
                        ->where('setlist_songs.set_id', $setId);
                })
                ->whereNotNull('setlist_songs.project_song_id')
                ->join('songs', 'project_songs.song_id', '=', 'songs.id')
                ->distinct();
        } else {
            $query = $this->project->projectSongs()
                ->where('project_songs.is_public', true)
                ->join('songs', 'project_songs.song_id', '=', 'songs.id');
        }

        if ($this->shouldShowChristmasSongsOnly()) {
            $query->whereRaw('coalesce(project_songs.theme, songs.theme) = ?', [SongTheme::Christmas->value]);
        }

        return $query;
    }

    private function shouldShowChristmasSongsOnly(): bool
    {
        return now()->month === 12;
    }

    private function shouldShowChristmasTheme(): bool
    {
        return $this->shouldShowChristmasSongsOnly();
    }

    private function songRequestCountSubquery()
    {
        return SongRequest::query()->selectRaw('song_id, count(*) as request_count')->where('project_id', $this->project->id)->groupBy('song_id');
    }

    private function eraSortWeight(string $era): int
    {
        $era = $this->formatEraForDisplay($era) ?? $era;

        // Two-digit decade labels (30s..90s) are 20th-century music decades.
        // Modern decades use the explicit four-digit form (2000s, 2010s, ...).
        if (preg_match('/^(\d{2})s$/', $era, $matches) === 1) {
            return 1900 + (int) $matches[1];
        }

        if (preg_match('/^(\d{4})s$/', $era, $matches) === 1) {
            return (int) $matches[1];
        }

        return -1;
    }

    public function formatEraForDisplay(?string $era): ?string
    {
        if ($era === null) {
            return null;
        }

        $trimmed = trim($era);
        if ($trimmed === '') {
            return null;
        }

        $normalized = str_replace(['\'', '’'], '', strtolower($trimmed));

        if (preg_match('/^(\d{4})s$/', $normalized, $matches) === 1) {
            $decade = (int) $matches[1];
            if ($decade % 10 !== 0) {
                return $trimmed;
            }

            if ($decade < 2000) {
                return substr((string) $decade, 2, 2) . 's';
            }

            return (string) $decade . 's';
        }

        if (preg_match('/^(\d{2})s$/', $normalized, $matches) === 1) {
            return $matches[1] . 's';
        }

        return $trimmed;
    }

    /**
     * @return array<int, string>
     */
    private function eraFilterCandidates(string $era): array
    {
        $trimmed = trim($era);
        if ($trimmed === '') {
            return [];
        }

        $normalized = str_replace(['\'', '’'], '', strtolower($trimmed));
        $candidates = [$trimmed];
        $formattedEra = $this->formatEraForDisplay($trimmed);
        if ($formattedEra !== null) {
            $candidates[] = $formattedEra;
        }

        if (preg_match('/^(\d{2})s$/', $normalized, $matches) === 1) {
            $yearSuffix = (int) $matches[1];
            // Two-digit decades represent 20th-century music decades.
            $baseYear = 1900 + $yearSuffix;
            $candidates[] = $matches[1] . 's';
            $candidates[] = $baseYear . 's';
            $candidates[] = $baseYear . '\'s';
        }

        if (preg_match('/^(\d{4})s$/', $normalized, $matches) === 1) {
            $candidates[] = $matches[1] . 's';
            $candidates[] = $matches[1] . '\'s';
        }

        $uniqueCandidates = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $uniqueCandidates[strtolower($candidate)] = $candidate;
        }

        return array_values($uniqueCandidates);
    }
};
?>

@if (!$embed && $project->brand_color_hex)
@php
    $brandHex = $project->brand_color_hex;
    $brandR = hexdec(substr($brandHex, 1, 2));
    $brandG = hexdec(substr($brandHex, 3, 2));
    $brandB = hexdec(substr($brandHex, 5, 2));
@endphp
@push('head')
<style>
    .st-branded { --st-brand: {{ $brandHex }}; --st-brand-rgb: {{ $brandR }},{{ $brandG }},{{ $brandB }}; }
    .st-branded .bg-brand { background-color: var(--st-brand) !important; }
    .st-branded .hover\:bg-brand:hover { background-color: var(--st-brand) !important; filter: brightness(1.1); }
    .st-branded .hover\:bg-brand-100:hover { background-color: var(--st-brand) !important; filter: brightness(1.15); }
    .st-branded .bg-brand-600 { background-color: var(--st-brand) !important; }
    .st-branded .text-brand-600 { color: var(--st-brand) !important; }
    .st-branded .text-brand-300 { color: rgb(var(--st-brand-rgb) / 0.7) !important; }
    .st-branded .ring-brand-500\/30 { --tw-ring-color: rgb(var(--st-brand-rgb) / 0.3) !important; }
    .st-branded .ring-brand-300\/40 { --tw-ring-color: rgb(var(--st-brand-rgb) / 0.4) !important; }
    .st-branded .focus\:ring-brand-300:focus { --tw-ring-color: rgb(var(--st-brand-rgb) / 0.5) !important; }
    .st-branded .focus\:ring-brand-200:focus { --tw-ring-color: rgb(var(--st-brand-rgb) / 0.4) !important; }
    .st-branded .shadow-\[0_0_10px_rgba\(255\,179\,117\,0\.28\)\] { box-shadow: 0 0 10px rgb(var(--st-brand-rgb) / 0.28) !important; }
    .st-branded .from-accent-100 { --tw-gradient-from: rgb(var(--st-brand-rgb) / 0.15) !important; }
    .st-branded .dark\:from-surface-elevated:is(.dark *) { --tw-gradient-from: rgb(var(--st-brand-rgb) / 0.08) !important; }
    .st-branded .dark\:bg-brand-500\/10:is(.dark *) { background-color: rgb(var(--st-brand-rgb) / 0.1) !important; }
    .st-branded .bg-accent-400\/90 { background-color: rgb(var(--st-brand-rgb) / 0.15) !important; }
    .st-branded .dark\:bg-accent-900\/20:is(.dark *) { background-color: rgb(var(--st-brand-rgb) / 0.12) !important; }
    .st-branded .bg-accent-100 { background-color: rgb(var(--st-brand-rgb) / 0.15) !important; }
    .st-branded .text-accent-800 { color: var(--st-brand) !important; }
    .st-branded .hover\:bg-brand:hover { background-color: var(--st-brand) !important; }
    .st-branded .dark\:hover\:bg-brand:is(.dark *):hover { background-color: var(--st-brand) !important; }
    .st-branded .st-banner-header { background-color: rgb(var(--st-brand-rgb) / 0.05); }
    .st-branded .dark .st-banner-header, .st-branded .st-banner-header:is(.dark *) { background-color: rgb(var(--st-brand-rgb) / 0.1); }
</style>
@endpush
@endif

<div @class([
    'relative isolate min-h-screen overflow-hidden bg-canvas-light text-ink dark:bg-canvas-dark dark:text-ink-inverse' => !$embed,
    'st-branded' => !$embed && $project->brand_color_hex,
])>
    @if (!$embed)
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute inset-0 bg-gradient-to-b from-accent-100 via-canvas-light to-canvas-light dark:from-surface-elevated dark:via-canvas-dark dark:to-canvas-dark"></div>
        <div class="absolute -left-20 top-0 h-80 w-80 rounded-full blur-3xl dark:bg-brand-500/10"></div>
        <div class="absolute inset-0 bg-[repeating-linear-gradient(90deg,rgba(48,41,56,0.06)_0_1px,transparent_1px_28px)] dark:bg-[repeating-linear-gradient(90deg,rgba(220,236,244,0.03)_0_1px,transparent_1px_28px)]"></div>
        @if ($project->background_image_url)
            <div class="absolute inset-0 opacity-[0.08] dark:opacity-[0.04]">
                <img src="{{ $project->background_image_url }}" alt="" class="h-full w-full object-cover">
            </div>
        @endif
    </div>
    @endif

    @if (!$embed)
    @if ($project->header_banner_image_url)
    <header class="relative overflow-hidden">
        <img src="{{ $project->header_banner_image_url }}" alt="" class="absolute inset-0 h-full w-full object-cover">
        <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/30 to-black/10"></div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-6 pt-16 sm:pt-24">
            <div class="flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
                <div class="flex items-center gap-4">
                    @if ($project->performer_profile_image_url)
                        <img src="{{ $project->performer_profile_image_url }}" alt="{{ $project->owner->name }} profile image" class="h-20 w-20 rounded-full object-cover ring-2 ring-white/40 shadow-lg">
                    @else
                        <div class="flex h-20 w-20 items-center justify-center rounded-full bg-white/20 text-xl font-semibold text-white ring-2 ring-white/30 shadow-lg backdrop-blur-sm">
                            {{ strtoupper(substr($project->owner->name, 0, 1)) }}
                        </div>
                    @endif

                    <div>
                        <h1 class="text-3xl font-bold tracking-tight text-white drop-shadow-md">
                            {{ $project->name }}
                        </h1>
                        @if ($this->isRepertoireMode)
                            <p class="text-sm text-white/80">Repertoire</p>
                        @endif
                    </div>
                </div>

                @if (!$this->isRepertoireMode)
                <div class="flex flex-wrap items-center justify-end gap-2">
                    @if ($project->performer_info_url)
                        <a href="{{ route('project.learn-more', ['projectSlug' => $project->slug]) }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-xl border border-white/30 bg-white/15 px-4 py-2 text-sm font-medium text-white backdrop-blur-sm transition hover:bg-white/25">
                            Learn More About the Performer
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                        </a>
                    @endif
                </div>
                @endif
            </div>
        </div>
    </header>
    @else
    <header class="border-b border-subtle bg-ink-inverse dark:bg-ink/60 to-brand-50 dark:border-ink-border-dark">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-4">
                    @if ($project->performer_profile_image_url)
                        <img src="{{ $project->performer_profile_image_url }}" alt="{{ $project->owner->name }} profile image" class="h-20 w-20 rounded-full object-cover ring-2 ring-brand-500/30 dark:ring-brand-300/40">
                    @else
                        <div class="flex h-20 w-20 items-center justify-center rounded-full bg-surface text-xl font-semibold text-ink ring-2 ring-ink-border dark:bg-surface-elevated dark:text-ink-inverse dark:ring-ink-border-dark">
                            {{ strtoupper(substr($project->owner->name, 0, 1)) }}
                        </div>
                    @endif

                    <div>
                        <h1 class="text-3xl font-bold tracking-tight text-ink dark:text-ink-inverse">
                            {{ $project->name }}
                        </h1>
                        @if ($this->isRepertoireMode)
                            <p class="text-sm text-ink-muted dark:text-ink-soft">Repertoire</p>
                        @endif
                    </div>
                </div>

                @if (!$this->isRepertoireMode)
                <div class="flex flex-wrap items-center justify-end gap-2">
                    @if ($project->performer_info_url)
                        <a href="{{ route('project.learn-more', ['projectSlug' => $project->slug]) }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-xl border border-accent bg-accent-50 px-4 py-2 text-sm font-medium text-accent-700 transition hover:bg-accent-100 dark:border-accent-900 dark:bg-accent/90 dark:text-accent-100 dark:hover:bg-accent-900/45">
                            Learn More About the Performer
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                        </a>
                    @endif
                </div>
                @endif
            </div>
        </div>
    </header>
    @endif
    @endif

    <main @class([
        'max-w-7xl mx-auto',
        'px-4 sm:px-6 lg:px-8 pt-4 pb-24' => !$embed,
        'px-0 py-0' => $embed,
    ])>
        @if (!$this->isRepertoireMode)
            @if (($queuePosition = session('request_success.queue_position')) !== null)
                <section class="mb-4 rounded-2xl border border-success-100 bg-success-50/90 p-4 shadow-sm dark:border-success-900/70 dark:bg-success-900/25">
                    <p class="text-sm font-semibold text-success-700 dark:text-success-100">
                        Request submitted. You're currently #{{ $queuePosition }} in the queue.
                    </p>
                </section>
            @endif

            @if ($this->hasEarnedFreeRequest)
                <section class="mb-4 rounded-2xl border border-success-100 bg-success-50/90 p-4 shadow-sm dark:border-success-900/70 dark:bg-success-900/25">
                    <p class="text-sm font-semibold text-success-700 dark:text-success-100">
                        You've earned a free request!
                    </p>
                    <p class="mt-1 text-sm text-success-700 dark:text-success-100">
                        Pick any song below and submit it without a tip.
                    </p>
                </section>
            @endif

            @foreach ($this->earnedNonClaimableRewards as $rewardLabel)
                <section class="mb-4 rounded-2xl border border-accent-100 bg-accent-50/90 p-4 shadow-sm dark:border-accent-900/70 dark:bg-accent-900/25">
                    <p class="text-sm font-semibold text-accent-700 dark:text-accent-100">
                        You've earned: {{ $rewardLabel }}!
                    </p>
                    <p class="mt-1 text-sm text-accent-700 dark:text-accent-100">
                        Approach the musician to receive your reward.
                    </p>
                </section>
            @endforeach

            @if ($project->is_accepting_requests && $project->is_accepting_tips)
                <div class="mb-4 rounded-2xl border border-accent-100 bg-surface px-4 py-3 dark:border-accent-900/80 dark:bg-surface-inverse">
                    <p class="text-sm text-accent/80 dark:text-accent-100/80">
                        {{ $this->queueRulesExplainer }}
                    </p>
                </div>
            @endif

            <livewire:project-current-requests :project="$project" :key="'project-current-requests-' . $project->id" />

            @if (!$project->is_accepting_requests)
                <div class="mb-4 rounded-xl border border-accent-100 bg-surface p-4 dark:border-accent-900">
                    <p class="text-sm text-accent-700 dark:text-accent">
                        This performer is not currently accepting requests.
                    </p>
                </div>
            @endif
        @endif

        @if ($this->isRepertoireMode)
            <div @class([
                'px-3 pb-1 pt-1.5' => $embed,
                'mb-2' => !$embed,
            ])>
                <a href="{{ route('home') }}" target="_blank" rel="noopener noreferrer" @class([
                    'text-[10px] font-medium tracking-wide uppercase',
                    'st-embed-powered' => $embed,
                    'text-ink-muted hover:text-brand dark:text-ink-soft dark:hover:text-brand-300' => !$embed,
                ])>
                    Powered by {{ config('app.name', 'Song Tipper') }}
                </a>
            </div>
        @endif

        <div x-data="{ filterOpen: false }" class="mb-2 flex flex-col gap-2">
            <div class="flex items-center gap-2">
                <button @click="filterOpen = !filterOpen" class="inline-flex items-center gap-2 rounded-xl border border-ink-border/70 bg-surface px-3 py-2 text-xs font-semibold text-ink transition hover:bg-surface-muted dark:border-ink-border-dark/80 dark:bg-surface-inverse dark:text-ink-inverse dark:hover:bg-surface-inverse/90 sm:px-4 sm:py-2 sm:text-sm">
                    Filter Songs
                    <svg x-show="!filterOpen" class="h-5 w-5 leading-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7" />
                    </svg>
                    <svg x-show="filterOpen" class="h-5 w-5 leading-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7 7 7" />
                    </svg>
                </button>
                <div class="flex-1 sm:hidden"></div>
                @if (!$this->isRepertoireMode)
                <button wire:click="surpriseMe" class="inline-flex border border-canvas items-center justify-center gap-2 rounded-xl bg-brand px-3 py-2 text-xs font-semibold text-ink shadow-[0_0_10px_rgba(255,179,117,0.28)] transition hover:bg-brand-100 focus:outline-none focus:ring-2 focus:ring-brand-300 focus:ring-offset-2 focus:ring-offset-canvas-light dark:focus:ring-brand-200 dark:focus:ring-offset-canvas-dark sm:px-4 sm:py-2 sm:text-sm">
                    IDK, Surprise Me
                </button>
                @endif
            </div>

            <div x-show="filterOpen" class="rounded-xl border border-ink-border/70 bg-surface p-2 dark:border-ink-border-dark/80 dark:bg-surface-inverse">
                <div class="grid grid-cols-2 gap-1.5 {{ $this->isRepertoireMode ? 'sm:grid-cols-7' : 'sm:grid-cols-8' }}">
                    <x-text-input type="text" wire:model.live.debounce.300ms="title" placeholder="Search Title" class="h-8 px-2 text-xs" />
                    <x-text-input type="text" wire:model.live.debounce.300ms="artist" placeholder="Search Artist" class="h-8 px-2 text-xs" />
                    <x-select-input wire:model.live="era" class="h-8 px-2 text-xs">
                        <option value="">All Eras</option>
                        @foreach ($this->eras as $eraOption)
                            <option value="{{ $eraOption }}">{{ $eraOption }}</option>
                        @endforeach
                    </x-select-input>
                    <x-select-input wire:model.live="genre" class="h-8 px-2 text-xs">
                        <option value="">All Genres</option>
                        @foreach ($this->genres as $genreOption)
                            <option value="{{ $genreOption }}">{{ $genreOption }}</option>
                        @endforeach
                    </x-select-input>
                    <x-select-input wire:model.live="theme" class="h-8 px-2 text-xs">
                        <option value="">All Themes</option>
                        @foreach ($this->themes as $themeValue => $themeLabel)
                            <option value="{{ $themeValue }}">{{ $themeLabel }}</option>
                        @endforeach
                    </x-select-input>
                    <x-select-input wire:model.live="sortField" class="h-8 px-2 text-xs">
                        <option value="title">Sort by title</option>
                        <option value="artist">Sort by artist</option>
                        <option value="era">Sort by era</option>
                        <option value="genre">Sort by genre</option>
                        @if (!$this->isRepertoireMode)
                            <option value="request_count">Most requested</option>
                        @endif
                    </x-select-input>
                    <x-select-input wire:model.live="sortDirection" class="h-8 px-2 text-xs">
                        <option value="asc">A-Z</option>
                        <option value="desc">Z-A</option>
                    </x-select-input>
                    @if (!$this->isRepertoireMode)
                    <button wire:click="clearFilters" @disabled(!$this->hasActiveFilters) class="inline-flex h-8 items-center justify-center rounded-md border border-ink-border-dark/50 bg-surface px-2 text-xs font-semibold text-ink transition enabled:hover:bg-surface-muted disabled:cursor-not-allowed disabled:opacity-40 dark:border-ink-border-dark dark:bg-canvas-dark dark:text-ink-inverse dark:enabled:hover:bg-surface-elevated">
                        Clear
                    </button>
                    @endif
                </div>
            </div>
        </div>

        <x-ui.card @class(['overflow-hidden', 'st-embed-card' => $embed])>
            @if ($this->isRepertoireMode)
                {{-- Repertoire mode: richer metadata, no request column --}}
                <div @class([
                    'hidden gap-3 border-b px-3 py-2 text-[11px] font-semibold uppercase tracking-wide sm:grid',
                    'grid-cols-[minmax(0,2fr)_minmax(0,1.5fr)_minmax(0,1fr)_minmax(0,0.8fr)_minmax(0,0.8fr)]',
                    'border-ink-border bg-surface text-ink-muted dark:border-ink-border-dark dark:bg-surface-elevated dark:text-ink-soft' => !$embed,
                    'st-embed-header-row' => $embed,
                ])>
                    <span>Title</span>
                    <span>Artist</span>
                    <span>Genre</span>
                    <span>Era</span>
                    <span>Theme</span>
                </div>

                <div @class([
                    'divide-y',
                    'divide-ink-border dark:divide-ink-border-dark' => !$embed,
                ])>
                    @forelse ($this->repertoire as $item)
                        <div wire:key="project-song-{{ $item->song_id }}" @class(['st-embed-row' => $embed])>
                            <div class="grid grid-cols-1 items-start gap-2 px-3 py-2.5 sm:grid-cols-[minmax(0,2fr)_minmax(0,1.5fr)_minmax(0,1fr)_minmax(0,0.8fr)_minmax(0,0.8fr)] sm:items-center sm:gap-3">
                                <div class="min-w-0">
                                    <p @class(['truncate text-sm font-semibold', 'text-ink dark:text-ink-inverse' => !$embed, 'st-embed-title' => $embed])>
                                        {{ $item->title }}@if ($item->instrumental) <span @class(['ml-1 inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium', 'bg-accent-50 text-accent-700 dark:bg-accent-900/30 dark:text-accent-200' => !$embed, 'st-embed-badge-instrumental' => $embed])>Instrumental</span>@endif
                                        @if ($item->mashup) <span @class(['ml-1 inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium', 'bg-danger-50 text-danger-700 dark:bg-danger-900/30 dark:text-danger-200' => !$embed, 'st-embed-badge-mashup' => $embed])>Mashup</span>@endif
                                    </p>
                                    <p @class(['truncate text-[11px] sm:hidden', 'text-ink-muted dark:text-ink-soft' => !$embed, 'st-embed-muted' => $embed])>
                                        {{ $item->artist }} | {{ $item->resolvedGenre() ?: 'Unspecified' }} | {{ $this->formatEraForDisplay($item->song->era) ?: 'Unknown era' }}
                                    </p>
                                </div>

                                <p @class(['hidden truncate text-xs sm:block', 'text-ink dark:text-ink-inverse' => !$embed, 'st-embed-text' => $embed])>
                                    {{ $item->artist }}
                                </p>

                                <p @class(['hidden truncate text-xs sm:block', 'text-ink dark:text-ink-inverse' => !$embed, 'st-embed-text' => $embed])>
                                    {{ $item->resolvedGenre() ?: '-' }}
                                </p>

                                <p @class(['hidden truncate text-xs sm:block', 'text-ink dark:text-ink-inverse' => !$embed, 'st-embed-text' => $embed])>
                                    {{ $this->formatEraForDisplay($item->song->era) ?: '-' }}
                                </p>

                                <p @class(['hidden truncate text-xs sm:block', 'text-ink dark:text-ink-inverse' => !$embed, 'st-embed-text' => $embed])>
                                    @php
                                        $resolvedTheme = $item->resolvedTheme();
                                        $themeEnum = $resolvedTheme ? \App\Enums\SongTheme::fromInput($resolvedTheme) : null;
                                    @endphp
                                    {{ $themeEnum?->label() ?? ($resolvedTheme ? ucfirst(str_replace('_', ' ', $resolvedTheme)) : '-') }}
                                </p>
                            </div>
                        </div>
                        @empty
                            <div class="px-4 py-12 text-center">
                                <p @class(['text-ink-muted dark:text-ink-soft' => !$embed, 'st-embed-muted' => $embed])>No songs found matching your criteria.</p>
                            </div>
                        @endforelse
                    </div>
            @else
                {{-- Request mode: original layout with request column --}}
                @php($pinnedActionRows = $this->pinnedActionRows)
                <div class="hidden grid-cols-[minmax(0,1.6fr)_minmax(0,1.2fr)_minmax(0,0.9fr)_minmax(0,0.8fr)_7.5rem] gap-3 border-b border-ink-border bg-surface px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-ink-muted dark:border-ink-border-dark dark:bg-surface-elevated dark:text-ink-soft sm:grid">
                    <span>Title</span>
                    <span>Artist</span>
                    <span>Genre</span>
                    <span>Era</span>
                    <span class="text-right">Request</span>
                </div>

                <div class="divide-y divide-ink-border dark:divide-ink-border-dark">
                    @foreach ($pinnedActionRows as $pinnedActionRow)
                        <div wire:key="pinned-action-row-{{ $pinnedActionRow['key'] }}" @class([$pinnedActionRow['row_classes']])>
                            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2 px-3 py-2.5 sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1.2fr)_minmax(0,0.9fr)_minmax(0,0.8fr)_7.5rem] sm:items-center sm:gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-ink dark:text-ink-inverse">
                                        {{ $pinnedActionRow['title'] }}
                                    </p>
                                    <p class="mt-1 text-xs text-ink-muted dark:text-ink-soft">
                                        {{ $pinnedActionRow['description'] }}
                                    </p>
                                    @if ($pinnedActionRow['artist'] !== '' || $pinnedActionRow['genre'] !== '' || $pinnedActionRow['era'] !== '')
                                        <p class="mt-1 truncate text-[11px] text-ink-muted dark:text-ink-soft sm:hidden">
                                            {{ $pinnedActionRow['artist'] }} | {{ $pinnedActionRow['genre'] }} | {{ $pinnedActionRow['era'] }}
                                        </p>
                                    @endif
                                </div>

                                <p class="hidden truncate text-xs text-ink dark:text-ink-inverse sm:block">
                                    {{ $pinnedActionRow['artist'] }}
                                </p>

                                <p class="hidden truncate text-xs text-ink dark:text-ink-inverse sm:block">
                                    {{ $pinnedActionRow['genre'] }}
                                </p>

                                <p class="hidden truncate text-xs text-ink dark:text-ink-inverse sm:block">
                                    {{ $pinnedActionRow['era'] }}
                                </p>

                                <div class="flex justify-end">
                                    <a href="{{ $pinnedActionRow['href'] }}" @class([
                                        'inline-flex w-24 items-center justify-center gap-2 rounded-xl px-2.5 py-1 text-[11px] font-semibold transition focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-canvas-light dark:focus:ring-offset-canvas-dark sm:px-3 sm:py-1.5 sm:text-xs',
                                        $pinnedActionRow['button_classes'],
                                    ])>
                                        {{ $pinnedActionRow['action_label'] }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    @forelse ($this->repertoire as $item)
                        <div wire:key="project-song-{{ $item->song_id }}">
                            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2 px-3 py-2.5 sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1.2fr)_minmax(0,0.9fr)_minmax(0,0.8fr)_7.5rem] sm:items-center sm:gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-ink dark:text-ink-inverse">
                                        {{ $item->title }}@if ($item->instrumental)
                                            (instrumental)
                                        @endif
                                    </p>
                                    <p class="truncate text-[11px] text-ink-muted dark:text-ink-soft sm:hidden">
                                        {{ $item->artist }} | {{ $item->resolvedGenre() ?: 'Unspecified' }} | {{ $this->formatEraForDisplay($item->song->era) ?: 'Unknown era' }}
                                    </p>
                                </div>

                                <p class="hidden truncate text-xs text-ink dark:text-ink-inverse sm:block">
                                    {{ $item->artist }}
                                </p>

                                <p class="hidden truncate text-xs text-ink dark:text-ink-inverse sm:block">
                                    {{ $item->resolvedGenre() ?: '-' }}
                                </p>

                                <p class="hidden truncate text-xs text-ink dark:text-ink-inverse sm:block">
                                    {{ $this->formatEraForDisplay($item->song->era) ?: '-' }}
                                </p>

                                <div class="flex justify-end">
                                    @if ($project->is_accepting_requests)
                                        <a href="{{ route('request.page', [
                                            'projectSlug' => $project->slug,
                                            'song' => $item->song_id,
                                        ]) }}" class="inline-flex w-24 items-center justify-center gap-2 rounded-xl bg-brand px-2.5 py-1 text-[11px] font-semibold text-ink shadow-[0_0_10px_rgba(255,179,117,0.28)] transition hover:bg-brand-100 focus:outline-none focus:ring-2 focus:ring-brand-300 focus:ring-offset-2 focus:ring-offset-canvas-light dark:focus:ring-brand-200 dark:focus:ring-offset-canvas-dark sm:px-3 sm:py-1.5 sm:text-xs">
                                            Request
                                        </a>
                                    @else
                                        <span class="inline-flex items-center justify-center rounded-md border border-ink-border px-2.5 py-1 text-[11px] font-medium text-ink-muted dark:border-ink-border-dark dark:text-ink-soft sm:px-3 sm:py-1.5 sm:text-xs">
                                            Closed
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @empty
                            <div class="px-4 py-12 text-center">
                                <p class="text-ink-muted dark:text-ink-soft">No songs found matching your criteria.</p>
                            </div>
                        @endforelse
                    </div>
            @endif
            </x-ui.card>

            <div class="mt-6">
                {{ $this->repertoire->links() }}
            </div>

        </main>

        @if (!$embed)
            @include('partials.site-footer')
        @endif
</div>

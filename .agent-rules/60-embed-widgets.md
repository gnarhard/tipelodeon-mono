# Embed Widgets

The public repertoire page can be rendered as an embeddable iframe widget when the request includes `?embed=1`. Performers paste a generated `<iframe>` snippet into their own websites, so the embedded view must be safe to render inside any third-party page.

## Where embed mode applies

- Route: `GET /project/{projectSlug}/repertoire?embed=1`
  (named `project.repertoire`, defined in `web/routes/web.php`)
- Middleware: `App\Http\Middleware\AllowEmbedding` strips `X-Frame-Options` and sets `Content-Security-Policy: frame-ancestors *` only when `embed=1` is present.
- Top-level view: `web/resources/views/pages/repertoire.blade.php` reads `request()->boolean('embed')` and forwards it to `<livewire:project-page mode="repertoire" :embed="$embed">`.
- Component: `web/resources/views/components/⚡project-page.blade.php` is the only Livewire component that accepts an `$embed` flag. Embed-only styling is applied via the `st-embed` body class and the `st-embed-*` element classes.

There is no other place where `embed=1` is honoured. Do not invent new embed entry points without updating this rule.

## Hard constraint: no actionable buttons in embed mode

When `$embed === true`, the rendered markup MUST NOT contain any of the following:

1. **Server-action buttons** — anything with `wire:click`, `wire:submit`, `<form method="post|put|patch|delete">`, or a submit button. The audience embed is read-only browsing; tipping, requesting, surprising, and clearing filters all belong to the full project page.
2. **Same-window navigation links** — any `<a href="...">` that would navigate the iframe away from the embed view (no `target="_blank"`). This includes links to the request flow, login, dashboard, learn-more page, or any other Song Tipper route. A navigation click inside the iframe replaces the host page's widget content with an unrelated page, which is broken.
3. **Auth-gated UI** — login/logout, account, dashboard, or admin affordances. The embed renders for anonymous audience visitors and must never expose performer-only controls.
4. **Outbound POST forms** — even GET forms that would change the visible state outside Livewire are forbidden; use `wire:model.live` on inputs instead.

### Allowed interactive elements

These remain useful in an embed and are explicitly permitted:

- Client-side toggles implemented with Alpine `x-data` / `@click` (e.g. the "Filter Songs" disclosure button) — they only mutate local UI state.
- Filter and sort inputs bound with `wire:model.live` — the Livewire round-trip stays inside the iframe and never navigates.
- Livewire-paginated lists rendered via `WithPagination` (`{{ $paginator->links() }}`) — page links are intercepted by Livewire and handled as AJAX, not as full-page navigations.
- A single attribution link to Song Tipper, but **only** with `target="_blank" rel="noopener noreferrer"` so it opens a new tab and leaves the embed intact.

## How to gate elements correctly

Inside `⚡project-page.blade.php` (or any future embeddable component), wrap actionable affordances in one of:

```blade
@if (!$embed)
    {{-- Anything with wire:click, navigation <a>, forms, auth UI, etc. --}}
@endif
```

or, when the action is also gated by mode, combine the conditions:

```blade
@if (!$embed && !$this->isRepertoireMode)
    <button wire:click="surpriseMe">…</button>
@endif
```

Do not rely on CSS (`hidden`, `display:none`) to suppress actions in embed mode — the markup must not be present at all. Hidden buttons are still tab-focusable and still POST when scripted.

## Tests that enforce this rule

`web/tests/Feature/EmbedRepertoireTest.php` covers the constraint. When you add a new affordance to `⚡project-page.blade.php`, extend that test so the embed render is asserted to not contain it. The minimum assertions for any new actionable element are:

- `assertDontSee('wire:click="yourAction"', false)` for the new server action.
- `assertDontSee(route('your.route', [...]), false)` for the new outbound link.

If you intentionally add a new allowed element (e.g. another `wire:model.live` filter), add a positive assertion in the same test so future regressions are caught.

## Adding new embeddable views

If a future feature exposes a second embeddable view:

1. Reuse the existing `?embed=1` query parameter contract and the `AllowEmbedding` middleware — do not invent a separate flag.
2. Pass `:embed="$embed"` into the Livewire component and gate every actionable element by `$embed` per the rules above.
3. Extend `EmbedRepertoireTest` (or add a sibling test) so the new view is covered by the same actionable-element assertions.
4. Update this file with the new route and any new allowed elements.

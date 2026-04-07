# SongTipper Manual Test Checklist v2

Exhaustive feature verification for Web & Mobile App

**Date:** \_\_\_\_\_\_\_\_\_\_\_\_\_\_ **Tester:** \_\_\_\_\_\_\_\_\_\_\_\_\_\_ **Environment:** \_\_\_\_\_\_\_\_\_\_\_\_\_\_

---

## WEB APP

### Authentication

- [ ] Register with email, name, password, primary instrument
- [ ] Register with optional secondary instrument
- [ ] Register validation (missing fields, weak password, duplicate email)
- [ ] Login with email/password
- [ ] Login rate limiting (5 attempts/min)
- [ ] "Remember me" toggle persists session
- [ ] Email verification prompt after registration
- [ ] Email verification link works
- [ ] Forgot password sends reset email
- [ ] Reset password with valid token
- [ ] Reset password with expired/invalid token
- [ ] Logout clears session

### Profile & Account

- [ ] Update name
- [ ] Update email (triggers re-verification)
- [ ] Change password
- [ ] Update primary instrument
- [ ] Update secondary instrument (optional, clearable)
- [ ] Delete account (with confirmation dialog)

### Billing & Subscription

- [ ] New signup lands directly on dashboard (no billing wall, no credit card required)
- [ ] Free tier: audience requesting/tipping and all features enabled from day one (no card required, until $200 cumulative)
- [ ] Subscription auto-activates at $200 cumulative tips threshold (`pro_monthly` default, `pro_yearly` offered)
- [ ] Pro pricing: `$20/month` or `$200/year`
- [ ] 14-day grace period begins at threshold to add payment method
- [ ] After 14-day grace without card: `billing_status=card_needed`, audience requesting blocked until subscription active
- [ ] Auto-skip: monthly billing skipped automatically if monthly tips < $200 (monthly plans only)
- [ ] Yearly plan nudge email sent after $600 cumulative tips ($400 past threshold)
- [ ] Veteran plan (`veteran_monthly`, `$50/month`) auto-upgrade when earning $2,500+/month (Top Earner badge)
- [ ] Complimentary access: `free_year` expires after configured period
- [ ] Complimentary access: `lifetime` never expires
- [ ] Update payment method
- [ ] Access Stripe billing portal

### Dashboard (read-only, gateway to mobile)

- [ ] Shows all owned projects
- [ ] "Manage Everything in the App" banner with App Store / Google Play download links
- [ ] Displays wallet balance (available, pending, total)
- [ ] Shows payout account setup status (Stripe Express)
- [ ] Shows account usage (storage, AI) and any fair-use warnings
- [ ] Current billing plan displayed (Free / Pro monthly / Pro yearly / Veteran)
- [ ] Embed widget code shown per project (for external sites)
- [ ] Links to payout onboarding work
- [ ] Earnings progress toward $200 activation threshold visible on Free tier
- [ ] Grace period countdown visible if threshold reached without card on file
- [ ] Stripe refresh-connect button re-polls payout status

### Payout Account (Stripe Express)

- [ ] Onboarding link redirects to Stripe
- [ ] Complete Stripe onboarding flow
- [ ] Refresh onboarding if incomplete
- [ ] Access Stripe Express dashboard
- [ ] Payout status updates (not_started -> pending -> enabled)

> **Note:** All performer-facing features (repertoire, charts, audio, setlists, queue, performance sessions, cash tips, stats) are **mobile-only**. The web app handles only auth, billing, payout onboarding, a read-only dashboard, admin, and public audience pages.

### Admin Features

- [ ] Admin songs page -- search, edit, delete songs
- [ ] Admin access page -- manage user access
- [ ] Song integrity page -- view/fix data issues

---

## MOBILE APP (Flutter)

### Authentication

- [ ] Login with email/password
- [ ] Login error handling (wrong credentials)
- [ ] Link to web registration visible
- [ ] Forgot password flow
- [ ] Change password (from settings)
- [ ] Logout
- [ ] Auth token persisted securely (app restart stays logged in)
- [ ] Unauthenticated redirect to login

### Context / Project Selection

- [ ] List all projects with roles (owner/manager/performer)
- [ ] Create new project
- [ ] Delete project (owner only)
- [ ] Switch active project
- [ ] Project context required before main app access
- [ ] No project limit on any tier — create as many projects as desired

### Home Screen (Dashboard)

- [ ] User greeting displays
- [ ] Project name shown
- [ ] Stats display (tips, requests, performances, trending)
- [ ] Timeline selector (today/week/month/year/lifetime)
- [ ] Pull-to-refresh
- [ ] Log cash tip dialog
- [ ] Payout dashboard link (external)
- [ ] Switch projects from home
- [ ] Error banner on stats load failure
- [ ] Stats refresh on app resume
- [ ] Cash tip amount shown separately from digital tips in stats
- [ ] Year-to-date earnings chart (daily buckets from `/stats/history`, available on all tiers)
- [ ] Chart omits zero-income days; empty state when no YTD activity

### Queue Management

- [ ] View active requests in priority order
- [ ] Request cards show: title, artist, requester, tip, timestamp
- [ ] Request cards show requester_name (from billing details)
- [ ] Request cards show request_location (geolocation)
- [ ] Request cards show is_manual badge for performer-added items
- [ ] Mark request as played
- [ ] Delete request
- [ ] Move request up/down in queue
- [ ] Add manual request (song title/artist, tip amount, requester name, note)
- [ ] Manual request tip rounded up to whole dollar
- [ ] Edit manual request tip (PATCH)
- [ ] View queue archive/history
- [ ] Auto-poll every 10 seconds (ETag-based, 304 short-circuits)
- [ ] Polling stops when offline
- [ ] New request toast notification
- [ ] Daily record celebration event
- [ ] Dismiss tip-only requests
- [ ] Manual refresh

### Repertoire

- [ ] View song list with metadata (title, artist, performance count, tips)
- [ ] Project-specific title/artist displayed (not canonical)
- [ ] Search by title/artist
- [ ] Filter by instrument, era, genre, theme, energy
- [ ] Filter by era options: 50s, 60s, 70s, 80s, 90s, 2000s, 2010s, 2020s
- [ ] Theme strict enum validation (love, party, worship, story, st_patricks, christmas, halloween, patriotic) — invalid value returns 422
- [ ] Sort by title, requests, tips, performances
- [ ] Multi-select for bulk operations
- [ ] Toggle public/private visibility
- [ ] Delete song from project
- [ ] Record song performance
- [ ] No song count limit on any tier — add unlimited songs per project
- [ ] Mark song as instrumental (appends " (instrumental)" to display title)
- [ ] Mark song as mashup (skips global dedup, no global metadata, red "Mashup" pill)
- [ ] Create alternate song version (version_label, e.g. "Acoustic", "Solo")
- [ ] Unique constraint on (project, user, song, version_label) — duplicate returns error
- [ ] Copy entire repertoire from another project

### Member Repertoire Isolation

- [ ] Each project member has an independent copy of songs (scoped by `project_songs.user_id`)
- [ ] On member join, all owner songs (with charts) are copied via `SyncRepertoireToMember` job
- [ ] Owner creating a new song fans out copies to all existing members (`FanOutSongToMembers` job)
- [ ] Fan-out does not overwrite if a member already has the song
- [ ] Owner edits do NOT auto-propagate to member copies
- [ ] API response includes `source_project_song_id` and `is_owner_copy` fields
- [ ] Pull Owner Copy: `POST /repertoire/{projectSongId}/pull-owner-copy` adds owner's current version as a new alternate version on member's song
- [ ] Pull Owner Copy label: `"Owner's Version (synced {Mon DD, YYYY})"`
- [ ] Pull Owner Copy: 422 if song has no linked owner version
- [ ] Pull Owner Copy: 404 if owner's version no longer exists

### Song Detail

- [ ] View all metadata (title, artist, keys, tuning, capo, duration, etc.)
- [ ] Edit song metadata
- [ ] Edit project-specific title/artist override
- [ ] View performance & request stats
- [ ] Chart viewer (multi-page, pan/zoom, full-screen)
- [ ] Chart light/dark theme toggle
- [ ] Page scrubber navigation
- [ ] Drawing annotations on charts (pencil tool)
- [ ] Annotation stroke color & size selection
- [ ] Undo/redo annotations
- [ ] Clear all annotations
- [ ] Save annotations
- [ ] Audio player (play/pause, seek, skip tracks)
- [ ] Switch between multiple audio files
- [ ] Quick-add to setlist
- [ ] Mark as performed
- [ ] Navigate to prev/next song (in setlist context)
- [ ] Version label displayed for alternate versions
- [ ] Upload chart PDF (max 2MB, throttle 5 req/min)
- [ ] Upload upsert behavior: same PDF → 200 no change; different PDF → 200 replace + re-render
- [ ] Check render status (ready/pending/failed)
- [ ] Page-level render URLs (light/dark, 15-min TTL)
- [ ] Manual re-render trigger
- [ ] Delete chart
- [ ] Duplicate chart detection
- [ ] Adopt chart from another user (POST /charts/{chartId}/adopt)
- [ ] Chart adoption: 409 if adopter already has chart for that song
- [ ] Chart adoption: 403 if adopter lacks project access
- [ ] Chart annotations saved per page (requires Idempotency-Key)
- [ ] Chart cache manifest endpoint (POST /charts/cache-manifest, 2 req/5 min)
- [ ] Cache manifest: delta downloads via known_revisions
- [ ] Cache manifest: response includes generated_at, pages with light/dark URLs (30-min TTL)
- [ ] Generate AI lyric/chord sheet (POST /me/charts/generate-lyrics with song_id + project_id)
- [ ] Generate lyrics returns 202 with Chart (`import_status='generating'`, `source_type='ai_generated'`)
- [ ] Generate lyrics: client polls render-status until `import_status=null` and `status='ready'`
- [ ] Generate lyrics: 409 when chart already exists for (user, song, project)
- [ ] Generate lyrics: 429 when monthly AI usage quota exceeded
- [ ] Chart badge shows `source_type` (uploaded vs ai_generated)
- [ ] Chart resource includes `import_status` (null/generating/identifying/identified/failed)
- [ ] On generation failure, render-status exposes `import_error` detail
- [ ] Pull Owner Copy action visible when song is a member copy (`source_project_song_id` set)

### Audio Files

- [ ] Upload audio file to song (MP3 only, max 15 MB)
- [ ] List audio files for a song
- [ ] Play audio via signed URL (60-min TTL)
- [ ] Replace audio file (keeps ID/order/label)
- [ ] Update audio metadata (label, sort_order)
- [ ] Delete audio file (soft delete, reindex sort_order)
- [ ] Max 3 audio files per song enforced
- [ ] SHA-256 dedup: duplicate upload returns 409
- [ ] Batch fetch: POST /audio-files/batch with project_song_ids (max 50)
- [ ] Audio cache manifest: GET /audio-files/manifest returns all files with signed URLs

### Add Song

- [ ] Title & artist input
- [ ] Version label field (e.g. "Acoustic", "Solo", "Electric")
- [ ] Energy level, era, genre, theme selection
- [ ] Genre list includes "Singer/Songwriter"
- [ ] Instrumental / mashup flags
- [ ] Public toggle (is_public, default true)
- [ ] Performed & original key pickers
- [ ] Tuning, capo, duration fields
- [ ] MusicBrainz auto-fetch
- [ ] Chart upload (PDF/image file picker)
- [ ] Form validation

### Image Import

- [ ] Camera capture
- [ ] Gallery photo selection
- [ ] Accepts jpeg/png/webp/heic, max 10MB
- [ ] OCR processing extracts song list
- [ ] Smart matching to existing repertoire
- [ ] Manual editing before import
- [ ] Bulk song creation
- [ ] Duplicates skipped automatically

### Bulk Import (PDF)

- [ ] CSV file picker
- [ ] PDF file picker
- [ ] Drag-and-drop (desktop/tablet)
- [ ] Phase 1 — Upload: POST /repertoire/bulk-upload with up to 20 PDFs (2MB each)
- [ ] Phase 1: Filename metadata parsing (key, capo, tuning, energy, era, genre, theme)
- [ ] Phase 1: Returns import_status per song (queued/identified/deferred) with import_metadata
- [ ] Phase 2 — Enrich: POST /repertoire/bulk-enrich fetches metadata by title+artist
- [ ] Phase 2: Enrichment sources reported (songs_table/cache/ai) with ai_calls_used count
- [ ] Phase 3 — Confirm: POST /repertoire/bulk-import/confirm finalizes with user-reviewed metadata
- [ ] Phase 3: Per-song action status returned (imported/duplicate/no_match)
- [ ] Import progress tracking per song
- [ ] Error display per failed import
- [ ] Duplicate detection
- [ ] Pending review for ambiguous matches

### Learning Songs

- [ ] View to-learn list
- [ ] Add song to learn
- [ ] Toggle learned/unlearned
- [ ] View YouTube / tabs / MusicBrainz links
- [ ] Open external resources
- [ ] Filter and sort
- [ ] Demote from repertoire to learn list
- [ ] Promote learning song to repertoire (creates ProjectSong, removes from learn list)

### Setlists

- [ ] View setlist list with counts
- [ ] Folder grouping
- [ ] Search setlists by name
- [ ] Create new setlist
- [ ] Edit setlist metadata (name, notes, folder)
- [ ] Delete setlist
- [ ] Archive / restore setlist
- [ ] Share setlist (generate link)
- [ ] Bulk select (move, archive, delete)
- [ ] Active performance indicator

### Setlist Detail

- [ ] View sets and songs
- [ ] Add / edit / delete sets
- [ ] Reorder sets
- [ ] Drag-to-reorder songs within set
- [ ] Add songs from repertoire
- [ ] Bulk add songs
- [ ] Import from text (newline-separated)
- [ ] Color override per song (6 colors, hex #RRGGBB)
- [ ] Notes per song
- [ ] Remove song from set
- [ ] Duplicate setlist
- [ ] AI setlist builder: prompt-based generation (min 3, max 500 chars)
- [ ] AI setlist builder: 1-10 sets per request, named or unnamed
- [ ] AI setlist builder: only selects from existing repertoire
- [ ] Random song picker
- [ ] Bulk adopt charts from shared source
- [ ] Chart coverage indicator per song
- [ ] CSV / PDF import with auto-matching (fuzzy scoring UI)
- [ ] Extract songs from setlist image (camera/gallery, AI vision)
- [ ] Image extraction returns set_label groupings
- [ ] Launch performance from setlist
- [ ] Share setlist with all project members (owner-only button)
- [ ] Pull Owner Copy button on member's song (fetches owner's current version as alternate)

### Shared Setlist (Deep Link)

- [ ] Open shared setlist link
- [ ] Accept shared setlist into project
- [ ] Detect already-accepted (idempotent)
- [ ] Chart adoption dialog
- [ ] Shared assets: charts + audio files with signed URLs offered for download
- [ ] Redirect to setlist detail after acceptance

### Perform Screen

- [ ] Only visible when performance active
- [ ] Shows current song detail
- [ ] Set & song position indicator ("Set 1, Song 3 of 5")
- [ ] Chart display during performance
- [ ] Prev/next song navigation
- [ ] "Mark Performed" button
- [ ] Performance timestamp recorded
- [ ] Session persists through app background/reopen
- [ ] End performance session

### Cash Tips

- [ ] Log cash tip (amount, date, timezone, note)
- [ ] Edit existing cash tip (PATCH)
- [ ] Delete cash tip
- [ ] Cash tips listed with date filtering and pagination

### Settings

- [ ] Display current user (name, email)
- [ ] Change password link
- [ ] Logout button
- [ ] Project name display
- [ ] Project members list
- [ ] Payout status & dashboard link
- [ ] Earnings-based billing banner: "Free until $200 in tips" with progress toward threshold
- [ ] 14-day grace countdown shown when threshold reached and no card on file
- [ ] Toggle: accept requests
- [ ] Toggle: accept tips
- [ ] Toggle: accept original requests
- [ ] Toggle: email notifications (notify_on_request)
- [ ] Min tip amount setting
- [ ] Quick tip button amounts (3 values, whole dollars, descending)
- [ ] Primary instrument selector (required)
- [ ] Secondary instrument selector (optional)
- [ ] Persistent queue strip toggle & position
- [ ] Public repertoire set selector (public_repertoire_set_id)
- [ ] Audio cache refresh button (downloads all audio via manifest)
- [ ] Free request threshold setting (`free_request_threshold_cents`; 0 disables)
- [ ] Reward thresholds editor (add/remove thresholds with reward_type + is_repeating)
- [ ] Owner: remove member from project (with confirmation dialog)

### Offline & Connectivity

- [ ] Offline indicator shown when disconnected
- [ ] Queue polling stops when offline
- [ ] Outbox queues changes locally when offline
- [ ] Outbox syncs when back online
- [ ] Temp ID reconciliation after sync
- [ ] Cached repertoire accessible offline
- [ ] Charts cacheable for offline viewing
- [ ] Audio files cacheable for offline playback (via manifest)

### Navigation & Shell

- [ ] Bottom nav: Home, Queue, Repertoire, Setlists, (Perform), Settings
- [ ] Queue tab hidden if not entitled
- [ ] Perform tab appears only during active session
- [ ] Queue strip (persistent floating bar) when enabled
- [ ] Max-width layout on large screens
- [ ] Light/dark theme follows system

### Cross-Cutting Concerns

- [ ] Idempotency headers prevent duplicate writes
- [ ] ETag caching on queue reduces bandwidth
- [ ] Toast messages appear for operations
- [ ] Loading states shown during async operations
- [ ] Error states display user-friendly messages
- [ ] App version policy check (platform-aware: ios/android/macos/windows/linux)
- [ ] Version comparison: numeric x.y.z first, then latest_build_number
- [ ] Sentry crash reporting active (production)

---

## INTEGRATION TESTS (Cross-App)

> Web handles **audience** + billing/payout. Mobile handles **performer** operations. These tests verify the handoff between them.

### Audience (web) → Performer (mobile)

- [ ] Audience submits tipped request on web -> appears in performer's mobile queue within one poll cycle (10s)
- [ ] Audience tips on web -> performer's mobile wallet balance updates (available + pending)
- [ ] Audience submits request on web -> request card on mobile shows `requester_name` (from Stripe billing details) and `request_location`
- [ ] Audience claims `free_request` reward on web -> request appears in mobile queue with `payment_provider=awarded`, `tip_amount_cents=0`
- [ ] Audience crosses a reward threshold on web -> performer receives threshold email notification and sees claim in `audience_reward_claims`
- [ ] Stripe webhook processes payment -> queue request status transitions are visible on mobile

### Performer (mobile) → Audience (web)

- [ ] Performer marks request played on mobile -> request removed from audience queue view on web
- [ ] Song marked private on mobile -> hidden from audience repertoire page on web
- [ ] Song with project-specific title/artist override on mobile -> override title shown on audience-facing web page
- [ ] Instrumental flag set on mobile -> " (instrumental)" suffix appears on web repertoire listing
- [ ] `public_repertoire_set_id` set on mobile -> audience sees only that set on web (is_public flags bypassed)
- [ ] `reward_thresholds` edited on mobile -> new thresholds visible in public project payload on web
- [ ] Toggles (accept_requests / accept_tips / accept_original_requests) flipped on mobile -> audience sees updated state on web

### Performer (mobile) ↔ Performer (web dashboard)

- [ ] Project settings changed on web dashboard -> reflected on mobile after refresh
- [ ] Performance session / tips on mobile -> wallet tiles on web dashboard update on next load
- [ ] Performer reaches $200 cumulative tips on mobile -> web dashboard shows grace-period countdown and prompts to add card
- [ ] Adding card via web billing portal -> mobile settings screen reflects subscription active

### Shared assets & deep links

- [ ] Shared setlist link (generated on mobile) opens correctly via mobile deep link and via web `/shared-setlists/{token}` redirect
- [ ] Shared setlist acceptance pulls chart + audio assets with signed URLs

### Multi-member sync (mobile-only operations)

- [ ] Owner invites new member on mobile -> `SyncRepertoireToMember` job copies all owner songs+charts into member's account
- [ ] Owner creates a new song on mobile -> `FanOutSongToMembers` job copies it into every existing member's account
- [ ] Member pulls owner copy on mobile -> new alternate version added to member's song with label `Owner's Version (synced ...)`
- [ ] Owner shares setlist with members on mobile -> setlist appears in every member's setlist list
- [ ] Owner removes member on mobile -> removed member loses access to project on next app launch

---

## Public Pages

### Legal & Marketing Pages

- [ ] Homepage renders
- [ ] Terms page loads
- [ ] Privacy page loads
- [ ] EULA page loads
- [ ] Blog listing page
- [ ] Blog article pages
- [ ] Contact form submits
- [ ] Shared setlist redirect link works

### Audience Request Page

- [ ] View performer project page
- [ ] Songs with is_public=false are hidden from the public page
- [ ] When public_repertoire_set_id is set, only songs from that set are shown
- [ ] Set-specific filtering ignores is_public flags and version_label filters
- [ ] Instrumental songs display with " (instrumental)" appended
- [ ] Filter songs (theme, era, genre, energy, search)
- [ ] Theme filter uses strict enum validation (invalid value -> 422)
- [ ] Sort songs (request count, tip amount, title, artist)
- [ ] Pagination (50/page)
- [ ] "Surprise me" random song picker
- [ ] Submit song request (repertoire song)
- [ ] Submit original request
- [ ] Submit tip-only
- [ ] Tip-only maps to placeholder song "Tip Jar Support" by "Audience"
- [ ] Original request maps to placeholder "Original Request" by "Audience"
- [ ] Tip amount input (presets and custom)
- [ ] Stripe payment processes correctly
- [ ] Minimum tip enforcement
- [ ] Tip-disabled mode: tip_amount_cents=0 required, min_tip_cents ignored
- [ ] Paid tips increment audience member's `cumulative_tip_cents` (grows only, never resets)
- [ ] Reward thresholds displayed when project has `reward_thresholds` configured
- [ ] Repeating threshold: available claims = `floor(cumulative / threshold) - claims_made`
- [ ] Non-repeating threshold: earned once when cumulative >= threshold
- [ ] `free_request` reward: progress shown ("You're $X away from: Free Song Request!")
- [ ] `free_request` reward: claim button appears when earned
- [ ] `free_request` claim submits with `payment_provider=awarded`, `tip_amount_cents=0`
- [ ] `free_request` bypasses min_tip_cents requirements
- [ ] Tip-only submissions cannot use a free request credit
- [ ] Non-`free_request` reward types (e.g. `free_cd`, `custom`) display label and manual fulfillment message
- [ ] Each claim written to `audience_reward_claims` table
- [ ] Performer receives email notification when audience crosses a threshold
- [ ] Project with no reward thresholds: no reward UI shown
- [ ] Backward compat: project payload still includes deprecated `free_request_threshold_cents`
- [ ] Queue priority guidance based on tip
- [ ] Request confirmation page displays
- [ ] View current requests in queue (visitor cookie tracking)
- [ ] Queue position polling (60s interval)
- [ ] Public request rate limiting (60/min)
- [ ] "Learn more" page loads

## API-SPECIFIC VALIDATIONS

### Rate Limits & Throttling

- [ ] Chart upload: 5 requests/min per user
- [ ] Cache manifest: 2 requests/5 min
- [ ] Public requests: 60/min
- [ ] AI interactive: 30/min per user
- [ ] Login: 5 attempts/min

### Plan Enforcement Matrix

> Tiers are earnings-based, not feature-gated. Everyone starts on **Free** with every feature available. **Pro** activates at $200 cumulative tips (billing only). **Veteran** auto-upgrades at $2,500+/month in tips (recognition only — Top Earner badge is the sole Veteran-exclusive). Audience requesting is the only thing that can be blocked, and only when `billing_status=card_needed` (post 14-day grace after the $200 threshold with no card on file).

| Capability | Free | Pro | Veteran |
|---|---|---|---|
| [ ] Project limit | unlimited | unlimited | unlimited |
| [ ] Repertoire song limit | unlimited | unlimited | unlimited |
| [ ] Audience requesting (public tips + requests) | yes (until `card_needed`) | yes | yes |
| [ ] Queue access | yes | yes | yes |
| [ ] Request history | yes | yes | yes |
| [ ] Owner stats | yes | yes | yes |
| [ ] YTD stats history chart | yes | yes | yes |
| [ ] Wallet view | yes | yes | yes |
| [ ] Invite members (band sync) | yes | yes | yes |
| [ ] "Top Earner" verified badge | no | no | **yes** |

- [ ] Free-tier account: all features available from day one, no card required, no limits
- [ ] $200 cumulative threshold → `pro_monthly` activation, 14-day grace to add card
- [ ] Post-grace without card → `billing_status=card_needed` → audience requesting blocked (all other features remain)
- [ ] Adding a card flips `billing_status` back to active and re-enables audience requesting on next project payload refresh
- [ ] $2,500+/month sustained → auto-upgrade to `veteran_monthly`, `is_top_earner=true`, Top Earner badge appears on audience-facing profile

### Error Code Spot-Checks

- [ ] `payout_setup_incomplete` when enabling tips/requests without payout
- [ ] 409 on duplicate chart adoption
- [ ] 409 on duplicate audio file upload (SHA-256 match)
- [ ] 409 on starting second active performance session
- [ ] 422 on invalid theme enum value
- [ ] 422 on setlist image extraction failure
- [ ] `audience_requesting_gated` when attempting a public request against an owner with `billing_status=card_needed`
- [ ] 409 on generate-lyrics when chart already exists for (user, song, project)
- [ ] 429 on generate-lyrics when monthly AI usage quota exceeded
- [ ] 422 on pull-owner-copy when song has no linked owner version
- [ ] 404 on pull-owner-copy when owner's version no longer exists
- [ ] 403 on share-setlist-with-members when caller is non-owner
- [ ] 403 on member removal by non-owner
- [ ] 404 on member removal with membership ID from another project
- [ ] 403 on stats/history for non-owners

---

## Notes

\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

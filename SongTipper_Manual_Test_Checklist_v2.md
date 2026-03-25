# SongTipper Manual Test Checklist v2

Exhaustive feature verification for Web & Mobile App

**Date:** \_\_\_\_\_\_\_\_\_\_\_\_\_\_ **Tester:** \_\_\_\_\_\_\_\_\_\_\_\_\_\_ **Environment:** \_\_\_\_\_\_\_\_\_\_\_\_\_\_

> Items marked with **[NEW]** are new or updated since v1 of this checklist.

---

## WEB APP

### Authentication

- [ ] Register with email, name, password, primary instrument
- [ ] **[NEW]** Register with optional secondary instrument
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
- [ ] **[NEW]** Update primary instrument
- [ ] **[NEW]** Update secondary instrument (optional, clearable)
- [ ] Delete account (with confirmation dialog)

### Billing & Subscription

- [ ] Billing setup page shown before dashboard access
- [ ] Select Free plan (no card required)
- [ ] Select Basic plan (monthly/yearly) with card
- [ ] Select Pro plan (monthly/yearly) with card
- [ ] 14-day trial starts correctly
- [ ] Upgrade plan (Free -> Basic -> Pro)
- [ ] Downgrade plan
- [ ] **[NEW]** Downgrade from Pro disables tipping/requests on all owned projects immediately
- [ ] **[NEW]** Downgrade: existing songs above new tier limit remain readable but no new songs addable
- [ ] Update payment method
- [ ] Access Stripe billing portal
- [ ] **[NEW]** Feature gating matches tier (Free: 1 project/20 songs, Basic: 3/200, Pro: unlimited)

### Dashboard

- [ ] Shows all owned projects
- [ ] Displays wallet balance (available, pending, total)
- [ ] Shows payout account setup status
- [ ] Shows account usage/limits
- [ ] Pro billing indicator visible
- [ ] Links to payout onboarding work

### Payout Account (Stripe Express)

- [ ] Onboarding link redirects to Stripe
- [ ] Complete Stripe onboarding flow
- [ ] Refresh onboarding if incomplete
- [ ] Access Stripe Express dashboard
- [ ] Payout status updates (not_started -> pending -> enabled)
- [ ] View payout history (Pro only)

### Project Management

- [ ] Create project (name, slug auto-generated)
- [ ] Edit project name and slug
- [ ] Set performer info URL
- [ ] Set min tip amount
- [ ] Set 3 quick tip preset amounts (whole dollars, descending)
- [ ] Toggle: accepting requests
- [ ] Toggle: accepting tips
- [ ] Toggle: accepting original requests
- [ ] **[NEW]** Toggle: notify_on_request (email notifications for new requests/tips)
- [ ] Upload performer profile image (JPG/PNG, max 5MB)
- [ ] Remove performer image
- [ ] Delete project
- [ ] Unique slug validation
- [ ] **[NEW]** Project limit enforced per tier (Free: 1, Basic: 3, Pro: unlimited)
- [ ] **[NEW]** Exceeding project limit returns `project_limit_reached` error
- [ ] **[NEW]** Set public_repertoire_set_id (filter public page to a specific set)
- [ ] **[NEW]** Clear public_repertoire_set_id (revert to full public repertoire)
- [ ] **[NEW]** Enabling requests/tips without payout setup returns `payout_setup_incomplete`
- [ ] **[NEW]** Basic plan user enabling public requests returns `feature_requires_pro`
- [ ] Toggle: show persistent queue strip

### Project Members

- [ ] List project members
- [ ] Add members (invite existing users)
- [ ] Member roles display correctly (owner/manager/performer)
- [ ] Re-inviting existing member is idempotent

### Repertoire (Songs)

- [ ] Add song (new -- title, artist, metadata)
- [ ] Add existing song from global catalog
- [ ] Edit song metadata (energy, era, genre, theme, key, capo, tuning, duration, BPM)
- [ ] **[NEW]** Genre list includes "Singer/Songwriter" as a distinct genre
- [ ] **[NEW]** Era options: 50s, 60s, 70s, 80s, 90s, 2000s, 2010s, 2020s
- [ ] **[NEW]** Theme strict enum validation (love, party, worship, story, st_patricks, christmas, halloween, patriotic) -- invalid value returns 422
- [ ] Mark song as instrumental
- [ ] **[NEW]** Instrumental display appends " (instrumental)" to title
- [ ] Mark song as mashup
- [ ] **[NEW]** Mashup flag: skips global dedup, no global metadata, red "Mashup" pill
- [ ] **[NEW]** Toggle song public/private visibility (is_public field)
- [ ] **[NEW]** Songs with is_public=false hidden from audience on public request page
- [ ] **[NEW]** Create alternate song version (version_label, e.g. "Acoustic", "Solo")
- [ ] **[NEW]** Unique constraint on (project, song, version_label) -- duplicate returns error
- [ ] **[NEW]** Alternate versions count toward repertoire limit
- [ ] **[NEW]** Public repertoire only shows primary versions (version_label=null)
- [ ] **[NEW]** Project-specific title/artist (project_songs.title/artist) separate from canonical song values
- [ ] **[NEW]** API response includes flat title/artist (project-specific) and nested song object (canonical)
- [ ] Delete song from project
- [ ] Record song performance
- [ ] **[NEW]** Song count limit enforced per tier (Free: 20, Basic: 200, Pro: unlimited)
- [ ] **[NEW]** Exceeding song limit returns `repertoire_limit_reached` error
- [ ] Search/filter repertoire by title/artist
- [ ] Filter by energy level, era, genre, theme
- [ ] View aggregate metadata stats
- [ ] **[NEW]** Demote song to learn list (demote_to_learn field)

### Bulk Import

- [ ] **[NEW]** Phase 1 -- Upload: POST /repertoire/bulk-upload with up to 20 PDFs (2MB each)
- [ ] **[NEW]** Phase 1: Filename metadata parsing (key, capo, tuning, energy, era, genre, theme)
- [ ] **[NEW]** Phase 1: Returns import_status per song (queued/identified/deferred) with import_metadata
- [ ] **[NEW]** Phase 2 -- Enrich: POST /repertoire/bulk-enrich fetches metadata by title+artist
- [ ] **[NEW]** Phase 2: Enrichment sources reported (songs_table/cache/ai) with ai_calls_used count
- [ ] **[NEW]** Phase 3 -- Confirm: POST /repertoire/bulk-import/confirm finalizes with user-reviewed metadata
- [ ] **[NEW]** Phase 3: Per-song action status returned (imported/duplicate/limit_reached/no_match)
- [ ] **[NEW]** Plan-based limits enforced during confirm phase
- [ ] Import songs from image (AI OCR extraction)
- [ ] **[NEW]** Image import: accepts jpeg/png/webp/heic, max 10MB
- [ ] **[NEW]** Image import: duplicates skipped (firstOrCreate semantics)
- [ ] **[NEW]** Image import: respects repertoire limits
- [ ] **[NEW]** Image import: AI usage tracked via AccountUsageService
- [ ] Copy entire repertoire from another project
- [ ] **[NEW]** Copy repertoire: rejects if destination hits plan limits

### Charts (Sheet Music)

- [ ] Upload chart PDF (max 2MB, throttle 5 req/min)
- [ ] **[NEW]** Upload upsert behavior: same PDF -> 200 no change; different PDF -> 200 replace + re-render
- [ ] Chart page rendering completes (PDF -> PNG)
- [ ] View rendered chart pages
- [ ] Check render status endpoint (ready/pending/failed)
- [ ] Download chart via signed URL
- [ ] Get page-level render URLs (light/dark, 15-min TTL)
- [ ] Trigger manual re-render
- [ ] Delete chart
- [ ] Duplicate chart detection
- [ ] **[NEW]** Adopt chart from another user (POST /charts/{chartId}/adopt)
- [ ] **[NEW]** Chart adoption: 409 if adopter already has chart for that song
- [ ] **[NEW]** Chart adoption: 403 if adopter lacks project access
- [ ] Chart annotations -- save per page (requires Idempotency-Key)
- [ ] Chart viewport preferences (zoom/pan) saved per user per page
- [ ] **[NEW]** Cache manifest endpoint (POST /charts/cache-manifest, rate limit 2 req/5 min)
- [ ] **[NEW]** Cache manifest: delta downloads via known_revisions -- unchanged charts excluded
- [ ] **[NEW]** Cache manifest: response includes generated_at, pages with light/dark URLs (30-min TTL)
- [ ] Light/dark theme rendering

### Audio Files

- [ ] Upload audio file to song (MP3 only)
- [ ] **[NEW]** Max file size: 15 MB per file (increased from 10MB)
- [ ] List audio files for a song
- [ ] Play audio via signed URL (60-min TTL)
- [ ] Replace audio file (keeps ID/order/label)
- [ ] Update audio metadata (label, sort_order)
- [ ] Delete audio file (soft delete, reindex sort_order)
- [ ] Max 3 audio files per song enforced
- [ ] **[NEW]** SHA-256 dedup: duplicate upload returns 409
- [ ] **[NEW]** Batch fetch: POST /audio-files/batch with project_song_ids (max 50)
- [ ] **[NEW]** Audio cache manifest: GET /audio-files/manifest returns all files with signed URLs

### Learning Songs (To-Learn List)

- [ ] Add song to learn list
- [ ] Set YouTube URL
- [ ] Set Ultimate Guitar URL
- [ ] **[NEW]** Backend auto-resolves YouTube/UG URLs if omitted
- [ ] Remove song from learn list
- [ ] Update learning song details (notes)

### Setlists

- [ ] Create setlist (name, description, notes)
- [ ] Edit setlist metadata (name, notes, folder)
- [ ] Delete setlist
- [ ] Archive setlist
- [ ] Restore archived setlist
- [ ] Add set to setlist
- [ ] Edit set (name, notes, timing)
- [ ] Delete set
- [ ] Add song to set
- [ ] Bulk add songs to set
- [ ] Import songs from newline-separated text (Title or Title - Artist)
- [ ] Reorder songs within set
- [ ] Update song in set (notes, color_hex #RRGGBB)
- [ ] Remove song from set
- [ ] Generate shareable link (stable share token, share_url, deep_link_url)
- [ ] Accept shared setlist invitation (idempotent per share link + user)
- [ ] **[NEW]** Shared setlist acceptance: auto-creates missing repertoire rows
- [ ] **[NEW]** Shared setlist acceptance: response includes shared_assets (charts + audio with signed URLs)
- [ ] **[NEW]** AI setlist generation: prompt-based (min 3, max 500 chars), 1-10 sets per request
- [ ] **[NEW]** AI setlist generation: songs selected from repertoire only (no invented songs)
- [ ] **[NEW]** AI setlist generation: response includes generation_version, provider, total_sets, total_songs_placed
- [ ] **[NEW]** Extract songs from setlist image (POST /setlists/extract-songs-from-image, max 10MB)
- [ ] **[NEW]** Image extraction: returns songs with title and set_label (AI-detected grouping)
- [ ] **[NEW]** Image extraction: 422 if extraction fails
- [ ] **[NEW]** CSV import with client-side fuzzy matching against repertoire
- [ ] **[NEW]** CSV matching: auto-match (exact 1.00 score, or top >= 0.90 with >= 0.08 margin)
- [ ] **[NEW]** CSV matching: needs review (multiple 1.00 scores, or top >= 0.70 but ambiguous)
- [ ] **[NEW]** CSV matching: unresolved (top < 0.70), top 3 candidates shown
- [ ] Duplicate setlist

### Performance Sessions

- [ ] Start performance session (manual mode)
- [ ] Start performance session (smart mode -- tip-based reordering)
- [ ] Starting while active returns 409
- [ ] Get current session status
- [ ] Mark song as completed/performed (sequential performed_order_index)
- [ ] Skip current song
- [ ] Play random song
- [ ] Smart mode reorders pending items after skip/complete
- [ ] Stop performance session

### Queue & Request Management (Performer Side)

- [ ] View current queue (ordered by tip DESC, then created_at ASC)
- [ ] **[NEW]** Queue supports ETag caching for efficient polling
- [ ] **[NEW]** Queue response includes meta.daily_record_event (nullable, triggers celebration)
- [ ] Manually add request (repertoire song)
- [ ] Manually add request (original song -- requires is_accepting_original_requests)
- [ ] Manually add request (custom song)
- [ ] **[NEW]** Manual items: tip_amount_cents with cents rounded up to whole dollar
- [ ] **[NEW]** Manual items: returned with is_manual=true
- [ ] **[NEW]** Update manual queue item tip (PATCH /queue/{requestId}, only for is_manual=true active items)
- [ ] **[NEW]** Request cards show requester_name (from billing_details.name, null for free/manual)
- [ ] **[NEW]** Request cards show request_location (human-readable geolocation, null if unavailable)
- [ ] Mark request as played (POST /me/requests/{requestId}/played)
- [ ] View request history (paginated, includes custom/original/repertoire/tip-only)
- [ ] Queue polls/refreshes correctly
- [ ] Dismiss tip-only requests

### Cash Tips

- [ ] Record cash tip (amount, date, timezone, note)
- [ ] **[NEW]** Edit cash tip (PATCH /cash-tips/{cashTipId})
- [ ] Delete cash tip
- [ ] List cash tips by date (with optional date filtering and pagination)
- [ ] **[NEW]** Manual queue item tips with payment_provider='none' counted in cash_tip_amount_cents
- [ ] **[NEW]** Cash tips included in stats as cash_tip_amount_cents (separate from digital tips)

### Wallet & Earnings

- [ ] View wallet balance (available, pending, total)
- [ ] View earnings breakdown by session
- [ ] View payout history (Pro only)

### Statistics

- [ ] View project stats (requests, tips, popular songs)
- [ ] Stats timeline selector works (today/week/month/year/lifetime)
- [ ] **[NEW]** Today stats contract (cash_tip_amount_cents visible in today breakdown)

### Public Audience Pages (No Auth)

- [ ] View performer project page
- [ ] Browse public repertoire
- [ ] **[NEW]** Songs with is_public=false are hidden from the public page
- [ ] **[NEW]** When public_repertoire_set_id is set, only songs from that set are shown
- [ ] **[NEW]** Set-specific filtering ignores is_public flags and version_label filters
- [ ] **[NEW]** Instrumental songs display with " (instrumental)" appended
- [ ] Filter songs (theme, era, genre, energy, search)
- [ ] **[NEW]** Theme filter uses strict enum validation (invalid value -> 422)
- [ ] Sort songs (request count, tip amount, title, artist)
- [ ] Pagination (50/page)
- [ ] "Surprise me" random song picker
- [ ] Submit song request (repertoire song)
- [ ] Submit original request
- [ ] Submit tip-only
- [ ] **[NEW]** Tip-only maps to placeholder song "Tip Jar Support" by "Audience"
- [ ] **[NEW]** Original request maps to placeholder "Original Request" by "Audience"
- [ ] Tip amount input (presets and custom)
- [ ] Stripe payment processes correctly
- [ ] Minimum tip enforcement
- [ ] **[NEW]** Tip-disabled mode: tip_amount_cents=0 required, min_tip_cents ignored
- [ ] Queue priority guidance based on tip
- [ ] Request confirmation page displays
- [ ] View current requests in queue (visitor cookie tracking)
- [ ] Queue position polling (60s interval)
- [ ] Public request rate limiting (60/min)
- [ ] "Learn more" page loads

### Admin Features

- [ ] Admin songs page -- search, edit, delete songs
- [ ] Admin access page -- manage user access
- [ ] Song integrity page -- view/fix data issues

### Legal & Marketing Pages

- [ ] Homepage renders
- [ ] Terms page loads
- [ ] Privacy page loads
- [ ] EULA page loads
- [ ] Blog listing page
- [ ] Blog article pages
- [ ] Contact form submits
- [ ] Shared setlist redirect link works

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
- [ ] **[NEW]** Project limit enforced per tier when creating

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
- [ ] **[NEW]** Cash tip amount shown separately from digital tips in stats

### Queue Management

- [ ] View active requests in priority order
- [ ] Request cards show: title, artist, requester, tip, timestamp
- [ ] **[NEW]** Request cards show requester_name (from billing details)
- [ ] **[NEW]** Request cards show request_location (geolocation)
- [ ] **[NEW]** Request cards show is_manual badge for performer-added items
- [ ] Mark request as played
- [ ] Delete request
- [ ] Move request up/down in queue
- [ ] Add manual request (song title/artist, tip amount, requester name, note)
- [ ] **[NEW]** Manual request tip rounded up to whole dollar
- [ ] **[NEW]** Edit manual request tip (PATCH)
- [ ] View queue archive/history
- [ ] Auto-poll every 15 seconds
- [ ] Polling stops when offline
- [ ] New request toast notification
- [ ] Daily record celebration event
- [ ] Dismiss tip-only requests
- [ ] Manual refresh

### Repertoire

- [ ] View song list with metadata (title, artist, performance count, tips)
- [ ] **[NEW]** Project-specific title/artist displayed (not canonical)
- [ ] Search by title/artist
- [ ] Filter by instrument, era, genre, theme, energy
- [ ] Sort by title, requests, tips, performances
- [ ] Multi-select for bulk operations
- [ ] Toggle public/private visibility

### Song Detail

- [ ] View all metadata (title, artist, keys, tuning, capo, duration, etc.)
- [ ] Edit song metadata
- [ ] **[NEW]** Edit project-specific title/artist override
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
- [ ] **[NEW]** Version label displayed for alternate versions

### Add Song

- [ ] Title & artist input
- [ ] **[NEW]** Version label field (e.g. "Acoustic", "Solo", "Electric")
- [ ] Energy level, era, genre, theme selection
- [ ] **[NEW]** Genre list includes "Singer/Songwriter"
- [ ] Instrumental / mashup flags
- [ ] **[NEW]** Public toggle (is_public, default true)
- [ ] Performed & original key pickers
- [ ] Tuning, capo, duration fields
- [ ] MusicBrainz auto-fetch
- [ ] Chart upload (PDF/image file picker)
- [ ] Form validation
- [ ] **[NEW]** Repertoire limit enforcement shown before add

### Image Import

- [ ] Camera capture
- [ ] Gallery photo selection
- [ ] **[NEW]** Accepts jpeg/png/webp/heic, max 10MB
- [ ] OCR processing extracts song list
- [ ] Smart matching to existing repertoire
- [ ] Manual editing before import
- [ ] Bulk song creation
- [ ] **[NEW]** Respects repertoire plan limits
- [ ] **[NEW]** Duplicates skipped automatically

### Bulk Import (PDF)

- [ ] **[NEW]** CSV file picker
- [ ] PDF file picker
- [ ] Drag-and-drop (desktop/tablet)
- [ ] **[NEW]** Phase 1: Upload up to 20 PDFs, 2MB each
- [ ] **[NEW]** Phase 1: Filename metadata parsing (key, capo, tuning, energy, era, genre, theme)
- [ ] **[NEW]** Phase 2: AI enrichment with source tracking (songs_table/cache/ai)
- [ ] **[NEW]** Phase 3: User review and confirm
- [ ] Import progress tracking per song
- [ ] Error display per failed import
- [ ] Duplicate detection
- [ ] Pending review for ambiguous matches
- [ ] Plan-based limits enforced

### Learning Songs

- [ ] View to-learn list
- [ ] Add song to learn
- [ ] Toggle learned/unlearned
- [ ] View YouTube / tabs / MusicBrainz links
- [ ] Open external resources
- [ ] Filter and sort
- [ ] **[NEW]** Demote from repertoire to learn list

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
- [ ] **[NEW]** AI setlist builder: prompt-based generation (min 3, max 500 chars)
- [ ] **[NEW]** AI setlist builder: 1-10 sets per request, named or unnamed
- [ ] **[NEW]** AI setlist builder: only selects from existing repertoire
- [ ] Random song picker
- [ ] Bulk adopt charts from shared source
- [ ] Chart coverage indicator per song
- [ ] **[NEW]** CSV / PDF import with auto-matching (fuzzy scoring UI)
- [ ] **[NEW]** Extract songs from setlist image (camera/gallery, AI vision)
- [ ] **[NEW]** Image extraction returns set_label groupings
- [ ] Launch performance from setlist

### Shared Setlist (Deep Link)

- [ ] Open shared setlist link
- [ ] Accept shared setlist into project
- [ ] Detect already-accepted (idempotent)
- [ ] Chart adoption dialog
- [ ] **[NEW]** Shared assets: charts + audio files with signed URLs offered for download
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
- [ ] **[NEW]** Edit existing cash tip (PATCH)
- [ ] Delete cash tip
- [ ] **[NEW]** Cash tips listed with date filtering and pagination

### Settings

- [ ] Display current user (name, email)
- [ ] Change password link
- [ ] Logout button
- [ ] Project name display
- [ ] Project members list
- [ ] Payout status & dashboard link
- [ ] Toggle: accept requests
- [ ] Toggle: accept tips
- [ ] Toggle: accept original requests
- [ ] **[NEW]** Toggle: email notifications (notify_on_request)
- [ ] Min tip amount setting
- [ ] Quick tip button amounts (3 values, whole dollars, descending)
- [ ] **[NEW]** Primary instrument selector (required)
- [ ] **[NEW]** Secondary instrument selector (optional)
- [ ] Persistent queue strip toggle & position
- [ ] **[NEW]** Public repertoire set selector (public_repertoire_set_id)
- [ ] **[NEW]** Audio cache refresh button (downloads all audio via manifest)

### Offline & Connectivity

- [ ] Offline indicator shown when disconnected
- [ ] Queue polling stops when offline
- [ ] Outbox queues changes locally when offline
- [ ] Outbox syncs when back online
- [ ] Temp ID reconciliation after sync
- [ ] Cached repertoire accessible offline
- [ ] Charts cacheable for offline viewing
- [ ] **[NEW]** Audio files cacheable for offline playback (via manifest)

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
- [ ] **[NEW]** App version policy check (platform-aware: ios/android/macos/windows/linux)
- [ ] **[NEW]** Version comparison: numeric x.y.z first, then latest_build_number
- [ ] Sentry crash reporting active (production)

---

## INTEGRATION TESTS (Cross-App)

- [ ] Audience submits request on web -> appears in mobile queue
- [ ] Audience tips on web -> wallet balance updates on mobile
- [ ] Performer marks request played on mobile -> queue updates on web
- [ ] Shared setlist link from web opens correctly in mobile deep link
- [ ] Chart uploaded on web -> viewable on mobile
- [ ] Song added on mobile -> appears in web repertoire
- [ ] **[NEW]** Song with project-specific title/artist on mobile -> correct title shown on web
- [ ] Project settings changed on web -> reflected on mobile after refresh
- [ ] Cash tip logged on mobile -> appears in web stats
- [ ] **[NEW]** Cash tip edited on mobile -> updated amount reflected in web stats
- [ ] Performance session started on mobile -> stats update on web dashboard
- [ ] Stripe webhook processes payment -> request status updates both apps
- [ ] **[NEW]** Bulk import started on web -> songs visible on mobile after confirm phase
- [ ] **[NEW]** Chart adopted on mobile -> available in web chart viewer
- [ ] **[NEW]** Public repertoire set filter on web -> audience sees only set songs
- [ ] **[NEW]** Song marked private on mobile -> hidden from audience on web
- [ ] **[NEW]** Manual queue item added on mobile -> shows is_manual flag, tip in cash stats

---

## API-SPECIFIC VALIDATIONS

### Rate Limits & Throttling

- [ ] Chart upload: 5 requests/min per user
- [ ] Cache manifest: 2 requests/5 min
- [ ] Public requests: 60/min
- [ ] AI interactive: Free/Basic 10/min, Pro 30/min
- [ ] Login: 5 attempts/min

### Plan Enforcement Matrix

| Capability | Free | Basic | Pro |
|---|---|---|---|
| [ ] Project limit | 1 | 3 | unlimited |
| [ ] Repertoire song limit | 20 | 200 | unlimited |
| [ ] Public requests | no | no | yes |
| [ ] Queue access | no | no | yes |
| [ ] Request history | no | no | yes |
| [ ] Owner stats | no | no | yes |
| [ ] Wallet view | no | no | yes |
| [ ] Invite members | no | no | yes |

### Error Code Spot-Checks

- [ ] `repertoire_limit_reached` when adding song past plan limit
- [ ] `project_limit_reached` when creating project past plan limit
- [ ] `feature_requires_pro` when Basic user enables public features
- [ ] `payout_setup_incomplete` when enabling tips/requests without payout
- [ ] 409 on duplicate chart adoption
- [ ] 409 on duplicate audio file upload (SHA-256 match)
- [ ] 409 on starting second active performance session
- [ ] 422 on invalid theme enum value
- [ ] 422 on setlist image extraction failure

---

## Notes

\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

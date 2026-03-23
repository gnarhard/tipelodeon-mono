# Song Tipper Architecture & Features Documentation

**Version:** 1.0
**Last Updated:** February 15, 2026

## Table of Contents

1. [Overview](#overview)
2. [Repository Structure](#repository-structure)
3. [Technology Stack](#technology-stack)
4. [Architecture Overview](#architecture-overview)
5. [Core Domain Models](#core-domain-models)
6. [API Architecture](#api-architecture)
7. [Authentication & Authorization](#authentication--authorization)
8. [Frontend Architecture (Flutter)](#frontend-architecture-flutter)
9. [Key Features](#key-features)
10. [Data Flow Patterns](#data-flow-patterns)
11. [Storage & Media Management](#storage--media-management)
12. [Background Jobs & Async Processing](#background-jobs--async-processing)
13. [Offline-First Strategy](#offline-first-strategy)
14. [Payment Integration](#payment-integration)
15. [Audience Request Experience](#audience-request-experience)
16. [Development Workflow](#development-workflow)
17. [Scaling & Performance Considerations](#scaling--performance-considerations)

---

## Overview

**Song Tipper** is a performer-focused application that helps musicians manage their performances, repertoire, and audience interactions in real-time. The platform bridges the gap between performers and their audience through song requests, tips, and live queue management. The mobile application offers a robust and highly optimized way of uploading and manipulating repertoire and associated charts through the use of AI.

### What Problem Does It Solve?

- **For Performers:**
  - Manage song repertoire with detailed metadata (keys, capo, tuning, energy levels, theme)
  - Organize setlists for performances
  - Handle audience song requests with monetary tips
  - Store and annotate charts (sheet music PDFs)
  - Track performance history and analytics
  - Monetize performances through integrated payments

- **For Audiences:**
  - Browse performer's available song repertoire
  - Request songs with optional tips
  - See live queue of upcoming requests
  - Support performers directly without song requests (tip jar)
  - Use a private browser-based identity for repeat requests without public profiles

### Core Value Proposition

Song Tipper consolidates the tedious, fragmented workflows of managing a live act into a single tool. It handles chart and repertoire management, setlist creation, band synchronization, audience requests, and integrated tipping.

---

## Repository Structure

Song Tipper is an umbrella workspace with three Git repositories:

1. Root workspace/meta repo (`songtipper-mono`)
2. Backend repo (`web/`)
3. Mobile repo (`mobile_app/`)

`web/` and `mobile_app/` are nested repositories inside the workspace root.

```
Song Tipper/                         # Workspace root (songtipper-mono)
├── _shared/                         # API contracts & documentation (source of truth)
│   ├── contracts/                   # Endpoint specifications
│   │   └── setlists.md
│   ├── api-contract-rules.md        # API design principles
│   └── audience-achievements.md     # Retired audience gamification notes
│
├── web/                             # Laravel backend (songtipper_web repo)
│   ├── app/
│   │   ├── Models/                  # Eloquent models
│   │   ├── Http/Controllers/Api/    # API controllers
│   │   ├── Http/Requests/           # Form request validation
│   │   ├── Http/Resources/          # API transformers
│   │   ├── Policies/                # Authorization policies
│   │   ├── Jobs/                    # Background jobs
│   │   └── Enums/                   # Type-safe enums
│   ├── database/migrations/         # Database schema
│   ├── routes/
│   │   ├── api.php                  # API routes
│   │   └── web.php                  # Web routes (audience pages)
│   └── config/                      # Laravel configuration
│
└── mobile_app/                      # Flutter app (songtipper_mobile_app repo)
    ├── lib/
    │   ├── main.dart                # App entry point
    │   ├── core/                    # Core infrastructure
    │   │   ├── http/                # API client
    │   │   ├── storage/             # Local data persistence
    │   │   ├── cache/               # Caching layer
    │   │   ├── outbox/              # Offline write queue
    │   │   ├── connectivity/        # Network monitoring
    │   │   ├── routing/             # Navigation
    │   │   └── theme/               # UI theming
    │   ├── features/                # Feature modules
    │   │   ├── auth/                # Authentication
    │   │   ├── context/             # Project context management
    │   │   ├── repertoire/          # Song management
    │   │   ├── queue/               # Request queue
    │   │   ├── charts/              # PDF charts & annotations
    │   │   ├── setlists/            # Setlist building
    │   │   ├── bulk_import/         # Bulk chart imports
    │   │   └── settings/            # App settings
    │   └── di/                      # Dependency injection (GetIt)
    └── test/                        # Unit & widget tests
```

### Repository Remotes

- **Monorepo:** `git@github.com:gnarhard/songtipper-mono.git`
- **Web:** `git@github.com:gnarhard/songtipper_web.git`
- **Mobile App:** `git@github.com:gnarhard/songtipper_mobile_app.git`

### Git Worktree Workflow

All development happens from a workspace worktree under the main project checkout (`./songtipper-worktrees/<track_id>`). Run `./scripts/create-worktree <track_id>` from the main `songtipper/` checkout, never by manually typing `git worktree add`, and never by creating a sibling `../songtipper-worktrees` directory. Inside that worktree, `web/` and `mobile_app/` keep their own Git histories/remotes.

```bash
# From the main songtipper checkout, create a workspace worktree
repo_root="$(pwd -P)"
worktree_path="$(./scripts/create-worktree feature-name)"

# Work in the workspace worktree
cd "$worktree_path"
pwd  # Must resolve under /songtipper-worktrees/

# Commit and open PRs per repo (root, web, mobile_app) as needed.
# Then clean up:
cd "$repo_root"
git worktree remove "$worktree_path"
```

---

## Technology Stack

### Backend (Web)

| Component | Technology | Purpose |
|-----------|-----------|---------|
| **Framework** | Laravel 12 | PHP web framework |
| **PHP Version** | 8.5+ | Server-side language |
| **Database** | MySQL 8.0+ | Primary data store |
| **Cache** | Redis | Session storage, queue, cache |
| **Authentication** | Laravel Sanctum | Token-based API auth |
| **Payments** | Stripe | Payment processing |
| **File Storage** | Cloudflare R2 (S3-compatible) | Chart PDFs and renders |
| **Queue System** | Laravel Queues (Redis) | Background job processing |
| **AI/ML** | Google Gemini | Song metadata enrichment |
| **PDF Rendering** | Custom job (ImageMagick/similar) | PDF to image conversion |

### Frontend (Mobile App)

| Component | Technology | Purpose |
|-----------|-----------|---------|
| **Framework** | Flutter 3.41+ | Cross-platform mobile framework |
| **Language** | Dart 3.10.7+ | Programming language |
| **State Management** | GetIt (Service Locator) | Dependency injection |
| **Routing** | GoRouter 17.1.0 | Declarative routing |
| **HTTP Client** | http 1.2.2 | API communication |
| **Local Storage** | Hive CE 2.10.1 | NoSQL embedded database |
| **Secure Storage** | flutter_secure_storage | Encrypted credential storage |
| **Connectivity** | connectivity_plus | Network status monitoring |
| **Error Tracking** | Sentry Flutter | Crash reporting |
| **Fonts** | Google Fonts | Typography |

### Infrastructure

- **Hosting:** Laravel Valet (local), Forge (production)
- **CDN:** Cloudflare (R2 storage)
- **Version Control:** Git, GitHub
- **CI/CD:** GitHub Actions (inferred from structure)

---

## Architecture Overview

Song Tipper follows a **client-server architecture** with clear separation of concerns:

```
┌─────────────────────────────────────────────────────────────┐
│                         AUDIENCE                            │
│                    (Web Browser)                      │
│                                                             │
│   - Browse Repertoire                                       │
│   - Submit Requests                                         │
│   - Make Payments                                           │
│   - View Live Queue                                         │
└──────────────────────────┬──────────────────────────────────┘
                           │
                           │ HTTPS (Web Routes + Public API)
                           │
┌──────────────────────────▼──────────────────────────────────┐
│                                                             │
│                    LARAVEL BACKEND                          │
│                   (API + Web Server)                        │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │            RESTful API (v1)                         │   │
│  │  - Authentication (Sanctum)                         │   │
│  │  - Project Management                               │   │
│  │  - Repertoire CRUD                                  │   │
│  │  - Queue Management                                 │   │
│  │  - Chart Upload & Retrieval                        │   │
│  │  - Setlist Management                              │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │         Background Jobs (Laravel Queues)            │   │
│  │  - Chart PDF → Image Rendering                     │   │
│  │  - Gemini Song Identification                      │   │
│  │  - Payment Webhook Processing                      │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │              Database (MySQL)                       │   │
│  │  - Users, Projects, Songs                          │   │
│  │  - Repertoire, Charts, Requests                    │   │
│  │  - Setlists, Performances                          │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │          Storage (Cloudflare R2)                    │   │
│  │  - Chart PDFs                                       │   │
│  │  - Rendered Page Images (light/dark themes)        │   │
│  │  - Profile Images                                   │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
└──────────────────────────┬──────────────────────────────────┘
                           │
                           │ HTTPS (Authenticated API)
                           │
┌──────────────────────────▼──────────────────────────────────┐
│                                                             │
│                  FLUTTER MOBILE APP                         │
│                  (iOS & Android)                            │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │         Presentation Layer (UI)                     │   │
│  │  - Screens & Widgets                                │   │
│  │  - Theme & Styling                                  │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │       Business Logic (Controllers)                  │   │
│  │  - AuthController                                   │   │
│  │  - ContextController (Project selection)            │   │
│  │  - RepertoireController                             │   │
│  │  - QueueController (polling)                        │   │
│  │  - ChartsController                                 │   │
│  │  - SetlistsController                               │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │         Data Layer (Services & Storage)             │   │
│  │  - API Client (HTTP)                                │   │
│  │  - Local Cache (Hive)                               │   │
│  │  - Outbox (Offline Write Queue)                     │   │
│  │  - Connectivity Service                             │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Key Architectural Principles

1. **API-First Design:** All functionality exposed through versioned REST API (`/api/v1`)
2. **Offline-First Mobile:** Flutter app caches data locally and queues writes when offline
3. **Project-Scoped Authorization:** All data is scoped to projects (bands/solo acts)
4. **Contract-Driven Development:** `_shared/` directory defines the contract between backend and frontend
5. **Idempotency:** Critical operations (chart uploads, annotations) are idempotent to handle retries
6. **Eventual Consistency:** Mobile app syncs with backend when online

---

## Core Domain Models

### Entity Relationship Overview

```
┌──────────┐         ┌─────────────┐         ┌──────────┐
│   User   │────────▶│   Project   │◀────────│  Song    │
└──────────┘   owns  └─────────────┘         └──────────┘
                             │                      │
                             │                      │
                     ┌───────┴───────┐             │
                     │               │             │
                     ▼               ▼             │
              ┌────────────┐   ┌─────────┐        │
              │  Request   │   │ Setlist │        │
              └────────────┘   └─────────┘        │
                     │               │             │
                     │               ▼             │
                     │         ┌──────────┐       │
                     │         │   Set    │       │
                     │         └──────────┘       │
                     │               │             │
                     │               ▼             │
                     │      ┌──────────────┐      │
                     │      │SetlistSong   │      │
                     │      └──────────────┘      │
                     │                             │
                     └──────────┬──────────────────┘
                                │
                                ▼
                        ┌──────────────┐
                        │ ProjectSong  │
                        └──────────────┘
                                │
                                ▼
                        ┌──────────────┐
                        │    Chart     │
                        └──────────────┘
                                │
                    ┌───────────┴───────────┐
                    ▼                       ▼
            ┌──────────────┐      ┌─────────────────┐
            │ ChartRender  │      │ AnnotationVers. │
            └──────────────┘      └─────────────────┘
```

### User

The performer/musician account.

**Fields:**
- `id` (PK)
- `name` - Display name
- `email` - Unique email for login
- `password` - Hashed password
- `created_at`, `updated_at`

**Relationships:**
- **Owns** many Projects
- **Member of** many Projects (via `project_members`)

### Project

A band, solo act, or performance context. All data is scoped to a project.

**Fields:**
- `id` (PK)
- `owner_user_id` (FK to User)
- `name` - Project name (e.g., "Friday Jazz Night")
- `slug` - URL-friendly identifier (unique)
- `performer_info_url` - Link to performer website
- `performer_profile_image_path` - S3/R2 path to profile image
- `min_tip_cents` - Minimum tip amount (default: 500 = $5, writes round up to whole dollars)
- `is_accepting_requests` - Toggle request queue on/off
- `is_accepting_original_requests` - Allow "play an original" requests
- `show_persistent_queue_strip` - UI preference for audience
- `chart_viewport_prefs` - deprecated; superseded by per-user chart page prefs table
- `created_at`, `updated_at`

**Relationships:**
- **Owned by** one User
- **Has** many ProjectMembers (collaborators)
- **Has** many ProjectSongs (repertoire)
- **Has** many Requests (queue)
- **Has** many Charts
- **Has** many Setlists

**Access Control:**
- Owner has full permissions
- Members can view/edit repertoire, queue, charts (permission model can be extended)

### Song

Global song catalog. Songs are deduplicated by normalized title+artist.

**Fields:**
- `id` (PK)
- `title` - Song title
- `artist` - Artist/band name
- `normalized_key` - Deduplication key (lowercase, no special chars)
- `energy_level` - Enum: `low`, `medium`, `high` (global default)
- `era` - e.g., "60s", "90s", "2020s"
- `theme` - e.g., "love", "party", etc.
- `genre` - e.g., "Jazz", "Rock", "Pop" (global default)
- `original_musical_key` - e.g., "C", "F#m"
- `duration_in_seconds` - Song length
- `created_at`, `updated_at`

**Special Songs:**
- "Original Request" + "Audience" - placeholder for original song requests
- "Tip Jar Support" + "Audience" - placeholder for tip-only submissions

**Relationships:**
- **Has** many ProjectSongs (project-specific overrides)
- **Has** many Charts
- **Has** many Requests

### ProjectSong

Project-specific repertoire entry. Allows per-project overrides of song metadata.

**Fields:**
- `id` (PK)
- `project_id` (FK to Project)
- `song_id` (FK to Song)
- `energy_level` - Project override (nullable, falls back to Song)
- `era` - Inherited or overridden
- `genre` - Project override (nullable, falls back to Song)
- `performed_musical_key` - Key the performer plays it in
- `tuning` - Guitar tuning (e.g., "Drop D", "Standard")
- `capo` - Capo position (0-12)
- `needs_improvement` - Flag for practice tracking
- `performance_count` - Number of times performed
- `last_performed_at` - Timestamp of last performance
- `created_at`, `updated_at`

**Unique Constraint:** `(project_id, song_id)`

**Relationships:**
- **Belongs to** Project
- **Belongs to** Song
- **Has** many Charts (via song_id + project_id)
- **Has** many SongPerformances

### Chart

PDF sheet music / chord charts uploaded by performers.

**Fields:**
- `id` (PK)
- `owner_user_id` (FK to User)
- `song_id` (FK to Song, required)
- `project_id` (FK to Project, required)
- `storage_disk` - Storage driver (default: "r2")
- `storage_path_pdf` - Path to source PDF in storage
- `original_filename` - Original upload filename
- `page_count` - Number of pages in PDF
- `has_renders` - Boolean, true when page images exist
- `created_at`, `updated_at`

**Unique Constraint:** `(owner_user_id, project_id, song_id)` (one chart per song per project per performer)

**Relationships:**
- **Owned by** User
- **Belongs to** Song
- **Belongs to** Project
- **Has** many ChartRenders (light/dark theme images)
- **Has** many ChartAnnotationVersions

### ChartRender

Pre-rendered PNG images of chart pages for fast mobile viewing.

**Fields:**
- `id` (PK)
- `chart_id` (FK to Chart)
- `page_number` - 1-based page index
- `theme` - "light" or "dark"
- `storage_path_image` - Path to PNG in storage
- `width`, `height` - Image dimensions
- `created_at`, `updated_at`

**Unique Constraint:** `(chart_id, page_number, theme)`

### ChartAnnotationVersion

Stores the latest saved drawing annotation on a chart page.

**Fields:**
- `id` (PK)
- `chart_id` (FK to Chart)
- `page_number` - Which page
- `local_version_id` - UUID from client (idempotent writes)
- `strokes` - JSON array of drawing strokes
- `client_created_at` - Client timestamp
- `created_at`, `updated_at`

**Constraint:** One row per `(owner_user_id, chart_id, page_number)`

**Write policy:** Last write wins for the saved annotation state.

### Request

Audience song request with optional tip.

**Fields:**
- `id` (PK)
- `project_id` (FK to Project)
- `song_id` (FK to Song)
- `tip_amount_cents` - Tip in cents (can be 0)
- `score_cents` - Used for queue ordering (currently same as tip)
- `note` - Optional message from requester
- `status` - Enum: `pending`, `active`, `played`
- `requested_from_ip` - IP address for fraud detection
- `payment_provider` - "stripe"
- `payment_intent_id` - Stripe Payment Intent ID
- `played_at` - Timestamp when marked as played
- `created_at`, `updated_at`

**Queue Ordering:** `ORDER BY tip_amount_cents DESC, created_at ASC`

**Lifecycle:**
1. **Pending:** Created, payment not yet confirmed
2. **Active:** Payment confirmed, in queue
3. **Played:** Marked as completed by performer

**Relationships:**
- **Belongs to** Project
- **Belongs to** Song

### Setlist

Organized song list for a performance.

**Fields:**
- `id` (PK)
- `project_id` (FK to Project)
- `name` - Setlist name (e.g., "Friday Night Set")
- `created_at`, `updated_at`

**Relationships:**
- **Belongs to** Project
- **Has** many SetlistSets (ordered groups)

### SetlistSet

A set within a setlist (e.g., "Set 1", "Set 2", "Encore").

**Fields:**
- `id` (PK)
- `setlist_id` (FK to Setlist)
- `name` - Set name
- `order_index` - Position in setlist
- `created_at`, `updated_at`

**Relationships:**
- **Belongs to** Setlist
- **Has** many SetlistSongs (ordered songs)

### SetlistSong

A song within a set.

**Fields:**
- `id` (PK)
- `set_id` (FK to SetlistSet)
- `project_song_id` (FK to ProjectSong)
- `order_index` - Position in set
- `created_at`, `updated_at`

**Unique Constraint:** A song cannot appear twice in the same set.

**Relationships:**
- **Belongs to** SetlistSet
- **Belongs to** ProjectSong

### SongPerformance

Tracks when a song was performed (for analytics).

**Fields:**
- `id` (PK)
- `project_song_id` (FK to ProjectSong)
- `performed_at` - Timestamp
- `source` - Enum: `repertoire`, `setlist`
- `setlist_id`, `set_id`, `setlist_song_id` - Context when source = setlist
- `created_at`, `updated_at`

**Relationships:**
- **Belongs to** ProjectSong

### AudienceProfile

Tracks audience member identity via cookie token.

**Fields:**
- `id` (PK)
- `project_id` (FK to Project)
- `token` - Long-lived cookie value
- `created_at`, `updated_at`

### AudienceAchievement (legacy)

Historical record of retired audience achievements. No public audience flow, profile, or leaderboard depends on this model now.

**Fields:**
- `id` (PK)
- `audience_profile_id` (FK to AudienceProfile)
- `project_id` (FK to Project)
- `achievement_type` - historical code, e.g., legacy award labels
- `awarded_at`
- `created_at`, `updated_at`

---

## API Architecture

Song Tipper exposes a **RESTful JSON API** at `/api/v1`.

### Design Principles

1. **Versioned:** All endpoints under `/api/v1` for future compatibility
2. **Consistent Response Shape:**
   - Success: `{ "data": ..., "meta": { ... } }`
   - Error: `{ "message": "...", "errors": { "field": ["..."] } }`
3. **Snake Case:** JSON keys use `snake_case` (mapped to `camelCase` in Dart)
4. **ISO 8601 Dates:** All timestamps in UTC with timezone offset
5. **Pagination:** Laravel's default paginator with `data`, `links`, `meta`
6. **ETag Caching:** Queue endpoint supports `If-None-Match` for efficient polling
7. **Idempotency:** Critical operations (annotations, bulk imports) use client-provided UUIDs
8. **Atomic Operations:** Complex writes wrapped in database transactions

### API Structure

```
/api/v1
├── /auth
│   ├── POST /login                     # Get access token
│   ├── POST /logout                    # Revoke token
│   ├── POST /forgot-password           # Request reset
│   ├── POST /reset-password            # Reset password
│   └── PUT  /password                  # Update password
│
├── /public/projects/{slug}             # Audience-facing (no auth)
│   ├── GET  /repertoire                # Browse songs
│   └── POST /requests                  # Submit request + payment
│
├── /me                                 # Authenticated performer
│   ├── GET  /projects                  # List projects
│   ├── POST /projects                  # Create project
│   ├── PUT  /projects/{id}             # Update project
│   ├── POST /projects/{id}/performer-image  # Upload profile image
│   │
│   ├── /projects/{id}
│   │   ├── GET  /queue                 # Active request queue (ETag)
│   │   ├── POST /queue                 # Manually add to queue
│   │   ├── GET  /requests/history      # Played requests
│   │   │
│   │   ├── GET    /repertoire          # List repertoire
│   │   ├── POST   /repertoire          # Add song
│   │   ├── GET    /repertoire/metadata # Fetch metadata suggestions
│   │   ├── PUT    /repertoire/{id}     # Update song
│   │   ├── DELETE /repertoire/{id}     # Remove song
│   │   ├── POST   /repertoire/{id}/performances  # Log performance
│   │   ├── POST   /repertoire/bulk-import        # Bulk repertoire import
│   │   │
│   │   ├── GET    /setlists            # List setlists
│   │   ├── POST   /setlists            # Create setlist
│   │   ├── GET    /setlists/{id}       # Get setlist detail
│   │   ├── PUT    /setlists/{id}       # Update setlist
│   │   ├── DELETE /setlists/{id}       # Delete setlist
│   │   │
│   │   └── /setlists/{setlist_id}
│   │       ├── POST   /sets            # Create set
│   │       ├── PUT    /sets/{id}       # Update set
│   │       ├── DELETE /sets/{id}       # Delete set
│   │       │
│   │       └── /sets/{set_id}
│   │           ├── POST   /songs       # Add song to set
│   │           ├── POST   /songs/bulk  # Bulk add songs
│   │           ├── DELETE /songs/{id}  # Remove song
│   │           └── PUT    /songs/reorder # Reorder songs
│   │
│   ├── POST   /requests/{id}/played    # Mark request as played
│   │
│   └── /charts
│       ├── GET    /                    # List charts
│       ├── POST   /                    # Upload chart
│       ├── GET    /{id}                # Get chart metadata
│       ├── GET    /{id}/signed-url     # Download PDF (temporary URL)
│       ├── GET    /{id}/page?page=1&theme=light  # Get page image URL
│       ├── POST   /{id}/render         # Trigger background render
│       ├── POST   /{id}/pages/{page}/annotations  # Save annotations
│       └── DELETE /{id}                # Delete chart
│
└── /webhooks
    └── POST /stripe                    # Stripe webhook handler
```

### Authentication Flow

1. **Login:** `POST /api/v1/auth/login` with email/password
2. **Response:** Token + user + projects (access bundle)
3. **Subsequent Requests:** `Authorization: Bearer {token}` header
4. **Token Type:** Laravel Sanctum personal access token
5. **Logout:** `POST /api/v1/auth/logout` revokes token

### Authorization Scoping

All authenticated endpoints enforce:
- **User ownership:** User must own or be a member of the project
- **Project scoping:** Data is filtered by active project context
- **Policy-based:** Laravel policies check permissions (e.g., `ProjectPolicy::update`)

---

## Authentication & Authorization

### Authentication

**Technology:** Laravel Sanctum (token-based)

**Flow:**
1. User logs in with email + password
2. Server validates credentials
3. Server creates a Sanctum personal access token
4. Token returned to client with user info and project access bundle
5. Client stores token securely (Flutter: `flutter_secure_storage`)
6. Client sends `Authorization: Bearer {token}` on all API requests
7. Laravel middleware `auth:sanctum` validates token

**Token Characteristics:**
- **Format:** Plaintext token (hashed in database)
- **Lifetime:** No expiration (can be revoked)
- **Scope:** User-level (not project-specific)
- **Storage:** `personal_access_tokens` table

### Authorization

**Model:** Project-based multi-tenancy

**Roles:**
- **Owner:** Created the project, full permissions
- **Member:** Added by owner, can manage repertoire, queue, charts
- **Readonly:** (Future) View-only access

**Permission Checks:**
- **Policy Classes:** `ProjectPolicy`, `ChartPolicy`, etc.
- **Middleware:** Routes scoped to `me/projects/{project}`
- **Query Scoping:** Controllers filter by `project_id`

**Example Authorization Chain:**
```
Request: PUT /api/v1/me/projects/123/repertoire/456
  ↓
1. auth:sanctum middleware → validates token
2. Route binding → loads Project(123), ProjectSong(456)
3. Policy check → ProjectPolicy::update(user, project)
4. Controller check → projectSong->project_id === project->id
5. Authorized ✓
```

---

## Frontend Architecture (Flutter)

### Architecture Pattern

**Service Locator + Controller Pattern**

- **Dependency Injection:** `GetIt` for registering singletons and factories
- **Controllers:** Business logic and state management
- **Services:** Infrastructure (HTTP, storage, connectivity)
- **Repositories:** Data access abstraction (if needed)
- **UI Layer:** Stateful/Stateless widgets consume controllers

### Initialization Flow

```dart
void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Initialize DI container
  await initializeDependencies();

  // Initialize core services
  await _initializeServices();

  runApp(const SongTipperApp());
}

Future<void> _initializeServices() async {
  final connectivity = getIt<ConnectivityService>();
  await connectivity.initialize();

  final authController = getIt<AuthController>();
  await authController.initialize();

  final contextController = getIt<ContextController>();
  await contextController.initialize();

  // Start background polling/sync
  final queueController = getIt<QueueController>();
  queueController.startPolling();

  final outboxProcessor = getIt<OutboxProcessor>();
  outboxProcessor.start();
}
```

### Core Services

#### ConnectivityService

Monitors network status and emits events when online/offline.

**Responsibilities:**
- Listen to `connectivity_plus` stream
- Expose `isOnline` boolean
- Trigger sync when connectivity restored

#### AuthController

Manages authentication state.

**State:**
- `isAuthenticated` - Boolean
- `currentUser` - User model
- `accessToken` - Sanctum token

**Methods:**
- `login(email, password)` - Authenticate
- `logout()` - Revoke token and clear state
- `initialize()` - Restore from secure storage

#### ContextController

Manages active project selection.

**State:**
- `currentProject` - Selected project
- `availableProjects` - User's projects

**Methods:**
- `switchProject(projectId)` - Change active project
- `refreshProjects()` - Reload from API

#### QueueController

Manages the request queue with ETag-based polling.

**State:**
- `activeRequests` - List of active queue items
- `lastEtag` - ETag for conditional requests

**Methods:**
- `startPolling()` - Begin 5-second interval polling
- `stopPolling()` - Stop polling
- `fetchQueue()` - GET with `If-None-Match` header
- `markPlayed(requestId)` - Move request to history

#### RepertoireController

Manages song repertoire with local caching.

**State:**
- `songs` - Cached repertoire list
- `isLoading` - Loading state

**Methods:**
- `fetchRepertoire()` - Load from API
- `addSong(song)` - Add to repertoire
- `updateSong(songId, updates)` - Update metadata
- `deleteSong(songId)` - Remove from repertoire
- `bulkImport(files)` - Upload multiple PDFs

#### ChartsController

Manages chart uploads, downloads, and caching.

**State:**
- `charts` - List of chart metadata
- `cachedPages` - Map of page images in cache

**Methods:**
- `uploadChart(file, songId, projectId)` - Upload PDF
- `getChartPage(chartId, page, theme)` - Fetch rendered page
- `downloadChart(chartId)` - Get signed URL for PDF
- `deleteChart(chartId)` - Remove chart

#### OutboxProcessor

Offline-first write queue.

**Responsibilities:**
- Queue write operations when offline
- Retry failed requests when online
- Maintain FIFO order
- Store in Hive for persistence

**Storage Schema:**
```dart
class OutboxEntry {
  String id;          // UUID
  String method;      // POST, PUT, DELETE
  String endpoint;    // /api/v1/...
  Map<String, dynamic> body;
  int retryCount;
  DateTime createdAt;
}
```

### Local Storage (Hive)

**Boxes:**
- `authBox` - Stores token, user JSON
- `cacheBox` - Stores repertoire, projects, queue
- `outboxBox` - Offline write queue
- `chartCacheBox` - Downloaded page images

**Data Flow:**
```
API Response → Controller → Hive Cache → UI
                             ↓
                    (survives app restart)
```

### Routing (GoRouter)

Declarative routing with deep linking support.

**Route Structure:**
```
/                     → Splash / Auto-login
/login                → Login screen
/home                 → Main shell (bottom nav)
  /repertoire         → Repertoire list
  /queue              → Request queue
  /charts             → Charts library
  /setlists           → Setlist builder
/chart/:chartId       → Chart viewer with annotations
/settings             → App settings
```

**Guards:**
- Unauthenticated users redirect to `/login`
- No project selected redirects to project picker

### Theme System

**Light/Dark Mode:** System-detected, supports manual override

**Custom Theme:**
- **Background:** Animated wave gradient (`WaveBackground`)
- **Fonts:** Google Fonts
- **Colors:** Material Design 3 color scheme

### Layout Width Constraint (Required)

All mobile app presentation surfaces must align content to
`AppTheme.contentMaxWidth`.

**Scope (required):**
- Headers and app bars
- Screen body content
- Footer content and bottom action areas
- Navigation surfaces (bottom navigation bars, top navigation rows, and
   similar shell navigation containers)

**Rule:**
- Do not hardcode ad-hoc max widths for primary screen layout containers.
- Use `AppTheme.contentMaxWidth` as the single source of truth.
- Keep decorative/background layers full-bleed only when needed, but place all
   interactive/readable content inside a container constrained to
   `AppTheme.contentMaxWidth`.

**Implementation guidance:**
- App bars and header content should use the shared max-width app bar pattern.
- Bodies should be wrapped in a centered constrained container using
   `AppTheme.contentMaxWidth`.
- Bottom and navigation sections should also center and constrain their content
   to `AppTheme.contentMaxWidth`.

---

## Key Features

### 1. Repertoire Management

**Purpose:** Organize and track all songs a performer can play.

**Capabilities:**
- **Add Songs:** By title/artist (with Gemini metadata enrichment)
- **Metadata Overrides:** Per-project energy, genre, key, capo, tuning
- **Performance Tracking:** Count and last performed date
- **Practice Flags:** Mark songs needing improvement
- **Filtering/Sorting:** By title, artist, energy, genre, era
- **Bulk Import:** Upload PDFs with filename parsing or Gemini identification

**Mobile UX:**
- Swipe to edit/delete
- Long-press for quick actions
- Search with debounced API calls

### 2. Chart Management

**Purpose:** Store and annotate sheet music PDFs.

**Capabilities:**
- **Upload PDFs:** Up to 2MB per chart
- **Automatic Rendering:** Background job converts PDF → PNG (light/dark themes)
- **Annotations:** Draw on charts with strokes, colors, thickness, eraser
- **Offline Annotations:** Stored locally with UUID-based versioning
- **Zoom/Pan Preferences:** Per-chart, per-page viewport settings
- **Chart Linking:** Associate charts with songs and projects

**Mobile UX:**
- Chart viewer with zoom and reposition
- Drawing toolbar (color: green, red, blue; eraser; undo and redo)
- Auto-save annotations every 5 seconds
- Offline mode: annotations queued for sync

**Backend Processing:**
1. User uploads PDF
2. Stored in R2 with unique path
3. Background job (`RenderChartPages`) extracts pages
4. ImageMagick (or similar) converts PDF pages → PNG
5. Generates light and dark theme versions
6. Stores in R2, updates `chart_renders` table

### 3. Setlist Builder

**Purpose:** Organize songs into performance sets.

**Capabilities:**
- **Create Setlists:** Named collections
- **Add Sets:** "Set 1", "Set 2", "Encore"
- **Add Songs to Sets:** Drag-and-drop reordering
- **Bulk Add:** Select multiple songs from repertoire
- **Reorder:** Within sets and across sets
- **Performance Logging:** Mark songs as performed from setlist context

**Mobile UX:**
- Nested list: Setlist → Sets → Songs
- Drag handles for reordering
- Quick add from repertoire sheet

### 4. Request Queue

**Purpose:** Real-time audience request management.

**Capabilities:**
- **Audience Requests:** Public endpoint for song requests with tips
- **Manual Adds:** Performers can add custom/original requests
- **Queue Ordering:** Highest tip first, then FIFO
- **Mark Played:** Move to history
- **ETag Polling:** Efficient 5-second polling with 304 responses
- **History:** View all played requests

**Mobile UX:**
- Live updating list
- Swipe to mark as played
- Pull-to-refresh
- Tip amounts prominently displayed

### 5. Bulk Chart Import

**Purpose:** Onboard large repertoires quickly.

**Capabilities:**
- **Filename Parsing:** `Song Title - Artist -- key=C -- capo=2.pdf`
- **Gemini Identification:** If filename is unclear, AI identifies song from chart image
- **Batch Processing:** Mobile app sends up to 20 files per request
- **Chunking:** Mobile app chunks selections into 20 files/request (2MB each)
- **Duplicate Detection:** Byte-identical PDFs skipped
- **Progress Tracking:** UI shows imported/queued/failed counts

**Mobile UX:**
- File picker with multi-select
- Progress bar with per-file status
- Failed files can be retried
- Tab interface: Pending / Imported / Failed

### 6. Audience Public Pages

**Purpose:** Allow audiences to browse repertoire and submit requests.

**Capabilities:**
- **Browse Repertoire:** Filter by energy, genre, era, theme
- **Search:** Full-text search on title/artist
- **Request Song:** With optional tip and note
- **Tip-Only:** Support without requesting a song
- **Live Queue:** See what's up next
- **Audience Identity:** Cookie-backed repeat-request linking with no public profile or leaderboard

**Payment Flow:**
1. User selects song and tip amount
2. Backend creates `Request` (status: pending)
3. Backend creates Stripe Payment Intent
4. Returns `client_secret` to client
5. Client completes payment with Stripe SDK
6. Stripe webhook confirms payment
7. Backend updates `Request` (status: active)
8. Request appears in performer's queue

### 7. Performance Analytics

**Purpose:** Track what songs are played and when.

**Capabilities:**
- **Performance Logging:** Manual or automatic (from setlist)
- **Counters:** `performance_count` on `project_songs`
- **Last Performed:** Timestamp of most recent play
- **Source Tracking:** Was it from repertoire or a setlist?
- **Future Analytics:** Heat maps, popularity trends, etc.

### 8. Song Metadata Enrichment

**Purpose:** Auto-populate song details using AI.

**Capabilities:**
- **Gemini Integration:** Query for energy, genre, era, key, duration, theme
- **Fallback Chain:** DB → Gemini → None
- **Chart Identification:** Upload unknown PDF, Gemini extracts title/artist from image
- **Manual Override:** User can always edit metadata

**API Endpoint:**
```
GET /api/v1/me/projects/{id}/repertoire/metadata?title=Wonderwall&artist=Oasis

Response:
{
  "data": {
    "source": "gemini",
    "metadata": {
      "energy_level": "medium",
      "era": "90s",
      "genre": "Rock",
      "original_musical_key": "F#m",
      "duration_in_seconds": 259
    }
  }
}
```

### 9. Audience Achievements

**Purpose:** Gamify audience engagement.

**Examples:**
- **Broke the Seal:** First tip
- **Big Spender Energy:** Single tip above $50
- **Certified No-Requests, Just Vibes:** Tip without request
- **Speed Demon:** 3 tips within 2 minutes
- **Mindful of Your Manners:** Said "please" in message

**Tracking:**
- `audience_profile` identified by long-lived cookie token
- `audience_achievement` is retained only as legacy data from retired gamification work
- No achievement notifications are shown on request confirmation or repertoire pages

---

## Data Flow Patterns

### Read Flow (Offline-First)

```
1. User opens app
   ↓
2. Controller checks Hive cache
   ↓
3. If cached data exists → Display immediately
   ↓
4. If online → Fetch from API in background
   ↓
5. Update cache with fresh data
   ↓
6. UI automatically reflects updates
```

### Write Flow (Outbox Pattern)

```
1. User performs action (add song, mark request played)
   ↓
2. Controller checks connectivity
   ↓
3. If ONLINE:
     → Send to API immediately
     → On success: Update local cache
     → On failure: Add to outbox
   ↓
4. If OFFLINE:
     → Add to outbox queue
     → Update local cache optimistically
     → Show "Queued for sync" indicator
   ↓
5. When connectivity restored:
     → OutboxProcessor runs
     → Retry all queued writes in FIFO order
     → Remove from outbox on success
```

### Queue Polling (ETag-Based)

```
1. QueueController starts 10-second interval timer
   ↓
2. Every 10 seconds:
     → GET /api/v1/me/projects/{id}/queue
     → Headers: If-None-Match: {lastEtag}
   ↓
3. Server checks ETag:
     → If unchanged: 304 Not Modified (no body)
     → If changed: 200 OK with full queue + new ETag
   ↓
4. Client:
     → 304 → Skip processing (no changes)
     → 200 → Update cache, refresh UI, store new ETag
```

**Benefits:**
- Minimal bandwidth (304 responses have no body)
- Server can compute ETag from `MAX(updated_at)` on requests
- Client never misses updates

### Chart Render Flow

```
1. User uploads PDF chart
   ↓
2. Backend stores PDF in R2
   ↓
3. Backend dispatches RenderChartPages job
   ↓
4. Job downloads PDF from R2
   ↓
5. Job uses ImageMagick/similar to render:
     → Page 1 light theme → PNG
     → Page 1 dark theme → PNG
     → Page 2 light theme → PNG
     → ...
   ↓
6. Job uploads PNGs to R2
   ↓
7. Job creates ChartRender records in DB
   ↓
8. Job sets chart.has_renders = true
   ↓
9. Mobile app can now request page URLs:
     → GET /api/v1/me/charts/{id}/page?page=1&theme=light
     → Returns signed R2 URL (15min expiry)
```

---

## Storage & Media Management

### Storage Strategy

**Backend:** Cloudflare R2 (S3-compatible)

**Storage Structure:**
```
r2://songtipper/
├── performers/
│   └── {owner_user_id}/
│       └── profile.png           # Profile images
│
├── charts/
│   └── {owner_user_id}/
│       └── {project_id}/
│           └── {chart_id}/
│               ├── source.pdf    # Original upload
│               └── renders/
│                   ├── light/
│                   │   ├── page-1.png
│                   │   ├── page-2.png
│                   │   └── ...
│                   └── dark/
│                       ├── page-1.png
│                       ├── page-2.png
│                       └── ...
```

### Signed URLs

**Why:** Direct R2 access is private. Signed URLs grant temporary access.

**Implementation:**
- Laravel's `Storage::temporaryUrl($path, $expiry)`
- Expiry: 15 minutes (configurable)
- Workflow:
  1. Mobile app requests `/charts/{id}/page?page=1&theme=light`
  2. Backend validates ownership
  3. Backend generates signed URL
  4. Mobile app fetches image directly from R2

**Caching:**
- Mobile app caches downloaded images in Hive
- Chart pages persist across app sessions
- Cache invalidation when chart updated

### Upload Limits

- **Single Chart:** 2MB PDF
- **Bulk Import (mobile app per request):**
  - max 20 files
  - max ~7MB total multipart payload budget (byte-aware chunking)
  - max 2MB per PDF
  - This app-side cap avoids PHP's default `max_file_uploads=20` truncation behavior and reduces `post_max_size` / 413 failures.
- **Profile Image:** 5MB (JPEG, PNG, WebP)

---

## Background Jobs & Async Processing

### Queue System

**Technology:** Laravel Queues + Redis

**Configuration:**
```php
QUEUE_CONNECTION=redis
CHART_IDENTIFICATION_QUEUE=imports
CHART_RENDER_QUEUE=renders
```

**Queue Topology:**
- `imports` queue: `ProcessImportedChart` (Gemini identification)
- `renders` queue: `RenderChartPages` (PDF-to-image rendering)
- `default` queue: all remaining lightweight jobs
- Dedicated queue workers are recommended for `imports` and `renders`.

### Job Classes

#### RenderChartPages

**Trigger:** After chart PDF upload

**Responsibilities:**
1. Download PDF from R2
2. Use ImageMagick (or similar) to convert each page to PNG
3. Generate light and dark theme versions
4. Upload rendered images to R2
5. Create `ChartRender` records
6. Set `chart.has_renders = true`

**Failure Handling:**
- Retries: 3 attempts
- Exponential backoff
- On final failure: Log error, notify user (future)

#### ProcessImportedChart

**Trigger:** After chart-backed bulk import when song is unknown

**Responsibilities:**
1. Download chart PDF from R2
2. Extract first page as image
3. Send image to Gemini API for identification
4. Parse response for title + artist
5. Create or match `Song` record
6. Link chart to song
7. Add to `project_songs` if not already in repertoire

**Idempotency:**
- If chart already linked, skip
- If song already exists, reuse

### Render Verification Endpoint

- `GET /api/v1/me/charts/{chartId}/render-status`
- Used by mobile bulk upload verification to reduce per-page API fan-out.
- Returns a normalized status:
  - `ready`: chart renders exist and are storage-backed
  - `pending`: chart exists but rendering is still in progress
  - `failed`: chart artifacts are inconsistent/missing (for example source PDF or render files missing)

#### Stripe Webhook Handler

**Trigger:** Stripe sends webhook event

**Events Handled:**
- `payment_intent.succeeded` → Update request status to active
- `payment_intent.payment_failed` → Mark request as failed

**Security:**
- Webhook signature validation
- Idempotency via `payment_intent_id` uniqueness

---

## Offline-First Strategy

### Principles

1. **Read from cache first:** Instant UI, background refresh
2. **Optimistic updates:** Apply changes locally before server confirms
3. **Outbox pattern:** Queue writes when offline
4. **Sync on reconnect:** Retry failed operations when online

### Critical Offline Capabilities

| Feature | Offline Behavior |
|---------|-----------------|
| **View Repertoire** | ✅ Cached, fully functional |
| **View Queue** | ✅ Last cached state |
| **View Charts** | ✅ Downloaded pages available |
| **Add Song** | 🟡 Queued for sync |
| **Edit Song** | 🟡 Queued for sync |
| **Mark Request Played** | 🟡 Queued for sync |
| **Draw Annotations** | ✅ Saved locally, queued for sync |
| **Upload Chart** | ❌ Requires connection (file upload) |
| **Bulk Import** | ❌ Requires connection |

### Conflict Resolution

**Annotations:**
- UUID-based `local_version_id` identifies annotation save attempts
- Server stores one saved annotation per chart page and overwrites prior state
- Client can safely retry without creating duplicate rows

**Other Writes:**
- Last-write-wins (simple model for MVP)
- Future: Implement CRDTs or operational transforms for complex conflicts

---

## Payment Integration

### Stripe Integration

**SDK:** Stripe Payment Intents API

**Flow:**
1. **Client:** User selects song + tip amount
2. **Backend:** Creates `Request` (status: pending)
3. **Backend:** Creates Stripe Payment Intent
   ```php
   $paymentIntent = $stripe->paymentIntents->create([
       'amount' => $tipAmountCents,
       'currency' => 'usd',
       'metadata' => [
           'request_id' => $request->id,
           'project_id' => $project->id,
       ],
   ]);
   ```
4. **Backend:** Returns `client_secret` and `payment_intent_id`
5. **Client:** Presents Stripe payment sheet
   ```dart
   await Stripe.instance.confirmPayment(
       paymentIntentClientSecret: clientSecret,
       data: PaymentMethodParams.card(...),
   );
   ```
6. **Stripe:** Charges card, sends webhook to backend
7. **Backend Webhook:** Updates `Request` (status: active)
8. **Client:** Polls queue, sees new request appear

### Payment Methods

- **Card:** Visa, Mastercard, Amex, Discover
- **Apple Pay:** iOS devices (configured in Stripe)
- **Google Pay:** Android devices (configured in Stripe)

### Minimum Tip

- Configurable per project: `project.min_tip_cents` (default: $5, cent inputs round up)
- Validated on backend before creating Payment Intent
- Can be set to 0 for free requests

### Tip-Only Flow

- Set `tip_only: true` in request payload
- Omit `song_id`
- Request auto-marked as `played` (doesn't enter queue)
- Used for general support without a specific song request

---

## Audience Request Experience

### Public Repertoire Page

**URL:** `/project/{projectSlug}`

**Features:**
- Search by title/artist
- Filter by genre, era, and theme
- Sort by title, artist, era, genre, and top tipped
- Pagination (50 items/page)
- Shows song-level social proof like top active tip amounts and "Hot tonight" badges
- Explains queue rules with performer-trust copy
- Click song → request page

### Request Flow

**URL:** `/project/{projectSlug}/request/{songId}`

**Form:**
- Song details (pre-filled)
- Tip amount (preset and custom amounts, min: project minimum)
- Optional note (max 500 chars)
- Payment method selector (Card, Apple Pay, Google Pay)
- Exact queue-position guidance when the selected tip can or cannot take `#1`

**Submission:**
1. Validate tip meets minimum
2. Create or resolve the private audience identity from the browser token
3. Initiate Stripe payment when a payment is required
4. On success for song requests → Redirect to the repertoire page with the exact queue position
5. On success for tip-only payments → Show confirmation with a CTA back to the repertoire flow
6. On failure → Show error, allow retry

### Retired audience gamification

- Public audience profiles, leaderboards, and achievement notifications were removed on March 6, 2026
- Audience identity remains private and browser-scoped
- See [`_shared/audience-achievements.md`](./audience-achievements.md) for the retirement note

---

## Development Workflow

### Git Worktree Process

**Why Worktrees?**
- Main repository stays clean
- Multiple feature branches can be worked on simultaneously
- Avoids accidental commits to main repo

**Create Worktree:**
```bash
# From the main songtipper checkout
repo_root="$(pwd -P)"
worktree_path="$(./scripts/create-worktree feature-name)"
cd "$worktree_path"
pwd  # Must resolve under /songtipper-worktrees/
```

**Work in Worktree:**
```bash
# Make changes to web/ or mobile_app/
cd web
git status  # Only shows changes in web/ repo
git commit -m "Add feature"
cd ../mobile_app
git status  # Only shows changes in mobile_app/ repo
git commit -m "Add mobile support for feature"
```

**Create Pull Requests:**
```bash
# From web/
gh pr create --title "Add feature" --body "Description"

# From mobile_app/
gh pr create --title "Add mobile support for feature" --body "Description"
```

**Cleanup:**
```bash
cd "$repo_root"
git worktree remove "$worktree_path"
```

### Testing

**Backend (Laravel):**
```bash
cd web
php artisan test
```

**Frontend (Flutter):**
```bash
cd mobile_app
flutter test
```

### Code Quality

**Backend:**
```bash
cd web
./vendor/bin/pint  # Laravel Pint (code formatting)
php artisan test   # PHPUnit tests
```

**Frontend:**
```bash
cd mobile_app
dart format .      # Dart formatter
dart analyze       # Static analysis
flutter test       # Unit/widget tests
```

### Environment Setup

**Web:**
1. Copy `.env.example` → `.env`
2. Configure database/Redis/Stripe credentials
3. `composer install`
4. `php artisan key:generate`
5. `php artisan migrate --seed`
6. `npm install && npm run build`

**Mobile App:**
1. `flutter pub get`
2. Configure API base URL in environment
3. `flutter run`

---

## Scaling & Performance Considerations

### Backend Optimizations

**Database Indexing:**
- `requests_queue_index` on `(project_id, status, tip_amount_cents, created_at)`
- Unique indexes on `(project_id, song_id)` for `project_songs`
- Full-text indexes on `songs.title`, `songs.artist` (MySQL only)

**Query Optimization:**
- Eager loading relationships (`with('song', 'project')`)
- Pagination on all list endpoints
- ETag-based caching for queue polling

**Caching:**
- Redis for session storage
- ETags for conditional GET requests
- Signed URL caching (15min expiry)

**Horizontal Scaling:**
- Stateless API (sessions in Redis, not file system)
- Background jobs can run on separate workers
- R2 storage scales independently

### Frontend Optimizations

**Image Loading:**
- Progressive loading with placeholders
- Image caching in Hive
- Thumbnail generation (future: smaller images for lists)

**List Virtualization:**
- Large repertoires use Flutter's `ListView.builder` (lazy rendering)
- Only visible items rendered

**Debouncing:**
- Search input debounced (300ms)
- Reduces API calls during typing

**Background Sync:**
- Outbox processor runs on timer, not every write
- Batches multiple writes when possible

### Monitoring & Observability

**Backend:**
- Laravel logs (storage/logs)
- Queue monitoring (Horizon for Redis queues)
- Sentry for error tracking (future)
- `php artisan charts:queue-stats --json` for queue depth + failed-job snapshot

**Frontend:**
- Sentry Flutter for crash reports
- Analytics (future: Mixpanel, Amplitude)

---

## Future Enhancements

### Planned Features

1. **Live Mode:**
   - WebSocket-based real-time queue updates (replace polling)
   - Performer broadcasts "now playing" to audience
   - Audience sees live updates without refresh

2. **Collaborative Projects:**
   - Invite band members with granular permissions
   - Role-based access (owner, editor, viewer)
   - Audit logs for changes

3. **Advanced Analytics:**
   - Performance heat maps (which songs played most)
   - Revenue analytics (tips over time)
   - Audience demographics (future: user accounts)

4. **Social Features:**
   - Performer profiles (bio, social links, upcoming shows)
   - Audience accounts (save payment methods, view history)
   - Share performer pages or setlists on social media

5. **API v2:**
   - GraphQL endpoint for more efficient queries
   - Subscriptions for real-time updates

6. **Multi-Currency:**
   - Support for non-USD tips
   - Currency conversion

7. **Offline Performance Logging:**
   - Log performances without internet
   - Sync later

### Technical Debt

- Add comprehensive test coverage (target: 100%)
- Implement rate limiting on public endpoints
- Add CSRF protection on web routes
- Improve error messages and validation feedback
- Document all API endpoints in OpenAPI spec
- Add database backups and disaster recovery plan

---

## Conclusion

Song Tipper is a robust, offline-first platform designed to empower performers and delight audiences. Its architecture balances simplicity and scalability, with clear contracts between backend and frontend enabling independent development and rapid iteration.

**Key Architectural Strengths:**
- **Offline-first:** Mobile app works without internet
- **Contract-driven:** `_shared/` ensures backend/frontend stay in sync
- **Project-scoped:** Multi-tenancy with clean data isolation
- **Payment-first:** Stripe integration built from the ground up
- **Extensible:** Clear patterns for adding features

For development questions or contributions, see `CLAUDE.md` and `_shared/api-contract-rules.md`.

---

**Document End**

# Patent Constraints

Read this file before designing or implementing any feature that touches
tipping, charges, payouts, the `performed` / completion state of a
request, the reward / free-song mechanic, or anything that could be
framed as a jukebox.

Source analyses:

- `docs/Tipelodeon_Patent_FTO_Analysis.pdf` — Tipsee Music carve-out
  (US Patent 12,511,587 B2, granted 2025-12-30, expires 2042-09-08).
- `docs/Tipelodeon_Patent_Landscape_Scan.md` — broader landscape scan
  (2026-05-12), source of the second and third invariants below.

## The three invariants

Tipelodeon stays outside every active patent identified in the scans
as long as all three of these hold.

### 1. Credit at request time, not on performance

The patent has 22 claims. Only claim 1 is independent; claims 2–22 all
depend from it and inherit every one of its limitations. Claim 1 is not
met by Tipelodeon because of one element: it requires that the performer
be credited **when the performance request is performed**. We credit at
request time, unconditional on performance.

As long as that stays true, no claim in the patent is infringed.
Everything else in the patent — geofencing, audio fingerprinting, PRO
pass-through, route-based venue suggestion, VIP at-door verification,
venue email lists, artist blog, etc. — lives in dependent claims and is
only reachable if claim 1 is reached first. They are not independently
prohibited.

So the rule is one rule:

> **Audience is charged at request time, and the performer is credited at
> request time, unconditional on whether the song is ever performed.**

Forms this rule rules out:

- Releasing, capturing, transferring, or paying out funds based on a
  "performed" / "fulfilled" / "completed" signal.
- Stripe `manual` capture, escrow, or any hold-then-release flow that
  resolves on a performance event.
- Server-side performance verification used as a payment gate.
- Refunds conditioned on "song wasn't performed" (functionally
  equivalent to performance-conditioned payout). Discretionary
  performer-initiated refunds are fine — the trigger is the performer's
  choice, not a performance signal.

The `performed` / completion flag is analytics and UX metadata. It must
never appear in a payment, capture, transfer, or payout code path, and
should not be named in a way that suggests payment depends on it (no
`release_tip_on_performance`, no `PerformanceVerifiedPayout`, etc.).

### 2. The platform is not a jukebox

No autonomous media playback, no server-side audio storage, no media
decompression, no streaming of recordings to audience devices. The
performer plays the song; Tipelodeon is the request and tipping channel.

> **Audience-side flow: scan → browse repertoire → request → pay.
> Performer-side flow: see queue → choose what to play. The system never
> "plays" anything itself.**

This invariant keeps Tipelodeon outside the TouchTunes patent families
(US 10,719,149 / 10,901,540 / 10,901,686 / 11,556,192 / 11,874,980 /
12,058,790 / 12,299,221, plus pending US 20250239127), whose claims are
all anchored to a "digital jukebox device" — a physical/networked
playback apparatus. Tipelodeon is not that apparatus and must never
be described as one.

Forms this rule rules out:

- Naming the system, any component, or any UI surface a "jukebox,"
  "digital jukebox," "mobile jukebox," or "venue jukebox" in code,
  comments, copy, ToS, marketing, investor materials, or social posts.
- Server-side audio rendition, mixing, or playback. Performer-uploaded
  audio files are reference material the performer plays back themselves
  on their own device — not platform-rendered playback to the audience.
- Streaming or auto-playing recordings from the platform to audience
  devices in a venue. (Adjacent to Mixhalo US 11,461,070 in addition.)
- Synchronized stage-cue / lighting / flash effects on audience phones
  driven by the platform. (Adjacent to Veres US 11,223,717.)

### 3. Rewards are loyalty milestones, not credit balances

The reward system is built on `cumulative_tip_cents`, which only grows
and is never decremented. Rewards are *earned* by passing a cumulative
threshold; they are not *spent down* from a balance.

> **Cumulative tipping is a monotonic loyalty ledger. Every $N
> threshold crossed earns a reward. There is no top-up, no balance, no
> low-balance prompt, no recommendation to buy more credits.**

This invariant keeps Tipelodeon outside TouchTunes US 11,144,946 /
US 11,978,083 ("revenue-enhancing features"), whose claims require
collecting credits, decrementing them on queue insertion, detecting
when available credits fall below a threshold, and recommending
additional purchase.

Forms this rule rules out:

- A pre-paid credit-balance product (audience buys $20 of credits, song
  requests consume credits, balance depletes).
- "Top up" UX, "balance low" notifications, "buy more credits" prompts,
  or recommendation engines triggered by a balance falling below a
  threshold.
- Decrementing `cumulative_tip_cents` on any event other than data
  correction. Free-song redemptions create `audience_reward_claims`
  rows; they do not decrement cumulative tipping.
- Framing the free-song reward as "credits" anywhere — in field names,
  API responses, audience UI copy, performer-onboarding copy, or
  marketing. Use "loyalty," "milestone," "earn," "free song earned at
  every $N in tips."

## Framing in copy, ToS, and API surface

The product should read as request-triggered, not event-triggered. The
audience is purchasing the act of submitting a request from the
performer's curated catalog; performance is at the performer's sole
discretion. Keep that framing consistent across product copy, ToS,
receipts, error messages, and field names. The prosecution-history
estoppel argument depends on actual system behavior, but inconsistent
framing makes that argument more expensive to defend.

## Claim-adjacent features — flag, don't block

If a task introduces one of these, the feature itself is not prohibited,
but the developer should be pinged so we can decide whether to refresh
the FTO read before shipping. They only become live patent risks if one
of the three invariants is also broken.

### Adjacent to invariant 1 (Tipsee dependent claims)

Live risk only if invariant 1 is broken first.

- Audio fingerprinting that detects which songs were performed (claims
  15–16).
- Geofencing that gates request submission on venue proximity (claim 11).
- ASCAP / BMI / SESAC royalty pass-through executed by the platform on
  behalf of the venue (claims 13–14).
- Route-based suggestion of additional tour venues (claim 18).
- VIP / at-door verification tied to audience identity (claim 6).
- Audience email-list building tied to venues (claim 5).
- Promotional blog tied to artist profile (claim 11, separate).

### Adjacent to invariant 2 (TouchTunes "improved UI" family + audio/cue patents)

Live risk only if invariant 2 is broken first.

- Mobile UI that visually resembles a jukebox cabinet.
- Real-time low-latency audio streaming from the venue soundboard to
  audience devices (Mixhalo US 11,461,070).
- Karaoke or photo-booth modes (TouchTunes US 10,848,807, US 10,963,132).
- Social-network playback announcements (TouchTunes US 9,076,155).

### Adjacent to invariant 3 (TouchTunes "revenue-enhancing" family + loyalty patents)

Live risk only if invariant 3 is broken first.

- Pre-paid credit balance, top-up flow, low-balance notifications.
- Multi-merchant / cross-project loyalty network (Liberty Peak / AmEx
  family — now expired, but verify before launch).
- Merchant-presented visit codes with transitory anti-fraud display
  (Laiderman US 10,776,806).

### Adjacent to features re-scanned in 2026 landscape

Live risk independent of the three invariants. Redo FTO before shipping
any of these — they were ruled out only because Tipelodeon does not have
them today:

- Algorithmic setlist generation (smart, strategic, energy-adjusted, or
  any soft-weighted attribute ordering). Re-scan Spotify US 10,108,708 /
  US 11,436,276, Pandora US 8,306,976, Spotify US 9,766,854, Echo Nest
  US 8,073,854.
- Energy / mood slider or other target-attribute adjuster on the
  performance queue or session.
- Per-page chart viewport with element-of-interest tracking
  (Microsoft US 10,191,890 — different from our static zoom/offset).
- Concert / event discovery for fans based on listening history
  (Spotify US 10,475,108 family).
- Audio-signal analysis to extract tempo / key / danceability
  (Spotify US 10,089,578, US 9,852,721, US 7,689,422).

For any of these, mention this file and `docs/Tipelodeon_Patent_Landscape_Scan.md`
in the PR description so the non-infringement position is documented
contemporaneously.

## If a task seems to require breaking any of the three invariants

Stop and surface the conflict. Propose an alternative that preserves the
invariant. Examples:

- Performance-conditioned payment → mark-as-performed for analytics
  only, with no payment side effect.
- Audio streaming to audience devices → request-and-tip channel only;
  performer plays audio on their own device.
- Pre-paid credits → per-request payment with cumulative-tip loyalty
  rewards.

Do not work around any invariant with euphemisms, indirection, or
"temporary" shims — the prosecution-history-estoppel argument (for
invariant 1) and the structural non-infringement arguments (for
invariants 2 and 3) all depend on the actual behavior of the system,
not on what we call it.

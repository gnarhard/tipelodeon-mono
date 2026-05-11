# Patent Constraints

Read this file before designing or implementing any feature that touches
tipping, charges, payouts, or the `performed` / completion state of a
request.

Source analysis: `docs/Tipelodeon_Patent_FTO_Analysis.pdf` (US Patent
12,511,587 B2, Tipsee Music LLC, granted 2025-12-30, expires 2042-09-08).

## The single invariant

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
but it is worth pinging the developer so we can decide whether to refresh
the FTO read before shipping. They only become live patent risks if the
single invariant above is also broken:

- Audio fingerprinting that detects which songs were performed (claims
  15–16).
- Geofencing that gates request submission on venue proximity (claim 11).
- ASCAP / BMI / SESAC royalty pass-through executed by the platform on
  behalf of the venue (claims 13–14).
- Route-based suggestion of additional tour venues (claim 18).
- VIP / at-door verification tied to audience identity (claim 6).
- Audience email-list building tied to venues (claim 5).
- Promotional blog tied to artist profile (claim 11, separate).

For any of these, mention this file in the PR description so the
non-infringement position is documented contemporaneously.

## If a task seems to require performance-conditioned payment

Stop and surface the conflict. Propose a request-triggered alternative
(e.g. mark-as-performed for analytics only, with no payment side effect).
Do not work around the rule with euphemisms, indirection, or "temporary"
shims — the estoppel argument depends on the actual behavior of the
system, not on what we call it.

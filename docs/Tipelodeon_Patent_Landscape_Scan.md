# Tipelodeon — Patent Landscape Scan

**Scan date:** 2026-05-12
**Scope:** Beyond US 12,511,587 (Tipsee Music) — broader landscape across feature surfaces.
**Companion doc:** `Tipelodeon_Patent_FTO_Analysis.pdf` (Tipsee carve-out, already completed)
**Disclaimer:** Internal working summary, not legal advice. A formal FTO opinion from patent counsel is recommended before commercial scale-up.

---

## Bottom line

No verified active patent reads literally on Tipelodeon today. Two near-term concerns require attention before broad commercial launch:

1. **TouchTunes "revenue-enhancing features" family** (US 11,144,946 / US 11,978,083) is claim-adjacent to the $30 cumulative-tip free-song reward. **Mitigation:** never frame the reward as a depleting credit balance with a "buy more credits when low" recommendation. Keep it strictly as a loyalty milestone earned from cumulative tipping.
2. **TouchTunes "improved user interfaces" family** (US 10,719,149 / US 10,901,540 / US 10,901,686 / US 11,556,192 / US 11,874,980 / US 12,058,790 / US 12,299,221 + pending US 20250239127) is being actively prosecuted. Today its claims are anchored to physical jukebox cabinets. **Mitigation:** never describe Tipelodeon as a "jukebox" / "digital jukebox" / "mobile jukebox" — preserve the structural distinction that there is no autonomous playback device, no audio storage, no media decompression. Code naming, marketing copy, and ToS must remain consistent.

The "credit at request time, not on performance" invariant from the Tipsee carve-out does **not** independently defend against TouchTunes (their claims don't require performance verification). The defenses there are structural — no jukebox apparatus, no depleting credit balance.

One dormant feature concern: if **smart setlist generation** is ever re-added (it was removed in v1.3), Spotify US 10,108,708 / US 11,436,276 would likely read on it. That family requires its own FTO before any reintroduction.

The Layer-2 crowd-sourced location mechanic (cross-project, places_provider_id grouping, k=3 disclosure threshold) is the area of strongest freedom-to-operate. No patent surfaced reading on it. Recommend a defensive publication to lock in prior art.

---

## Tier 1 — Active patents requiring attention

### A. US 12,511,587 B2 — Tipsee Music
Already analyzed in `Tipelodeon_Patent_FTO_Analysis.pdf`. Current invariant (`.agent-rules/15-patent-constraints.md`) keeps Tipelodeon outside every claim. No new action.

### B. TouchTunes US 11,144,946 + US 11,978,083 — "Digital downloading jukebox with revenue-enhancing features"
- **Status:** Active. US 11,144,946 expires ~2029-10. US 11,978,083 continuation, ~2029.
- **Claim element of concern:** "collecting a number of credits from a user, queuing media for playback in exchange for a first predetermined number of credits, detecting when available credits fall below a threshold, then recommending additional media for purchase in exchange for a second predetermined number of credits."
- **Why it matters:** The shape of "credit balance + threshold + recommendation when low" overlaps with how a careless redesign of the $30 free-song reward could be framed.
- **Why we are not in the claim today:** Tipelodeon's reward is a one-shot loyalty milestone awarded at every $30 of `cumulative_tip_cents`, which only grows and is never decremented. There is no "balance running low" state and no recommendation to buy credits. Tipelodeon also is not a "digital jukebox device" — every TouchTunes claim element is anchored to a physical playback apparatus.
- **Action:**
  - UX audit: ensure the reward is described as a loyalty earn ("You're $X away from a free song"), never as a deplating credit balance or as low-balance prompts to top up.
  - Do not introduce a pre-paid credit system. Keep payments per-request.
  - Before commercial scale-up, commission a claim-chart opinion from patent counsel for both patents in the family.

### C. TouchTunes "improved user interfaces" family
US 10,719,149 / US 10,901,540 / US 10,901,686 / US 11,556,192 / US 11,874,980 / US 12,058,790 / US 12,299,221, plus pending US 20250239127.
- **Status:** All active, all in continuing prosecution. TouchTunes amends pending continuations aggressively to target competitor features.
- **Why we are not in the claims today:** Independent claims of every issued member of this family are anchored to a physical jukebox cabinet — sealed touchscreen, latching mechanism, or autonomous playback hardware. Tipelodeon has none of that.
- **Action:**
  - **Hard rule:** never refer to Tipelodeon as a "jukebox," "digital jukebox," or "mobile jukebox" in code identifiers, comments, product copy, marketing, ToS, or investor materials. The patentee's specifications consistently use the word "jukebox" to mean a physical apparatus; our consistent contrary framing supports the structural non-infringement position.
  - Track pending US 20250239127 and any new continuations on the priority chain (US 10,719,149 etc.) annually.
  - Include this family in the pre-launch opinion-of-counsel.

### D. Spotify US 10,108,708 + US 11,436,276 — "Classifying, comparing and ordering songs in a playlist"
- **Status:** Active through ~2036.
- **Risk:** **LITERAL READ POSSIBLE if smart-setlist generation is re-added.** Claim 1 covers: classify each digital file by musical-attribute metadata (tempo, key, energy, etc.); compute pairwise similarity scores; determine an optimal path; output an ordered playlist. This is precisely the shape of the removed `POST /setlists/generate-smart` endpoint.
- **Current status of Tipelodeon feature:** Smart and strategic setlist generation were removed in v1.3 (`_shared/contracts/setlists.md`). The risk is **dormant**, not live.
- **Action:**
  - Add a re-FTO trigger: any task proposing to reintroduce algorithmic setlist generation must redo this analysis first.
  - If reintroduced, prefer deterministic constraint-satisfaction over performer-authored rules — avoid learned-similarity scoring, avoid acoustic feature analysis, avoid soft-weighted attribute distance.
  - Document the seed/version reproducibility mechanism as our prior art — no patent surfaced claiming reproducible-seed music ordering.

### E. Spotify US 9,766,854 family — "Dynamic energy-level control of playlists"
- **Status:** Active through ~2035.
- **Risk:** **CLAIM-ADJACENT.** Claim 1 requires receiving a request to adjust the energy level of a playlist and adjusting in response. Adjacent to smart-mode reorder after skip/complete events, especially if a future UI ever exposes an energy slider or target-energy reorder.
- **Action:** Do not add explicit energy/mood "adjust" controls on the queue or session UI. If a feature like that is desired, redo FTO before designing.

### F. Pandora US 8,306,976 — "Contextual feedback to generate and modify playlists"
- **Status:** Active through ~2027.
- **Risk:** **CLAIM-ADJACENT** to the smart-mode performance session reorder. Claim involves user feedback (thumbs/skip) modifying a generated playlist via weighted attribute scoring.
- **Why we are not in the claim today:** Tipelodeon's smart-mode reorder is triggered by skip/complete session events on the queue, not by a feedback signal weighting attribute scores. Reorders are deterministic over current queue state, not learned.
- **Action:** Keep the reorder algorithm deterministic and constraint-driven. Do not introduce attribute-weight updates from skip/complete signals.

### G. Microsoft US 10,191,890 — "Persistent viewports"
- **Status:** Active through ~2035.
- **Risk:** **CLAIM-ADJACENT** to per-page chart viewport persistence. Microsoft's claim covers identifying an element-of-interest and recomputing viewport position. Tipelodeon stores static `(zoom_scale, offset_dx, offset_dy)` per `(user, chart, page)` — different mechanism, no element-of-interest tracking.
- **Action:** Include in pre-launch claim chart for completeness. The differentiator (static-storage vs. element-tracking) is clean but the assignee is litigious.

### H. US 10,776,806 — Laiderman "Mobile loyalty system"
- **Status:** Active through ~2034.
- **Risk:** **CLAIM-ADJACENT** but distinguishable. Requires a merchant-presented code, transitory anti-fraud display element, and two-stage scan-then-redeem icon. Tipelodeon's QR is a payment entry, not a "visit credit" code, and there is no anti-fraud transitory display.
- **Action:** A one-page rebuttal memo from patent counsel before any major reward UX evolution.

---

## Tier 2 — Watch list

### Competitor "patent-pending" applications in 18-month blackout
~10 small competitors advertise "patent-pending" status but have no published USPTO applications visible today: Tip Top Jar, NoSongRequests, mySet, RequestNow, Rekwest, Juke, Lime DJ, eTip.io, TipTune, Encore. Some of these may be marketing puffery; some may be real applications inside the 18-month publication blackout.
- **Action:** Re-scan November 2026 and again May 2027 for new published applications from these names. The highest-stakes scenario is one of them claiming "credit at request time, no performance verification" — which would be the first competitor claim that overlaps with Tipelodeon's design and bypasses the Tipsee carve-out.

### Google US 8,200,247 — "Confirming a venue of user location"
- Active through ~2030. Claim-adjacent to the L1 venue suggestion layer but framed around microblog check-in confirmation, not performance-session attribution. Different intent and scoring criteria. Expiration is near; low concern.

### HERE Global US 11,562,168 — k-anonymity clustering for location trajectory data
- Active through ~2041 but requires location obfuscation/modification, which Tipelodeon does not do. Claim-adjacent only at a high level (any k-anonymous location disclosure mechanism); structural mismatch.

---

## Tier 3 — Prior-art shields (expired or abandoned)

These patents are dead but useful as defensive prior-art citations if any later patent or NPE asserts a similar claim against Tipelodeon. Keep a record.

| Patent | Title / topic | Status | Relevance |
| --- | --- | --- | --- |
| US 6,421,651 (Walker Digital) | Priority-based jukebox queuing | Expired 2018 | Gold-standard prior art for **pay-to-prioritize-in-queue** |
| US 6,430,537 (Walker Digital) | Same family, apparatus claim | Expired 2018 | Same |
| US 5,999,499 (P&P Marketing) | Jukebox with priority play | Expired 2011 | Pay-above-threshold prioritize prior art |
| US 8,447,227 (SoundNet/TouchTunes) | Jukebox system w/ mobile-device WAP link | Lapsed | Mobile-selects-via-venue-web-link prior art |
| US 8,332,895 (TouchTunes) | Mobile patron interaction | Expired | Early mobile-jukebox spec, prior art |
| US 6,308,204 (TouchTunes) | Networked playback architecture | Expired 2015 | Core jukebox architecture prior art |
| US 5,341,350 (NSM) | Networked coin-operated jukebox | Expired 2011 | Seminal prior art |
| US 6,397,189 (Arachnid) | Computer jukebox network | Expired 2010 | "Central server + venue device" prior art |
| US 7,398,225 (American Express / Liberty Peak) | Networked loyalty program | Expired 2025 | Cumulative loyalty prior art |
| US 9,585,011 (IBM) | k-anonymity for location | Lapsed | k-anonymous disclosure prior art |
| US 8,725,740 (Concert Tech) | Active playlist with dynamic groups | Lapsed | Reorder-from-pool prior art |
| US 8,583,615 (Yahoo) | Playlist from a mood gradient | Lapsed | Mood/energy playlist prior art |
| US 8,749,587 (Fuji Xerox) | Content-based auto-zoom for documents | Lapsed | PDF viewport prior art |
| US 7,688,327 (NPE chain) | Visual content browsing zoom/pan | Expired 2020 | PDF viewport prior art |
| US 20060206478 (Pandora) | Playlist generating methods | Abandoned | Playlist generation prior art |
| US 8,073,854 (Echo Nest/Spotify) | Music similarity from cultural + acoustic info | Active to ~2031 | Cite as canonical similarity prior art (only relevant if Tipelodeon ever does dual-signal similarity scoring) |
| US 20210150421 (Disney) | Dynamic virtual queue management | Abandoned | Virtual queue prior art |
| US 20170083897 (BBVA) | Mobile purchase rewards | Abandoned | Reward-on-transaction prior art |
| US 20140310089 (fuel rewards) | Automatic threshold-triggered reward credit | Application only | Automatic-loyalty-credit prior art |
| US 8,552,281 (Cotrone) | Digital sheet music distribution | Lapsed | Digital sheet music prior art |
| US 20080196575 (Recordare/MakeMusic) | Digital sheet music on a media device | Abandoned | Digital sheet music prior art |

---

## Tier 4 — Cleared (no overlap)

These were considered and ruled out. Captured here so the next FTO doesn't re-search them.

- **Foursquare US 9,753,965** — polygon/sub-polygon spatial index. Tipelodeon uses simple lat/lon haversine + Google `place_id` grouping. No overlap.
- **Foursquare continuations** (US 10,013,446 / 10,268,708 / 10,459,896 / 10,817,484 / 11,461,289) — same family, same non-infringement.
- **TouchTunes US 9,076,155** (social networking) and **US 10,848,807** (karaoke/photo booth) — physical-jukebox-device claims, no overlap.
- **Adobe collaborative-PDF patents** (e.g., US 8,943,129) — require real-time multi-user editing. Tipelodeon annotations are single-user with last-write-wins.
- **Mixhalo US 11,461,070** — low-latency audio streaming, no overlap.
- **Veres US 11,223,717** — synchronized stage-cue lighting on audience phones, no overlap.
- **YouTube Super Chat** — no granted US patent located. Even if Google had one, Tipelodeon is not a livestream chat.
- **TikTok LIVE Gifts / Twitch Bits / Patreon / OnlyFans / Cameo / Buy-Me-a-Coffee / Ko-fi** — no blocking US patent located. Tipelodeon is not a livestream tipping product.
- **Starbucks / McDonald's / Disney loyalty tier programs** — operated as trade secrets; no blocking patent located. Underlying cumulative-spend reward concept has decades of prior art (punch cards, frequent-flyer programs).
- **Audio fingerprinting** (Shazam, Audible Magic, Gracenote) — Tipelodeon does not analyze audio signal. Cleared. Only relevant if audio fingerprinting is ever added.
- **Iframe embed mechanics** — pre-2010 standards-track prior art (oEmbed, `Content-Security-Policy: frame-ancestors`). Cleared.

---

## Re-scan triggers

Redo FTO before shipping any of the following:

| Feature change | New patent areas to re-scan |
| --- | --- |
| Performance verification or payment gating on performance status | **Tipsee US 12,511,587 (re-check)**, plus the whole "event-triggered monetization" landscape |
| Smart / strategic setlist generation reintroduced | Spotify US 10,108,708 / US 11,436,276, Pandora US 8,306,976, Echo Nest US 8,073,854 |
| Energy slider, mood slider, or any target-attribute adjuster on the queue or session | Spotify US 9,766,854 family |
| Pre-paid credit balance, top-up flow, or low-balance prompts | TouchTunes US 11,144,946 / US 11,978,083 |
| Any mobile UI screen that visually resembles a jukebox cabinet, or any product copy describing the platform as a jukebox | TouchTunes "improved UI" family (US 10,719,149 etc., plus pending) |
| Geofencing — gating request submission on physical proximity | Tipsee dependent claim 11; Foursquare; Google Places |
| Audio fingerprinting / automatic performance detection | Tipsee dependent claims 15–16; Shazam (US 6,990,453, US 8,996,557), Audible Magic, Gracenote |
| ASCAP / BMI / SESAC royalty pass-through executed by the platform | Tipsee dependent claims 13–14 |
| Route-based / tour-itinerary venue suggestion | Tipsee dependent claim 18 |
| VIP at-door audience verification | Tipsee dependent claim 6 |
| Audience email-list building tied to venues | Tipsee dependent claim 5 |
| Promotional blog tied to artist profile | Tipsee dependent claim 11 (separate) |
| Live audio streaming to audience phones | Mixhalo US 11,461,070 |
| Synchronized stage cues / lighting effects on audience devices | Veres US 11,223,717 |
| Multi-merchant or cross-project loyalty rewards | Tier 1 American Express family (now expired) + revisit |
| Concert / event discovery for fans based on listening history | Spotify US 10,475,108 family |
| Audio-signal analysis (extracting tempo/key/danceability from audio) | Spotify US 10,089,578, US 9,852,721, US 7,689,422 |

---

## Recommended actions

1. **Today** — Audit reward UX copy. Ensure language is "earn a free song every $30 in tips" and not "credit balance" / "top up" / "buy more credits when low." Update any code identifier, comment, or doc that frames the reward as a depleting balance.
2. **Today** — Audit naming. Search code, copy, marketing, ToS, and investor materials for any use of "jukebox" / "digital jukebox" / "mobile jukebox" applied to Tipelodeon. Replace with "live performer platform," "song request platform," or similar.
3. **This quarter** — Defensive publication for the Layer-2 crowd-sourced location architecture (cross-project places_provider_id grouping, k=3 disclosure threshold). This is the area of strongest FTO and a defensive publication locks in prior art for any future filings in the space.
4. **Before commercial scale-up** — Formal opinion-of-counsel claim chart covering:
   - US 12,511,587 (already analyzed; re-confirm against issued claim text)
   - TouchTunes US 11,144,946 + US 11,978,083
   - TouchTunes US 10,719,149 / US 10,901,540 / US 10,901,686 / US 11,556,192 / US 11,874,980 / US 12,058,790 / US 12,299,221 + pending US 20250239127
   - Microsoft US 10,191,890 (persistent viewports)
   - Laiderman US 10,776,806 (mobile loyalty) if reward UX evolves
5. **November 2026 + May 2027** — Re-scan competitor "patent-pending" applications (Tip Top Jar, NoSongRequests, mySet, RequestNow, Rekwest, Juke, Lime DJ, eTip.io, TipTune, Encore) as they exit the 18-month publication blackout.
6. **Annually** — Recheck pending TouchTunes continuations (priority chains rooted at US 10,719,149 etc.) for newly issued claims that broaden beyond "jukebox device."

---

## Architectural invariants — extended summary

The single Tipsee invariant in `.agent-rules/15-patent-constraints.md` remains in force:

> **Audience is charged at request time, and the performer is credited at request time, unconditional on whether the song is ever performed.**

This scan adds two more architectural invariants that protect Tipelodeon against the broader landscape:

> **The platform is not a jukebox.** No audio storage, no autonomous playback, no media decompression by the system. The performer plays the song. Naming and copy must consistently reflect this.

> **Rewards are loyalty milestones, not credit balances.** Cumulative tipping only grows; it never decrements. No top-up flow, no low-balance prompts, no recommendations to buy more credits.

The crowded operating field of QR-tip-song-request competitors (Lime DJ, TipTune, Encore, Juketune, NoSongRequests, requestsongs.co, DJFY, mySet, Juke, plus Tipsee Music itself) with zero reported litigation is the strongest market signal that no incumbent NPE holds actionable IP in the exact niche. Tipelodeon's FTO posture in this category is clear, contingent only on preserving the three invariants above.

---

## Search methodology

Six parallel research agents searched Google Patents and USPTO records by feature category:

1. Direct-competitor live-music tipping (TipCow, Set.live, Vibo, Tunespeak, Encore, Songfinch, Mixhalo, etc.)
2. Digital jukebox / venue music selection (TouchTunes, AMI, NSM, Rowe, Arachnid)
3. Smart playlist / setlist generation (Spotify, Pandora, Echo Nest)
4. Crowd-sourced location / proximity (Foursquare, Yelp, Google, Apple, Snap, HERE, IBM)
5. QR-code venue commerce + cumulative-spend rewards (Starbucks, Disney, YouTube Super Chat, TikTok LIVE, Twitch, Square)
6. PDF chart annotation + iframe embed widgets (Adobe, Microsoft, Apple, forScore, Twitter, Stripe)

Every reported patent number was verified by direct URL fetch against Google Patents. Hallucinated patent numbers are excluded. Where a patent's claim text could not be reproduced (Google Patents truncation), the rejection or non-infringement reasoning is based on the claim element list and prosecution history accessible via the publication record.

This scan is internal-only and is not a legal opinion. Confirm issued claim language and obtain a written FTO opinion from qualified patent counsel before relying on this analysis for go-to-market.

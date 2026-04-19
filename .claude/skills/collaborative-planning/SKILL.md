---
name: collaborative-planning
description: Run a collaborative planning session on a feature or multi-step change before any code is written. The user presents a draft plan; you audit its claims against the codebase, flag hard blockers vs soft concerns, push back with numbers and alternatives, research open questions with real data, and only after full convergence write the final plan to .claude/plans/<FEATURE>.md. Trigger on phrases like "here's my plan", "review this plan", "plan this feature with me", "I want to build X, let's plan it".
---

# Collaborative Planning

A shared-ownership planning workflow. The user is the architect — you are the technical sparring partner and verifier. Nothing gets implemented during this skill; implementation is a separate follow-up session.

## The rules

1. **Verify every claim about existing code.** The user's plan is a hypothesis, not a specification. "Already there" / "we use X for Y" are claims to check, not facts to accept. Use the `Explore` agent with a focused checklist when the surface area is wide; otherwise `Grep`/`Read` directly. Report with file:line citations.

2. **Separate hard blockers from soft concerns.** A hard blocker means "the plan as written won't work" (wrong FK target, wrong endpoint, incompatible data type). A soft concern means "this is fine but could be better" (naming, scope, perf future-proofing). Stack your review so blockers land first; concerns come after.

3. **Push back when you disagree — with specifics.** "I don't think we need Elasticsearch here" is weak. "100 cards per deck × one aggregate query = 1 round-trip, ~ms latency. Adding ES means a new container, JVM memory, sync pipeline, operational surface." is strong. Numbers, tradeoffs, explicit costs. Defer to the user after you've laid out your case — it's their call.

4. **Research before committing to any decision.** If the plan hinges on an external API's shape, hit it (`Bash` + `curl` beats `WebFetch` when the latter 403s). If it hinges on regex behavior, run the regex against real data. If it hinges on a library's capability, read the library. Never ship "this probably works" into the final plan.

5. **Iterate on the tricky bits.** Hard problems rarely settle in the first pass. Plan to revisit. The test is: after 3-5 rounds on a sub-area, does the user sound confident or still hedging? If still hedging, keep digging.

6. **Counter-propose, don't just veto.** If you think something's wrong, show what you think is right. Pair every "this won't work" with "here's what would." Even when the alternative might be rejected — it clarifies the tradeoff.

7. **Defer scope creep explicitly.** "Nice but later" features get a named place to live: an **Out of scope** section on the plan and, if you're confident it'll eventually ship, a suggested follow-up session name. Don't silently drop ideas — document the defer.

8. **Validate with real data, not plausibility.** The regex that "looks right" is probably wrong on some edge case. The endpoint that "should return X" sometimes doesn't. Run it. If you can't run it, say "unverified — confirm before relying on this."

9. **Lock in decisions as you go.** Maintain a running "Decisions locked in" list (informally during the conversation, formally in the final plan). When a contradiction appears later, that list is the arbiter. If you drift from a locked decision (e.g., writing `DELETE` after deciding on `POST`), expect the user to call it out — and fix it.

10. **Write the plan only after convergence.** The final plan document is a commitment artifact, not a brainstorming medium. Once written, revisions should be small corrections, not fresh architectural rethinks.

## The flow

```
User presents plan
    ↓
[Audit existing-code claims]
    ↓
Respond with: hard blockers | clarifying questions | soft concerns
    ↓
User answers
    ↓
[Research any open technical questions with real data]
    ↓
Counter-propose on disputed calls
    ↓
Iterate until convergence (may take several rounds on tricky areas)
    ↓
Write final plan to .claude/plans/<FEATURE-CODE>.md
    ↓
End skill. Implementation is a separate session.
```

## Auditing existing code

For wide-surface audits, use the `Explore` agent with a checklist. Frame it: "verify these claims, report as CONFIRMED / DIFFERENT / MISSING with file:line citations." Keep the agent focused on facts — no opinions, no recommendations; those are the user-and-you conversation.

For narrow lookups (a single file, a single symbol), use `Grep`/`Read` directly. Agents are overhead for small queries.

Always cite file:line when reporting a finding. Plans that say "we use X in the controller" rot faster than plans that say "controller.php:42 does X".

## The "Decisions locked in" section

Every plan should have this as the second section (after the overview). Each decision is a numbered item:

```markdown
## Decisions locked in during planning

1. **Decision name:** concrete choice. One sentence of reasoning so future-reader (or future-you) can judge edge cases without re-running the conversation.

2. **Next decision:** ...
```

Include decisions about:
- Data model trade-offs taken (and rejected alternatives, briefly)
- API shape choices (e.g., POST vs DELETE-with-body)
- Scope boundaries (what's explicitly excluded)
- Migration order constraints
- External API quirks that shaped the design

This section is the load-bearing part of the plan for future sessions. If context gets compressed and the detailed parts get summarized away, the decisions list is what survives.

## Plan document structure

```
# <FEATURE-CODE> — <Short title>

1-2 sentence scope statement.

## Decisions locked in during planning
(numbered items; see above)

## Part 1 — <First logical chunk>
...

## Part N — <Last logical chunk>

## Tests
(DataProvider-driven where possible; concrete test-case seeds)

## <Runbook / Migration order / Verification>
(If there are operational steps, strict order, pre-flight checks)

## Out of scope for <FEATURE-CODE>
(What's NOT being built here; suggested follow-up session names)
```

Name the file `<FEATURE-CODE>-<slug>.md` (e.g., `DB-1-deckbuilder-backend.md`). The feature code is a short alpha-numeric prefix the user picks; it makes cross-session references terse.

## What this skill is NOT

- **Not a shortcut.** Proper planning is slow on purpose. A 10-round back-and-forth on partner detection is not waste — it's what produces a plan that survives implementation.
- **Not a rubber-stamp.** The user may know less about an area than you do (and vice versa). You are expected to disagree when you see a problem, not to smooth it over.
- **Not implementation.** Do not write migrations, controllers, or tests during this skill. The deliverable is a plan document. Writing code is a separate session that reads the plan.

## Reference

- Example plan produced via this process: `.claude/plans/DB-1-deckbuilder-backend.md`

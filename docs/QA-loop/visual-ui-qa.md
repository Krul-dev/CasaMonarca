# Visual UI QA Loop

This note records the current workflow for using Playwright as a visual feedback loop while iterating on fragile UI layouts.

The immediate use case is the Admin Panel Signing Trust graph, where screenshots alone were not enough to catch node positioning, connector geometry, and responsive fallback issues before manual review.

## Current Project Setup

- Config: `playwright.config.ts`
- Specs: `tests/visual/*.spec.ts`
- Command: `npm run qa:visual`
- Headed mode: `npm run qa:visual:headed`
- Artifacts: `test-results/visual`
- Report: `playwright-report`

Generated artifacts are ignored by git. Keep the config and specs versioned.

## Signing Trust Coverage

The current `tests/visual/signing-trust.spec.ts` test suite:

- Starts the Vite app in dev mode with `VITE_APP_CHANNEL=dev`.
- Mocks admin API responses so the test does not depend on a live Laravel session.
- Opens `/app/admin`.
- Switches to the `Signing Trust` tab.
- Captures desktop, tablet, and mobile screenshots.
- Verifies the desktop graph renders nodes and connector paths.
- Verifies user/key/revision nodes stay inside graph bounds.
- Verifies user/key nodes do not overlap.
- Verifies connector endpoints remain inside the graph body.
- Verifies tablet/mobile widths use the compact ledger instead of the SVG graph.
- Verifies narrow layouts do not create horizontal document overflow.

## Run Workflow

Run the loop after layout changes that affect:

- Graph/node positioning.
- SVG connector paths.
- Responsive breakpoints.
- Collapsed/expanded revision cards.
- Admin Panel shell spacing or toolbar layout.

```bash
npm run qa:visual
```

If local port binding or browser launch is sandbox-blocked, run the command with elevated local execution. Playwright needs to start a local Vite server and launch Chromium.

For visual inspection:

```bash
npm run qa:visual:headed
```

Screenshots are written below `test-results/visual`.

## Useful Assertions To Keep

Prefer geometry assertions that catch user-visible layout failures without locking the UI to exact pixels:

- Elements exist and have non-zero dimensions.
- Important nodes stay inside their container bounds.
- Nodes that represent distinct graph entities do not overlap.
- SVG path endpoints stay inside the graph body.
- Narrow breakpoints switch to an alternate layout instead of compressing the graph.
- `documentElement.scrollWidth` does not exceed `clientWidth` at mobile/tablet sizes.

Avoid brittle assertions such as exact card coordinates, exact curve control points, or screenshot snapshots unless the visual design has stabilized.

## When Converting To A Formal Skill

The future skill should target requests like:

- "Set up visual QA for this UI."
- "Capture screenshots while iterating on a component."
- "Add browser checks for a graph/dashboard layout."
- "Use Playwright to validate responsive behavior."
- "Create a visual feedback loop for frontend changes."

Recommended skill shape:

- `SKILL.md`: concise workflow and decision rules.
- `scripts/`: reusable Playwright spec generator or geometry helper snippets.
- `references/`: examples for common checks like bounds, overlap, overflow, and screenshot artifact paths.

Generalizable workflow:

1. Detect project stack and existing test tooling.
2. Add or reuse Playwright config.
3. Mock unstable backend/session dependencies.
4. Navigate directly to the target route/state.
5. Capture screenshots across relevant viewports.
6. Add geometry assertions for the specific failure modes.
7. Run the visual suite.
8. Use screenshots and assertion output to drive UI fixes.

Skill guidance:

- Keep project-specific selectors and mock payloads in the target repo, not in the skill.
- Put reusable geometry helpers in the skill only after they are used in at least two contexts.
- Prefer "diagnostic visual QA" over formal screenshot snapshot testing during active design iteration.

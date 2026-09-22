/*
 * Copyright © Mago Assistant
 */

/**
 * A turn that hits the real backend (ChatService, tool execution, a WireMock call and an SSE round
 * trip) competes with whatever else the suite's other parallel workers are doing on the same
 * PHP-FPM pool, MySQL instance and WireMock container, which regularly exceeds Playwright's 5s
 * default assertion timeout under normal load - expected, not hung. `test.setTimeout()` raises a
 * test's own ceiling but not any individual `expect(...)` call's timeout, so every assertion that
 * waits on a streamed reply from the real backend needs `{timeout: PROVIDER_ROUND_TRIP_TIMEOUT}`
 * explicitly. See Test/End-2-end/README.md for the full reasoning.
 */
export const PROVIDER_ROUND_TRIP_TIMEOUT = 20000;

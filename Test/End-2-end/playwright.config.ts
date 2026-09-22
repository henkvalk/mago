/*
 * Copyright © Mago Assistant
 */

import {defineConfig, devices} from '@playwright/test';

/**
 * Read environment variables from file.
 * https://github.com/motdotla/dotenv
 */
// import dotenv from 'dotenv';
// import path from 'path';
// dotenv.config({ path: path.resolve(__dirname, '.env') });

/**
 * See https://playwright.dev/docs/test-configuration.
 */
export default defineConfig({
  testDir: './tests',
  /* Run tests in files in parallel */
  fullyParallel: true,
  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,
  /* Retry on CI only */
  retries: process.env.CI ? 2 : 0,
  /* Opt out of parallel tests on CI.

     Locally, capped at 3 rather than left to Playwright's default of half the cores. Every worker
     here drives full admin page loads against a single PHP-FPM pool and one MySQL, so the server is
     the bottleneck, not the browsers: on a 10-core box the default of 5 starved even the trivial
     ChatMock specs into the 25 s per-test ceiling, failing roughly every other run once the suite
     passed ~80 tests. Three is stable over repeated runs and costs nothing, because 5 workers
     produced the same 3.3 minute wall clock while thrashing. Raise it only alongside more PHP-FPM
     workers. */
  workers: process.env.CI ? 1 : 3,
  /* Don't run the whole test suite but fail fast */
  maxFailures: process.env.CI ? 3 : undefined,
  /* Reporter to use. See https://playwright.dev/docs/test-reporters */
  reporter: process.env.CI ?
    [['list'], ['html']] :
    [['html', { open: 'never' }],
  ],
  /* Shared settings for all the projects below. See https://playwright.dev/docs/api/class-testoptions. */
  use: {
    /* Base URL to use in actions like `await page.goto('/')`. */
    baseURL: process.env.BASE_URL || 'https://mago.test/',

    /* Collect trace when retrying the failed test. See https://playwright.dev/docs/trace-viewer */
    trace: 'retain-on-failure',

    /* Opt-in screen recording, for producing demo clips of a spec rather than for debugging.
       Off by default because recording every test costs time and disk on a suite this size.
       Enable per run: MAGO_DEMO_VIDEO=1 npx playwright test -g "..." */
    video: process.env.MAGO_DEMO_VIDEO ? 'on' : 'off',

    ignoreHTTPSErrors: true,
  },

  /* The slowest test measured locally is 10 seconds, plus 15 seconds of headroom.
     Anything reaching this ceiling is hung rather than slow. */
  timeout: 25000,

  /* Configure projects for major browsers */
  projects: [
    { name: 'setup', testMatch: /.*\.setup\.ts/ },

    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        storageState: '.auth/backend.json',
      },

      dependencies: ['setup'],
    },

    // {
    //   name: 'firefox',
    //   use: { ...devices['Desktop Firefox'] },
    // },
    //
    // {
    //   name: 'webkit',
    //   use: { ...devices['Desktop Safari'] },
    // },

    /* Test against mobile viewports. */
    // {
    //   name: 'Mobile Chrome',
    //   use: { ...devices['Pixel 5'] },
    // },
    // {
    //   name: 'Mobile Safari',
    //   use: { ...devices['iPhone 12'] },
    // },
  ],
});

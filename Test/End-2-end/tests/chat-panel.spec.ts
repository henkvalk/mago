/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import {lookupProduct, streamFailure} from 'Fixtures/scenarios';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

test('Greets the admin user with the welcome screen when the panel is opened', async ({page}) => {
  await chatMock.install(page, lookupProduct);

  await chatPanel.openOnDashboard(page);

  await expect(chatPanel.panel(page)).toHaveClass(/is-empty/);
  await expect(chatPanel.welcome(page)).toBeVisible();
  await expect(chatPanel.welcome(page)).toContainText('what needs doing?');
  await expect(chatPanel.assistantMessages(page)).toHaveCount(0);
});

test('Replaces the welcome screen with the conversation once a question is asked', async ({page}) => {
  await chatMock.install(page, lookupProduct);

  await chatPanel.openOnDashboard(page);
  await chatPanel.ask(page, 'Which products contain candle?');

  await expect(chatPanel.panel(page)).not.toHaveClass(/is-empty/);
  await expect(chatPanel.welcome(page)).toBeHidden();
  await expect(chatPanel.userMessages(page)).toHaveCount(1);
});

test('Keeps the conversation when the panel is closed and reopened', async ({page}) => {
  await chatMock.install(page, lookupProduct);

  await chatPanel.openOnDashboard(page);
  await chatPanel.ask(page, 'Which products contain candle?');
  await expect(chatPanel.assistantMessages(page)).toHaveCount(1);

  await chatPanel.close(page);
  await chatPanel.open(page);

  await expect(chatPanel.panel(page)).toHaveClass(/is-open/);
  await expect(chatPanel.assistantMessages(page)).toHaveCount(1);
});

test('Starts a fresh conversation in a second tab instead of sharing the first tab\'s', async ({page, context}) => {
  await chatMock.install(page, lookupProduct);
  await chatPanel.openOnDashboard(page);
  await chatPanel.ask(page, 'Which products contain candle?');
  await expect(chatPanel.assistantMessages(page)).toHaveCount(1);

  const secondTab = await context.newPage();
  await chatMock.install(secondTab, lookupProduct);
  await chatPanel.openOnDashboard(secondTab);

  const streamRequest = secondTab.waitForRequest(/\/mago\/chat\/stream/);
  await chatPanel.ask(secondTab, 'Which products contain candle?');
  const body = JSON.parse((await streamRequest).postData() ?? '{}');

  expect(body.conversation_id).toBeNull();
  await expect(chatPanel.userMessages(secondTab)).toHaveCount(1);
  await secondTab.close();
});

test('Filters skills in the slash menu and fills the input on selection', async ({page}) => {
  await chatMock.install(page, lookupProduct);

  await chatPanel.openOnDashboard(page);
  await chatPanel.input(page).pressSequentially('/');

  await expect(chatPanel.slashMenu(page)).toHaveClass(/is-visible/);
  const skillCount = await chatPanel.slashItems(page).count();

  await chatPanel.input(page).pressSequentially('coupon');

  await expect(chatPanel.slashItems(page).first()).toContainText('/coupon_manager');
  expect(await chatPanel.slashItems(page).count()).toBeLessThan(skillCount);

  await chatPanel.input(page).press('Enter');

  await expect(chatPanel.input(page)).toHaveValue('Use the coupon_manager skill to ');
  await expect(chatPanel.slashMenu(page)).not.toHaveClass(/is-visible/);
  await expect(chatPanel.userMessages(page)).toHaveCount(0);
});

test('Surfaces a streamed error instead of failing silently', async ({page}) => {
  await chatMock.install(page, streamFailure);

  await chatPanel.openOnDashboard(page);
  await chatPanel.ask(page, 'Give me the revenue for last month');

  await expect(chatPanel.lastAssistantMessage(page)).toContainText('rate limit exceeded');
  await expect(chatPanel.sendButton(page)).toBeEnabled();
});

test('Places the toggle in the admin header, between search and notifications, not floating over the page', async ({page}) => {
  await chatMock.install(page, lookupProduct);

  await chatPanel.openOnDashboard(page);

  await expect(page.locator('.mago-toggle-tab')).toHaveCount(0);
  await expect(page.locator('.page-header #mago-toggle')).toHaveCount(1);

  // Search reserves a box wider than its visible icon (the icon itself is
  // right-aligned inside it), so neighbours sit a few px inside that box by
  // design - comparing left edges avoids being tripped up by that overlap.
  const searchBox = await page.locator('.search-global').boundingBox();
  const toggleBox = await page.locator('#mago-toggle').boundingBox();
  const notificationsBox = await page.locator('.notifications-wrapper').boundingBox();
  const userBox = await page.locator('.admin-user').boundingBox();

  expect(toggleBox.x).toBeGreaterThan(searchBox.x);
  expect(notificationsBox.x).toBeGreaterThan(toggleBox.x);
  expect(userBox.x).toBeGreaterThan(notificationsBox.x);
});

test('Makes it obvious the header icon closes Mago once the panel is open', async ({page}) => {
  await chatMock.install(page, lookupProduct);

  await chatPanel.openOnDashboard(page);

  const toggle = page.locator('#mago-toggle');
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(toggle).toHaveAttribute('title', /Close/);

  await toggle.click();

  await expect(chatPanel.panel(page)).not.toHaveClass(/is-open/);
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(toggle).toHaveAttribute('title', /Open/);
});

test('Colors the header icon with the configured accent color so it stands out', async ({page}) => {
  await chatMock.install(page, lookupProduct);

  await page.goto('/' + (process.env.ADMIN_PATH || 'admin') + '/admin/dashboard', {waitUntil: 'load'});

  const toggle = page.locator('#mago-toggle');
  const accent = await page.evaluate(
    () => getComputedStyle(document.documentElement).getPropertyValue('--mago-accent').trim()
  );
  const iconColor = await toggle.evaluate((el) => getComputedStyle(el).color);
  const accentRgb = await page.evaluate((hex) => {
    const probe = document.createElement('div');
    probe.style.color = hex;
    document.body.appendChild(probe);
    const rgb = getComputedStyle(probe).color;
    probe.remove();
    return rgb;
  }, accent);

  expect(iconColor).toBe(accentRgb);
});

test('Does not push search, notifications or the user menu away from their original position', async ({page}) => {
  await chatMock.install(page, lookupProduct);

  await page.goto('/' + (process.env.ADMIN_PATH || 'admin') + '/admin/dashboard', {waitUntil: 'load'});

  // Before Mago's icon existed, these three were floated right and sat flush
  // against the actions column's own right edge. Adding a fourth icon must
  // claim the column's spare width, not re-anchor the whole group to the left
  // and leave a gap where the user menu used to end.
  const actionsBox = await page.locator('.page-header-actions').boundingBox();
  const userBox = await page.locator('.admin-user').boundingBox();

  expect(Math.abs((userBox.x + userBox.width) - (actionsBox.x + actionsBox.width))).toBeLessThan(2);
});

test('Lines the header icon up with the icons it sits between', async ({page}) => {
  await chatMock.install(page, lookupProduct);

  await page.goto('/' + (process.env.ADMIN_PATH || 'admin') + '/admin/dashboard', {waitUntil: 'load'});

  // Admin themes size their header icons differently, so the toggle takes its
  // box from the theme rather than from fixed pixels: same height and same
  // center line as the icons on either side of it, whatever the theme picks.
  const toggleBox = await page.locator('#mago-toggle').boundingBox();
  const notificationsBox = await page.locator('.notifications-action').boundingBox();
  const userBox = await page.locator('.admin-user .admin__action-dropdown').boundingBox();

  const centerY = (box: {y: number, height: number}): number => box.y + box.height / 2;

  expect(Math.abs(toggleBox.height - notificationsBox.height)).toBeLessThan(2);
  expect(Math.abs(centerY(toggleBox) - centerY(notificationsBox))).toBeLessThan(2);
  expect(Math.abs(centerY(toggleBox) - centerY(userBox))).toBeLessThan(2);
});

/*
 * Copyright © Mago Assistant
 */

import {expect, test, type Page} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import {
  formApplyOnRead,
  formApplyOnConfirm,
  formApplyUnknownType,
  formApplyNotAnObject,
  formWriteDirective,
} from 'Fixtures/scenarios';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

/**
 * form-bridge.js does not have apply() yet (Task 007 adds it), so these specs prove routing only:
 * chat-panel.js's form_apply handling is replaced by a recording stub before the event arrives, and
 * these specs assert the stub was (or was not) called, never any actual form effect.
 */
async function stubFormBridgeApply(page: Page) {
  await page.waitForFunction(() => !!(window as any).magoFormBridge);
  await page.evaluate(() => {
    (window as any).__appliedDirectives = [];
    (window as any).magoFormBridge.apply = function (directive: unknown) {
      (window as any).__appliedDirectives.push(directive);
    };
  });
}

async function appliedDirectives(page: Page) {
  return page.evaluate(() => (window as any).__appliedDirectives || []);
}

test('it hands a form_apply event to the form bridge on the stream response', async ({page}) => {
  await chatMock.install(page, formApplyOnRead);

  await chatPanel.openOnDashboard(page);
  await stubFormBridgeApply(page);
  await chatPanel.ask(page, 'Update the product name');

  await expect(chatPanel.lastAssistantMessage(page)).toContainText('Done.');
  expect(await appliedDirectives(page)).toEqual([formWriteDirective]);
});

test('it hands a form_apply event to the form bridge on the confirm response', async ({page}) => {
  await chatMock.install(page, formApplyOnConfirm);

  await chatPanel.openOnDashboard(page);
  await stubFormBridgeApply(page);
  await chatPanel.ask(page, 'Update the product name');

  await expect(chatPanel.confirmActions(page)).toHaveCount(1);
  await chatPanel.confirmButton(page).click();

  await expect(chatPanel.lastAssistantMessage(page)).toContainText('Updated the product name.');
  expect(await appliedDirectives(page)).toEqual([formWriteDirective]);
});

test('it ignores a directive with an unknown type', async ({page}) => {
  await chatMock.install(page, formApplyUnknownType);

  await chatPanel.openOnDashboard(page);
  await stubFormBridgeApply(page);
  await chatPanel.ask(page, 'Update the product name');

  await expect(chatPanel.lastAssistantMessage(page)).toContainText('Done.');
  expect(await appliedDirectives(page)).toEqual([]);
});

test('it ignores a directive that is not an object', async ({page}) => {
  await chatMock.install(page, formApplyNotAnObject);

  await chatPanel.openOnDashboard(page);
  await stubFormBridgeApply(page);
  await chatPanel.ask(page, 'Update the product name');

  await expect(chatPanel.lastAssistantMessage(page)).toContainText('Done.');
  expect(await appliedDirectives(page)).toEqual([]);
});

test('it renders the rest of the message normally when a directive is malformed', async ({page}) => {
  await chatMock.install(page, formApplyNotAnObject);

  await chatPanel.openOnDashboard(page);
  await chatPanel.ask(page, 'Update the product name');

  await expect(chatPanel.lastAssistantMessage(page)).toContainText('Updating the product name. Done.');
});

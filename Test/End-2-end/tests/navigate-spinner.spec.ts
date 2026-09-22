/*
 * Copyright © Mago Assistant
 */

import {expect, test, type Page} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock, {type ChatScenario, textDeltas} from 'Actions/backend/ChatMock';
import MagentoApi from 'Services/MagentoApi';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();
const magentoApi = new MagentoApi();

const CONVERSATION_ID = 9401;
const MESSAGE_ID = 200901;
const SS_KEY_NAVIGATE_INTENT = 'mago_navigate_intent';

/**
 * Covers chat-panel.js's own 8 s wait for the target form (NAVIGATE_INTENT_FORM_TIMEOUT_MS) on
 * top of the page load itself.
 */
const NAVIGATE_FORM_TIMEOUT_MARGIN = 20000;

function navigateDirectiveScenario(directive: Record<string, unknown>): ChatScenario {
  return {
    stream: [
      {event: 'conversation', data: {conversation_id: CONVERSATION_ID, admin_user: 'Tester'}},
      ...textDeltas('On it. '),
      {event: 'tool_call', data: {id: 'toolu_navigate_1', name: 'page_form', input: {action: 'write_fields'}}},
      {
        event: 'confirm',
        data: {
          tools: [
            {
              name: 'page_form',
              description: 'Read and write the admin form currently open in the browser',
              input: {action: 'write_fields'},
            },
          ],
        },
      },
      {event: 'done', data: {message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true}},
    ],
    status: {message_id: MESSAGE_ID},
    confirm: [
      {event: 'form_apply', data: directive},
      ...textDeltas('On my way.'),
      {event: 'done', data: {conversation_id: CONVERSATION_ID}},
    ],
  };
}

async function proposeNavigateAndConfirm(page: Page, directive: Record<string, unknown>) {
  await chatMock.install(page, navigateDirectiveScenario(directive));
  await chatPanel.ask(page, 'Rename that product');
  await expect(chatPanel.confirmButton(page)).toHaveCount(1);
  await chatPanel.confirmButton(page).click();
}

/**
 * Playwright cannot read a page while a main-frame navigation is pending: every locator assertion
 * and every evaluate() waits the navigation out, which is exactly the window this spec is about, so
 * holding the target page's response open only makes the reads hang until the test times out.
 * Failing the request instead ends the navigation immediately and leaves the browser on the page
 * the assistant is sending the administrator away from, in the state it had the moment it started
 * leaving - which is the state under test.
 */
function failTheNavigationTo(page: Page, url: string) {
  return page.route((requested) => requested.href === url, (route) => route.abort('aborted'));
}

function navigateStatusText(page: Page) {
  return page.evaluate(() => {
    const el = document.querySelector('#mago-messages .mago-navigate-status');

    return el ? (el.textContent || '').trim() : null;
  });
}

function hasNavigateSpinner(page: Page) {
  return page.evaluate(
    () => !!document.querySelector('#mago-messages .mago-navigate-status .mago-tool-status-spinner')
  );
}

function isSendDisabled(page: Page) {
  return page.evaluate(() => (document.querySelector('#mago-send') as HTMLButtonElement | null)?.disabled === true);
}

function storeNavigateIntent(page: Page, intent: Record<string, unknown>) {
  return page.evaluate(
    ([key, value]) => sessionStorage.setItem(key as string, JSON.stringify(value)),
    [SS_KEY_NAVIGATE_INTENT, intent] as const
  );
}

test.describe('Spinner while navigating to a form', () => {
  test.describe.configure({mode: 'serial'});

  let sku: string;
  let productId: number;

  test.beforeAll(async ({request}) => {
    const product = await magentoApi.createProduct(request);

    sku = product.sku;
    productId = product.id;
  });

  test.afterAll(async ({request}) => {
    await magentoApi.deleteProduct(request, sku);
  });

  test('it shows a spinner on the page being left while the target page is still loading', async ({page}) => {
    test.setTimeout(40000);

    const targetUrl = await chatPanel.keyedUrlFor(page, 'catalog/product/edit/id/' + productId);

    await chatPanel.openOnDashboard(page);
    await failTheNavigationTo(page, targetUrl);

    const dashboardUrl = page.url();

    await proposeNavigateAndConfirm(page, {
      type: 'form_navigate',
      target: {entity_type: 'product', entity_id: String(productId), store_id: ''},
      url: targetUrl,
      changes: [{path: 'data.product.name', value: 'Spinner While Leaving'}],
    });

    await expect.poll(() => navigateStatusText(page)).toContain('product #' + productId);
    expect(await hasNavigateSpinner(page)).toBe(true);
    await expect.poll(() => isSendDisabled(page)).toBe(true);
    expect(page.url()).toBe(dashboardUrl);
  });

  test('it keeps the spinner on the target page until the approved fields are staged', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await storeNavigateIntent(page, {
      target: {entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [{path: 'data.product.name', value: 'Spinner Until Staged'}],
      expiresAt: Date.now() + 60000,
    });

    await page.reload({waitUntil: 'commit'});

    await expect(chatPanel.navigateStatus(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    await expect(page.locator('[name="product[name]"]'))
      .toHaveValue('Spinner Until Staged', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.navigateStatus(page)).toHaveCount(0, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.lastAssistantMessage(page)).toContainText(/staged/i, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.sendButton(page)).toBeEnabled();
  });

  test('it removes the spinner when the target form never loads', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOnDashboard(page);

    await storeNavigateIntent(page, {
      target: {entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [{path: 'data.product.name', value: 'Spinner Until Timeout'}],
      expiresAt: Date.now() + 60000,
    });

    await page.reload({waitUntil: 'load'});

    await expect(chatPanel.navigateStatus(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.navigateStatus(page)).toContainText('product #' + productId);
    await expect(chatPanel.sendButton(page)).toBeDisabled();

    await expect(chatPanel.lastAssistantMessage(page))
      .toContainText(/did not load in time/i, {timeout: NAVIGATE_FORM_TIMEOUT_MARGIN});
    await expect(chatPanel.navigateStatus(page)).toHaveCount(0);
    await expect(chatPanel.sendButton(page)).toBeEnabled();
  });
});

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

const CONVERSATION_ID = 9201;
const MESSAGE_ID = 200701;
const SS_KEY_NAVIGATE_INTENT = 'mago_navigate_intent';

/**
 * A real page navigation, on top of the real-backend round trip PROVIDER_ROUND_TRIP_TIMEOUT already
 * covers, regularly exceeds Playwright's 5 s assertion default under the suite's parallelism. This
 * spec's own `whenFormReady()` wait on the client is bounded separately, by
 * NAVIGATE_INTENT_FORM_TIMEOUT_MS in chat-panel.js (8 s); the margin below covers both.
 */
const NAVIGATE_FORM_TIMEOUT_MARGIN = 20000;

/**
 * Task 006's WriteFieldsAction is what produces a real form_navigate directive when no matching
 * form is open; this task is only about what the browser does once it already has one, so a
 * ChatMock scenario that carries the directive straight through the confirm response (the same
 * shape task 005/007 already proved chat-panel.js routes to the bridge) is enough. No provider, no
 * WriteFieldsAction, no WireMock.
 */
function navigateDirectiveScenario(directive: Record<string, unknown>, summaryText: string): ChatScenario {
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
      ...textDeltas(summaryText),
      {event: 'done', data: {conversation_id: CONVERSATION_ID}},
    ],
  };
}

async function proposeNavigateAndConfirm(page: Page, directive: Record<string, unknown>, summaryText = 'On my way.') {
  await chatMock.install(page, navigateDirectiveScenario(directive, summaryText));
  await chatPanel.ask(page, 'Rename that product');
  await expect(chatPanel.confirmButton(page)).toHaveCount(1);
  await chatPanel.confirmButton(page).click();
}

function storeNavigateIntent(page: Page, intent: Record<string, unknown>) {
  return page.evaluate(
    ([key, value]) => sessionStorage.setItem(key as string, JSON.stringify(value)),
    [SS_KEY_NAVIGATE_INTENT, intent] as const
  );
}

test.describe('Navigate then act on the product form', () => {
  /* Every test below acts on the same disposable product, and openOn()/keyedUrlFor() themselves
     mutate shared admin grid bookmark state, so these cannot safely run in parallel. */
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

  test('it navigates to the target entity when the assistant writes to a form that is not open', async ({page}) => {
    test.setTimeout(40000);

    const targetUrl = await chatPanel.keyedUrlFor(page, 'catalog/product/edit/id/' + productId);

    await chatPanel.openOnDashboard(page);

    const navigated = page.waitForURL(targetUrl, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    await proposeNavigateAndConfirm(page, {
      type: 'form_navigate',
      target: {entity_type: 'product', entity_id: String(productId), store_id: ''},
      url: targetUrl,
      changes: [{path: 'data.product.name', value: 'Navigated Name'}],
    });

    await navigated;

    expect(page.url()).toBe(targetUrl);
  });

  test('it stages the approved fields once the target form has loaded', async ({page}) => {
    test.setTimeout(40000);

    const targetUrl = await chatPanel.keyedUrlFor(page, 'catalog/product/edit/id/' + productId);

    await chatPanel.openOnDashboard(page);

    const navigated = page.waitForURL(targetUrl, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    await proposeNavigateAndConfirm(page, {
      type: 'form_navigate',
      target: {entity_type: 'product', entity_id: String(productId), store_id: ''},
      url: targetUrl,
      changes: [{path: 'data.product.name', value: 'Staged After Navigate'}],
    });

    await navigated;
    await chatPanel.waitForFormRegistered(page);

    await expect(page.locator('[name="product[name]"]'))
      .toHaveValue('Staged After Navigate', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(page.locator('.admin__field[data-index="name"]'))
      .toHaveClass(/mago-field-changed/, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
  });

  test('it leaves the navigated form unsaved', async ({page, request}) => {
    test.setTimeout(40000);

    const targetUrl = await chatPanel.keyedUrlFor(page, 'catalog/product/edit/id/' + productId);

    await chatPanel.openOnDashboard(page);

    const navigated = page.waitForURL(targetUrl, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    await proposeNavigateAndConfirm(page, {
      type: 'form_navigate',
      target: {entity_type: 'product', entity_id: String(productId), store_id: ''},
      url: targetUrl,
      changes: [{path: 'data.product.name', value: 'Not Persisted Yet'}],
    });

    await navigated;
    await chatPanel.waitForFormRegistered(page);

    await expect(page.locator('[name="product[name]"]'))
      .toHaveValue('Not Persisted Yet', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const persisted = await magentoApi.findProduct(request, sku);

    expect(persisted.name).not.toBe('Not Persisted Yet');
  });

  test('it applies a stored intent only once', async ({page, request}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await storeNavigateIntent(page, {
      target: {entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [{path: 'data.product.name', value: 'Applied Once'}],
      expiresAt: Date.now() + 60000,
    });

    await page.reload({waitUntil: 'load'});
    await chatPanel.waitForFormRegistered(page);

    await expect(page.locator('[name="product[name]"]'))
      .toHaveValue('Applied Once', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    /* Nothing was ever saved, and the intent was consumed on that first load, so a second, plain
       reload must show the product's real, still-unstaged name again rather than restaging it. */
    await page.reload({waitUntil: 'load'});
    await chatPanel.waitForFormRegistered(page);

    const persisted = await magentoApi.findProduct(request, sku);

    await expect(page.locator('[name="product[name]"]'))
      .toHaveValue(persisted.name, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
  });

  test('it discards a stored intent that has expired', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    const originalName = await page.locator('[name="product[name]"]').inputValue();

    await storeNavigateIntent(page, {
      target: {entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [{path: 'data.product.name', value: 'Should Not Apply Expired'}],
      expiresAt: Date.now() - 1000,
    });

    await page.reload({waitUntil: 'load'});
    await chatPanel.waitForFormRegistered(page);

    await expect(page.locator('[name="product[name]"]'))
      .toHaveValue(originalName, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(page.locator('.admin__field[data-index="name"]')).not.toHaveClass(/mago-field-changed/);

    const remaining = await page.evaluate((key) => sessionStorage.getItem(key), SS_KEY_NAVIGATE_INTENT);

    expect(remaining).toBeNull();
  });

  test('it discards the stored intent when the admin lands on a different entity', async ({page, request}) => {
    test.setTimeout(40000);

    const otherProduct = await magentoApi.createProduct(request);

    try {
      await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

      const originalName = await page.locator('[name="product[name]"]').inputValue();

      await storeNavigateIntent(page, {
        target: {entity_type: 'product', entity_id: String(otherProduct.id), store_id: ''},
        changes: [{path: 'data.product.name', value: 'Wrong Product'}],
        expiresAt: Date.now() + 60000,
      });

      await page.reload({waitUntil: 'load'});
      await chatPanel.waitForFormRegistered(page);

      await expect(page.locator('[name="product[name]"]'))
        .toHaveValue(originalName, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
      await expect(chatPanel.lastAssistantMessage(page))
        .toContainText(/different/i, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    } finally {
      await magentoApi.deleteProduct(request, otherProduct.sku);
    }
  });

  test('it discards the stored intent when the target form never loads', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOnDashboard(page);

    await storeNavigateIntent(page, {
      target: {entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [{path: 'data.product.name', value: 'Never Loads'}],
      expiresAt: Date.now() + 60000,
    });

    await page.reload({waitUntil: 'load'});

    await expect(chatPanel.lastAssistantMessage(page))
      .toContainText(/did not load in time/i, {timeout: NAVIGATE_FORM_TIMEOUT_MARGIN});

    const remaining = await page.evaluate((key) => sessionStorage.getItem(key), SS_KEY_NAVIGATE_INTENT);

    expect(remaining).toBeNull();
  });
});

test.describe('admin_navigator after the route map extraction', () => {
  test('it still resolves an entity link through admin_navigator after the route map is extracted', async ({page}) => {
    test.setTimeout(40000);

    const scenario: ChatScenario = {
      stream: [
        {event: 'conversation', data: {conversation_id: 9302, admin_user: 'Tester'}},
        {event: 'tool_call', data: {id: 'toolu_nav_1', name: 'admin_navigator', input: {entity_type: 'product', entity_id: 123}}},
        ...textDeltas('Here it is: [Product #123](/admin/catalog/product/edit/id/123/key/abcabcabc/)'),
        {event: 'done', data: {conversation_id: 9302}},
      ],
    };

    await chatMock.install(page, scenario);
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Where can I edit product 123?');

    await expect(chatPanel.toolTags(page)).toHaveText([/admin_navigator/], {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const link = chatPanel.lastAssistantMessage(page).locator('a[href*="catalog/product/edit/id/123"]');

    await expect(link).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
  });
});

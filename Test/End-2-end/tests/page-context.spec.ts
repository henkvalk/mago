/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import {promises as fs} from 'fs';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import MagentoApi from 'Services/MagentoApi';
import WireMock from 'Services/WireMock';
import {createCmsPage, lookupProduct} from 'Fixtures/scenarios';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();
const magentoApi = new MagentoApi();
const wireMock = new WireMock();

const DEBUG_LOG_PATH = process.env.MAGO_DEBUG_LOG || '/var/www/html/var/log/mago-debug.log';

/**
 * The debug log is one shared file for the whole suite, so a byte-offset diff can pick up another
 * test's concurrently-running turn as easily as its own once enough parallel workers are exercising
 * page_form actions at the same time. This isolates the lines that belong to the request carrying
 * triggerPhrase (from its own "Stream Request" line up to, but not including, the next one), so an
 * assertion against it reflects only what this test's own turn logged.
 */
function extractOwnTurnLog(appendedLog: string, triggerPhrase: string): string {
  const lines = appendedLog.split('\n');
  const ownLines: string[] = [];
  let capturing = false;

  for (const line of lines) {
    if (line.includes(triggerPhrase)) {
      capturing = true;
    } else if (capturing && line.includes('Stream Request:')) {
      break;
    }

    if (capturing) {
      ownLines.push(line);
    }
  }

  return ownLines.join('\n');
}

test.describe('Page context transport', () => {
  /* Every test below acts on the same disposable product, and openOn() itself mutates shared
     admin grid bookmark state, so these cannot safely run in parallel. */
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

  test('it posts the current admin route and form namespace with every chat message', async ({page}) => {
    test.setTimeout(40000);

    await chatMock.install(page, lookupProduct);
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    const streamRequest = page.waitForRequest(/\/mago\/chat\/stream/);

    await chatPanel.ask(page, 'What is this page?');

    const body = JSON.parse((await streamRequest).postData() ?? '{}');

    expect(body.page_context.route).toContain('catalog/product/edit');
    expect(body.page_context.namespace).toBe('product_form');
  });

  test('it posts the entity id and store scope of the open form', async ({page, request}) => {
    test.setTimeout(40000);

    const storeViews = await magentoApi.getStoreViews(request);
    const nonDefaultStore = storeViews.find((storeView: any) => storeView.id !== 0);

    await chatMock.install(page, lookupProduct);
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId + '/store/' + nonDefaultStore.id);

    const streamRequest = page.waitForRequest(/\/mago\/chat\/stream/);

    await chatPanel.ask(page, 'What is this page?');

    const body = JSON.parse((await streamRequest).postData() ?? '{}');

    expect(body.page_context.entityId).toBe(String(productId));
    expect(body.page_context.storeId).toBe(String(nonDefaultStore.id));
  });

  test('it posts the field snapshot of the open form', async ({page}) => {
    test.setTimeout(40000);

    await chatMock.install(page, lookupProduct);
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    const streamRequest = page.waitForRequest(/\/mago\/chat\/stream/);

    await chatPanel.ask(page, 'What is this page?');

    const body = JSON.parse((await streamRequest).postData() ?? '{}');
    const nameField = body.page_context.fields.find((field: any) => field.path.endsWith('.name'));

    expect(nameField.label).toBe('Product Name');
    expect(nameField.value).toBe('Mago E2E Product ' + sku);
  });

  test('it posts the same page context again when the admin confirms a write', async ({page}) => {
    test.setTimeout(40000);

    await chatMock.install(page, createCmsPage);
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await chatPanel.ask(page, 'Create a CMS page titled Summer Sale with url key summer-sale');

    await expect(chatPanel.confirmActions(page)).toHaveCount(1);

    const confirmRequest = page.waitForRequest(/\/mago\/chat\/confirm/);

    await chatPanel.confirmButton(page).click();

    const body = JSON.parse((await confirmRequest).postData() ?? '{}');

    expect(body.page_context.namespace).toBe('product_form');
    expect(body.page_context.entityId).toBe(String(productId));
  });

  test('it omits page context on an admin page that has no form', async ({page}) => {
    test.setTimeout(40000);

    await chatMock.install(page, lookupProduct);
    await chatPanel.openOnDashboard(page);

    const streamRequest = page.waitForRequest(/\/mago\/chat\/stream/);

    await chatPanel.ask(page, 'Which products contain candle?');

    const body = JSON.parse((await streamRequest).postData() ?? '{}');

    expect(body.page_context).toBeNull();
  });

  test.describe('Real backend', () => {
    test('it ignores a page context payload that is not an object', async ({page, request}) => {
      test.setTimeout(40000);

      await wireMock.ensureReachable(request);
      await chatPanel.openOnDashboard(page);

      /* Bypasses chat-panel.js on purpose: the client always sends a well-formed object, so the
         only way to reach the server's tolerance for a forged or malformed payload is a raw
         fetch that speaks the same wire format the panel does. */
      const streamBody = await page.evaluate(async () => {
        const config = (window as any).MAGO_CONFIG;
        const result = await fetch(config.streamUrl, {
          method: 'POST',
          headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
          body: JSON.stringify({
            message: 'E2E Page Context Ack Check: not an object test',
            conversation_id: null,
            form_key: config.formKey,
            page_context: ['not', 'an', 'object'],
          }),
          credentials: 'same-origin',
        });

        return result.text();
      });

      expect(streamBody).toContain('event: done');
      expect(streamBody).not.toContain('event: error');
      expect(streamBody).toContain('Page context check acknowledged');
    });

    test('it names the current admin page in the system prompt sent to the provider', async ({page, request}) => {
      test.setTimeout(40000);

      await wireMock.ensureReachable(request);
      await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
      await chatPanel.ask(page, 'E2E Page Context Ack Check: what page am I on?');

      await expect(chatPanel.lastAssistantMessage(page)).toContainText('Page context check acknowledged', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

      const requestBody = await wireMock.findRequestBody(request, 'E2E Page Context Ack Check');
      const systemMessage = requestBody.messages.find((message: any) => message.role === 'system');

      expect(systemMessage).toBeTruthy();
      expect(systemMessage.content).toContain('catalog/product/edit');
      expect(systemMessage.content).toContain('product #' + productId);
      expect(systemMessage.content).not.toContain('Mago E2E Product');
    });

    test('it names the current admin page when the conversation already has a system message', async ({page, request}) => {
      test.setTimeout(40000);

      await wireMock.ensureReachable(request);
      await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
      await chatPanel.ask(page, 'E2E Page Context Merge Check: diagnose sku e2e-page-context-check');

      await expect(chatPanel.toolTags(page)).toHaveText([/product_debug/], {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
      await expect(chatPanel.lastAssistantMessage(page)).toContainText('Diagnosis complete', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

      const requestBody = await wireMock.findRequestBody(
        request,
        'E2E Page Context Merge Check',
        (decodedBody) => decodedBody.includes('"role":"tool"')
      );
      const systemMessages = requestBody.messages.filter((message: any) => message.role === 'system');
      const pageContextMessages = systemMessages.filter((message: any) => message.content.includes('catalog/product/edit'));

      expect(systemMessages.length).toBeGreaterThan(1);
      expect(pageContextMessages).toHaveLength(1);
    });

    test('it keeps field values out of the debug log', async ({page, request}) => {
      test.setTimeout(40000);

      await wireMock.ensureReachable(request);

      const logSizeBefore = await fs.stat(DEBUG_LOG_PATH).then((stat) => stat.size).catch(() => 0);

      await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
      await chatPanel.ask(page, 'E2E Page Context Ack Check: what page am I on?');

      await expect(chatPanel.lastAssistantMessage(page)).toContainText('Page context check acknowledged', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

      const logContents = await fs.readFile(DEBUG_LOG_PATH, 'utf8');
      const appended = logContents.slice(logSizeBefore);
      /* Privacy mode (#97) masks the request body as text before it is logged, so it lands as one
         JSON-encoded string with its quotes escaped. */
      const ownTurnLog = extractOwnTurnLog(appended, 'E2E Page Context Ack Check').replace(/\\"/g, '"');

      expect(ownTurnLog).toContain('"namespace":"product_form"');
      expect(ownTurnLog).toContain('"entityId":"' + productId + '"');
      expect(ownTurnLog).toContain('"fieldCount"');
      expect(ownTurnLog).not.toContain('Mago E2E Product');
      expect(ownTurnLog).not.toContain('Product Name');
    });
  });
});

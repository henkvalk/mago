/*
 * Copyright © Mago Assistant
 */

import {expect, test, type APIRequestContext} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import MagentoApi from 'Services/MagentoApi';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();
const magentoApi = new MagentoApi();

const WIREMOCK_ADMIN_URL = 'http://wiremock:8080/__admin/requests';

/**
 * describe_form and read_fields read the request-scoped PageContext the controller builds from
 * the browser's page_context payload, so only a real ChatService turn against WireMock (rather
 * than ChatMock, which intercepts in the browser before PHP ever runs) can prove what they return.
 */
async function ensureWireMockReachable(request: APIRequestContext) {
  const response = await request.get('http://wiremock:8080/__admin/mappings').catch(() => null);

  if (!response || !response.ok()) {
    throw new Error(
      'WireMock is not reachable at http://wiremock:8080. These specs need the mageos_ai/services/configuration '
      + 'service row pointed at WireMock (Stores > Configuration > Mage-OS > AI Configuration).'
    );
  }
}

/**
 * Reads the page_form tool result back out of the follow-up request WireMock received, rather
 * than the assistant's final text, so these specs assert the actual structured data the action
 * produced instead of a canned summary sentence.
 */
async function findPageFormToolResult(request: APIRequestContext, triggerPhrase: string): Promise<any> {
  for (let attempt = 0; attempt < 30; attempt++) {
    const response = await request.get(WIREMOCK_ADMIN_URL);
    const payload = await response.json();
    const matches = (payload.requests ?? []).filter((entry: any) => {
      if (entry.request.url !== '/v1/chat/completions') {
        return false;
      }
      const decoded = Buffer.from(entry.request.bodyAsBase64, 'base64').toString('utf8');
      return decoded.includes(triggerPhrase) && decoded.includes('"role":"tool"');
    });

    if (matches.length > 0) {
      /* WireMock's request journal is newest-first, not chronological. */
      const decoded = Buffer.from(matches[0].request.bodyAsBase64, 'base64').toString('utf8');
      const body = JSON.parse(decoded);
      const toolMessage = body.messages.find((message: any) => message.role === 'tool');

      return JSON.parse(toolMessage.content);
    }

    await new Promise((resolve) => setTimeout(resolve, 500));
  }

  throw new Error(
    'No request containing "' + triggerPhrase + '" with a tool result reached WireMock within the timeout.'
  );
}

test.describe('page_form skill', () => {
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

  test('it lists the fields of the open product form when the assistant calls describe_form', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await chatPanel.ask(page, 'E2E PageForm Describe Check: what fields are on this form?');

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Form described.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const toolResult = await findPageFormToolResult(request, 'E2E PageForm Describe Check');
    const nameField = toolResult.fields.find((field: any) => field.path === 'data.product.name');

    expect(nameField.label).toBe('Product Name');
    expect(nameField.type).toBe('input');
    expect(toolResult.total_fields).toBeGreaterThan(0);
  });

  test('it keeps the last-registered fields of a large form in describe_form', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);
    /* The New Product form is the largest form the module meets, and category_ids registers last
       on it: a byte cap that drops fields from the tail loses exactly that one. */
    await chatPanel.openOn(page, 'catalog/product/new/set/4/type/simple');
    await chatPanel.ask(page, 'E2E PageForm Describe Check: what fields are on this form?');

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Form described.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const toolResult = await findPageFormToolResult(request, 'E2E PageForm Describe Check');
    const paths = toolResult.fields.map((field: any) => field.path);

    expect(paths).toContain('data.product.category_ids');
    expect(paths).toContain('data.product.name');
  });

  test('it reports the form namespace entity id and store scope from describe_form', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);

    const storeViews = await magentoApi.getStoreViews(request);
    const nonDefaultStore = storeViews.find((storeView: any) => storeView.id !== 0);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId + '/store/' + nonDefaultStore.id);
    await chatPanel.ask(page, 'E2E PageForm Describe Scope Check: what page is this?');

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Form described.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const toolResult = await findPageFormToolResult(request, 'E2E PageForm Describe Scope Check');

    expect(toolResult.namespace).toBe('product_form');
    expect(toolResult.entity_type).toBe('product');
    expect(toolResult.entity_id).toBe(String(productId));
    expect(toolResult.store_id).toBe(String(nonDefaultStore.id));
  });

  test('it omits field values from the describe_form result', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await chatPanel.ask(page, 'E2E PageForm Describe NoValues Check: what fields are on this form?');

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Form described.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const toolResult = await findPageFormToolResult(request, 'E2E PageForm Describe NoValues Check');

    expect(toolResult.fields.length).toBeGreaterThan(0);
    expect(toolResult.fields.every((field: any) => !('value' in field))).toBe(true);
  });

  test('it returns the value of a named field when the assistant calls read_fields', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await chatPanel.ask(page, 'E2E PageForm ReadField Check: what is the product name?');

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Fields read.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const toolResult = await findPageFormToolResult(request, 'E2E PageForm ReadField Check');
    const nameField = toolResult.fields.find((field: any) => field.path === 'data.product.name');

    expect(nameField.found).toBe(true);
    expect(nameField.value).toBe('Mago E2E Product ' + sku);
  });

  test('it returns values for several named fields in one call', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await chatPanel.ask(page, 'E2E PageForm ReadFields Multi Check: what are the name and sku?');

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Fields read.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const toolResult = await findPageFormToolResult(request, 'E2E PageForm ReadFields Multi Check');
    const nameField = toolResult.fields.find((field: any) => field.path === 'data.product.name');
    const skuField = toolResult.fields.find((field: any) => field.path === 'data.product.sku');

    expect(toolResult.fields).toHaveLength(2);
    expect(nameField.value).toBe('Mago E2E Product ' + sku);
    expect(skuField.value).toBe(sku);
  });

  test('it reports that no form is open when called on the dashboard', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'E2E PageForm NoForm Check: what fields are on this form?');

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Form described.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const toolResult = await findPageFormToolResult(request, 'E2E PageForm NoForm Check');

    expect(toolResult.form_open).toBe(false);
    expect(toolResult.message.toLowerCase()).toContain('navigate');
  });

  test('it reports an unknown field path instead of returning an empty value', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await chatPanel.ask(page, 'E2E PageForm UnknownField Check: what is this field?');

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Fields read.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const toolResult = await findPageFormToolResult(request, 'E2E PageForm UnknownField Check');
    const unknownField = toolResult.fields[0];

    expect(unknownField.found).toBe(false);
    expect(unknownField.value).toBeUndefined();
    expect(unknownField.message.toLowerCase()).toContain('not a field on this form');
  });
});

test('it flips the page_form skill to a write skill in the skills grid', async ({page}) => {
  const items = await chatPanel.openSkillsGrid(page);
  const pageForm = items.find((item: any) => item.name === 'page_form');

  expect(pageForm.category).toBe('Form');
  expect(pageForm.type).toBe('write');
});

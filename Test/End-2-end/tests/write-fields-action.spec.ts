/*
 * Copyright © Mago Assistant
 */

import {expect, test, type APIRequestContext, type Page} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import MagentoApi from 'Services/MagentoApi';
import {stageFieldChange} from 'Fixtures/scenarios';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();
const magentoApi = new MagentoApi();

/**
 * page_form.write_fields is a write action, so ChatMock (which never reaches PHP) is enough to
 * prove the panel asks for confirmation, renders it readably, and stages nothing on rejection: none
 * of that depends on the action's own validation. The validation itself (target and field checks,
 * the directive contents) can only be proven against the real ChatService and PHP action, so those
 * requirements live in the "Real backend" describe block below, against WireMock.
 */
test.describe('page_form.write_fields confirmation prompt', () => {
  test('it asks the admin to confirm before staging a field change', async ({page}) => {
    await chatMock.install(page, stageFieldChange);

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Rename the product to Sedia');

    await expect(chatPanel.confirmButton(page)).toHaveCount(1);
    await expect(chatPanel.rejectButton(page)).toHaveCount(1);
  });

  test('it names the field label with its old and new value in the confirmation prompt', async ({page, request}) => {
    test.setTimeout(40000);

    const product = await magentoApi.createProduct(request);

    try {
      await chatMock.install(page, stageFieldChange);
      await chatPanel.openOn(page, 'catalog/product/edit/id/' + product.id);
      await chatPanel.ask(page, 'Rename the product to Sedia');

      await expect(chatPanel.lastAssistantMessage(page)).toContainText('Stage 1 field on product #' + product.id);
      await expect(chatPanel.lastAssistantMessage(page)).toContainText('Product Name');
      await expect(chatPanel.lastAssistantMessage(page)).toContainText('Mago E2E Product ' + product.sku);
      await expect(chatPanel.lastAssistantMessage(page)).toContainText('Sedia');
      await expect(chatPanel.lastAssistantMessage(page)).toContainText('Nothing is saved until you click Save');
    } finally {
      await magentoApi.deleteProduct(request, product.sku);
    }
  });

  test('it renders a field value containing markup as text in the confirmation prompt', async ({page, request}) => {
    test.setTimeout(40000);

    /* A backtick closes the code span the old prompt wrapped values in; whatever follows was
       raw HTML for marked, so a product description written by an import could run script in
       the confirming admin's session. */
    const hostileName = 'x` <img src=x onerror="document.title=\'owned\'"> `y';
    const product = await magentoApi.createProduct(request, {
      name: hostileName,
      custom_attributes: [{attribute_code: 'url_key', value: 'mago-e2e-hostile-' + Date.now()}],
    });

    try {
      await chatMock.install(page, stageFieldChange);
      await chatPanel.openOn(page, 'catalog/product/edit/id/' + product.id);
      await chatPanel.ask(page, 'Rename the product to Sedia');

      await expect(chatPanel.confirmButton(page)).toHaveCount(1);
      await expect(page.locator('#mago-messages img')).toHaveCount(0);
      await expect(chatPanel.lastAssistantMessage(page)).toContainText('<img src=x');
      expect(await page.title()).not.toBe('owned');
    } finally {
      await magentoApi.deleteProduct(request, product.sku);
    }
  });

  test('it stages nothing when the admin rejects the confirmation', async ({page}) => {
    await chatMock.install(page, stageFieldChange);
    const confirmCalls = chatMock.countRequestsTo(page, /\/mago\/chat\/confirm/);

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Rename the product to Sedia');
    await chatPanel.rejectButton(page).click();

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Action rejected. No changes were made.');
    await expect(chatPanel.confirmActions(page)).toHaveCount(0);
    expect(confirmCalls.total()).toBe(0);
  });
});

const WIREMOCK_URL = 'http://wiremock:8080';

/**
 * These specs exercise the real ChatService/Confirm controller and the real WriteFieldsAction
 * against WireMock, because only that can prove the action's own validation (target matching, field
 * lookup, the directive contents) rather than a scripted stand-in for it. Unlike the read-only
 * page_form specs, the entity id the model must "already know" (as if it had called describe_form)
 * has to equal this run's real, dynamically-created product id, which a static .sse fixture cannot
 * embed. So each test registers its own WireMock stub mapping at runtime, with that id baked in,
 * rather than reading one of the checked-in wiremock/mappings files.
 */
async function ensureWireMockReachable(request: APIRequestContext) {
  const response = await request.get(WIREMOCK_URL + '/__admin/mappings').catch(() => null);

  if (!response || !response.ok()) {
    throw new Error(
      'WireMock is not reachable at ' + WIREMOCK_URL + '. These specs need the mageos_ai/services/configuration '
      + 'service row pointed at WireMock (Stores > Configuration > Mage-OS > AI Configuration).'
    );
  }
}

function sseChunk(id: string, delta: Record<string, unknown>, finishReason: string | null = null): string {
  return 'data: ' + JSON.stringify({
    id: 'chatcmpl-e2e-' + id,
    object: 'chat.completion.chunk',
    created: 1754000000,
    model: 'gemma-3-4b-it-qat',
    choices: [{index: 0, delta, finish_reason: finishReason}],
  }) + '\n\n';
}

function toolCallSse(id: string, toolName: string, args: Record<string, unknown>): string {
  return sseChunk(id, {role: 'assistant', content: ''})
    + sseChunk(id, {content: 'Let me update that field.'})
    + sseChunk(id, {
      tool_calls: [{index: 0, id: 'call_' + id, type: 'function', function: {name: toolName, arguments: ''}}],
    })
    + sseChunk(id, {tool_calls: [{index: 0, function: {arguments: JSON.stringify(args)}}]})
    + sseChunk(id, {}, 'tool_calls')
    + 'data: [DONE]\n\n';
}

function summarySse(id: string, text: string): string {
  return sseChunk(id, {role: 'assistant', content: ''})
    + sseChunk(id, {content: text})
    + sseChunk(id, {}, 'stop')
    + 'data: [DONE]\n\n';
}

/**
 * Registers one stub mapping and returns its id, so the caller can delete it again. WireMock is a
 * single instance shared by the whole suite (and by whoever runs it next), not a fixture scoped to
 * one test, so anything registered at runtime has to be removed again or it silently pollutes every
 * later run: a stub left behind from an old, deleted product id sits there matching the same trigger
 * phrase as this run's fresh one, and which of the two WireMock picks becomes arbitrary.
 *
 * priority is deliberately far below (i.e. numerically lower, so higher-precedence than) the 1/2
 * range the checked-in wiremock/mappings/*.json fixtures use, so a runtime stub always wins even if
 * a body pattern were ever ambiguous against the file-based ones, rather than relying only on the
 * trigger phrase being unique.
 */
async function registerMapping(
  request: APIRequestContext,
  opts: {triggerPhrase: string; matchesToolResult: boolean; priority: number; body: string}
): Promise<string> {
  const response = await request.post(WIREMOCK_URL + '/__admin/mappings', {
    data: {
      priority: opts.priority,
      request: {
        method: 'POST',
        urlPath: '/v1/chat/completions',
        bodyPatterns: [
          {contains: opts.triggerPhrase},
          opts.matchesToolResult ? {contains: '"role":"tool"'} : {doesNotMatch: '.*"role":"tool".*'},
        ],
      },
      response: {
        status: 200,
        headers: {'Content-Type': 'text/event-stream'},
        body: opts.body,
      },
    },
  });
  const mapping = await response.json();

  return mapping.id;
}

/**
 * Registers both turns of one write_fields conversation: turn 1 is the model proposing the tool
 * call (matched on the trigger phrase, before any tool result exists in the conversation), turn 2 is
 * the model's summary once the confirmed write has run (matched on the same trigger phrase, now with
 * a "role":"tool" message in the body). This mirrors the two-mapping pattern the checked-in
 * wiremock/mappings/page-form.json fixtures use for describe_form and read_fields.
 *
 * The registered ids are pushed onto trackIds rather than returned, so every test in the describe
 * block below can call this the same way and have afterEach clean up without repeating the plumbing.
 */
async function installWriteFieldsTurn(
  request: APIRequestContext,
  opts: {triggerPhrase: string; id: string; toolArgs: Record<string, unknown>; summaryText: string},
  trackIds: string[]
): Promise<void> {
  trackIds.push(
    await registerMapping(request, {
      triggerPhrase: opts.triggerPhrase,
      matchesToolResult: false,
      priority: -1,
      body: toolCallSse(opts.id, 'page_form', {action: 'write_fields', ...opts.toolArgs}),
    }),
    await registerMapping(request, {
      triggerPhrase: opts.triggerPhrase,
      matchesToolResult: true,
      priority: -2,
      body: summarySse(opts.id, opts.summaryText),
    })
  );
}

/**
 * Reads the page_form tool result back out of the turn-2 request WireMock received, which is built
 * directly from the mago_message row Confirm.php persisted for that tool call, rather than from the
 * assistant's own summary text.
 */
async function findToolResult(request: APIRequestContext, triggerPhrase: string): Promise<any> {
  for (let attempt = 0; attempt < 30; attempt++) {
    const response = await request.get(WIREMOCK_URL + '/__admin/requests');
    const payload = await response.json();
    const matches = (payload.requests ?? []).filter((entry: any) => {
      if (entry.request.url !== '/v1/chat/completions') {
        return false;
      }
      const decoded = Buffer.from(entry.request.bodyAsBase64, 'base64').toString('utf8');
      return decoded.includes(triggerPhrase) && decoded.includes('"role":"tool"');
    });

    if (matches.length > 0) {
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

/**
 * form-bridge.js does not have apply() yet (task 007), so this replaces it with a recording stub,
 * exactly as form-apply-directive.spec.ts does for the ChatMock-driven specs.
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

async function appliedDirectives(page: Page): Promise<any[]> {
  return page.evaluate(() => (window as any).__appliedDirectives || []);
}

/**
 * Forces the fields the browser reports back to the server to a fixed, known set, independent of
 * whatever the real product form happens to contain. The form's own identity (namespace, entity
 * type, entity id, store scope) is left alone, so target-matching in the action is unaffected.
 */
async function overrideLiveFields(page: Page, fields: Record<string, unknown>[]) {
  await page.waitForFunction(() => !!(window as any).magoFormBridge);
  await page.evaluate((customFields) => {
    const bridge = (window as any).magoFormBridge;
    const original = bridge.snapshot.bind(bridge);

    bridge.snapshot = function () {
      const snapshot = original();

      snapshot.fields = customFields;
      snapshot.fieldCount = customFields.length;

      return snapshot;
    };
  }, fields);
}

/**
 * Simulates the administrator navigating to a different entity between the model proposing the
 * write and the administrator confirming it, without an actual page reload (which would drop the
 * pending confirmation UI). form-bridge.js would report a different entity id after a real
 * navigation; this reports one directly.
 */
async function overrideLiveEntityId(page: Page, entityId: string) {
  await page.waitForFunction(() => !!(window as any).magoFormBridge);
  await page.evaluate((fakeEntityId) => {
    const bridge = (window as any).magoFormBridge;
    const original = bridge.snapshot.bind(bridge);

    bridge.snapshot = function () {
      const snapshot = original();

      snapshot.entityId = fakeEntityId;

      return snapshot;
    };
  }, entityId);
}

test.describe('page_form.write_fields real backend', () => {
  /* Every test below acts on the same disposable product, and openOn() itself mutates shared admin
     grid bookmark state, so these cannot safely run in parallel. */
  test.describe.configure({mode: 'serial'});

  let sku: string;
  let productId: number;

  /* Populated by installWriteFieldsTurn() during each test and wiped out again in afterEach, so a
     stub registered for one test is never still present (and never still winning a match) for the
     next test, this run or a later one. */
  let mappingIds: string[] = [];

  test.beforeAll(async ({request}) => {
    const product = await magentoApi.createProduct(request);

    sku = product.sku;
    productId = product.id;
  });

  test.afterAll(async ({request}) => {
    await magentoApi.deleteProduct(request, sku);
  });

  test.afterEach(async ({request}) => {
    await Promise.all(
      mappingIds.map((id) => request.delete(WIREMOCK_URL + '/__admin/mappings/' + id).catch(() => {}))
    );
    mappingIds = [];
  });

  test('it returns a directive naming the form namespace entity id and store scope', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields Target Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-target-1',
      toolArgs: {
        form_namespace: 'product_form',
        entity_id: String(productId),
        store_id: '',
        changes: [{path: 'data.product.name', value: 'Sedia'}],
      },
      summaryText: 'Updated the product name.',
    }, mappingIds);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await stubFormBridgeApply(page);
    await chatPanel.ask(page, trigger + ': rename the product to Sedia');

    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.confirmButton(page).click();

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Updated the product name.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const directives = await appliedDirectives(page);

    expect(directives).toHaveLength(1);
    expect(directives[0].target).toEqual({
      namespace: 'product_form',
      entity_type: 'product',
      entity_id: String(productId),
      store_id: '',
      is_new: false,
    });
  });

  test('it refuses a field path that is not on the open form', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields UnknownPath Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-unknown-path-1',
      toolArgs: {
        form_namespace: 'product_form',
        entity_id: String(productId),
        store_id: '',
        changes: [{path: 'data.product.does_not_exist', value: 'x'}],
      },
      summaryText: 'That field does not exist on this form.',
    }, mappingIds);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await stubFormBridgeApply(page);
    await chatPanel.ask(page, trigger + ': set a field that does not exist');

    /* Refused before any confirmation: the answer arrives as a plain reply, no Confirm button. */

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('That field does not exist on this form.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    await expect(chatPanel.confirmActions(page)).toHaveCount(0);

    const toolResult = await findToolResult(request, trigger);

    expect(toolResult.error).toBeTruthy();
    expect(toolResult.error.toLowerCase()).toContain('not a field on this form');
    expect(toolResult.client_directive).toBeUndefined();
  });

  test('it refuses a field the form marks disabled', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields Disabled Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-disabled-1',
      toolArgs: {
        form_namespace: 'product_form',
        entity_id: String(productId),
        store_id: '',
        changes: [{path: 'data.product.name', value: 'Sedia'}],
      },
      summaryText: 'That field is disabled and cannot be changed.',
    }, mappingIds);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await overrideLiveFields(page, [
      {
        path: 'data.product.name',
        label: 'Product Name',
        type: 'input',
        value: 'Chair',
        options: null,
        required: true,
        disabled: true,
        usesDefaultValue: false,
      },
    ]);
    await stubFormBridgeApply(page);
    await chatPanel.ask(page, trigger + ': rename the product to Sedia');

    /* Refused before any confirmation: the answer arrives as a plain reply, no Confirm button. */

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('That field is disabled and cannot be changed.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    await expect(chatPanel.confirmActions(page)).toHaveCount(0);

    const toolResult = await findToolResult(request, trigger);

    expect(toolResult.error).toBeTruthy();
    expect(toolResult.error.toLowerCase()).toContain('disabled');
    expect(toolResult.client_directive).toBeUndefined();
  });

  test('it refuses a write whose target no longer matches the open form', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields TargetMismatch Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-mismatch-1',
      toolArgs: {
        form_namespace: 'product_form',
        entity_id: String(productId),
        store_id: '',
        changes: [{path: 'data.product.name', value: 'Sedia'}],
      },
      summaryText: 'That page has changed, so nothing was written.',
    }, mappingIds);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await stubFormBridgeApply(page);
    await chatPanel.ask(page, trigger + ': rename the product to Sedia');

    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    /* The administrator navigated to a different entity between the proposal and the confirmation. */
    await overrideLiveEntityId(page, productId + 999999 + '');
    await chatPanel.confirmButton(page).click();

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('That page has changed, so nothing was written.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const toolResult = await findToolResult(request, trigger);

    expect(toolResult.error).toBeTruthy();
    expect(toolResult.error.toLowerCase()).toContain('no longer matches');
    expect(toolResult.client_directive).toBeUndefined();
    expect(await appliedDirectives(page)).toEqual([]);
  });

  test('it reports that no form is open when called on a page without one', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields NoForm Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-no-form-1',
      toolArgs: {
        form_namespace: 'product_form',
        entity_id: String(productId),
        store_id: '',
        changes: [{path: 'data.product.name', value: 'Sedia'}],
      },
      summaryText: 'There is no form open right now.',
    }, mappingIds);

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, trigger + ': rename the product to Sedia');

    /* Refused before any confirmation: the answer arrives as a plain reply, no Confirm button. */

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('There is no form open right now.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    await expect(chatPanel.confirmActions(page)).toHaveCount(0);

    const toolResult = await findToolResult(request, trigger);

    expect(toolResult.form_open).toBe(false);
    expect(toolResult.message.toLowerCase()).toContain('navigate');
  });

  test('it refuses to navigate to a customer entity when no form is open', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields DeniedNavigateCustomer Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-denied-navigate-customer-1',
      toolArgs: {
        entity_type: 'customer',
        entity_id: '1',
        changes: [{path: 'email', value: 'nope@example.com'}],
      },
      summaryText: 'I cannot write to that form.',
    }, mappingIds);

    await chatPanel.openOnDashboard(page);
    const startUrl = page.url();

    await chatPanel.ask(page, trigger + ": update customer 1's email");

    /* Refused before any confirmation: the answer arrives as a plain reply, no Confirm button. */

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('I cannot write to that form.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    /* The refusal itself (asserted above) proves nothing was staged; this proves the browser was
       never even sent anywhere, which a staging-only assertion would miss entirely - the directive
       that would have triggered `window.location.href` is never emitted in the first place. */
    expect(page.url()).toBe(startUrl);

    const remainingIntent = await page.evaluate(() => sessionStorage.getItem('mago_navigate_intent'));
    expect(remainingIntent).toBeNull();

    await expect(chatPanel.confirmActions(page)).toHaveCount(0);

    const toolResult = await findToolResult(request, trigger);

    expect(toolResult.form_open).toBe(false);
    expect(toolResult.denied).toBe(true);
    expect(toolResult.message.toLowerCase()).toContain('customer_data');
    expect(toolResult.client_directive).toBeUndefined();
  });

  test('it refuses to navigate to an order entity when no form is open', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields DeniedNavigateOrder Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-denied-navigate-order-1',
      toolArgs: {
        entity_type: 'order',
        entity_id: '1',
        changes: [{path: 'status', value: 'complete'}],
      },
      summaryText: 'I cannot write to that form either.',
    }, mappingIds);

    await chatPanel.openOnDashboard(page);
    const startUrl = page.url();

    await chatPanel.ask(page, trigger + ': mark order 1 as complete');

    /* Refused before any confirmation: the answer arrives as a plain reply, no Confirm button. */

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('I cannot write to that form either.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    expect(page.url()).toBe(startUrl);

    const remainingIntent = await page.evaluate(() => sessionStorage.getItem('mago_navigate_intent'));
    expect(remainingIntent).toBeNull();

    await expect(chatPanel.confirmActions(page)).toHaveCount(0);

    const toolResult = await findToolResult(request, trigger);

    expect(toolResult.form_open).toBe(false);
    expect(toolResult.denied).toBe(true);
    expect(toolResult.client_directive).toBeUndefined();
  });

  test('it navigates to the New Product form and stages the values when asked to create a product', async ({page, request}) => {
    test.setTimeout(60000);

    await ensureWireMockReachable(request);

    const category = await magentoApi.createCategory(request);
    const trigger = 'E2E WriteFields CreateProduct Check';

    try {
      await installWriteFieldsTurn(request, {
        triggerPhrase: trigger,
        id: 'wf-create-product-1',
        toolArgs: {
          entity_type: 'product',
          entity_id: '',
          store_id: '',
          changes: [
            {path: 'data.product.name', value: 'Jacket Deluxe'},
            {path: 'data.product.category_ids', value: String(category.id)},
          ],
        },
        summaryText: 'Opening the New Product form with the name and category filled in.',
      }, mappingIds);

      await chatPanel.openOnDashboard(page);

      const navigated = page.waitForURL(/catalog\/product\/new\//, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

      await chatPanel.ask(page, trigger + ': add a product named Jacket Deluxe to this category');

      await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
      await expect(chatPanel.lastAssistantMessage(page)).toContainText('Open a new product and stage 2 fields there');
      await expect(chatPanel.lastAssistantMessage(page)).toContainText('You will leave this page.');
      await expect(chatPanel.lastAssistantMessage(page)).not.toContainText('write_fields');
      await chatPanel.confirmButton(page).click();

      await navigated;
      await chatPanel.waitForFormRegistered(page);

      await expect(page.locator('[name="product[name]"]')).toHaveValue('Jacket Deluxe', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
      await expect.poll(() => page.evaluate(() => {
        const registry = (window as any).require('uiRegistry');
        const field = registry.filter((component: any) =>
          component && component.dataScope === 'data.product.category_ids' && typeof component.value === 'function'
        )[0];

        return field ? field.value() : null;
      }), {timeout: PROVIDER_ROUND_TRIP_TIMEOUT}).toEqual([String(category.id)]);

      const toolResult = await findToolResult(request, trigger);

      expect(toolResult.navigating).toBe(true);
      expect(toolResult.creating).toBe(true);
      expect(toolResult.client_directive).toBeUndefined();
    } finally {
      await magentoApi.deleteCategory(request, category.id);
    }
  });

  test('it leaves an open form for the New Product form when the write names a new product', async ({page, request}) => {
    test.setTimeout(60000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields CreateFromOpenForm Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-create-from-open-form-1',
      toolArgs: {
        entity_type: 'product',
        entity_id: '',
        store_id: '',
        changes: [{path: 'data.product.name', value: 'Jacket Deluxe'}],
      },
      summaryText: 'Opening the New Product form.',
    }, mappingIds);

    /* A product edit form is open, so before this the call was refused as a stale target. */
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await page.locator('[name="product[name]"]').fill('Edited but not saved');

    const navigated = page.waitForURL(/catalog\/product\/new\//, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    await chatPanel.ask(page, trigger + ': create a new product named Jacket Deluxe');

    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Unsaved edits on product #' + productId + ' will be lost.');
    page.once('dialog', (dialog) => dialog.accept());
    await chatPanel.confirmButton(page).click();

    await navigated;
    await chatPanel.waitForFormRegistered(page);

    await expect(page.locator('[name="product[name]"]')).toHaveValue('Jacket Deluxe', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    expect(await page.evaluate(() => sessionStorage.getItem('mago_navigate_intent'))).toBeNull();
  });

  test('it stages values on an open New Product form', async ({page, request}) => {
    test.setTimeout(60000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields NewFormWrite Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-new-form-write-1',
      toolArgs: {
        form_namespace: 'product_form',
        entity_id: '',
        store_id: '',
        changes: [{path: 'data.product.price', value: '100.00'}],
      },
      summaryText: 'Staged the price.',
    }, mappingIds);

    await chatPanel.openOn(page, 'catalog/product/new/set/4/type/simple');
    await chatPanel.ask(page, trigger + ': set the price to 100');

    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.confirmButton(page).click();

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Staged 1 of 1 field', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(page.locator('[name="product[price]"]')).toHaveValue('100.00');
  });

  test('it navigates to the New CMS Page form and stages the title when asked to create a page', async ({page, request}) => {
    test.setTimeout(60000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields CreateCmsPage Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-create-cms-page-1',
      toolArgs: {
        entity_type: 'cms_page',
        entity_id: '',
        store_id: '',
        changes: [{path: 'data.title', value: 'Summer Sale'}],
      },
      summaryText: 'Opening the New Page form with the title filled in.',
    }, mappingIds);

    await chatPanel.openOnDashboard(page);

    const navigated = page.waitForURL(/cms\/page\/new\//, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    await chatPanel.ask(page, trigger + ': create a page titled Summer Sale');

    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.confirmButton(page).click();

    await navigated;
    await chatPanel.waitForFormRegistered(page);

    await expect(page.locator('input[name="title"]')).toHaveValue('Summer Sale', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
  });

  test('it navigates to the New Category form under the root category when asked to create a category', async ({page, request}) => {
    test.setTimeout(60000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields CreateCategory Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-create-category-1',
      toolArgs: {
        entity_type: 'category',
        entity_id: '',
        store_id: '',
        changes: [{path: 'data.name', value: 'Sale'}],
      },
      summaryText: 'Opening the New Category form with the name filled in.',
    }, mappingIds);

    await chatPanel.openOnDashboard(page);

    /* Without a parent, Magento's Add controller redirects to the tree and the root category's
       own edit form, which is not a new entity and would be refused. */
    const navigated = page.waitForURL(/catalog\/category\/add\/parent\/\d+\//, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    await chatPanel.ask(page, trigger + ': create a category called Sale');

    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.confirmButton(page).click();

    await navigated;

    /* Mage-OS 3.3 ships MageOS_AutomaticTranslation, whose category form modifier calls
       CategoryRepository::get(null) on the Add page and dies on PHP 8.4+ with "Using null as an
       array offset is deprecated". The navigation itself (asserted above) is this module's part;
       the staged value can only be checked where that page renders at all. */
    const isBrokenAddPage = await page.evaluate(() => typeof (window as any).require !== 'function');
    test.skip(isBrokenAddPage, 'catalog/category/add is broken by MageOS_AutomaticTranslation on this install');

    await chatPanel.waitForFormRegistered(page);

    await expect(page.locator('input[name="name"]')).toHaveValue('Sale', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
  });

  test('it leaves an open product form for another product named by id', async ({page, request}) => {
    test.setTimeout(60000);

    await ensureWireMockReachable(request);

    const other = await magentoApi.createProduct(request);
    const trigger = 'E2E WriteFields OtherProduct Check';

    try {
      await installWriteFieldsTurn(request, {
        triggerPhrase: trigger,
        id: 'wf-other-product-1',
        toolArgs: {
          entity_type: 'product',
          entity_id: String(other.id),
          store_id: '',
          changes: [{path: 'data.product.name', value: 'Renamed Elsewhere'}],
        },
        summaryText: 'Opening the other product.',
      }, mappingIds);

      await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

      const navigated = page.waitForURL(new RegExp('catalog/product/edit/id/' + other.id + '/'), {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

      await chatPanel.ask(page, trigger + ': rename product ' + other.id);

      await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
      await chatPanel.confirmButton(page).click();

      await navigated;
      await chatPanel.waitForFormRegistered(page);

      await expect(page.locator('[name="product[name]"]')).toHaveValue('Renamed Elsewhere', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    } finally {
      await magentoApi.deleteProduct(request, other.sku);
    }
  });

  test('it renders a hostile entity_type from the model as text in the confirmation prompt', async ({page, request}) => {
    test.setTimeout(60000);

    await ensureWireMockReachable(request);

    /* An unknown entity_type is judged against the open form and passes validation, so the
       model (or content it read that carried an injected instruction) decides what the card's
       heading contains. */
    const trigger = 'E2E WriteFields HostileEntityType Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-hostile-entity-type-1',
      toolArgs: {
        form_namespace: 'product_form',
        entity_id: String(productId),
        store_id: '',
        entity_type: 'x<img src=x onerror="document.title=\'owned\'">',
        changes: [{path: 'data.product.name', value: 'Sedia'}],
      },
      summaryText: 'Updated the product name.',
    }, mappingIds);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await stubFormBridgeApply(page);
    await chatPanel.ask(page, trigger + ': rename the product to Sedia');

    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(page.locator('#mago-messages img')).toHaveCount(0);
    expect(await page.title()).not.toBe('owned');
  });

  test('it emits a form_apply event on the confirm response', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields FormApply Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-form-apply-1',
      toolArgs: {
        form_namespace: 'product_form',
        entity_id: String(productId),
        store_id: '',
        changes: [{path: 'data.product.name', value: 'Sedia'}],
      },
      summaryText: 'Updated the product name.',
    }, mappingIds);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await stubFormBridgeApply(page);
    await chatPanel.ask(page, trigger + ': rename the product to Sedia');

    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.confirmButton(page).click();

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Updated the product name.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const directives = await appliedDirectives(page);

    expect(directives).toHaveLength(1);
    expect(directives[0].type).toBe('form_write');
  });

  test('it keeps the directive out of the tool result the provider sees', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields NoLeak Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-no-leak-1',
      toolArgs: {
        form_namespace: 'product_form',
        entity_id: String(productId),
        store_id: '',
        changes: [{path: 'data.product.name', value: 'Sedia'}],
      },
      summaryText: 'Updated the product name.',
    }, mappingIds);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await stubFormBridgeApply(page);
    await chatPanel.ask(page, trigger + ': rename the product to Sedia');

    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.confirmButton(page).click();

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Updated the product name.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const toolResult = await findToolResult(request, trigger);

    expect(toolResult.staged).toBe(true);
    expect('client_directive' in toolResult).toBe(false);
  });

  test('it emits no form_apply event for a tool result without a directive', async ({page, request}) => {
    test.setTimeout(40000);

    await ensureWireMockReachable(request);

    const trigger = 'E2E WriteFields NoDirective Check';

    await installWriteFieldsTurn(request, {
      triggerPhrase: trigger,
      id: 'wf-no-directive-1',
      toolArgs: {
        form_namespace: 'product_form',
        entity_id: String(productId),
        store_id: '',
        changes: [{path: 'data.product.does_not_exist', value: 'x'}],
      },
      summaryText: 'That field does not exist on this form.',
    }, mappingIds);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await stubFormBridgeApply(page);
    await chatPanel.ask(page, trigger + ': set a field that does not exist');

    /* An unknown field is refused before confirmation, so the reply arrives without a Confirm button. */
    await expect(chatPanel.lastAssistantMessage(page)).toContainText('That field does not exist on this form.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.confirmActions(page)).toHaveCount(0);

    expect(await appliedDirectives(page)).toEqual([]);
  });
});

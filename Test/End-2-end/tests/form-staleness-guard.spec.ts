/*
 * Copyright © Mago Assistant
 */

import {expect, test, type Page} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock, {type ChatScenario, textDeltas} from 'Actions/backend/ChatMock';
import MagentoApi from 'Services/MagentoApi';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();
const magentoApi = new MagentoApi();

const CONVERSATION_ID = 9101;
const MESSAGE_ID = 200601;

/**
 * Task 006's WriteFieldsAction is what produces a real form_write directive; this task is only
 * about what the browser does once the directive it already has no longer matches the form now
 * open, so a ChatMock scenario that carries a directive straight through the confirm response (the
 * same shape task 005/007 already proved chat-panel.js routes to the bridge) is enough. No
 * provider, no WriteFieldsAction, no WireMock.
 */
function staleDirectiveScenario(directive: Record<string, unknown>, summaryText: string): ChatScenario {
  return {
    stream: [
      {event: 'conversation', data: {conversation_id: CONVERSATION_ID, admin_user: 'Tester'}},
      ...textDeltas('Working on it. '),
      {event: 'tool_call', data: {id: 'toolu_stale_1', name: 'page_form', input: {action: 'write_fields'}}},
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

/**
 * The panel appends its own refusal message as a further assistant message after the model's own
 * summary text, exactly as it appends the outcome report for a directive that does apply (task
 * 007), so asserting against the whole message list rather than only the first bubble is what
 * keeps this helper correct.
 */
async function proposeAndConfirm(page: Page, directive: Record<string, unknown>, summaryText = 'Done.') {
  await chatMock.install(page, staleDirectiveScenario(directive, summaryText));
  await chatPanel.ask(page, 'Apply the staged change');
  await expect(chatPanel.confirmButton(page)).toHaveCount(1);
  await chatPanel.confirmButton(page).click();
  await expect(page.locator('#mago-messages')).toContainText(summaryText);
}

test.describe('Form bridge staleness guard on the product form', () => {
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

  test('it refuses a directive targeting a different entity id', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await proposeAndConfirm(page, {
      type: 'form_write',
      target: {
        namespace: 'product_form',
        entity_type: 'product',
        entity_id: String(productId + 999999),
        store_id: '',
      },
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Wrong Entity', clear_use_default: false},
      ],
    });

    await expect(chatPanel.lastAssistantMessage(page)).toContainText(/different/i);
    await expect(page.locator('[name="product[name]"]')).not.toHaveValue('Wrong Entity');
  });

  test('it refuses a directive targeting a different form namespace', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await proposeAndConfirm(page, {
      type: 'form_write',
      target: {
        namespace: 'cms_page_form',
        entity_type: 'cms_page',
        entity_id: String(productId),
        store_id: '',
      },
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Wrong Namespace', clear_use_default: false},
      ],
    });

    await expect(chatPanel.lastAssistantMessage(page)).toContainText(/different/i);
    await expect(page.locator('[name="product[name]"]')).not.toHaveValue('Wrong Namespace');
  });

  test('it refuses a directive targeting a different store scope', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await proposeAndConfirm(page, {
      type: 'form_write',
      target: {
        namespace: 'product_form',
        entity_type: 'product',
        entity_id: String(productId),
        store_id: '1',
      },
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Wrong Store', clear_use_default: false},
      ],
    });

    await expect(chatPanel.lastAssistantMessage(page)).toContainText(/different/i);
    await expect(page.locator('[name="product[name]"]')).not.toHaveValue('Wrong Store');
  });

  test('it refuses a directive proposed on a new entity form', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/new/set/4/type/simple');

    await proposeAndConfirm(page, {
      type: 'form_write',
      target: {
        namespace: 'product_form',
        entity_type: 'product',
        entity_id: '',
        store_id: '',
      },
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: '', value: 'Wrong New Entity', clear_use_default: false},
      ],
    });

    await expect(chatPanel.lastAssistantMessage(page)).toContainText(/different/i);
    await expect(page.locator('[name="product[name]"]')).not.toHaveValue('Wrong New Entity');
  });

  test('it applies a directive the server flagged as meant for a new entity form', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/new/set/4/type/simple');

    await proposeAndConfirm(page, {
      type: 'form_write',
      target: {
        namespace: 'product_form',
        entity_type: 'product',
        entity_id: '',
        store_id: '',
        is_new: true,
      },
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: '', value: 'Right New Entity', clear_use_default: false},
      ],
    });

    await expect(page.locator('[name="product[name]"]')).toHaveValue('Right New Entity');
    await expect(chatPanel.lastAssistantMessage(page)).not.toContainText(/different/i);
  });

  test('it leaves the open form untouched when it refuses', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    const nameField = page.locator('[name="product[name]"]');
    const originalName = await nameField.inputValue();

    await proposeAndConfirm(page, {
      type: 'form_write',
      target: {
        namespace: 'product_form',
        entity_type: 'product',
        entity_id: String(productId + 999999),
        store_id: '',
      },
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: originalName, value: 'Should Not Apply', clear_use_default: false},
      ],
    });

    await expect(nameField).toHaveValue(originalName);
    await expect(page.locator('.admin__field[data-index="name"]')).not.toHaveClass(/mago-field-changed/);
  });

  test('it tells the admin which entity the change was meant for', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    const targetId = String(productId + 999999);

    await proposeAndConfirm(page, {
      type: 'form_write',
      target: {
        namespace: 'product_form',
        entity_type: 'product',
        entity_id: targetId,
        store_id: '',
      },
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Wrong Entity', clear_use_default: false},
      ],
    });

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('product');
    await expect(chatPanel.lastAssistantMessage(page)).toContainText(targetId);
  });
});

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

const CONVERSATION_ID = 9301;
const MESSAGE_ID = 200801;

function applyOnConfirmScenario(directive: Record<string, unknown>): ChatScenario {
  return {
    stream: [
      {event: 'conversation', data: {conversation_id: CONVERSATION_ID, admin_user: 'Tester'}},
      ...textDeltas('Working on it. '),
      {event: 'tool_call', data: {id: 'toolu_format_1', name: 'page_form', input: {action: 'write_fields'}}},
      {
        event: 'confirm',
        data: {tools: [{name: 'page_form', description: 'Write the open form', input: {action: 'write_fields'}}]},
      },
      {event: 'done', data: {message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true}},
    ],
    status: {message_id: MESSAGE_ID},
    confirm: [
      {event: 'form_apply', data: directive},
      ...textDeltas('Done.'),
      {event: 'done', data: {conversation_id: CONVERSATION_ID}},
    ],
  };
}

function productWrite(productId: number, path: string, value: string): Record<string, unknown> {
  return {
    type: 'form_write',
    target: {namespace: 'product_form', entity_type: 'product', entity_id: String(productId), store_id: '', is_new: false},
    changes: [{path, label: path, previous_value: '', value, clear_use_default: false}],
  };
}

async function applyDirective(page: Page, directive: Record<string, unknown>) {
  await chatMock.install(page, applyOnConfirmScenario(directive));
  await chatPanel.ask(page, 'Apply the staged change');
  await expect(chatPanel.confirmButton(page)).toHaveCount(1);
  await chatPanel.confirmButton(page).click();
  await expect(page.locator('#mago-messages')).toContainText('Staged 1 of 1 field');
}

function componentValue(page: Page, dataScope: string) {
  return page.evaluate((scope) => {
    const registry = (window as any).require('uiRegistry');
    const field = registry.filter((component: any) =>
      component && component.dataScope === scope && typeof component.value === 'function'
    )[0];

    return field ? field.value() : null;
  }, dataScope);
}

/**
 * Every value in a directive is a string, but the fields it lands on are not all text inputs. The
 * checks below pin the value format each field type expects, so the write_fields description can
 * keep promising the model formats that actually take. Pure browser tests: no provider, no PHP.
 */
test.describe('Form bridge value formats on the product form', () => {
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

  test.beforeEach(async ({page}) => {
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
  });

  test('it switches a toggle off with the option value for disabled', async ({page}) => {
    test.setTimeout(40000);

    await applyDirective(page, productWrite(productId, 'data.product.status', '2'));

    expect(await componentValue(page, 'data.product.status')).toBe('2');
    await expect(page.locator('[name="product[status]"]')).not.toBeChecked();
  });

  test('it selects a dropdown option by its value', async ({page}) => {
    test.setTimeout(40000);

    await applyDirective(page, productWrite(productId, 'data.product.visibility', '2'));

    expect(await componentValue(page, 'data.product.visibility')).toBe('2');
    await expect(page.locator('[name="product[visibility]"]')).toHaveValue('2');
  });

  test('it ticks a website checkbox with its own value', async ({page}) => {
    test.setTimeout(40000);

    await applyDirective(page, productWrite(productId, 'data.product.website_ids.1', '1'));

    expect(await componentValue(page, 'data.product.website_ids.1')).toBe('1');
    await expect(page.locator('[name="product[website_ids][1]"]')).toBeChecked();
  });

  test('it fills a date field from an ISO date and shows it in the admin locale', async ({page}) => {
    test.setTimeout(40000);

    /* Magento's date component parses the value observable in its input format (ISO), not in the
       locale format it displays; a locale-formatted string lands as "Invalid date". */
    await applyDirective(page, productWrite(productId, 'data.product.special_from_date', '2026-08-30'));

    /* The field sits in the Advanced Pricing modal, so no input is rendered to read; the
       component's own displayed value (shiftedValue) is what the modal would show. */
    expect(await componentValue(page, 'data.product.special_from_date')).not.toBe('Invalid date');
    expect(await page.evaluate(() => {
      const registry = (window as any).require('uiRegistry');
      const field = registry.filter((component: any) =>
        component && component.dataScope === 'data.product.special_from_date' && typeof component.shiftedValue === 'function'
      )[0];

      return field ? field.shiftedValue() : null;
    })).toBe('8/30/2026');
  });

  test('it assigns categories from a comma separated id list', async ({page}) => {
    test.setTimeout(40000);

    await applyDirective(page, productWrite(productId, 'data.product.category_ids', '2'));

    expect(await componentValue(page, 'data.product.category_ids')).toEqual(['2']);
  });
});

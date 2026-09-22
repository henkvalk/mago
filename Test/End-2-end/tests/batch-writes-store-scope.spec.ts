/*
 * Copyright © Mago Assistant
 */

import {expect, test, type APIRequestContext, type Page} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock, {type ChatScenario, textDeltas} from 'Actions/backend/ChatMock';
import MagentoApi from 'Services/MagentoApi';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();
const magentoApi = new MagentoApi();

const CONVERSATION_ID = 9201;
const MESSAGE_ID = 200701;

/**
 * A product Save is a full admin round trip; the same 5s Playwright default that bit
 * form-bridge-apply.spec.ts applies here too, so anything waiting on a save or a streamed
 * confirmation reply carries this explicit timeout instead.
 */
const ADMIN_SAVE_TIMEOUT = 30000;

/**
 * WriteFieldsAction (task 006) is what produces a real form_write directive with a genuine
 * form_namespace/entity_id/store_id and per-field clear_use_default; this task is only about what
 * the browser does with a batch of changes once it has one. A ChatMock scenario that carries the
 * tool proposal and the directive straight through, exactly as form-bridge-apply.spec.ts and
 * form-staleness-guard.spec.ts already do for a single change, is enough for a batch of them. No
 * provider, no WriteFieldsAction, no WireMock.
 */
function batchScenario(opts: {
  toolChanges: Array<{path: string; value: string}>;
  target: Record<string, unknown>;
  directiveChanges: Array<Record<string, unknown>>;
  summaryText: string;
}): ChatScenario {
  return {
    stream: [
      {event: 'conversation', data: {conversation_id: CONVERSATION_ID, admin_user: 'Tester'}},
      ...textDeltas('Translating the requested fields. '),
      {
        event: 'tool_call',
        data: {
          id: 'toolu_batch_1',
          name: 'page_form',
          input: {
            action: 'write_fields',
            form_namespace: opts.target.namespace,
            entity_id: opts.target.entity_id,
            store_id: opts.target.store_id,
            changes: opts.toolChanges,
          },
        },
      },
      {
        event: 'confirm',
        data: {
          tools: [
            {
              name: 'page_form',
              description: 'Read and write the admin form currently open in the browser',
              input: {
                action: 'write_fields',
                form_namespace: opts.target.namespace,
                entity_id: opts.target.entity_id,
                store_id: opts.target.store_id,
                changes: opts.toolChanges,
              },
            },
          ],
        },
      },
      {event: 'done', data: {message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true}},
    ],
    status: {message_id: MESSAGE_ID},
    confirm: [
      {
        event: 'form_apply',
        data: {type: 'form_write', target: opts.target, changes: opts.directiveChanges},
      },
      ...textDeltas(opts.summaryText),
      {event: 'done', data: {conversation_id: CONVERSATION_ID}},
    ],
  };
}

async function askAndConfirm(page: Page, summaryText = 'Done.') {
  await chatPanel.ask(page, 'Translate these fields to Italian');
  await expect(chatPanel.confirmButton(page)).toHaveCount(1);
  await chatPanel.confirmButton(page).click();
  await expect(page.locator('#mago-messages')).toContainText(summaryText, {timeout: ADMIN_SAVE_TIMEOUT});
}

test.describe('Batch writes at store scope', () => {
  /* Every test below acts on the same disposable product, and openOn() itself mutates shared
     admin grid bookmark state, so these cannot safely run in parallel. */
  test.describe.configure({mode: 'serial'});

  let sku: string;
  let productId: number;
  let storeId: number;

  test.beforeAll(async ({request}) => {
    const product = await magentoApi.createProduct(request);

    sku = product.sku;
    productId = product.id;

    const storeViews = await magentoApi.getStoreViews(request);
    const storeView = storeViews.find((view: any) => view.id !== 0);

    if (!storeView) {
      throw new Error(
        'This install has only the default (admin) store scope. Store-scope batch writes need at '
        + 'least one real store view to run against.'
      );
    }

    storeId = storeView.id;
  });

  test.afterAll(async ({request}) => {
    await magentoApi.deleteProduct(request, sku);
  });

  test('it stages several fields under a single confirmation', async ({page}) => {
    test.setTimeout(40000);

    const target = {
      namespace: 'product_form',
      entity_type: 'product',
      entity_id: String(productId),
      store_id: String(storeId),
    };

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId + '/store/' + storeId);

    await chatMock.install(page, batchScenario({
      toolChanges: [
        {path: 'data.product.name', value: 'Sedia'},
        {path: 'data.product.meta_title', value: 'Titolo Meta'},
        {path: 'data.product.short_description', value: 'Descrizione breve'},
      ],
      target,
      directiveChanges: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Sedia', clear_use_default: false},
        {path: 'data.product.meta_title', label: 'Meta Title', previous_value: '', value: 'Titolo Meta', clear_use_default: false},
        {path: 'data.product.short_description', label: 'Short Description', previous_value: '', value: 'Descrizione breve', clear_use_default: false},
      ],
      summaryText: 'Translated three fields.',
    }));

    await askAndConfirm(page, 'Translated three fields.');

    await expect(page.locator('[name="product[name]"]')).toHaveValue('Sedia');
    await expect(page.locator('[name="product[meta_title]"]')).toHaveValue('Titolo Meta');
  });

  test('it lists every field it is about to change in the confirmation prompt', async ({page}) => {
    test.setTimeout(40000);

    const target = {
      namespace: 'product_form',
      entity_type: 'product',
      entity_id: String(productId),
      store_id: String(storeId),
    };

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId + '/store/' + storeId);

    await chatMock.install(page, batchScenario({
      toolChanges: [
        {path: 'data.product.name', value: 'Sedia'},
        {path: 'data.product.meta_title', value: 'Titolo Meta'},
        {path: 'data.product.short_description', value: 'Descrizione breve'},
      ],
      target,
      directiveChanges: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Sedia', clear_use_default: false},
        {path: 'data.product.meta_title', label: 'Meta Title', previous_value: '', value: 'Titolo Meta', clear_use_default: false},
        {path: 'data.product.short_description', label: 'Short Description', previous_value: '', value: 'Descrizione breve', clear_use_default: false},
      ],
      summaryText: 'Translated three fields.',
    }));

    await chatPanel.ask(page, 'Translate these fields to Italian');
    await expect(chatPanel.confirmButton(page)).toHaveCount(1);

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Product Name');
    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Meta Title');
    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Short Description');
    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Sedia');
    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Titolo Meta');
    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Descrizione breve');
  });

  test('it keeps the confirmation prompt readable at twenty fields', async ({page}) => {
    test.setTimeout(40000);

    const target = {
      namespace: 'product_form',
      entity_type: 'product',
      entity_id: String(productId),
      store_id: String(storeId),
    };
    const toolChanges = Array.from({length: 20}, (_, i) => ({
      path: 'data.product.custom_field_' + (i + 1),
      value: 'Value ' + (i + 1),
    }));
    const directiveChanges = toolChanges.map((change, i) => ({
      path: change.path,
      label: 'Custom Field ' + (i + 1),
      previous_value: '',
      value: change.value,
      clear_use_default: false,
    }));

    await chatPanel.openOnDashboard(page);

    await chatMock.install(page, batchScenario({
      toolChanges,
      target,
      directiveChanges,
      summaryText: 'Translated twenty fields.',
    }));

    await chatPanel.ask(page, 'Translate these fields to Italian');
    await expect(chatPanel.confirmButton(page)).toHaveCount(1);

    const promptText = (await chatPanel.lastAssistantMessage(page).innerText());
    const bulletLines = promptText.split('\n').filter((line) => line.trim().indexOf('-') === 0);

    expect(promptText).toContain('20 field');
    expect(promptText).toMatch(/more field/i);
    expect(bulletLines.length).toBeLessThan(20);
    expect(promptText).not.toContain('Custom Field 20');
  });

  test('it stages each field at the store view scope the form is on', async ({page}) => {
    test.setTimeout(40000);

    const target = {
      namespace: 'product_form',
      entity_type: 'product',
      entity_id: String(productId),
      store_id: String(storeId),
    };

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId + '/store/' + storeId);

    await chatMock.install(page, batchScenario({
      toolChanges: [
        {path: 'data.product.name', value: 'Nome A Livello Di Negozio'},
      ],
      target,
      directiveChanges: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Nome A Livello Di Negozio', clear_use_default: false},
      ],
      summaryText: 'Staged the store view name.',
    }));

    await askAndConfirm(page, 'Staged the store view name.');

    await expect(page.locator('[name="product[name]"]')).toHaveValue('Nome A Livello Di Negozio');
  });

  test('it clears use default value on every field it stages', async ({page}) => {
    test.setTimeout(40000);

    const target = {
      namespace: 'product_form',
      entity_type: 'product',
      entity_id: String(productId),
      store_id: String(storeId),
    };

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId + '/store/' + storeId);

    const useDefaultCheckbox = page.locator('input[name="use_default[name]"]');

    await expect(useDefaultCheckbox).toBeChecked();

    await chatMock.install(page, batchScenario({
      toolChanges: [
        {path: 'data.product.name', value: 'Nome Senza Predefinito'},
      ],
      target,
      directiveChanges: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Nome Senza Predefinito', clear_use_default: true},
      ],
      summaryText: 'Cleared the default and staged the name.',
    }));

    await askAndConfirm(page, 'Cleared the default and staged the name.');

    await expect(page.locator('[name="product[name]"]')).toHaveValue('Nome Senza Predefinito');
    await expect(useDefaultCheckbox).not.toBeChecked();
  });

  test('it leaves a global field alone when it has no use default checkbox', async ({page}) => {
    test.setTimeout(40000);

    const target = {
      namespace: 'product_form',
      entity_type: 'product',
      entity_id: String(productId),
      store_id: String(storeId),
    };

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId + '/store/' + storeId);

    await expect(page.locator('input[name="use_default[sku]"]')).toHaveCount(0);

    await chatMock.install(page, batchScenario({
      toolChanges: [
        {path: 'data.product.sku', value: sku + '-it'},
      ],
      target,
      directiveChanges: [
        {path: 'data.product.sku', label: 'SKU', previous_value: sku, value: sku + '-it', clear_use_default: true},
      ],
      summaryText: 'Staged the sku, which has no store scope.',
    }));

    await askAndConfirm(page, 'Staged the sku, which has no store scope.');

    await expect(page.locator('[name="product[sku]"]')).toHaveValue(sku + '-it');
    await expect(page.locator('input[name="use_default[sku]"]')).toHaveCount(0);
  });

  test('it continues with the remaining fields when one field cannot be set', async ({page}) => {
    test.setTimeout(40000);

    const target = {
      namespace: 'product_form',
      entity_type: 'product',
      entity_id: String(productId),
      store_id: String(storeId),
    };

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId + '/store/' + storeId);

    await chatMock.install(page, batchScenario({
      toolChanges: [
        {path: 'data.product.name', value: 'Sopravvive Al Lotto'},
        {path: 'data.product.does_not_exist', value: 'y'},
      ],
      target,
      directiveChanges: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Sopravvive Al Lotto', clear_use_default: false},
        {path: 'data.product.does_not_exist', label: 'Ghost Field', previous_value: '', value: 'y', clear_use_default: false},
      ],
      summaryText: 'Staged what could be staged.',
    }));

    await askAndConfirm(page, 'Staged what could be staged.');

    await expect(page.locator('[name="product[name]"]')).toHaveValue('Sopravvive Al Lotto');
    await expect(chatPanel.assistantMessages(page).last()).toContainText('Ghost Field');
  });

  test('it reports how many fields it staged and which failed', async ({page}) => {
    test.setTimeout(40000);

    const target = {
      namespace: 'product_form',
      entity_type: 'product',
      entity_id: String(productId),
      store_id: String(storeId),
    };

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId + '/store/' + storeId);

    await chatMock.install(page, batchScenario({
      toolChanges: [
        {path: 'data.product.name', value: 'Nome Riuscito'},
        {path: 'data.product.meta_title', value: 'Titolo Riuscito'},
        {path: 'data.product.does_not_exist', value: 'y'},
      ],
      target,
      directiveChanges: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Nome Riuscito', clear_use_default: false},
        {path: 'data.product.meta_title', label: 'Meta Title', previous_value: '', value: 'Titolo Riuscito', clear_use_default: false},
        {path: 'data.product.does_not_exist', label: 'Ghost Field', previous_value: '', value: 'y', clear_use_default: false},
      ],
      summaryText: 'Two of three fields staged.',
    }));

    await askAndConfirm(page, 'Two of three fields staged.');

    await expect(chatPanel.assistantMessages(page).last()).toContainText('Staged 2 of 3 fields');
    await expect(chatPanel.assistantMessages(page).last()).toContainText('Ghost Field');
  });

  test('it leaves fields the assistant did not name unchanged', async ({page}) => {
    test.setTimeout(40000);

    const target = {
      namespace: 'product_form',
      entity_type: 'product',
      entity_id: String(productId),
      store_id: String(storeId),
    };

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId + '/store/' + storeId);

    const skuInput = page.locator('[name="product[sku]"]');
    const originalSku = await skuInput.inputValue();

    await chatMock.install(page, batchScenario({
      toolChanges: [
        {path: 'data.product.name', value: 'Solo Il Nome'},
      ],
      target,
      directiveChanges: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Solo Il Nome', clear_use_default: false},
      ],
      summaryText: 'Only the name was staged.',
    }));

    await askAndConfirm(page, 'Only the name was staged.');

    await expect(page.locator('[name="product[name]"]')).toHaveValue('Solo Il Nome');
    await expect(skuInput).toHaveValue(originalSku);
  });

  test('it focuses the first field of a batch and leaves the rest merely highlighted', async ({page}) => {
    test.setTimeout(40000);

    const target = {
      namespace: 'product_form',
      entity_type: 'product',
      entity_id: String(productId),
      store_id: String(storeId),
    };

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId + '/store/' + storeId);

    await chatMock.install(page, batchScenario({
      toolChanges: [
        {path: 'data.product.name', value: 'Prima Il Focus'},
        {path: 'data.product.meta_title', value: 'Poi Solo Evidenziato'},
      ],
      target,
      /* clear_use_default: true on both, exactly as a real store-scope translation batch would
         carry it - a field left ticked "Use Default Value" stays disabled and cannot take focus
         at all, which is a real constraint of the form, not something this test is guarding. */
      directiveChanges: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Prima Il Focus', clear_use_default: true},
        {path: 'data.product.meta_title', label: 'Meta Title', previous_value: '', value: 'Poi Solo Evidenziato', clear_use_default: true},
      ],
      summaryText: 'Focused the first, highlighted the rest.',
    }));

    await askAndConfirm(page, 'Focused the first, highlighted the rest.');

    await expect(page.locator('[name="product[name]"]')).toBeFocused();
    await expect(page.locator('[name="product[meta_title]"]')).not.toBeFocused();
    await expect(page.locator('.admin__field[data-index="name"]')).toHaveClass(/mago-field-changed/);
    await expect(page.locator('.admin__field[data-index="meta_title"]')).toHaveClass(/mago-field-changed/);
  });

  /* Not one of the requirements above, but the task's own acceptance criteria call this out
     separately: a value staged at store scope, with "Use Default Value" cleared, has to actually
     survive a real Save rather than silently reverting to the default value the checkbox would
     otherwise restore. */
  test('every staged value survives the administrator clicking Save at store scope', async ({page, request}) => {
    test.setTimeout(90000);

    const target = {
      namespace: 'product_form',
      entity_type: 'product',
      entity_id: String(productId),
      store_id: String(storeId),
    };

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId + '/store/' + storeId);

    await chatMock.install(page, batchScenario({
      toolChanges: [
        {path: 'data.product.name', value: 'Sopravvive Al Salvataggio'},
      ],
      target,
      directiveChanges: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Sopravvive Al Salvataggio', clear_use_default: true},
      ],
      summaryText: 'Staged the store view name for saving.',
    }));

    await askAndConfirm(page, 'Staged the store view name for saving.');
    await chatPanel.close(page);

    const saved = page.waitForResponse(
      (response) => response.url().includes('catalog/product/save'),
      {timeout: ADMIN_SAVE_TIMEOUT}
    );

    await page.locator('button[data-ui-id="save-button"]').click();
    await saved;

    const storeViews = await magentoApi.getStoreViews(request);
    const storeView = storeViews.find((view: any) => view.id === storeId);
    const persisted = await magentoApi.findProductAtStore(request, sku, storeView.code);

    expect(persisted.name).toBe('Sopravvive Al Salvataggio');
  });
});

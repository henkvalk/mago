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

const CONVERSATION_ID = 9001;
const MESSAGE_ID = 200501;

/**
 * A product Save is a form submit, server-side validation, the save itself, a redirect and a full
 * admin page render. That comfortably exceeds Playwright's 5 s assertion default under the suite's
 * parallelism, which showed up as this spec failing two runs in three on the success message alone.
 * `test.setTimeout()` does not cover it: per-assertion timeouts are separate.
 */
const ADMIN_SAVE_TIMEOUT = 30000;

/**
 * Task 006's WriteFieldsAction is what produces a real form_write directive; this task is only
 * about what the browser does once it has one, so a ChatMock scenario that carries a directive
 * straight through the confirm response (the same shape task 005 already proved chat-panel.js
 * routes to the bridge) is enough. No provider, no WriteFieldsAction, no WireMock.
 */
function applyOnConfirmScenario(directive: Record<string, unknown>, summaryText: string): ChatScenario {
  return {
    stream: [
      {event: 'conversation', data: {conversation_id: CONVERSATION_ID, admin_user: 'Tester'}},
      ...textDeltas('Working on it. '),
      {event: 'tool_call', data: {id: 'toolu_apply_1', name: 'page_form', input: {action: 'write_fields'}}},
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
 * The panel appends its own outcome report as a further assistant message after the model's own
 * summary text (task 007's own requirement), so once a directive is present there are two
 * assistant bubbles, not one; asserting against the whole message list rather than only the last
 * one is what keeps this helper correct regardless of which of the two carries summaryText.
 */
async function applyDirective(page: Page, directive: Record<string, unknown>, summaryText = 'Done.') {
  await chatMock.install(page, applyOnConfirmScenario(directive, summaryText));
  await chatPanel.ask(page, 'Apply the staged change');
  await expect(chatPanel.confirmButton(page)).toHaveCount(1);
  await chatPanel.confirmButton(page).click();
  await expect(page.locator('#mago-messages')).toContainText(summaryText);
}

test.describe('Form bridge apply on the product form', () => {
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

  test('it sets the product name input to the staged value', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await applyDirective(page, {
      type: 'form_write',
      target: {namespace: 'product_form', entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Sedia', clear_use_default: false},
      ],
    });

    await expect(page.locator('[name="product[name]"]')).toHaveValue('Sedia');
  });

  test('it persists nothing until the admin clicks save', async ({page, request}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await applyDirective(page, {
      type: 'form_write',
      target: {namespace: 'product_form', entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Not Saved Yet', clear_use_default: false},
      ],
    });

    await expect(page.locator('[name="product[name]"]')).toHaveValue('Not Saved Yet');

    const persisted = await magentoApi.findProduct(request, sku);

    expect(persisted.name).not.toBe('Not Saved Yet');
  });

  test('it marks the form dirty so leaving the page warns the admin', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await applyDirective(page, {
      type: 'form_write',
      target: {namespace: 'product_form', entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Marks Dirty', clear_use_default: false},
      ],
    });

    /* Magento's own admin__page-nav-item-message "_changed" indicator (and the leave-page warning
       it feeds) is driven by a field component's hasChanged(), which compares its current value
       against the value the form loaded with - the same signal a real keystroke would produce. */
    const hasChanged = await page.evaluate(() => new Promise((resolve) => {
      (window as any).require(['uiRegistry'], function (registry: any) {
        const component = registry.filter((c: any) => c && c.dataScope === 'data.product.name')[0];

        resolve(component.hasChanged());
      });
    }));

    expect(hasChanged).toBe(true);
  });

  test('it highlights every field it changed', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await applyDirective(page, {
      type: 'form_write',
      target: {namespace: 'product_form', entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Highlighted Name', clear_use_default: false},
        {path: 'data.product.meta_title', label: 'Meta Title', previous_value: '', value: 'Highlighted Meta Title', clear_use_default: false},
      ],
    });

    await expect(page.locator('.admin__field[data-index="name"]')).toHaveClass(/mago-field-changed/);
    await expect(page.locator('.admin__field[data-index="meta_title"]')).toHaveClass(/mago-field-changed/);
  });

  test('it focuses the field it changed', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await applyDirective(page, {
      type: 'form_write',
      target: {namespace: 'product_form', entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Focused Name', clear_use_default: false},
      ],
    });

    await expect(page.locator('[name="product[name]"]')).toBeFocused();
  });

  test('it scrolls a field below the fold into view before focusing it', async ({page}) => {
    test.setTimeout(40000);

    await page.setViewportSize({width: 1280, height: 400});
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    /* Meta Title lives in the collapsed "Search Engine Optimization" section, which is both below
       the fold and not yet rendered at all, so this exercises the expand-then-scroll path together. */
    await applyDirective(page, {
      type: 'form_write',
      target: {namespace: 'product_form', entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [
        {path: 'data.product.meta_title', label: 'Meta Title', previous_value: '', value: 'Scrolled Meta Title', clear_use_default: false},
      ],
    });

    const metaTitleInput = page.locator('[name="product[meta_title]"]');

    await expect(metaTitleInput).toBeFocused();
    await expect(metaTitleInput).toBeInViewport();
  });

  test('it reports the fields it could not set without abandoning the rest', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await applyDirective(page, {
      type: 'form_write',
      target: {namespace: 'product_form', entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Survives The Batch', clear_use_default: false},
        {path: 'data.product.does_not_exist', label: 'Ghost Field', previous_value: '', value: 'y', clear_use_default: false},
      ],
    });

    await expect(page.locator('[name="product[name]"]')).toHaveValue('Survives The Batch');
    await expect(chatPanel.assistantMessages(page).last()).toContainText('Ghost Field');
  });

  test('it says nothing was changed when no field could be set', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await applyDirective(page, {
      type: 'form_write',
      target: {namespace: 'product_form', entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [
        {path: 'data.product.no_such_field', label: 'Ghost Field', previous_value: '', value: 'x', clear_use_default: false},
      ],
    });

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Could not stage Ghost Field. Nothing was changed.');
    await expect(chatPanel.lastAssistantMessage(page)).not.toContainText('Not saved yet');
  });

  test('it says in the chat that the change is staged and not saved', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await applyDirective(page, {
      type: 'form_write',
      target: {namespace: 'product_form', entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Staged Not Saved', clear_use_default: false},
      ],
    });

    await expect(chatPanel.assistantMessages(page).last()).toContainText(/staged/i);
    await expect(chatPanel.assistantMessages(page).last()).toContainText(/not saved/i);
  });

  /* Not one of task 007's named requirements, but its own acceptance criteria call this out
     separately: a staged value at default scope has to actually survive a real Save, not just
     look right in the DOM immediately after apply(). */
  test('a staged value at default scope survives the administrator clicking Save', async ({page, request}) => {
    test.setTimeout(90000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    await applyDirective(page, {
      type: 'form_write',
      target: {namespace: 'product_form', entity_type: 'product', entity_id: String(productId), store_id: ''},
      changes: [
        {path: 'data.product.name', label: 'Product Name', previous_value: 'x', value: 'Survives Save', clear_use_default: false},
      ],
    });

    await chatPanel.close(page);

    /* Waiting on the save round trip rather than on the admin's flash message. Those messages are
       session-stored and consumed by whichever page renders first, and every spec shares one admin
       session through .auth/backend.json, so a concurrent worker's page load can swallow this one.
       Persistence is what this test is actually about, and the API read below proves it outright. */
    const saved = page.waitForResponse(
      (response) => response.url().includes('catalog/product/save'),
      {timeout: ADMIN_SAVE_TIMEOUT}
    );

    await page.locator('button[data-ui-id="save-button"]').click();
    await saved;

    const persisted = await magentoApi.findProduct(request, sku);

    expect(persisted.name).toBe('Survives Save');
  });
});

test.describe('Form bridge apply on the CMS page WYSIWYG', () => {
  test('it sets the cms page content while the wysiwyg editor is open', async ({page, request}) => {
    test.setTimeout(40000);

    const cmsPage = await magentoApi.createCmsPage(request, {content: '<p>Legacy plain content.</p>'});

    try {
      await chatPanel.openOn(page, 'cms/page/edit/page_id/' + cmsPage.id);
      await page.locator('[data-index="content"] .fieldset-wrapper-title').click();

      const wysiwygId: string = await page.evaluate(() => new Promise((resolve) => {
        (window as any).require(['uiRegistry'], function (registry: any) {
          resolve(registry.filter((c: any) => c && c.dataScope === 'data.content')[0].wysiwygId);
        });
      }));

      /* This install forces Page Builder onto the CMS content field (Stores > Configuration >
         Content Management > Page Builder, locked on for this environment), which replaces the
         classic textarea + TinyMCE pairing this task is about with its own drag-and-drop stage.
         The precondition this spec needs - a TinyMCE editor genuinely open and bound to the
         field's own wysiwygId - is created directly with the same TinyMCE build Magento itself
         loads on this page, exactly as the classic (non-Page-Builder) component would have done
         on its own by the time the administrator opens the field. */
      await page.evaluate((id) => new Promise((resolve) => {
        const textarea = document.createElement('textarea');

        textarea.id = id;
        document.body.appendChild(textarea);
        (window as any).tinymce.init({
          selector: '#' + id,
          setup(editor: any) {
            editor.on('init', () => resolve(undefined));
          },
        });
      }), wysiwygId);

      await applyDirective(page, {
        type: 'form_write',
        target: {namespace: 'cms_page_form', entity_type: 'cms_page', entity_id: String(cmsPage.id), store_id: ''},
        changes: [
          {
            path: 'data.content',
            label: 'Content',
            previous_value: '<p>Legacy plain content.</p>',
            value: '<p>Updated via the assistant.</p>',
            clear_use_default: false,
          },
        ],
      });

      const editorContent: string = await page.evaluate(
        (id) => (window as any).tinymce.get(id).getContent(),
        wysiwygId
      );

      expect(editorContent).toContain('Updated via the assistant.');
    } finally {
      await magentoApi.deleteCmsPage(request, cmsPage.identifier);
    }
  });
});

/**
 * This install locks Page Builder on for CMS content, so `data.content` is a Page Builder stage.
 * Its value observable is what gets saved, but the stage only ever renders from the value it was
 * created with; before this, a staged content change was invisible until the page reloaded after
 * Save. Both orders matter: the stage may already be open when the change arrives, or be opened
 * afterwards from the "Edit with Page Builder" button.
 */
test.describe('Form bridge apply on the CMS page Page Builder stage', () => {
  /* Two CMS pages created in the same instant collide in Magento's own save (seen as a generic
     "Something went wrong while saving the page" from the REST API), so one page is created up
     front and both tests reuse it; each test only stages, never saves, so nothing carries over. */
  test.describe.configure({mode: 'serial'});

  const STAGED_TEXT = 'Staged through the assistant before any save.';
  let cmsPageId: number;
  let cmsPageIdentifier: string;

  test.beforeAll(async ({request}) => {
    const cmsPage = await magentoApi.createCmsPage(request, {content: '<p>Original content.</p>'});

    cmsPageId = cmsPage.id;
    cmsPageIdentifier = cmsPage.identifier;
  });

  test.afterAll(async ({request}) => {
    await magentoApi.deleteCmsPage(request, cmsPageIdentifier);
  });

  async function openContentSection(page: Page) {
    const section = page.locator('[data-index="content"]');

    if (!(await section.locator('.pagebuilder-stage, .pagebuilder-wysiwyg-overlay, button').first().isVisible().catch(() => false))) {
      await section.locator('.fieldset-wrapper-title').click();
    }
  }

  async function openStage(page: Page) {
    const editButton = page.locator('[data-index="content"] button', {hasText: 'Edit with Page Builder'});

    if (await editButton.isVisible().catch(() => false)) {
      await editButton.click();
    }

    await page.locator('.pagebuilder-stage').first().waitFor({state: 'visible', timeout: ADMIN_SAVE_TIMEOUT});
  }

  function contentDirective(cmsPageId: number): Record<string, unknown> {
    return {
      type: 'form_write',
      target: {namespace: 'cms_page_form', entity_type: 'cms_page', entity_id: String(cmsPageId), store_id: '', is_new: false},
      changes: [
        {path: 'data.content', label: 'Content', previous_value: '', value: '<p>' + STAGED_TEXT + '</p>', clear_use_default: false},
      ],
    };
  }

  test('it shows staged content on an already open Page Builder stage', async ({page}) => {
    test.setTimeout(60000);

    await chatPanel.openOn(page, 'cms/page/edit/page_id/' + cmsPageId);
    await openContentSection(page);
    await openStage(page);
    await expect(page.locator('.pagebuilder-stage').first()).toContainText('Original content.', {timeout: ADMIN_SAVE_TIMEOUT});

    await applyDirective(page, contentDirective(cmsPageId));

    await expect(page.locator('.pagebuilder-stage').first()).toContainText(STAGED_TEXT, {timeout: ADMIN_SAVE_TIMEOUT});
    await expect(page.locator('.pagebuilder-stage').first()).not.toContainText('Original content.');
  });

  test('it shows staged content when the Page Builder stage is opened afterwards', async ({page}) => {
    test.setTimeout(60000);

    await chatPanel.openOn(page, 'cms/page/edit/page_id/' + cmsPageId);

    await applyDirective(page, contentDirective(cmsPageId));

    await openContentSection(page);
    await openStage(page);

    await expect(page.locator('.pagebuilder-stage').first()).toContainText(STAGED_TEXT, {timeout: ADMIN_SAVE_TIMEOUT});
  });
});

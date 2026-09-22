/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock, {textDeltas} from 'Actions/backend/ChatMock';
import MagentoApi from 'Services/MagentoApi';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();
const magentoApi = new MagentoApi();

const CONVERSATION_ID = 9401;
const MESSAGE_ID = 204501;

const META_DESCRIPTION_PATH = 'data.meta_description';
const NEW_META_DESCRIPTION =
  'Hand-made Italian chairs, delivered across Europe within three working days.';

/**
 * The CMS counterpart to form-bridge-apply.spec.ts, which covers the product form.
 *
 * Meta Description rather than Content on purpose: this install locks Page Builder on
 * (`cms/pagebuilder/enabled`), so `data.content` is a Page Builder stage rather than a plain field,
 * and the one spec that drives it has to construct a classic TinyMCE editor itself. Meta Description
 * is an ordinary textarea, so this exercises the path an administrator actually walks on a CMS page.
 */
test.describe('Write to a CMS page form', () => {
  test.describe.configure({mode: 'serial'});

  test('it stages a new meta description on a cms page', async ({page, request}) => {
    test.setTimeout(60000);

    const cmsPage = await magentoApi.createCmsPage(request);
    const target = {
      namespace: 'cms_page_form',
      entity_type: 'cms_page',
      entity_id: String(cmsPage.id),
      store_id: '',
    };
    const toolInput = {
      action: 'write_fields',
      form_namespace: target.namespace,
      entity_id: target.entity_id,
      store_id: target.store_id,
      changes: [{path: META_DESCRIPTION_PATH, value: NEW_META_DESCRIPTION}],
    };

    try {
      await chatMock.install(page, {
        stream: [
          {event: 'conversation', data: {conversation_id: CONVERSATION_ID, admin_user: 'Tester'}},
          ...textDeltas('I will rewrite the meta description for this page. '),
          {event: 'tool_call', data: {id: 'toolu_cms_meta_1', name: 'page_form', input: toolInput}},
          {
            event: 'confirm',
            data: {
              tools: [
                {
                  name: 'page_form',
                  description: 'Read and write the admin form currently open in the browser',
                  input: toolInput,
                },
              ],
            },
          },
          {
            event: 'done',
            data: {
              message_id: MESSAGE_ID,
              conversation_id: CONVERSATION_ID,
              pending_confirmation: true,
            },
          },
        ],
        status: {message_id: MESSAGE_ID},
        confirm: [
          {
            event: 'form_apply',
            data: {
              type: 'form_write',
              target,
              changes: [
                {
                  path: META_DESCRIPTION_PATH,
                  label: 'Meta Description',
                  previous_value: '',
                  value: NEW_META_DESCRIPTION,
                  clear_use_default: false,
                },
              ],
            },
          },
          ...textDeltas('Updated the meta description. It is staged, not saved yet.'),
          {event: 'done', data: {conversation_id: CONVERSATION_ID}},
        ],
        reject: {success: true},
      });

      await chatPanel.openOn(page, 'cms/page/edit/page_id/' + cmsPage.id);
      await chatPanel.ask(page, 'Rewrite the meta description for this page');

      await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
      await expect(chatPanel.lastAssistantMessage(page)).toContainText('Meta Description');

      await chatPanel.confirmButton(page).click();

      /* apply() waits for the provider and the field to be registered before it writes, so the
         staged value is not readable the instant the click returns. The highlight is the first
         observable evidence that it ran; read the value only once that is on screen. */
      await expect(page.locator('.mago-field-changed')).toHaveCount(1, {
        timeout: PROVIDER_ROUND_TRIP_TIMEOUT,
      });

      const staged = await page.evaluate(
        (path) => {
          const field = (window as any).magoFormBridge
            .snapshot()
            .fields.find((candidate: {path: string}) => candidate.path === path);

          return field ? field.value : null;
        },
        META_DESCRIPTION_PATH
      );

      expect(staged).toBe(NEW_META_DESCRIPTION);

      expect(
        (await magentoApi.findCmsPage(request, cmsPage.identifier)).meta_description ?? '',
        'the meta description must not be persisted until the administrator saves'
      ).not.toBe(NEW_META_DESCRIPTION);
    } finally {
      await magentoApi.deleteCmsPage(request, cmsPage.identifier);
    }
  });
});

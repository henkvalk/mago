/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import MagentoApi from 'Services/MagentoApi';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();
const magentoApi = new MagentoApi();

const IDENTIFIER = 'e2e-wiremock-page';

/**
 * No browser-level mocking here. The request goes through the real controller, ChatService,
 * ACL checks, tool execution and database writes. Only the call to Anthropic is intercepted,
 * by WireMock replaying genuine wire format from Test/End-2-end/wiremock.
 */
test.describe('Backend integration', () => {
  /* Both tests act on the same CMS page, so they cannot share the database concurrently. */
  test.describe.configure({mode: 'serial'});

  test.beforeEach(async ({request}) => {
    await magentoApi.deleteCmsPage(request, IDENTIFIER);
  });

  test.afterEach(async ({request}) => {
    await magentoApi.deleteCmsPage(request, IDENTIFIER);
  });

  test('Creates a real CMS page once the admin confirms', async ({page, request}) => {
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Please run the E2E WireMock check and create the CMS page for it.');

    await expect(chatPanel.toolTags(page)).toHaveText([/cms_data/], {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.lastAssistantMessage(page)).toContainText(IDENTIFIER, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    expect(
      await magentoApi.findCmsPage(request, IDENTIFIER),
      'the page must not exist before the admin confirms'
    ).toBeNull();

    await chatPanel.confirmButton(page).click();

    await expect(chatPanel.lastAssistantMessage(page)).toContainText(
      'is live at /' + IDENTIFIER,
      {timeout: PROVIDER_ROUND_TRIP_TIMEOUT}
    );

    const created = await magentoApi.findCmsPage(request, IDENTIFIER);

    expect(created, 'the page was not created').not.toBeNull();
    expect(created.title).toBe('E2E WireMock Page');
    expect(created.active, 'the page was created but is disabled').toBe(true);

    const storefront = await request.get('/' + IDENTIFIER);

    expect(storefront.status(), 'the assistant said the page is live, but it is not reachable').toBe(200);
    expect(await storefront.text()).toContain('Created through the mocked provider.');
  });

  test('Makes no database change when the admin rejects', async ({page, request}) => {
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Please run the E2E WireMock check and create the CMS page for it.');

    await expect(chatPanel.rejectButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.rejectButton(page).click();

    await expect(chatPanel.lastAssistantMessage(page)).toContainText(
      'Action rejected',
      {timeout: PROVIDER_ROUND_TRIP_TIMEOUT}
    );
    expect(await magentoApi.findCmsPage(request, IDENTIFIER)).toBeNull();
  });
});

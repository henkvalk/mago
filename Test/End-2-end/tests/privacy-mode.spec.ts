/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import MagentoApi from 'Services/MagentoApi';
import WireMockJournal from 'Services/WireMockJournal';

const chatPanel = new ChatPanel();
const magentoApi = new MagentoApi();
const wireMockJournal = new WireMockJournal();

const CANARY_EMAIL = 'privacy-canary@example.test';
const CANARY_FIRSTNAME = 'Canary';
const CANARY_LASTNAME = 'Privacyman';
const CANARY_TELEPHONE = '0612000097';

/* A substring of the canary's last name. The mocked tool call searches on this so the lookup
   finds the row through the LIKE match without the outbound request ever containing one of the
   exact strings the assertions below forbid. */
const LOOKUP_TERM = 'Privacym';

const QUESTION = 'Please run the E2E privacy lookup check.';

/**
 * L2 verification for privacy mode (issue #97, decision 7). No browser-level mocking: the request
 * travels through the real controller, ChatService, tool execution and the privacy filter, and only
 * the provider is played by WireMock. The panel assertions prove the turn completed; the journal
 * assertions read what actually crossed the wire to the provider, so a regression that leaks the
 * canary cannot hide behind a working chat panel. A catch-all mapping
 * (wiremock/mappings/privacy-canary-tripwire.json) additionally answers HTTP 500 "PRIVACY LEAK"
 * to any unmatched provider request carrying the canary email, so a leak in any other spec of the
 * suite fails loudly too.
 */
test.describe('Privacy mode', () => {
  test.beforeEach(async ({request}) => {
    await magentoApi.deleteCustomerByEmail(request, CANARY_EMAIL);
    await magentoApi.createCustomerWithAddress(request, {
      email: CANARY_EMAIL,
      firstname: CANARY_FIRSTNAME,
      lastname: CANARY_LASTNAME,
      city: 'Duckburg',
      telephone: CANARY_TELEPHONE,
    });
  });

  test.afterEach(async ({request}) => {
    await magentoApi.deleteCustomerByEmail(request, CANARY_EMAIL);
  });

  test('Strips the canary PII from the provider request and the stored conversation', async ({page, request}) => {
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, QUESTION);

    /* The turn has to complete usefully first: the tool ran and the summary rendered, so the
       journal assertions below prove filtering rather than breakage. */
    await expect(chatPanel.toolTags(page)).toHaveText([/customer_data/]);
    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Privacy lookup complete');
    await expect(chatPanel.lastAssistantMessage(page)).not.toContainText('privacy canary row is missing');

    /* L2: the raw journalled bodies of every provider request of this scenario. Turn 2 is the
       one carrying the tool result, recognisable by its tool-role message. */
    const allBodies = await wireMockJournal.findRequestBodies(request, [
      {contains: 'E2E privacy lookup check'},
    ]);
    const toolBodies = await wireMockJournal.findRequestBodies(request, [
      {contains: 'E2E privacy lookup check'},
      {contains: '"role":"tool"'},
    ]);

    expect(allBodies.length, 'both turns must have reached the provider').toBeGreaterThanOrEqual(2);
    expect(toolBodies.length, 'no tool-result turn ever reached the provider').toBeGreaterThanOrEqual(1);

    for (const body of toolBodies) {
      /* The raw request body is JSON, so the token's slashes may arrive escaped. */
      expect(body, 'the tokenised customer reference is missing from the tool result').toMatch(/mago:(\\?\/){2}customer_\d/);
      expect(body, 'the lookup argument did not reach the provider intact').toContain(LOOKUP_TERM);
    }

    for (const body of allBodies) {
      expect(body, 'the canary email leaked to the provider').not.toContain(CANARY_EMAIL);
      expect(body, 'the canary first name leaked to the provider').not.toContain(CANARY_FIRSTNAME);
      expect(body, 'the canary last name leaked to the provider').not.toContain(CANARY_LASTNAME);
      expect(body, 'the canary telephone leaked to the provider').not.toContain(CANARY_TELEPHONE);
    }

    /* L3: the stored copy of the conversation, read through the web API endpoint that returns
       messages as persisted (no rehydration), unlike the panel's own mago/chat/load. The panel
       keeps the conversation id in sessionStorage under mago_conv. The read path does not persist
       tool results as messages (only user and assistant turns are stored), so the check here is
       that no canary PII was written to the conversation; the tokenised tool result reaching the
       provider is proven by the journal assertions above. */
    const conversationId = await page.evaluate(() => sessionStorage.getItem('mago_conv'));

    expect(conversationId, 'the panel never stored a conversation id').not.toBeNull();

    const stored = await magentoApi.getStoredConversation(request, parseInt(conversationId as string, 10));

    expect(stored, 'the canary email is stored in the conversation').not.toContain(CANARY_EMAIL);
    expect(stored, 'the canary first name is stored in the conversation').not.toContain(CANARY_FIRSTNAME);
    expect(stored, 'the canary last name is stored in the conversation').not.toContain(CANARY_LASTNAME);
    expect(stored, 'the canary telephone is stored in the conversation').not.toContain(CANARY_TELEPHONE);
  });
});

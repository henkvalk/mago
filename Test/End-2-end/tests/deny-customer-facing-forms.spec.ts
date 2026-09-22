/*
 * Copyright © Mago Assistant
 */

import {expect, test, type APIRequestContext} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import MagentoApi from 'Services/MagentoApi';

const chatPanel = new ChatPanel();
const magentoApi = new MagentoApi();

const WIREMOCK_ADMIN_URL = 'http://wiremock:8080/__admin/requests';

async function ensureWireMockReachable(request: APIRequestContext) {
  const response = await request.get('http://wiremock:8080/__admin/mappings').catch(() => null);

  if (!response || !response.ok()) {
    throw new Error(
      'WireMock is not reachable at http://wiremock:8080. These specs need the mageos_ai/services/configuration '
      + 'service row pointed at WireMock (Stores > Configuration > Mage-OS > AI Configuration).'
    );
  }
}

async function findWireMockRequestBody(request: APIRequestContext, triggerPhrase: string): Promise<any> {
  for (let attempt = 0; attempt < 30; attempt++) {
    const response = await request.get(WIREMOCK_ADMIN_URL);
    const payload = await response.json();
    const matches = (payload.requests ?? []).filter((entry: any) => {
      if (entry.request.url !== '/v1/chat/completions') {
        return false;
      }
      const decoded = Buffer.from(entry.request.bodyAsBase64, 'base64').toString('utf8');
      return decoded.includes(triggerPhrase);
    });

    if (matches.length > 0) {
      /* WireMock's request journal is newest-first, not chronological. */
      const decoded = Buffer.from(matches[0].request.bodyAsBase64, 'base64').toString('utf8');
      return JSON.parse(decoded);
    }

    await new Promise((resolve) => setTimeout(resolve, 500));
  }

  throw new Error('No request containing "' + triggerPhrase + '" reached WireMock within the timeout.');
}

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
      const decoded = Buffer.from(matches[0].request.bodyAsBase64, 'base64').toString('utf8');
      const body = JSON.parse(decoded);
      const toolMessage = body.messages.find((message: any) => message.role === 'tool');

      return JSON.parse(toolMessage.content);
    }

    await new Promise((resolve) => setTimeout(resolve, 500));
  }

  throw new Error('No request containing "' + triggerPhrase + '" with a tool result reached WireMock within the timeout.');
}

async function snapshot(page: import('@playwright/test').Page) {
  return page.evaluate(() => (window as any).magoFormBridge.snapshot());
}

/**
 * openOnDeniedForm() cannot wait on `snapshot().hasForm` the way openOn() does (that will never
 * become true on a denied page), so a form that genuinely does register - customer_form,
 * cms_block_form - can still be mid-registration the instant navigation settles. Waiting on the
 * bridge's own whenFormReady() first is what closes that race for those two forms specifically;
 * the order view and admin user edit pages register no UI component form at all, so nothing here
 * is needed before checking their snapshot.
 */
async function waitForFormReady(page: import('@playwright/test').Page, entityType: string) {
  await page.evaluate((type) => new Promise((resolve) => {
    (window as any).magoFormBridge.whenFormReady(type, 8000, resolve);
  }), entityType);
}

test.describe('Deny customer-facing forms', () => {
  /* openOn()/openOnDeniedForm() both mutate shared admin grid bookmark state, so these cannot
     safely run in parallel. */
  test.describe.configure({mode: 'serial'});

  /*
   * The admin customer edit page never registers a top-level "customer_form" UI component the way
   * catalog product and CMS page/block forms do - only its data provider and sub-fields register,
   * confirmed by inspecting uiRegistry directly against this install. findFormComponent() (task
   * 002, unchanged here) therefore reports no form at all on this page regardless of FormPolicy,
   * which already makes "no snapshot" true architecturally. FormPolicy's own denial is what a
   * customer_form that did register this way would additionally get, proven instead against a
   * namespace added to the published deny list below and against the server's own re-check
   * ("it rejects a denied form namespace...").
   */
  test('it takes no snapshot on a customer edit page', async ({page, request}) => {
    test.setTimeout(40000);

    const customer = await magentoApi.createCustomer(request);

    try {
      await chatPanel.openOnDeniedForm(page, 'customer/index/edit/id/' + customer.id);
      const result = await snapshot(page);

      expect(result.hasForm).toBe(false);
      expect(result.fields).toEqual([]);
    } finally {
      await magentoApi.deleteCustomer(request, customer.id);
    }
  });

  test('it takes no snapshot on an order view page', async ({page, request}) => {
    test.setTimeout(40000);

    const order = await magentoApi.getFirstOrder(request);

    await chatPanel.openOnDeniedForm(page, 'sales/order/view/order_id/' + order.entity_id);
    const result = await snapshot(page);

    expect(result.hasForm).toBe(false);
  });

  test('it takes no snapshot on an admin user edit page', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOnDeniedForm(page, 'admin/user/edit');
    const result = await snapshot(page);

    expect(result.hasForm).toBe(false);
  });

  test('it still snapshots a cms page form', async ({page, request}) => {
    test.setTimeout(40000);

    const cmsPage = await magentoApi.createCmsPage(request);

    try {
      await chatPanel.openOn(page, 'cms/page/edit/page_id/' + cmsPage.id);
      const result = await snapshot(page);

      expect(result.hasForm).toBe(true);
      expect(result.namespace).toBe('cms_page_form');
      expect(result.fields.length).toBeGreaterThan(0);
    } finally {
      await magentoApi.deleteCmsPage(request, cmsPage.identifier);
    }
  });

  /*
   * FormPolicy publishes its deny lists through MAGO_CONFIG (Block\Adminhtml\ChatPanel), and
   * form-bridge.js reads them on every snapshot. Overriding that published list at runtime is the
   * same thing an integrator's di.xml additionalDeniedNamespacePatterns produces on the page, so
   * this proves the client honours a namespace-only denial without shipping one for a real,
   * advertised entity type.
   */
  test('it denies a form namespace added to the published deny list', async ({page, request}) => {
    test.setTimeout(40000);

    const block = await magentoApi.createCmsBlock(request);

    try {
      await chatPanel.openOnDeniedForm(page, 'cms/block/edit/block_id/' + block.block_id);
      await waitForFormReady(page, 'cms_block');
      await page.evaluate(() => {
        (window as any).MAGO_CONFIG.formDenyNamespaces = ((window as any).MAGO_CONFIG.formDenyNamespaces || []).concat(['cms_block_form']);
      });
      const result = await snapshot(page);

      expect(result.hasForm).toBe(false);
      expect(result.denied).toBe(true);
    } finally {
      await magentoApi.deleteCmsBlock(request, block.identifier);
    }
  });

  /*
   * The customer, order and admin user pages above register no UI component form, so their
   * snapshot tests would pass with FormPolicy emptied out. This pins the policy itself: the
   * patterns the server publishes are what form-bridge.js checks every snapshot against.
   */
  test('it publishes the customer, order and admin user deny patterns to the browser', async ({page}) => {
    await chatPanel.openOnDashboard(page);

    const config = await page.evaluate(() => ({
      namespaces: (window as any).MAGO_CONFIG.formDenyNamespaces,
      routes: (window as any).MAGO_CONFIG.formDenyRoutes,
    }));

    expect(config.namespaces).toEqual(expect.arrayContaining(['customer_form', 'sales_order_view', 'admin_user_form', 'newsletter_subscriber_form']));
    expect(config.routes).toEqual(expect.arrayContaining(['customer/', 'sales/order', 'admin/user']));
  });

  test.describe('Real backend', () => {
    test('it rejects a denied form namespace posted directly to the stream endpoint', async ({page, request}) => {
      test.setTimeout(40000);

      await ensureWireMockReachable(request);
      await chatPanel.openOnDashboard(page);

      /* Bypasses form-bridge.js on purpose: an unmodified client never sends field data for a
         denied form (see the snapshot-level tests above), so the only way to reach the server's
         own FormPolicy re-check is a raw fetch that speaks the same wire format the panel does. */
      const streamBody = await page.evaluate(async () => {
        const config = (window as any).MAGO_CONFIG;
        const result = await fetch(config.streamUrl, {
          method: 'POST',
          headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
          body: JSON.stringify({
            message: 'E2E Deny Form Ack Check: what page am I on?',
            conversation_id: null,
            form_key: config.formKey,
            page_context: {
              hasForm: true,
              route: 'customer/index/edit/id/999',
              namespace: 'customer_form',
              entityType: 'customer',
              entityId: '999',
              isNewEntity: false,
              storeId: null,
              fields: [{path: 'email', label: 'Email', type: 'text', value: 'forged-secret@example.com'}],
            },
          }),
          credentials: 'same-origin',
        });

        return result.text();
      });

      expect(streamBody).toContain('event: done');
      expect(streamBody).not.toContain('event: error');
      expect(streamBody).not.toContain('forged-secret@example.com');
      expect(streamBody).toContain('Page context check acknowledged');

      const requestBody = await findWireMockRequestBody(request, 'E2E Deny Form Ack Check');
      const systemMessage = requestBody.messages.find((message: any) => message.role === 'system');

      expect(systemMessage.content).not.toContain('forged-secret@example.com');
      expect(systemMessage.content).not.toContain('customer_form');
    });

    /*
     * The administrator is genuinely on the customer edit page for this test, asking the assistant
     * a real question there. What the request carries is sent through a raw fetch rather than
     * chatPanel.ask(), for the same reason as findFormComponent() finding no form at all on this
     * page (see the snapshot-level customer test above): form-bridge.js's own detection cannot
     * observe a form that never registers, so it would never exercise this refusal on this
     * particular page either. The `{hasForm: false, denied: true}` payload sent here is exactly
     * what form-bridge.js does send once it does detect a denied form - proven directly in
     * "it denies a form namespace added to the published deny list" above -
     * so this is the same wire contract a working detection would produce, on the real page the
     * requirement names.
     */
    test('it tells the admin it cannot read this form when asked on a customer page', async ({page, request}) => {
      test.setTimeout(40000);

      await ensureWireMockReachable(request);

      const customer = await magentoApi.createCustomer(request);

      try {
        await chatPanel.openOnDeniedForm(page, 'customer/index/edit/id/' + customer.id);

        const streamBody = await page.evaluate(async () => {
          const config = (window as any).MAGO_CONFIG;
          const result = await fetch(config.streamUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({
              message: 'E2E Deny Form Describe Check: what fields are on this form?',
              conversation_id: null,
              form_key: config.formKey,
              page_context: {hasForm: false, denied: true},
            }),
            credentials: 'same-origin',
          });

          return result.text();
        });

        expect(streamBody).toContain('I cannot read this form.');

        const toolResult = await findPageFormToolResult(request, 'E2E Deny Form Describe Check');

        expect(toolResult.form_open).toBe(false);
        expect(toolResult.denied).toBe(true);
        expect(toolResult.message.toLowerCase()).toContain('customer_data');
      } finally {
        await magentoApi.deleteCustomer(request, customer.id);
      }
    });
  });
});

/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import MagentoApi from 'Services/MagentoApi';

const chatPanel = new ChatPanel();
const magentoApi = new MagentoApi();

/**
 * form-bridge.js publishes window.magoFormBridge specifically so these specs (and later tasks)
 * can call snapshot() directly through page.evaluate, without going through the chat panel at all.
 */
async function snapshot(page: import('@playwright/test').Page) {
  return page.evaluate(() => (window as any).magoFormBridge.snapshot());
}

test.describe('Form bridge snapshot', () => {
  /* Every product-edit spec below acts on the same disposable product, and openOn() itself
     mutates shared admin grid bookmark state, so these cannot safely run in parallel. */
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

  test('it reports no form on an admin page without a ui component form', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOnDashboard(page);

    expect((await snapshot(page)).hasForm).toBe(false);
  });

  test('it reports the form namespace and entity id on a product edit page', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    const result = await snapshot(page);

    expect(result.namespace).toBe('product_form');
    expect(result.entityType).toBe('product');
    expect(result.entityId).toBe(String(productId));
  });

  test('it lists the product name field with its label and current value', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    const result = await snapshot(page);
    const nameField = result.fields.find((field: any) => field.path.endsWith('.name'));

    expect(nameField.label).toBe('Product Name');
    expect(nameField.value).toBe('Mago E2E Product ' + sku);
  });

  test('it lists a select field with its available options', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    const result = await snapshot(page);
    const visibilityField = result.fields.find((field: any) => field.path.endsWith('.visibility'));

    expect(visibilityField.type).toBe('select');
    expect(visibilityField.options.length).toBeGreaterThan(0);
    expect(visibilityField.options).toContainEqual(expect.objectContaining({label: 'Search'}));
  });

  test('it reports the store scope of the form from the url', async ({page, request}) => {
    test.setTimeout(40000);

    const storeViews = await magentoApi.getStoreViews(request);
    const nonDefaultStore = storeViews.find((storeView: any) => storeView.id !== 0);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId + '/store/' + nonDefaultStore.id);
    const result = await snapshot(page);

    expect(result.storeId).toBe(String(nonDefaultStore.id));
  });

  test('it includes a value the admin has edited but not saved', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    /* .fill() sets the DOM value but does not reliably drive the knockout textInput binding in
       this admin theme; real keystrokes do, which is also closer to what an administrator does. */
    const nameInput = page.locator('[name="product[name]"]');

    await nameInput.click();
    await nameInput.fill('');
    await nameInput.pressSequentially('Edited But Not Saved');
    await nameInput.blur();

    const result = await snapshot(page);
    const nameField = result.fields.find((field: any) => field.path.endsWith('.name'));

    expect(nameField.value).toBe('Edited But Not Saved');
  });

  /**
   * A stock product form registers just over 200 field components, so the old cap of 200 cut it
   * short on every product page and dropped whichever fields registered last. The byte cap is what
   * bounds the payload; this guards the field cap having enough headroom for an ordinary form.
   */
  test('it describes a whole product form without cutting the field list short', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await page.evaluate(() => new Promise((resolve) => {
      (window as any).magoFormBridge.whenFieldsSettled(5000, resolve);
    }));

    const result = await snapshot(page);

    expect(result.truncated.fields).toBe(false);
  });

  /**
   * Description is a Page Builder field, which registers only once its stage has initialised, well
   * after the plain inputs around it. Snapshotting before that reported a product form with no
   * Description at all, which read as "this form has no such field" rather than "ask again".
   */
  test('it includes the Page Builder description field once the form has settled', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    await page.evaluate(() => new Promise((resolve) => {
      (window as any).magoFormBridge.whenFieldsSettled(5000, resolve);
    }));

    const result = await snapshot(page);
    const paths = result.fields.map((field: any) => field.path);

    expect(paths.some((path: string) => /(^|\.)description$/.test(path))).toBe(true);
  });

  test('it waits for a form that has not registered yet instead of reporting none', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    const fieldCount = await page.evaluate(() => new Promise((resolve) => {
      const bridge = (window as any).magoFormBridge;

      bridge.whenFieldsSettled(5000, () => resolve(bridge.snapshot().fields.length));
    }));

    expect(fieldCount).toBeGreaterThan(0);
  });

  test('it reports truncation when the form has more fields than the cap', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    const result = await page.evaluate(() => {
      (window as any).MAGO_CONFIG.formFieldCap = 3;

      return (window as any).magoFormBridge.snapshot();
    });

    expect(result.fields.length).toBe(3);
    expect(result.truncated.fields).toBe(true);
  });

  test('it reports an empty entity id on a new product form', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/new/set/4/type/simple');
    const result = await snapshot(page);

    expect(result.entityId).toBe('');
    expect(result.isNewEntity).toBe(true);
  });

  test('it reads the store scope from a url with an odd number of leading segments', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    /* web/seo/use_rewrites off, or a base path, puts one more segment before the key/value
       pairs; reading pairs from index 0 then shifts every key onto a value. The path is rewritten
       in place (no navigation), so the same registered form is snapshotted under the odd path. */
    await page.evaluate((id) => {
      const key = window.location.pathname.match(/\/key\/([^/]+)/)![1];
      history.replaceState({}, '', '/index.php/admin/catalog/product/edit/id/' + id + '/store/1/key/' + key + '/');
    }, productId);
    const result = await snapshot(page);

    expect(result.entityId).toBe(String(productId));
    expect(result.storeId).toBe('1');
  });

  test('it lists a secret field without its value', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);

    /* The product form has no password field; the sku component is flagged as one the way a
       UI component password field is, so the redaction rule itself is what gets exercised. */
    await page.evaluate(() => {
      const registry = (window as any).require('uiRegistry');
      const field = registry.filter((c: any) => c && c.dataScope === 'data.product.sku' && typeof c.value === 'function')[0];
      field.inputType = 'password';
    });
    const result = await snapshot(page);
    const skuField = result.fields.find((field: any) => field.path === 'data.product.sku');

    expect(skuField.redacted).toBe(true);
    expect(skuField.value).toBeNull();
    expect(skuField.type).toBe('password');
  });

  test('it stays under the byte cap on a product form', async ({page}) => {
    test.setTimeout(40000);

    await chatPanel.openOn(page, 'catalog/product/edit/id/' + productId);
    const byteSize = await page.evaluate(() => {
      (window as any).MAGO_CONFIG.formByteCap = 2000;
      const result = (window as any).magoFormBridge.snapshot();

      return new Blob([JSON.stringify(result)]).size;
    });

    expect(byteSize).toBeLessThanOrEqual(2000);
  });
});

test.describe('Form bridge on the CMS page form', () => {
  test('it works on the CMS page form without form-specific branching', async ({page, request}) => {
    test.setTimeout(40000);

    const token = await request
      .post('/rest/V1/integration/admin/token', {
        data: {
          username: process.env.ADMIN_USERNAME || 'exampleuser',
          password: process.env.ADMIN_PASSWORD || 'examplepassword123',
        },
      })
      .then((response) => response.json());
    const pagesResponse = await request.get('/rest/V1/cmsPage/search', {
      headers: {Authorization: 'Bearer ' + token},
      params: {
        'searchCriteria[pageSize]': '1',
      },
    });
    const pages = await pagesResponse.json();
    const pageId = pages.items[0].id;

    await chatPanel.openOn(page, 'cms/page/edit/page_id/' + pageId);
    const result = await snapshot(page);

    expect(result.namespace).toBe('cms_page_form');
    expect(result.entityType).toBe('cms_page');
    expect(result.entityId).toBe(String(pageId));
    expect(result.storeId).toBeNull();
    expect(result.fields.length).toBeGreaterThan(0);
  });
});

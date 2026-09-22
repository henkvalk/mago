/*
 * Copyright © Mago Assistant
 */

import {type APIRequestContext} from '@playwright/test';

export default class MagentoApi {
  private token: string | null = null;

  private async getToken(request: APIRequestContext): Promise<string> {
    if (this.token === null) {
      const response = await request.post('/rest/V1/integration/admin/token', {
        data: {
          username: process.env.ADMIN_USERNAME || 'exampleuser',
          password: process.env.ADMIN_PASSWORD || 'examplepassword123',
        },
      });

      this.token = await response.json();
    }

    return this.token;
  }

  async findCmsPage(request: APIRequestContext, identifier: string) {
    const response = await request.get('/rest/V1/cmsPage/search', {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
      params: {
        'searchCriteria[filterGroups][0][filters][0][field]': 'identifier',
        'searchCriteria[filterGroups][0][filters][0][value]': identifier,
      },
    });

    const result = await response.json();

    return result.items?.[0] ?? null;
  }

  /**
   * Creates a disposable CMS page with an identifier unique to this call. `is_active` is
   * deliberately not settable here: this install's cmsPage webapi schema rejects that field name
   * regardless of casing, a pre-existing issue unrelated to Mago, so pages are created inactive.
   */
  /**
   * Two CMS pages saved in the same instant (two describes creating their fixture in parallel)
   * intermittently fail inside Magento's own save with a generic "Something went wrong while
   * saving the page", nothing to do with the request itself; a fresh identifier on a second
   * attempt is all it takes.
   */
  async createCmsPage(request: APIRequestContext, overrides: Record<string, unknown> = {}) {
    let lastError = '';

    for (let attempt = 0; attempt < 3; attempt++) {
      const identifier = 'mago-e2e-' + Date.now() + '-' + Math.floor(Math.random() * 10000);
      const response = await request.post('/rest/V1/cmsPage', {
        headers: {Authorization: 'Bearer ' + await this.getToken(request)},
        data: {
          page: {
            identifier,
            title: 'Mago E2E Page ' + identifier,
            content: '<p>Mago E2E placeholder content.</p>',
            page_layout: '1column',
            ...overrides,
          },
        },
      });

      if (response.ok()) {
        return response.json();
      }

      lastError = 'Failed to create CMS page ' + identifier + ': ' + await response.text();
      await new Promise((resolve) => setTimeout(resolve, 500 * (attempt + 1)));
    }

    throw new Error(lastError);
  }

  async deleteCmsPage(request: APIRequestContext, identifier: string) {
    const page = await this.findCmsPage(request, identifier);

    if (page === null) {
      return;
    }

    await request.delete('/rest/V1/cmsPage/' + page.id, {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
    });
  }

  async findCmsBlock(request: APIRequestContext, identifier: string) {
    const response = await request.get('/rest/V1/cmsBlock/search', {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
      params: {
        'searchCriteria[filterGroups][0][filters][0][field]': 'identifier',
        'searchCriteria[filterGroups][0][filters][0][value]': identifier,
      },
    });

    const result = await response.json();

    return result.items?.[0] ?? null;
  }

  /**
   * Creates a disposable CMS block with an identifier unique to this call, so the deny-list specs
   * (task 003) have a real cms_block_form to open without depending on fixture data. `is_active` is
   * deliberately not settable here, the same pre-existing quirk createCmsPage() already works
   * around: this install's cmsBlock webapi schema rejects that field name regardless of casing, so
   * blocks are created inactive.
   */
  async createCmsBlock(request: APIRequestContext, overrides: Record<string, unknown> = {}) {
    const identifier = 'mago-e2e-' + Date.now() + '-' + Math.floor(Math.random() * 10000);
    const response = await request.post('/rest/V1/cmsBlock', {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
      data: {
        block: {
          identifier,
          title: 'Mago E2E Block ' + identifier,
          content: '<p>Mago E2E placeholder content.</p>',
          ...overrides,
        },
      },
    });

    if (!response.ok()) {
      throw new Error('Failed to create CMS block ' + identifier + ': ' + await response.text());
    }

    return response.json();
  }

  async deleteCmsBlock(request: APIRequestContext, identifier: string) {
    const block = await this.findCmsBlock(request, identifier);

    if (block === null) {
      return;
    }

    await request.delete('/rest/V1/cmsBlock/' + block.block_id, {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
    });
  }

  /**
   * Creates a disposable simple product with a SKU unique to this call, so parallel specs never
   * collide. The name embeds the SKU too: Magento derives the url_key from the name, and a fixed
   * name across many test runs collides on that url_key even though the SKU itself is unique.
   */
  async createProduct(request: APIRequestContext, overrides: Record<string, unknown> = {}) {
    const sku = 'mago-e2e-' + Date.now() + '-' + Math.floor(Math.random() * 10000);
    const response = await request.post('/rest/V1/products', {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
      data: {
        product: {
          sku,
          name: 'Mago E2E Product ' + sku,
          price: 19.99,
          status: 1,
          visibility: 4,
          type_id: 'simple',
          attribute_set_id: 4,
          ...overrides,
        },
      },
    });

    if (!response.ok()) {
      throw new Error('Failed to create product ' + sku + ': ' + await response.text());
    }

    return response.json();
  }

  async findProduct(request: APIRequestContext, sku: string) {
    const response = await request.get('/rest/V1/products/' + encodeURIComponent(sku), {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
    });

    if (!response.ok()) {
      return null;
    }

    return response.json();
  }

  /**
   * The default, store-code-less `/rest/V1/products/:sku` always reads the admin (global) scope,
   * so a store-view-scoped attribute value staged and saved at a specific store view never shows
   * up through it. Prefixing the path with that store view's own code is what reads the value as
   * that store view actually sees it.
   */
  async findProductAtStore(request: APIRequestContext, sku: string, storeCode: string) {
    const response = await request.get('/rest/' + storeCode + '/V1/products/' + encodeURIComponent(sku), {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
    });

    if (!response.ok()) {
      return null;
    }

    return response.json();
  }

  async deleteProduct(request: APIRequestContext, sku: string) {
    const product = await this.findProduct(request, sku);

    if (product === null) {
      return;
    }

    await request.delete('/rest/V1/products/' + encodeURIComponent(sku), {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
    });
  }

  async createCategory(request: APIRequestContext, overrides: Record<string, unknown> = {}) {
    const response = await request.post('/rest/V1/categories', {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
      data: {
        category: {
          name: 'Mago E2E Category ' + Date.now() + '-' + Math.floor(Math.random() * 10000),
          parent_id: 2,
          is_active: true,
          include_in_menu: false,
          ...overrides,
        },
      },
    });

    if (!response.ok()) {
      throw new Error('Failed to create category: ' + await response.text());
    }

    return response.json();
  }

  async deleteCategory(request: APIRequestContext, categoryId: number) {
    const response = await request.delete('/rest/V1/categories/' + categoryId, {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
    });

    if (!response.ok() && response.status() !== 404) {
      throw new Error('Failed to delete category ' + categoryId + ': ' + await response.text());
    }
  }

  async getStoreViews(request: APIRequestContext) {
    const response = await request.get('/rest/V1/store/storeViews', {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
    });

    return response.json();
  }

  /**
   * Creates a disposable customer with an email unique to this call, so the deny-list specs (task
   * 003) have a customer edit page to open without depending on fixture data that may or may not
   * exist on a given install.
   */
  async createCustomer(request: APIRequestContext, overrides: Record<string, unknown> = {}) {
    const unique = Date.now() + '-' + Math.floor(Math.random() * 10000);
    const response = await request.post('/rest/V1/customers', {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
      data: {
        customer: {
          email: 'mago-e2e-' + unique + '@example.test',
          firstname: 'Mago',
          lastname: 'E2E ' + unique,
          ...overrides,
        },
      },
    });

    if (!response.ok()) {
      throw new Error('Failed to create customer: ' + await response.text());
    }

    return response.json();
  }

  async deleteCustomer(request: APIRequestContext, customerId: number) {
    await request.delete('/rest/V1/customers/' + customerId, {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
    });
  }

  /**
   * Reads one existing order from sample data rather than creating one, since placing a real order
   * through the webapi needs a full cart/quote flow this suite has no other use for. The order view
   * page is read-only in these specs, so reusing fixture data is safe.
   */
  async getFirstOrder(request: APIRequestContext) {
    const response = await request.get('/rest/V1/orders', {
      headers: {Authorization: 'Bearer ' + await this.getToken(request)},
      params: {
        'searchCriteria[pageSize]': '1',
      },
    });

    const result = await response.json();
    const order = result.items?.[0];

    if (!order) {
      throw new Error('No orders found on this install; the deny-list specs need at least one order to view.');
    }

    return order;
  }
}

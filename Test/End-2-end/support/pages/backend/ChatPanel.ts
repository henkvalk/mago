/*
 * Copyright © Mago Assistant
 */

import {errors, type Locator, type Page} from '@playwright/test';
import AdminModals from 'Pages/backend/AdminModals';

/* These clicks act on the already-loaded panel, so a click that has not landed in five seconds
   is blocked rather than slow. Kept short deliberately: it is what lets a covered panel fail with
   a named error in seconds instead of waiting out the suite's 25s test timeout. */
const INTERACTION_TIMEOUT_MS = 5000;

/* Below the suite's 25s test timeout (playwright.config.ts) so a stuck bind still fails with an
   explaining message instead of surfacing as a bare test timeout, but with enough headroom that
   a busy dev instance loading chat-panel.js under require(['marked'], ...) does not trip it. */
const TOGGLE_BIND_TIMEOUT_MS = 20000;

/**
 * Admin GET requests other than the configured startup page (Dashboard) are rejected unless they
 * carry a secret key computed server-side from the route, controller and action name. The key does
 * not depend on any entity id, so one borrowed from an existing row in the target grid works for
 * any other id under the same controller/action for the rest of the session. Each recipe below
 * describes, for one admin route prefix, where to find that key: a menu link for the listing itself,
 * the grid's own data namespace for edit links, and the "Add" button for new-entity links.
 */
interface ListingRecipe {
  menuHrefContains: string;
  listingNamespace?: string;
  addButtonSelector?: string;
  /** The key the row's "actions" object carries its link under; most listings use "edit". */
  actionKey?: string;
  /**
   * Set for a listing rendered by a legacy (pre-UI-component) grid, which has no mui/index/render
   * JSON response to read a row's link from - only a substring an admin's own edit link contains,
   * scraped straight out of the rendered grid markup instead.
   */
  legacyEditHrefContains?: string;
}

const LISTING_RECIPES: Record<string, ListingRecipe> = {
  'catalog/product': {
    menuHrefContains: '/catalog/product/',
    listingNamespace: 'product_listing',
    addButtonSelector: '#add_new_product-button',
  },
  'cms/page': {
    menuHrefContains: '/cms/page/',
    listingNamespace: 'cms_page_listing',
  },
  'cms/block': {
    menuHrefContains: '/cms/block/',
    listingNamespace: 'cms_block_listing',
  },
  'mago/skills': {
    menuHrefContains: '/mago/skills/',
    listingNamespace: 'mago_skills_listing',
  },
  'customer/index': {
    menuHrefContains: '/customer/index/',
    listingNamespace: 'customer_listing',
  },
  'sales/order': {
    menuHrefContains: '/sales/order/',
    listingNamespace: 'sales_order_grid',
    actionKey: 'view',
  },
  'admin/user': {
    menuHrefContains: '/admin/user/',
    legacyEditHrefContains: '/user_id/',
  },
};

export default class ChatPanel {
  private readonly adminModals = new AdminModals();

  async openOnDashboard(page: Page) {
    const adminPath = process.env.ADMIN_PATH || 'admin';

    this.adminModals.watch(page);

    /* Wait for stylesheets, not just markup. Until the CSS applies the toggle sits in the
       header as an unstyled button rather than the icon next to search. */
    await page.goto('/' + adminPath + '/admin/dashboard', {waitUntil: 'load'});

    await this.open(page);
  }

  /**
   * Opens an arbitrary admin route (e.g. `catalog/product/edit/id/42` or `cms/page/edit/page_id/1`)
   * and waits for both the chat panel and the page's own UI component form to be ready.
   */
  async openOn(page: Page, adminRoute: string) {
    const url = await this.resolveKeyedUrl(page, adminRoute);

    await page.goto(url, {waitUntil: 'load'});
    await this.waitForFormRegistered(page);
    await this.open(page);
  }

  /**
   * Moves an already-running conversation to another admin page, the way an administrator does
   * when they save one entity and open the next while the panel stays open. chat-panel.js restores
   * the open panel and the conversation from sessionStorage on load, so this waits for that
   * instead of clicking the toggle (which would close the restored panel again).
   *
   * Takes a resolved URL (see keyedUrlFor()) rather than a route on purpose: resolving a key hops
   * through the dashboard and a listing grid, and every hop restores the panel and starts a
   * history load that the next hop aborts, which the panel treats as a lost conversation.
   */
  async continueOn(page: Page, url: string) {
    await page.goto(url, {waitUntil: 'load'});
    await this.waitForFormRegistered(page);
    await page.locator('#mago-chat.is-open').waitFor();
  }

  async continueOnDashboard(page: Page) {
    const adminPath = process.env.ADMIN_PATH || 'admin';

    await page.goto('/' + adminPath + '/admin/dashboard', {waitUntil: 'load'});
    await page.locator('#mago-chat.is-open').waitFor();
  }

  /**
   * Opens an admin route that FormPolicy (task 003) denies, or one that never registers a UI
   * component form to begin with (the order view and admin user edit pages are both legacy,
   * pre-UI-component screens): waiting for `bridge.snapshot().hasForm` the way openOn() does would
   * hang forever on either, since that is exactly the outcome a denied or form-less page produces.
   */
  async openOnDeniedForm(page: Page, adminRoute: string) {
    const url = await this.resolveKeyedUrl(page, adminRoute);

    await page.goto(url, {waitUntil: 'load'});
    await this.open(page);
  }

  /**
   * Waits until form-bridge.js has loaded and the page's UI component form has registered itself,
   * so a spec calling `window.magoFormBridge.snapshot()` right after never races the form. Also
   * waits out the form's own loading mask, which can otherwise intercept the next click.
   */
  async waitForFormRegistered(page: Page) {
    await page.waitForFunction(() => {
      const bridge = (window as any).magoFormBridge;

      return !!bridge && !!bridge.snapshot().hasForm;
    });

    await page.locator('[data-role="spinner"].admin__form-loading-mask').waitFor({state: 'hidden'}).catch(() => {});
  }

  /**
   * The Skills & Permissions grid has no id-scoped edit route to borrow a key from, so this skips
   * the openOn() flow entirely (which would otherwise hang waiting for a UI component form that a
   * grid-only page never registers) and reads the grid's own data source response directly.
   */
  async openSkillsGrid(page: Page): Promise<any[]> {
    const url = await this.resolveKeyedUrl(page, 'mago/skills/index');
    const renderResponse = page.waitForResponse(
      (response) => response.url().includes('mui/index/render') && response.url().includes('mago_skills_listing')
    );

    await page.goto(url, {waitUntil: 'load'});

    const body = await (await renderResponse).json();

    return body.items ?? [];
  }

  /**
   * Exposes the same secret-key resolution openOn() uses internally, for a spec that needs a real,
   * navigable admin URL for an entity (task 009's navigate-then-act) without opening the panel
   * there itself. Leaves the browser wherever resolving the key happened to land it (typically the
   * entity's own listing grid), so a caller that also wants a known starting page should navigate
   * there itself afterwards.
   */
  async keyedUrlFor(page: Page, adminRoute: string): Promise<string> {
    return this.resolveKeyedUrl(page, adminRoute);
  }

  private async resolveKeyedUrl(page: Page, adminRoute: string): Promise<string> {
    const adminPath = process.env.ADMIN_PATH || 'admin';
    const segments = adminRoute.split('/').filter(Boolean);
    const routeKey = segments.slice(0, 2).join('/');
    const action = segments[2];
    const recipe = LISTING_RECIPES[routeKey];

    if (!recipe) {
      return '/' + adminPath + '/' + adminRoute;
    }

    await page.goto('/' + adminPath + '/admin/dashboard', {waitUntil: 'load'});

    const listingHref = await page.evaluate((hrefContains) => {
      const links = Array.from(document.querySelectorAll<HTMLAnchorElement>('a[href*="/key/"]'));
      const match = links.find((link) => link.href.includes(hrefContains));

      return match ? match.href : null;
    }, recipe.menuHrefContains);

    if (!listingHref) {
      throw new Error(
        'No keyed admin menu link found for "' + routeKey + '". The admin user may be missing a permission.'
      );
    }

    if (action === 'new' && recipe.addButtonSelector) {
      return this.resolveNewUrl(page, listingHref, recipe.addButtonSelector);
    }

    if (action === 'edit' && recipe.legacyEditHrefContains) {
      return this.resolveLegacyLinkUrl(page, listingHref, recipe.legacyEditHrefContains);
    }

    if ((action === 'edit' || action === 'view') && recipe.listingNamespace) {
      return this.resolveEditUrl(page, adminRoute, listingHref, recipe.listingNamespace, recipe.actionKey || 'edit');
    }

    return listingHref;
  }

  /**
   * Scrapes an edit link straight out of a legacy (pre-UI-component) grid's rendered markup, for a
   * listing recipe with no mui/index/render JSON response to read one from instead. A legacy grid
   * row is not an `<a href>` (the whole row is clickable through a JS listener instead); the URL
   * it navigates to on click is its own `title` attribute, so that is what this reads. The grid's
   * rows also arrive through their own ajax call after the page loads (`use_ajax` in its layout
   * XML), so the matching row is not necessarily in the DOM the instant navigation settles - this
   * polls for it rather than assuming it is already there.
   */
  private async resolveLegacyLinkUrl(page: Page, listingHref: string, hrefContains: string): Promise<string> {
    await page.goto(listingHref, {waitUntil: 'load'});

    const handle = await page.waitForFunction((needle) => {
      const rows = Array.from(document.querySelectorAll<HTMLTableRowElement>('tr[data-role="row"][title]'));
      const match = rows.find((row) => (row.getAttribute('title') || '').includes(needle));

      return match ? match.getAttribute('title') : null;
    }, hrefContains);

    const href = await handle.jsonValue() as string | null;

    if (!href) {
      throw new Error('No keyed row with a title containing "' + hrefContains + '" found on ' + listingHref);
    }

    return href;
  }

  /**
   * The "Add" button on a grid toolbar is a jQuery widget, not a knockout component: its click
   * handler is attached with `.on('click', ...)` well after the button itself is visible and
   * actionable, so a single click can silently land before the handler exists. Retrying the click
   * is more robust here than a fixed delay, since the actual delay varies with page load.
   */
  private async resolveNewUrl(page: Page, listingHref: string, addButtonSelector: string): Promise<string> {
    await page.goto(listingHref, {waitUntil: 'load'});

    for (let attempt = 0; attempt < 10; attempt++) {
      await page.locator(addButtonSelector).click();

      try {
        await page.waitForURL((url) => url.href !== listingHref && url.href.includes('/key/'), {timeout: 1000});

        return page.url();
      } catch {
        // Handler was not bound yet; try again.
      }
    }

    throw new Error('Clicking "' + addButtonSelector + '" never navigated away from ' + listingHref);
  }

  private async resolveEditUrl(
    page: Page,
    adminRoute: string,
    listingHref: string,
    listingNamespace: string,
    actionKey: string = 'edit'
  ): Promise<string> {
    const renderResponse = page.waitForResponse(
      (response) => response.url().includes('mui/index/render') && response.url().includes(listingNamespace)
    );

    await page.goto(listingHref, {waitUntil: 'load'});

    const body = await (await renderResponse).json();
    const sampleHref: string | undefined = body.items?.[0]?.actions?.[actionKey]?.href;

    if (!sampleHref) {
      throw new Error('The "' + listingNamespace + '" grid returned no rows to borrow a "' + actionKey + '" link from.');
    }

    const idMatch = adminRoute.match(/\/(?:id|page_id|block_id|category_id|order_id|user_id)\/(\d+)/);
    let targetUrl = idMatch ? sampleHref.replace(/\/(\d+)\/key\//, '/' + idMatch[1] + '/key/') : sampleHref;

    const storeMatch = adminRoute.match(/\/store\/(\d+)/);

    if (storeMatch) {
      targetUrl = targetUrl.replace('/key/', '/store/' + storeMatch[1] + '/key/');
    }

    return targetUrl;
  }

  /**
   * chat-panel.js is pulled in through require(['marked'], ...), so the toggle exists in
   * the markup well before its click handler is bound. Clicking in that window is a silent
   * no-op, which shows up as the panel never opening.
   */
  async open(page: Page, toggleBindTimeout: number = TOGGLE_BIND_TIMEOUT_MS) {
    await this.waitForToggleBound(page, toggleBindTimeout);

    await this.dismissSessionWarningIfPresent(page);
    await this.adminModals.guard(page, () => page.locator('#mago-toggle').click({timeout: INTERACTION_TIMEOUT_MS}));
    await page.locator('#mago-chat.is-open').waitFor();
  }

  /**
   * A long-running suite can outlast the admin session's own idle-timeout warning, which is a
   * blocking modal unrelated to the assistant. It only exists when it happens to be open, so this
   * is a no-op on every normal page.
   */
  private async dismissSessionWarningIfPresent(page: Page) {
    const extendButton = page.locator('.modal-admintimeout .action-primary');

    if (await extendButton.isVisible().catch(() => false)) {
      await extendButton.click();
      await page.locator('.modal-admintimeout').waitFor({state: 'hidden'}).catch(() => {});
    }
  }

  async close(page: Page) {
    await this.adminModals.guard(page, () => page.locator('#mago-close').click({timeout: INTERACTION_TIMEOUT_MS}));
  }

  async ask(page: Page, question: string) {
    await this.input(page).fill(question);
    await this.adminModals.guard(page, () => this.sendButton(page).click({timeout: INTERACTION_TIMEOUT_MS}));
  }

  /**
   * Sends a question and waits for the whole SSE stream to finish, not merely for the first
   * assistant text to show up: a spec that navigates away or sends the next turn while the stream
   * is still open races the panel's own bookkeeping (conversation id, history) and loses.
   */
  async askAndAwaitReply(page: Page, question: string) {
    const streamResponse = page.waitForResponse((response) => response.url().includes('/mago/chat/stream'));

    await this.ask(page, question);

    const response = await streamResponse;
    await response.finished();
  }

  panel(page: Page): Locator {
    return page.locator('#mago-chat');
  }

  welcome(page: Page): Locator {
    return page.locator('#mago-welcome');
  }

  input(page: Page): Locator {
    return page.locator('#mago-input');
  }

  sendButton(page: Page): Locator {
    return page.locator('#mago-send');
  }

  userMessages(page: Page): Locator {
    return page.locator('#mago-messages .mago-message.is-user');
  }

  assistantMessages(page: Page): Locator {
    return page.locator('#mago-messages .mago-message.is-assistant:not(#mago-loading):not(.mago-navigate-status)');
  }

  lastAssistantMessage(page: Page): Locator {
    return this.assistantMessages(page).last();
  }

  toolTags(page: Page): Locator {
    return page.locator('#mago-messages .mago-tool-tag');
  }

  /**
   * The panel's own "Opening product #12..." indicator, shown from the moment a form_navigate
   * directive sends the browser elsewhere until the target page has staged the approved fields
   * (or given up waiting for its form).
   */
  navigateStatus(page: Page): Locator {
    return page.locator('#mago-messages .mago-navigate-status');
  }

  confirmActions(page: Page): Locator {
    return page.locator('#mago-messages .mago-confirm-actions');
  }

  confirmButton(page: Page): Locator {
    return page.locator('#mago-messages .mago-btn--confirm');
  }

  rejectButton(page: Page): Locator {
    return page.locator('#mago-messages .mago-btn--reject');
  }

  slashMenu(page: Page): Locator {
    return page.locator('#mago-slash-menu');
  }

  slashItems(page: Page): Locator {
    return page.locator('#mago-slash-menu .mago-slash-item');
  }

  private async waitForToggleBound(page: Page, timeout: number): Promise<void> {
    try {
      await page.waitForFunction(() => {
        const toggle: HTMLElement | null = document.querySelector('#mago-toggle');

        return toggle !== null && toggle.onclick !== null;
      }, undefined, {timeout});
    } catch (error) {
      if (!(error instanceof errors.TimeoutError)) {
        throw error;
      }

      throw new Error(
        'The chat panel toggle (#mago-toggle) never bound its click handler within ' + timeout + 'ms.'
      );
    }
  }
}

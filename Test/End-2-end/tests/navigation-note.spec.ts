/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import MagentoApi from 'Services/MagentoApi';
import WireMock from 'Services/WireMock';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();
const magentoApi = new MagentoApi();
const wireMock = new WireMock();

const TRIGGER = 'E2E Navigation Note Check';
const ACKNOWLEDGEMENT = 'Page context check acknowledged';
const CONTEXT_UPDATE_PREFIX = '[Context update:';

function userMessages(requestBody: any): string[] {
  return requestBody.messages
    .filter((message: any) => message.role === 'user')
    .map((message: any) => message.content);
}

/**
 * A conversation outlives the page it started on: the administrator asks about one entity, saves,
 * opens the next and keeps chatting. Every earlier turn is replayed to the provider, so without an
 * explicit marker the model has to work out from a single system prompt line that the form it
 * read and wrote two turns ago is not the one open now. These specs run the real Stream controller
 * against WireMock and assert on what actually reaches the provider.
 */
test.describe('Navigation note in the provider conversation', () => {
  test.describe.configure({mode: 'serial'});

  let firstSku: string;
  let firstProductId: number;
  let secondSku: string;
  let secondProductId: number;

  test.beforeAll(async ({request}) => {
    const first = await magentoApi.createProduct(request);
    const second = await magentoApi.createProduct(request);

    firstSku = first.sku;
    firstProductId = first.id;
    secondSku = second.sku;
    secondProductId = second.id;
  });

  test.afterAll(async ({request}) => {
    await magentoApi.deleteProduct(request, firstSku);
    await magentoApi.deleteProduct(request, secondSku);
  });

  test('it tells the provider when a later turn comes from a different admin page', async ({page, request}) => {
    test.setTimeout(90000);

    await wireMock.ensureReachable(request);
    const secondProductUrl = await chatPanel.keyedUrlFor(page, 'catalog/product/edit/id/' + secondProductId);
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + firstProductId);
    await chatPanel.askAndAwaitReply(page, TRIGGER + ': turn one');
    await expect(chatPanel.lastAssistantMessage(page)).toContainText(ACKNOWLEDGEMENT);
    await chatPanel.askAndAwaitReply(page, TRIGGER + ': turn two');

    await chatPanel.continueOn(page, secondProductUrl);
    await expect(chatPanel.userMessages(page)).toHaveCount(2, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.askAndAwaitReply(page, TRIGGER + ': turn three');

    const requestBody = await wireMock.findRequestBody(request, TRIGGER + ': turn three');
    const [turnOne, turnTwo, turnThree] = userMessages(requestBody);

    expect(turnOne).toBe(TRIGGER + ': turn one');
    expect(turnTwo).toBe(TRIGGER + ': turn two');
    expect(turnThree).toContain(CONTEXT_UPDATE_PREFIX);
    expect(turnThree).toContain('navigated from product #' + firstProductId);
    expect(turnThree).toContain('to product #' + secondProductId);
    expect(turnThree).toContain('catalog/product/edit');
    expect(turnThree).toContain('page_form');
    expect(turnThree).toMatch(new RegExp(TRIGGER + ': turn three$'));
  });

  test('it tells the provider when the administrator leaves the form for a page without one', async ({page, request}) => {
    test.setTimeout(90000);

    await wireMock.ensureReachable(request);
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + firstProductId);
    await chatPanel.askAndAwaitReply(page, TRIGGER + ': before leaving');
    await expect(chatPanel.lastAssistantMessage(page)).toContainText(ACKNOWLEDGEMENT);

    await chatPanel.continueOnDashboard(page);
    await expect(chatPanel.userMessages(page)).toHaveCount(1, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.askAndAwaitReply(page, TRIGGER + ': after leaving');

    const requestBody = await wireMock.findRequestBody(request, TRIGGER + ': after leaving');
    const [beforeLeaving, afterLeaving] = userMessages(requestBody);

    expect(beforeLeaving).toBe(TRIGGER + ': before leaving');
    expect(afterLeaving).toContain(CONTEXT_UPDATE_PREFIX);
    expect(afterLeaving).toContain('has left product #' + firstProductId);
    expect(afterLeaving).toContain('no form open');
  });

  test('it tells the provider when a new product has since been saved and has an id', async ({page, request}) => {
    test.setTimeout(90000);

    await wireMock.ensureReachable(request);
    /* A real Save is a form submit, validation and a redirect to the edit page; landing on an
       existing product's edit page is the same transition as far as the page context goes, and
       far cheaper. */
    const savedProductUrl = await chatPanel.keyedUrlFor(page, 'catalog/product/edit/id/' + firstProductId);
    await chatPanel.openOn(page, 'catalog/product/new/set/4/type/simple');
    await chatPanel.askAndAwaitReply(page, TRIGGER + ': before saving');
    await expect(chatPanel.lastAssistantMessage(page)).toContainText(ACKNOWLEDGEMENT);

    await chatPanel.continueOn(page, savedProductUrl);
    await expect(chatPanel.userMessages(page)).toHaveCount(1, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.askAndAwaitReply(page, TRIGGER + ': after saving');

    const requestBody = await wireMock.findRequestBody(request, TRIGGER + ': after saving');
    const [, afterSaving] = userMessages(requestBody);

    expect(afterSaving).toContain(CONTEXT_UPDATE_PREFIX);
    expect(afterSaving).toContain('from a new product');
    expect(afterSaving).toContain('to product #' + firstProductId);
  });

  test('it adds no note when the same product comes back under a different admin path', async ({page, request}) => {
    test.setTimeout(90000);

    await wireMock.ensureReachable(request);
    /* Save & Continue Edit reloads the same product under a path with extra segments and a new
       secret key; the location is compared by entity, not by pathname. */
    const sameProductOtherPath = (await chatPanel.keyedUrlFor(page, 'catalog/product/edit/id/' + firstProductId)).replace('/key/', '/back/edit/key/');
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + firstProductId);
    await chatPanel.askAndAwaitReply(page, TRIGGER + ': same page one');
    await expect(chatPanel.lastAssistantMessage(page)).toContainText(ACKNOWLEDGEMENT);

    await chatPanel.continueOn(page, sameProductOtherPath);
    await expect(chatPanel.userMessages(page)).toHaveCount(1, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.askAndAwaitReply(page, TRIGGER + ': same page two');

    const requestBody = await wireMock.findRequestBody(request, TRIGGER + ': same page two');
    const [, samePageTwo] = userMessages(requestBody);

    expect(samePageTwo).toBe(TRIGGER + ': same page two');
  });

  test('it keeps the stored message itself free of the note', async ({page, request}) => {
    test.setTimeout(90000);

    await wireMock.ensureReachable(request);
    const secondProductUrl = await chatPanel.keyedUrlFor(page, 'catalog/product/edit/id/' + secondProductId);
    await chatPanel.openOn(page, 'catalog/product/edit/id/' + firstProductId);
    await chatPanel.askAndAwaitReply(page, TRIGGER + ': stored one');
    await expect(chatPanel.lastAssistantMessage(page)).toContainText(ACKNOWLEDGEMENT);

    await chatPanel.continueOn(page, secondProductUrl);
    await expect(chatPanel.userMessages(page)).toHaveCount(1, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.askAndAwaitReply(page, TRIGGER + ': stored two');

    await chatPanel.continueOn(page, secondProductUrl);

    await expect(chatPanel.userMessages(page)).toHaveCount(2, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.userMessages(page).last().locator('.mago-message-content')).toHaveText(TRIGGER + ': stored two');
  });
});

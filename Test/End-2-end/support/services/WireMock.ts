/*
 * Copyright © Mago Assistant
 */

import type {APIRequestContext} from '@playwright/test';

const WIREMOCK_BASE_URL = 'http://wiremock:8080';
const WIREMOCK_ADMIN_REQUESTS_URL = WIREMOCK_BASE_URL + '/__admin/requests';
const WIREMOCK_ADMIN_MAPPINGS_URL = WIREMOCK_BASE_URL + '/__admin/mappings';
const COMPLETIONS_PATH = '/v1/chat/completions';
const POLL_ATTEMPTS = 30;
const POLL_INTERVAL_MS = 500;

/**
 * Specs that exercise the real ChatService/Stream controller need WireMock in place of the
 * provider. The suite's mageos_ai/services/configuration row must point at it for that to work;
 * failing with this message beats a generic Playwright timeout when a fresh install has not set
 * that row yet.
 */
export default class WireMock {
  async ensureReachable(request: APIRequestContext) {
    const response = await request.get(WIREMOCK_ADMIN_MAPPINGS_URL).catch(() => null);

    if (!response || !response.ok()) {
      throw new Error(
        'WireMock is not reachable at ' + WIREMOCK_BASE_URL + '. These specs need the mageos_ai/services/configuration '
        + 'service row pointed at WireMock (Stores > Configuration > Mage-OS > AI Configuration).'
      );
    }
  }

  /**
   * The most recent provider request whose body contains triggerPhrase (and satisfies the
   * optional predicate), decoded. WireMock's journal is newest-first, so the first match is the
   * latest, which matters when an earlier test used an overlapping trigger phrase.
   */
  async findRequestBody(
    request: APIRequestContext,
    triggerPhrase: string,
    predicate?: (decodedBody: string) => boolean
  ): Promise<any> {
    for (let attempt = 0; attempt < POLL_ATTEMPTS; attempt++) {
      const response = await request.get(WIREMOCK_ADMIN_REQUESTS_URL);
      const payload = await response.json();
      const matches = (payload.requests ?? []).filter((entry: any) => {
        if (entry.request.url !== COMPLETIONS_PATH) {
          return false;
        }
        const decoded = Buffer.from(entry.request.bodyAsBase64, 'base64').toString('utf8');
        return decoded.includes(triggerPhrase) && (!predicate || predicate(decoded));
      });

      if (matches.length > 0) {
        const decoded = Buffer.from(matches[0].request.bodyAsBase64, 'base64').toString('utf8');
        return JSON.parse(decoded);
      }

      await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS));
    }

    throw new Error(
      'No request containing "' + triggerPhrase + '" reached WireMock within the timeout. Check that '
      + 'mageos_ai/services/configuration points at ' + WIREMOCK_BASE_URL + '.'
    );
  }
}

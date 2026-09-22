/*
 * Copyright © Mago Assistant
 */

import {type ChatScenario, textDeltas} from 'Actions/backend/ChatMock';

const CONVERSATION_ID = 4242;
const MESSAGE_ID = 100501;

export const createCmsPage: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('Sure, I will create that CMS page for you. '),
        {
            event: 'tool_call',
            data: {
                id: 'toolu_cms_1',
                name: 'cms_data',
                input: {
                    action: 'create_page',
                    identifier: 'summer-sale',
                    title: 'Summer Sale',
                    content: '<p>Everything must go.</p>',
                    is_active: true,
                },
            },
        },
        {
            event: 'confirm',
            data: {
                tools: [
                    {
                        name: 'cms_data',
                        description: 'Manage CMS pages and blocks',
                        input: { action: 'create_page', identifier: 'summer-sale', title: 'Summer Sale' },
                    },
                ],
            },
        },
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true },
        },
    ],
    status: { message_id: MESSAGE_ID },
    confirm: [
        {
            event: 'tool_call',
            data: { id: 'toolu_cms_1', name: 'cms_data', input: { action: 'create_page' } },
        },
        ...textDeltas('Done. The page "Summer Sale" is live at /summer-sale.'),
        { event: 'done', data: { conversation_id: CONVERSATION_ID } },
    ],
}

export const createCoupon: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('I can set up a cart price rule with that coupon code. '),
        {
            event: 'tool_call',
            data: {
                id: 'toolu_coupon_1',
                name: 'coupon_manager',
                input: {
                    action: 'create_rule',
                    name: 'Summer 20',
                    discount_type: 'percent',
                    discount_amount: 20,
                    coupon_code: 'SUMMER20',
                },
            },
        },
        {
            event: 'confirm',
            data: {
                tools: [
                    {
                        name: 'coupon_manager',
                        description: 'Manage cart price rules and coupon codes',
                        input: {
                            action: 'create_rule',
                            name: 'Summer 20',
                            discount_type: 'percent',
                            discount_amount: 20,
                            coupon_code: 'SUMMER20',
                        },
                    },
                ],
            },
        },
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true },
        },
    ],
    status: { message_id: MESSAGE_ID },
    confirm: [
        ...textDeltas('Created cart price rule "Summer 20" with coupon code SUMMER20.'),
        { event: 'done', data: { conversation_id: CONVERSATION_ID } },
    ],
    reject: { success: true },
}

export const declineProductCreation: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas(
            'I cannot create products. My catalog tools are read-only, so you will have to add the '
            + 'Anti-musquito candle through Catalog > Products yourself.'
        ),
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: false },
        },
    ],
}

export const lookupProduct: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        {
            event: 'tool_call',
            data: { id: 'toolu_product_1', name: 'product_data', input: { action: 'search', query: 'candle' } },
        },
        ...textDeltas('I found 2 products matching "candle".'),
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: false },
        },
    ],
}

export const streamFailure: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        { event: 'error', data: { error: 'API error (HTTP 429) rate limit exceeded' } },
        { event: 'done', data: { conversation_id: CONVERSATION_ID } },
    ],
}

// Matches the form_apply directive contract from Task 005: the whole SSE `data` payload is the
// directive itself, not wrapped under another key.
export const formWriteDirective: Record<string, unknown> = {
    type: 'form_write',
    target: {
        namespace: 'product_form',
        entity_type: 'product',
        entity_id: '42',
        store_id: '',
    },
    changes: [
        {
            path: 'data.product.name',
            label: 'Product Name',
            previous_value: 'Chair',
            value: 'Sedia',
            clear_use_default: true,
        },
    ],
}

export const formApplyOnRead: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('Updating the product name. '),
        { event: 'form_apply', data: formWriteDirective },
        ...textDeltas('Done.'),
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: false },
        },
    ],
}

export const formApplyOnConfirm: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('I will update the product name for you. '),
        {
            event: 'tool_call',
            data: {
                id: 'toolu_form_write_1',
                name: 'write_fields',
                input: { action: 'apply', changes: [{ path: 'data.product.name', value: 'Sedia' }] },
            },
        },
        {
            event: 'confirm',
            data: {
                tools: [
                    {
                        name: 'write_fields',
                        description: 'Write staged field changes to the open form',
                        input: { action: 'apply' },
                    },
                ],
            },
        },
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true },
        },
    ],
    status: { message_id: MESSAGE_ID },
    confirm: [
        { event: 'form_apply', data: formWriteDirective },
        ...textDeltas('Updated the product name.'),
        { event: 'done', data: { conversation_id: CONVERSATION_ID } },
    ],
}

// A page_form.write_fields proposal: what a real turn looks like once task 006's action exists.
// Distinct from formApplyOnConfirm above, which predates the real tool and stands in only for
// routing the form_apply event, not for the shape of a genuine write_fields tool call.
export const stageFieldChange: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('I will update the product name for you. '),
        {
            event: 'tool_call',
            data: {
                id: 'toolu_write_fields_1',
                name: 'page_form',
                input: {
                    action: 'write_fields',
                    form_namespace: 'product_form',
                    entity_id: '42',
                    store_id: '',
                    changes: [{ path: 'data.product.name', value: 'Sedia' }],
                },
            },
        },
        {
            event: 'confirm',
            data: {
                tools: [
                    {
                        name: 'page_form',
                        description: 'Read and write the admin form currently open in the browser',
                        input: {
                            action: 'write_fields',
                            form_namespace: 'product_form',
                            entity_id: '42',
                            store_id: '',
                            changes: [{ path: 'data.product.name', value: 'Sedia' }],
                        },
                    },
                ],
            },
        },
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true },
        },
    ],
    status: { message_id: MESSAGE_ID },
    confirm: [
        { event: 'form_apply', data: formWriteDirective },
        ...textDeltas('Updated the product name.'),
        { event: 'done', data: { conversation_id: CONVERSATION_ID } },
    ],
    reject: { success: true },
}

export const formApplyUnknownType: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('Updating the product name. '),
        { event: 'form_apply', data: { ...formWriteDirective, type: 'something_else' } },
        ...textDeltas('Done.'),
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: false },
        },
    ],
}

export const formApplyNotAnObject: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('Updating the product name. '),
        { event: 'form_apply', data: ['not', 'an', 'object'] as unknown as Record<string, unknown> },
        ...textDeltas('Done.'),
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: false },
        },
    ],
}

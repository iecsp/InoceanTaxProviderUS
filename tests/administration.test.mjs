import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
const require = createRequire(path.join(root, 'vendor/shopware/administration/Resources/app/administration/package.json'));
const { compile } = require('@vue/compiler-dom');
let definition;
let requests = [];
globalThis.Shopware = {
    Component: { register(name, value) { assert.equal(name, 'ino-usa-tax-sync'); definition = value; } },
    Application: { getContainer() { return { httpClient: {
        get: async (...args) => { requests.push(['GET', ...args]); return { data: { sources: [{ state: 'CA', status: 'completed' }] } }; },
        post: async (...args) => { requests.push(['POST', ...args]); return { data: { jobId: 'test' } }; },
    } }; } },
};
await import('../src/Resources/app/administration/src/component/ino-usa-tax-sync/index.js');
function component() {
    const ctx = { ...definition.data(), loginService: { getToken: () => 'fake-test-token' } };
    for (const [name, fn] of Object.entries(definition.methods)) ctx[name] = fn.bind(ctx);
    Object.defineProperty(ctx, 'busy', { get: () => definition.computed.busy.call(ctx) });
    return ctx;
}
test('Vue compiles configuration template including state selection', () => {
    const errors = [];
    compile(definition.template, { onError: e => errors.push(e) });
    assert.deepEqual(errors, []);
});
test('status uses the real loginService token API', async () => {
    requests = [];
    const ctx = component(); await ctx.refresh();
    assert.equal(requests[0][0], 'GET');
    assert.equal(requests[0][2].headers.Authorization, 'Bearer fake-test-token');
    assert.equal(ctx.sources[0].state, 'CA'); assert.equal(ctx.loading, false);
});
test('sync uses authenticated POST and the selected source then refreshes', async () => {
    requests = []; const ctx = component(); ctx.state = 'WA'; await ctx.synchronize();
    assert.equal(requests[0][0], 'POST'); assert.match(requests[0][1], /sync\?state=WA$/);
    assert.deepEqual(requests[0][2], {});
    assert.equal(requests[0][3].headers.Authorization, 'Bearer fake-test-token');
    assert.equal(requests[1][0], 'GET');
});
test('component teardown stops status polling', () => {
    const ctx = component(); ctx.timer = setTimeout(() => assert.fail('must not fire'), 1000);
    definition.beforeUnmount.call(ctx); assert.equal(ctx.disposed, true);
});

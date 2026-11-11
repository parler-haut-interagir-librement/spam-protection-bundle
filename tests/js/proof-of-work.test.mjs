// The browser side of the proof of work, under Node's test runner, Stimulus resolved to a stub by `register.mjs`:
// `node --import ./tests/js/register.mjs --test tests/js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';

import { leadingZeroBits, sha256, solve } from '../../assets/src/proof_of_work.js';
import ProofOfWorkController from '../../assets/src/proof_of_work_controller.js';

const zeroBits = (hex) => {
    const binary = [...Buffer.from(hex, 'hex')].map((byte) => byte.toString(2).padStart(8, '0')).join('');

    return binary.length - binary.replace(/^0+/, '').length;
};

test('the digest is SHA-256, on one block and several', () => {
    for (let length = 0; length <= 200; length++) {
        const bytes = Uint8Array.from({ length }, (_, index) => (index * 131 + length) & 0xff);

        assert.equal(Buffer.from(sha256(bytes)).toString('hex'), createHash('sha256').update(bytes).digest('hex'), `length ${length}`);
    }

    assert.equal(
        Buffer.from(sha256(new TextEncoder().encode('abc'))).toString('hex'),
        'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
    );
});

test('a long search yields to the browser between slices', async () => {
    let ticked = false;
    setTimeout(() => {
        ticked = true;
    }, 0);

    const started = performance.now();
    await solve('0123456789abcdef0123456789abcdef', 16);

    assert.ok(performance.now() - started > 16, 'the search took longer than one frame');
    assert.ok(ticked, 'a timer ran while it searched');
});

test('leading zero bits are counted bit by bit', () => {
    assert.equal(leadingZeroBits(new Uint8Array([0, 0, 1])), 23);
    assert.equal(leadingZeroBits(new Uint8Array([0x80])), 0);
    assert.equal(leadingZeroBits(new Uint8Array([0x0f])), 4);
    assert.equal(leadingZeroBits(new Uint8Array([0, 0])), 16);
});

test('the solution is the smallest number the server accepts', async () => {
    for (const difficulty of [1, 6, 10]) {
        const solution = await solve('0123456789abcdef0123456789abcdef', difficulty);
        const digest = createHash('sha256').update(`0123456789abcdef0123456789abcdef:${solution}`).digest('hex');

        assert.ok(zeroBits(digest) >= difficulty);

        for (let smaller = 0; smaller < Number(solution); smaller++) {
            const earlier = createHash('sha256').update(`0123456789abcdef0123456789abcdef:${smaller}`).digest('hex');
            assert.ok(zeroBits(earlier) < difficulty);
        }
    }
});

test('no proof is a zero', async () => {
    assert.equal(await solve('anything', 0), '0');
});

test('the controller writes the solution into its field when it connects', async () => {
    assert.deepEqual(ProofOfWorkController.values, { challenge: String, difficulty: Number });

    const controller = new ProofOfWorkController();
    controller.element = { value: '' };
    controller.challengeValue = 'a';
    controller.difficultyValue = 4;
    await controller.connect();

    assert.equal(controller.element.value, await solve('a', 4));
});

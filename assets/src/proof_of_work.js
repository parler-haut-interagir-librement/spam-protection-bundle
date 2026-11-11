/*
 * The computation of phil/spam-protection-bundle's proof of work: the smallest number whose SHA-256, appended to the
 * challenge as `challenge:number`, starts with `difficulty` zero bits. The Stimulus controller runs it; it sends
 * nothing anywhere.
 *
 * The hash is computed here, synchronously, rather than with one awaited Web Crypto call per candidate: a promise
 * round trip per hash costs more than the hash itself, and the search tries thousands to millions of candidates.
 * The search yields to the browser after about a frame of work, so the page stays responsive while it works.
 */

const encoder = new TextEncoder();

/** The candidates tried between two looks at the clock. */
const SLICE = 1024;

/** The milliseconds of work between two yields to the browser: about one frame. */
const BUDGET = 16;

/** The first 32 bits of the fractional parts of the cube roots of the first 64 primes (FIPS 180-4, 4.2.2). */
const ROUND_CONSTANTS = new Uint32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5, 0xd807aa98,
    0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786,
    0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da, 0x983e5152, 0xa831c66d, 0xb00327c8,
    0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13,
    0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819,
    0xd6990624, 0xf40e3585, 0x106aa070, 0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a,
    0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7,
    0xc67178f2,
]);

/** The first 32 bits of the fractional parts of the square roots of the first 8 primes (FIPS 180-4, 5.3.3). */
const INITIAL_STATE = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];

const schedule = new Uint32Array(64);

const rotateRight = (word, bits) => (word >>> bits) | (word << (32 - bits));

/** The SHA-256 digest of the bytes, as 32 bytes. */
export function sha256(bytes) {
    // The message, a 1 bit, zeros, and its length in bits on 64 bits: a whole number of 64-byte blocks.
    const blocks = Math.ceil((bytes.length + 9) / 64);
    const padded = new Uint8Array(blocks * 64);
    padded.set(bytes);
    padded[bytes.length] = 0x80;
    const view = new DataView(padded.buffer);
    const bits = bytes.length * 8;
    view.setUint32(padded.length - 8, Math.floor(bits / 0x100000000));
    view.setUint32(padded.length - 4, bits >>> 0);

    const state = [...INITIAL_STATE];

    for (let offset = 0; offset < padded.length; offset += 64) {
        for (let t = 0; t < 16; t++) {
            schedule[t] = view.getUint32(offset + t * 4);
        }

        for (let t = 16; t < 64; t++) {
            const w15 = schedule[t - 15];
            const w2 = schedule[t - 2];
            const sigma0 = rotateRight(w15, 7) ^ rotateRight(w15, 18) ^ (w15 >>> 3);
            const sigma1 = rotateRight(w2, 17) ^ rotateRight(w2, 19) ^ (w2 >>> 10);
            schedule[t] = (schedule[t - 16] + sigma0 + schedule[t - 7] + sigma1) >>> 0;
        }

        let [a, b, c, d, e, f, g, h] = state;

        for (let t = 0; t < 64; t++) {
            const sum1 = rotateRight(e, 6) ^ rotateRight(e, 11) ^ rotateRight(e, 25);
            const choice = (e & f) ^ (~e & g);
            const first = (h + sum1 + choice + ROUND_CONSTANTS[t] + schedule[t]) >>> 0;
            const sum0 = rotateRight(a, 2) ^ rotateRight(a, 13) ^ rotateRight(a, 22);
            const majority = (a & b) ^ (a & c) ^ (b & c);
            const second = (sum0 + majority) >>> 0;

            h = g;
            g = f;
            f = e;
            e = (d + first) >>> 0;
            d = c;
            c = b;
            b = a;
            a = (first + second) >>> 0;
        }

        state[0] = (state[0] + a) >>> 0;
        state[1] = (state[1] + b) >>> 0;
        state[2] = (state[2] + c) >>> 0;
        state[3] = (state[3] + d) >>> 0;
        state[4] = (state[4] + e) >>> 0;
        state[5] = (state[5] + f) >>> 0;
        state[6] = (state[6] + g) >>> 0;
        state[7] = (state[7] + h) >>> 0;
    }

    const digest = new Uint8Array(32);
    const out = new DataView(digest.buffer);
    state.forEach((word, index) => {
        out.setUint32(index * 4, word);
    });

    return digest;
}

/** The number of leading zero bits of a digest. */
export function leadingZeroBits(bytes) {
    let bits = 0;

    for (const byte of bytes) {
        if (byte !== 0) {
            return bits + Math.clz32(byte) - 24;
        }

        bits += 8;
    }

    return bits;
}

const yieldToBrowser = () => new Promise((resolve) => setTimeout(resolve, 0));

/** The smallest solution of the challenge at this difficulty, as a decimal string. */
export async function solve(challenge, difficulty) {
    if (difficulty <= 0) {
        return '0';
    }

    let since = performance.now();

    for (let candidate = 0; ; candidate++) {
        if (leadingZeroBits(sha256(encoder.encode(`${challenge}:${candidate}`))) >= difficulty) {
            return String(candidate);
        }

        if (candidate % SLICE === SLICE - 1 && performance.now() - since >= BUDGET) {
            await yieldToBrowser();
            since = performance.now();
        }
    }
}

// `node --import ./tests/js/register.mjs --test tests/js`: synchronous resolution hooks, run on the loading thread.
import { registerHooks } from 'node:module';
import { resolve } from './hooks.mjs';

registerHooks({ resolve });

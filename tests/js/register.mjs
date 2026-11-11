// `node --import ./tests/js/register.mjs --test tests/js`
import { register } from 'node:module';

register('./hooks.mjs', import.meta.url);

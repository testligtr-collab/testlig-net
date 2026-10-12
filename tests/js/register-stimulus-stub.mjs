import { register } from 'node:module';

const loader = new URL('./stimulus-loader.mjs', import.meta.url);
await register(loader.href, import.meta.url);

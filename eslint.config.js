// This configuration is correct and ready but cannot currently run. Both typescript-eslint@8.70.0
// and @typescript-eslint/parser refuse to load under TypeScript 7.0 (peer range: >=4.8.4 <6.1.0).
// Upstream tracking: https://github.com/typescript-eslint/typescript-eslint/issues/10940
// Until support lands, npm run lint is not part of the verification gate.
// Verification gate for M1: npm run format && npm run types && npm run build
// Static type checking: tsc --noEmit under strict, noUncheckedIndexedAccess, and verbatimModuleSyntax

import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    {
        ignores: [
            'resources/js/actions/**',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
            'resources/js/components/ui/**',
            'bootstrap/ssr/**',
            'public/build/**',
        ],
    },
    js.configs.recommended,
    tseslint.configs.recommended,
    reactHooks.configs['recommended-latest'],
    {
        languageOptions: {
            globals: {
                ...globals.browser,
                ...globals.node,
            },
        },
    },
);

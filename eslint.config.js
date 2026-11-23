import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import typescript from 'typescript-eslint';

const EMOJI_PATTERN = /\p{Extended_Pictographic}/u;

const noEmojiPlugin = {
    rules: {
        'no-emoji': {
            meta: {
                type: 'problem',
                docs: {
                    description: 'Disallow Unicode emojis; use lucide-react icons instead.',
                },
                schema: [],
            },
            create(context) {
                const checkText = (node, text) => {
                    if (typeof text === 'string' && EMOJI_PATTERN.test(text)) {
                        context.report({
                            node,
                            message: 'Unicode emojis are disallowed. Use icons from lucide-react instead.',
                        });
                    }
                };

                return {
                    Literal(node) {
                        if (typeof node.value === 'string') {
                            checkText(node, node.value);
                        }
                    },
                    TemplateElement(node) {
                        checkText(node, node.value?.raw);
                    },
                    JSXText(node) {
                        checkText(node, node.value);
                    },
                };
            },
        },
    },
};

/** @type {import('eslint').Linter.Config[]} */
export default [
    js.configs.recommended,
    ...typescript.configs.recommended,
    {
        ...react.configs.flat.recommended,
        ...react.configs.flat['jsx-runtime'], // Required for React 17+
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
            'react/no-unescaped-entities': 'off',
        },
        settings: {
            react: {
                version: 'detect',
            },
        },
    },
    {
        plugins: {
            'react-hooks': reactHooks,
            'design-system': noEmojiPlugin,
        },
        rules: {
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
            'design-system/no-emoji': 'error',
        },
    },
    {
        ignores: ['vendor', 'node_modules', 'public', 'bootstrap/ssr', 'tailwind.config.js'],
    },
    prettier, // Turn off all rules that might conflict with Prettier
];

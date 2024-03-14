import plugin from 'tailwindcss/plugin.js';

/** @type {import('tailwindcss').Config} config */

const config = {
  content: ['./index.php', './app/**/*.php', './resources/**/*.{php,vue,js}'],
  theme: {
    extend: {
      colors: {
        primary: '#7075F4',
        primaryhover: '#5E62CD',
        light01: '#EDEEFC',
        light02: '#DDDEF0',
        actionL1: '#C5C7FF',
        actionL2: '#A5A7D6',
        accentone: '#3AE6E6',
        accenttwo: '#8206FF',
        accentthree: '#28EC9A',
        alertinfodark: '#00BFE9',
      },
      fontFamily: {
        "inter": ['Inter', 'sans-serif']
      },
      fontWeight: {
        normal: '400',
        medium: '500',
        semibold: '600',
      },
      borderRadius: {
        'none': '0',
        'sm': '0.125rem',
        DEFAULT: '0.25rem',
        DEFAULT: '4px',
        'md': '12px',
        'lg': '24px',
        'full': '9999px',
        'large': '12px',
      }
    },
  },
  plugins: [
    plugin(function({ addBase }) {
     addBase({
        'html': { fontSize: "16px" },
      })
    }),
  ],
};

export default config;

/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
  ],
  theme: {
    extend: {
      colors: {
        'zatara-blue': '#2B3280',
        'zatara-gold': '#F4A93F',
        'zatara-red': '#A13D44',
      },
      fontFamily: {
        'arabic': ['Tajawal', 'Cairo', 'sans-serif'],
        'english': ['Plus Jakarta Sans', 'Outfit', 'sans-serif'],
      },
      animation: {
        'slowPan': 'slowPan 30s infinite alternate ease-in-out',
      },
      keyframes: {
        slowPan: {
          '0%': { transform: 'scale(1.05) translate(0, 0)' },
          '100%': { transform: 'scale(1.1) translate(-2%, 2%)' },
        }
      }
    },
  },
  plugins: [],
}

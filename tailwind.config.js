/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './*.php',
    './api/**/*.php',
    './components/**/*.php',
    './includes/**/*.php',
    './process/**/*.php',
    './tools/**/*.php',
    './assets/js/**/*.js',
  ],
  theme: {
    extend: {
      // Shake for the failed-login banner (login.php). The markup has always
      // asked for `animate-shake`, but no stylesheet ever defined it.
      keyframes: {
        shake: {
          '0%, 100%': { transform: 'translateX(0)' },
          '20%, 60%': { transform: 'translateX(-6px)' },
          '40%, 80%': { transform: 'translateX(6px)' },
        },
      },
      animation: {
        shake: 'shake 0.4s cubic-bezier(0.36, 0.07, 0.19, 0.97) both',
      },
    },
  },
  // Safelist: classes Tailwind's regex scanner could miss, or that live only in
  // PHP/JS string literals. Most are actually scannable as contiguous literals;
  // this is cheap insurance, not a substitute for the content globs above.
  safelist: [
    // includes/helpers.php display_flash_message()
    'border-l-4',
    'bg-green-100','border-green-400','text-green-700',
    'bg-red-100','border-red-400','text-red-700',
    'bg-yellow-100','border-yellow-400','text-yellow-700',
    'bg-blue-100','border-blue-400','text-blue-700',
    'bg-gray-100','border-gray-400','text-gray-700',
    // toast colour maps (assets/js/progress.js, admin_laporan_event.php)
    'bg-green-500','bg-red-500','bg-yellow-500','bg-blue-500','bg-emerald-500',
    // dashboard_admin.php activity feed icon colours
    'bg-slate-100','text-slate-600','bg-blue-100','text-blue-600',
    'bg-red-100','text-red-600','bg-amber-100','text-amber-600',
    'bg-emerald-100','text-emerald-600',
    // colorMap message boxes (input_form / matpro_input_form / user_events / user_matpro_activities)
    'bg-blue-50/90','bg-red-50/90','bg-emerald-50/90',
    'text-blue-800','text-red-800','text-emerald-800',
    'bg-blue-600','bg-red-600','bg-emerald-600',
    // classList add/remove/toggle targets
    'hidden','flex','scale-95','scale-100','opacity-0','opacity-100','opacity-30','opacity-50',
    'bg-red-50','bg-emerald-50','bg-blue-50','bg-blue-50/50','text-white',
    'cursor-not-allowed','pointer-events-none','border-red-500','-translate-x-full',
    'animate-bounce','animate-pulse','animate-spin',
    // status badges driven by DB values
    'border-red-200','text-red-700','border-emerald-100','text-emerald-700',
    'bg-amber-50','text-amber-600','border-amber-100','text-amber-700',
    'bg-indigo-100','text-indigo-700','text-slate-700',
    'bg-indigo-50','text-indigo-600','border-indigo-100','border-indigo-500',
    'text-slate-400','italic','font-extrabold','border-red-100',
    // pagination (5 variants across 18 files)
    'border-blue-600','shadow-lg','shadow-blue-200','shadow-blue-100',
    'bg-white','border-slate-200','text-slate-500','hover:border-blue-500','hover:text-blue-600',
    'bg-slate-800','shadow-slate-200','hover:bg-slate-800','hover:text-white',
    'hover:bg-white','hover:bg-blue-50','hover:bg-slate-200','hover:bg-gray-50',
    'border-gray-300','text-gray-500','z-10','shadow-sm','text-slate-300',
    'bg-indigo-600','shadow-indigo-200','shadow-emerald-200',
    // JS-injected markup
    'bg-slate-900/50','bg-slate-900/60','bg-slate-900','hover:bg-slate-50',
    'bg-black','bg-opacity-50','backdrop-blur-sm','z-[9999]','z-[200]','z-50',
    'min-w-[200px]','text-[9px]','text-[10px]','border-t-transparent','border-4',
    'text-gray-400','hover:text-red-500','shadow-inner',
    'group-hover:scale-110','transition-transform',
    'grid-cols-2','leading-snug','tracking-tighter','tracking-widest','space-y-1',
  ],
  plugins: [require('tailwindcss-animate')],
}

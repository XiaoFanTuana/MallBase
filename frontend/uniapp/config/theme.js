export const DEFAULT_THEME = {
  colorPrimary: '#0d50d5',
  colorPrimaryLight: '#386bef',
  colorBg: '#ffffff',
  colorBgSecondary: '#faf8ff',
  colorBgSurface: '#f3f3fe',
  colorText: '#191b23',
  colorTextTitle: '#191b23',
  colorTextSecondary: '#434654',
  colorTextTertiary: '#737686',
  colorBorder: '#e0e4e8',
  colorDivider: '#f0f2f5',
  colorError: '#ba1a1a',
  colorSuccess: '#34c759',
  colorWarning: '#f0ad4e',
}

function camelToKebab(str) {
  return str.replace(/([A-Z])/g, '-$1').toLowerCase()
}

export function applyTheme(themeObj) {
  const vars = Object.entries(themeObj).map(([k, v]) => `--${camelToKebab(k)}: ${v}`).join('; ')
  // #ifdef H5
  document.documentElement.style.cssText += vars
  // #endif
}

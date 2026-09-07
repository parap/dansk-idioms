// Helpers every page needs.
//
// esc() is the only thing standing between author-written passage text and markup
// execution, so it lives in one place: a second copy is a second thing to miss when the
// escaping rules change. The i18n machinery is here for the same reason the README gives
// for it -- adding a language must mean editing one object, not one object per page.

export const $ = s => document.querySelector(s);

export const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

export const api = async (path, opts = {}) => {
  const r = await fetch('/api/v1' + path, {
    headers: {'Content-Type': 'application/json'}, ...opts,
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) {
    // The status rides along so a caller can tell "log in again" from "it broke".
    const err = new Error(j?.error?.message ?? 'Something went wrong.');
    err.status = r.status;
    throw err;
  }
  return j;
};

// A page registers its own strings; everything below is shared. Any key a translation
// has not filled in falls back to Russian.
let STRINGS = {};
let lang = 'ru';

export const registerStrings = strings => {
  STRINGS = strings;
  let stored = null;
  try { stored = localStorage.getItem('ui_lang'); } catch {}
  lang = STRINGS[stored] ? stored : 'ru';
};

export const currentLang = () => lang;

export const t = (key, count) => {
  const value = STRINGS[lang]?.[key] ?? STRINGS.ru?.[key] ?? key;
  if (typeof value === 'string') return value;
  // Russian needs three forms (1 раунд / 2 раунда / 5 раундов); English needs two.
  // Intl.PluralRules knows the rules for every locale, so no counting logic here.
  const form = new Intl.PluralRules(lang).select(count ?? 0);
  return value[form] ?? value.other ?? Object.values(value)[0];
};

export const setLang = code => {
  lang = STRINGS[code] ? code : 'ru';
  try { localStorage.setItem('ui_lang', lang); } catch {}
  document.documentElement.lang = lang;
  return lang;
};

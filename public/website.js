/* Shared, dependency-free enhancements for the public website and portal. */
(() => {
  'use strict';
  const meta = name => document.querySelector(`meta[name="${name}"]`)?.content.trim() || '';
  const read = key => { try { return localStorage.getItem(key); } catch { return null; } };
  const save = (key, value) => { try { localStorage.setItem(key, value); } catch { /* Private browsing: keep this visit usable. */ } };
  const announce = message => { document.querySelector('[data-site-status]').textContent = message; };
  const themeButton = document.querySelector('[data-theme-toggle]');
  const setTheme = theme => {
    document.documentElement.dataset.siteTheme = theme;
    themeButton?.setAttribute('aria-pressed', String(theme === 'dark'));
    if (themeButton) themeButton.textContent = theme === 'dark' ? 'Light mode' : 'Dark mode';
  };
  setTheme(read('tableplay-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
  themeButton?.addEventListener('click', () => {
    const theme = document.documentElement.dataset.siteTheme === 'dark' ? 'light' : 'dark';
    save('tableplay-theme', theme); setTheme(theme);
  });

  const navToggle = document.querySelector('[data-site-menu]');
  const nav = document.getElementById(navToggle?.getAttribute('aria-controls'));
  const closeMenu = () => { navToggle?.setAttribute('aria-expanded', 'false'); nav?.classList.remove('site-menu-open'); };
  navToggle?.addEventListener('click', () => {
    const open = navToggle.getAttribute('aria-expanded') !== 'true';
    navToggle.setAttribute('aria-expanded', String(open)); nav?.classList.toggle('site-menu-open', open);
  });
  nav?.querySelectorAll('a').forEach(a => a.addEventListener('click', closeMenu));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });

  const top = document.querySelector('[data-back-top]');
  const progress = document.querySelector('[data-scroll-progress]');
  let scrollQueued = false;
  const updateScroll = () => {
    const max = document.documentElement.scrollHeight - innerHeight;
    if (progress) progress.style.transform = `scaleX(${max > 0 ? Math.min(1, scrollY / max) : 0})`;
    if (top) top.hidden = scrollY < 450;
    scrollQueued = false;
  };
  addEventListener('scroll', () => { if (!scrollQueued) { scrollQueued = true; requestAnimationFrame(updateScroll); } }, {passive: true});
  addEventListener('resize', updateScroll); updateScroll();

  const search = document.querySelector('[data-site-search]');
  const results = document.querySelector('[data-search-results]');
  const sections = [...document.querySelectorAll('main section[id]')].map(section => ({
    id: section.id, title: section.querySelector('h2')?.textContent.trim() || section.id,
    text: section.textContent.toLowerCase(),
  }));
  search?.addEventListener('input', () => {
    const query = search.value.trim().toLowerCase().slice(0, 100);
    results.replaceChildren(); results.hidden = !query;
    if (!query) return;
    const matches = sections.filter(s => s.text.includes(query)).slice(0, 8);
    if (!matches.length) results.textContent = 'No results. Try menu, trial, tablets or billing.';
    matches.forEach(s => {
      const a = document.createElement('a'); a.href = `#${s.id}`; a.textContent = s.title;
      a.addEventListener('click', () => { results.hidden = true; const target = document.getElementById(s.id); target.setAttribute('tabindex','-1'); target.focus({preventScroll:true}); });
      results.append(a);
    });
  });

  document.querySelectorAll('[data-copy-site]').forEach(button => button.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(meta('website-canonical') || location.origin); announce('Website link copied.'); }
    catch { announce('Could not copy automatically. Copy the address from your browser.'); }
  }));
  document.querySelectorAll('[data-site-print]').forEach(button => button.addEventListener('click', () => window.print()));
  let collapsedForPrint = [];
  addEventListener('beforeprint', () => { collapsedForPrint = [...document.querySelectorAll('details:not([open])')]; collapsedForPrint.forEach(d => { d.open = true; }); });
  addEventListener('afterprint', () => { collapsedForPrint.forEach(d => { d.open = false; }); collapsedForPrint = []; });

  const whatsapp = meta('website-whatsapp');
  if (/^[1-9][0-9]{7,14}$/.test(whatsapp)) document.querySelectorAll('[data-whatsapp]').forEach(link => {
    link.href = `https://wa.me/${whatsapp}?text=${encodeURIComponent('Hello TablePlay, I would like help setting up my restaurant.')}`;
    link.hidden = false;
  });

  // Basic consent mode: no analytics request, campaign storage or attribution until opt-in.
  let consent = read('tableplay-consent-v1');
  let analyticsLoaded = false;
  const campaignKeys = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content'];
  const campaign = () => {
    const params = new URLSearchParams(location.search); const values = {};
    for (const key of campaignKeys) {
      const value = params.get(key);
      if (value && /^[a-zA-Z0-9 _.-]{1,100}$/.test(value)) values[key] = value;
    }
    return values;
  };
  const trialLinks = [...document.querySelectorAll('a[href]')].filter(a => {
    const url = new URL(a.href); return url.origin === 'https://tableplay-api.onrender.com' && url.pathname === '/trial/register';
  }).map(link => ({link, href: link.href}));
  const applyCampaign = enabled => trialLinks.forEach(({link, href}) => {
    const url = new URL(href);
    if (enabled) Object.entries(campaign()).forEach(([key,value]) => url.searchParams.set(key,value));
    link.href = url.href;
  });
  const loadAnalytics = () => {
    const id = meta('website-analytics');
    if (consent !== 'accepted' || analyticsLoaded || !/^G-[A-Z0-9]+$/.test(id)) return;
    analyticsLoaded = true; window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('consent', 'default', {analytics_storage:'denied', ad_storage:'denied', ad_user_data:'denied', ad_personalization:'denied'});
    window.gtag('consent', 'update', {analytics_storage:'granted'});
    window.gtag('js', new Date());
    const safeLocation = new URL(meta('website-canonical') || location.origin);
    Object.entries(campaign()).forEach(([key,value]) => safeLocation.searchParams.set(key,value));
    window.gtag('config', id, {page_location:safeLocation.href, page_referrer:'', allow_google_signals:false, allow_ad_personalization_signals:false});
    const script = document.createElement('script'); script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${id}`; document.head.append(script);
  };
  const banner = document.querySelector('[data-cookie-banner]');
  if (banner) banner.hidden = ['accepted','rejected'].includes(consent);
  applyCampaign(consent === 'accepted'); loadAnalytics();
  document.querySelector('[data-cookie-settings]')?.addEventListener('click', () => { banner.hidden = false; banner.querySelector('button')?.focus(); });
  document.querySelectorAll('[data-consent]').forEach(button => button.addEventListener('click', () => {
    consent = button.dataset.consent; save('tableplay-consent-v1',consent); banner.hidden = true;
    applyCampaign(consent === 'accepted');
    if (consent === 'accepted') loadAnalytics();
    else {
      window.gtag?.('consent','update',{analytics_storage:'denied'});
      if (analyticsLoaded) location.reload();
    }
    announce(consent === 'accepted' ? 'Optional analytics accepted.' : 'Only essential storage enabled.');
  }));

  // Server-rendered form remains functional without JavaScript. Never simulate success.
  const form = document.querySelector('[data-contact-form]');
  const dialog = document.querySelector('[data-contact-confirm]');
  let confirmed = false;
  let submitting = false;
  form?.addEventListener('submit', e => {
    if (submitting) { e.preventDefault(); return; }
    if (!form.reportValidity()) { e.preventDefault(); return; }
    if (dialog?.showModal && !confirmed) { e.preventDefault(); dialog.showModal(); return; }
    submitting = true; form.setAttribute('aria-busy','true');
    const button = form.querySelector('[type=submit]'); button.disabled = true; button.textContent = 'Sending...';
  });
  dialog?.querySelector('[data-confirm-send]')?.addEventListener('click', () => { confirmed = true; dialog.close(); form.requestSubmit(); });
  dialog?.querySelector('[data-cancel-send]')?.addEventListener('click', () => { dialog.close(); confirmed = false; });
  addEventListener('pageshow', () => { submitting = false; confirmed = false; if (form) { form.removeAttribute('aria-busy'); const b = form.querySelector('[type=submit]'); b.disabled = false; b.textContent = 'Send message'; } });
})();

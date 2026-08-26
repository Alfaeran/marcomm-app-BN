/**
 * Browser test: the failed-login banner must actually animate.
 *
 * Drives headless Chrome over CDP using Node's built-in WebSocket (Node 22+),
 * so this adds no npm dependency. Submits a deliberately wrong password, then
 * checks four things on the resulting error banner:
 *   1. the banner renders carrying .animate-shake
 *   2. .animate-shake resolves to a real computed animation (not "none")
 *   3. @keyframes shake is reachable from the page's stylesheets
 *   4. the element's x-position actually changes across animation frames
 *
 * (4) is the one that matters: 1-3 only prove the CSS was delivered, while 4
 * proves the browser ran it.
 *
 * Prereq: Chrome listening on --remote-debugging-port=9222.
 * Usage:  node tools/test_login_shake.js [baseUrl]
 *
 * ponytail: raw CDP over one WebSocket rather than Puppeteer, to avoid a
 * dependency for a four-assertion test. Move to Puppeteer if this grows.
 */
const BASE = process.argv[2] || 'http://localhost/marcomm_bn';
const USER = 'balibarat.3id.1';

async function main() {
  const list = await (await fetch('http://localhost:9222/json/list')).json();
  const page = list.find((t) => t.type === 'page');
  if (!page) throw new Error('no page target; start Chrome with --remote-debugging-port=9222');

  const ws = new WebSocket(page.webSocketDebuggerUrl);
  let id = 0;
  const pending = new Map();
  ws.addEventListener('message', (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg); pending.delete(msg.id); }
  });
  await new Promise((res, rej) => {
    ws.addEventListener('open', res, { once: true });
    ws.addEventListener('error', () => rej(new Error('CDP websocket failed')), { once: true });
  });

  const send = (method, params = {}) => new Promise((res) => {
    const i = ++id;
    pending.set(i, res);
    ws.send(JSON.stringify({ id: i, method, params }));
  });

  const evalJs = async (expression, awaitPromise = false) => {
    const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise });
    const res = r.result;
    if (res.exceptionDetails) {
      throw new Error(res.exceptionDetails.exception?.description || 'JS exception');
    }
    return res.result.value;
  };

  const settle = async () => {
    for (let i = 0; i < 100; i++) {
      if (await evalJs('document.readyState') === 'complete') return;
      await new Promise((r) => setTimeout(r, 100));
    }
  };

  await send('Page.enable');
  await send('Runtime.enable');
  // Without this the page can be measured against a cached stylesheet, so a
  // deleted CSS rule would still appear to work. Verified: omitting it makes
  // the negative test pass when it must fail.
  await send('Network.enable');
  await send('Network.setCacheDisabled', { cacheDisabled: true });

  // Load the login page, then submit a wrong password through the real form.
  await send('Page.navigate', { url: BASE + '/login.php' });
  await settle();

  await evalJs(`(() => {
    const f = document.querySelector('form');
    f.querySelector('[name=username]').value = ${JSON.stringify(USER)};
    f.querySelector('[name=password]').value = 'DELIBERATELY-WRONG';
    f.submit();
    return true;
  })()`);

  await new Promise((r) => setTimeout(r, 1500));
  await settle();

  const results = [];
  const check = (name, pass, detail) => {
    results.push(pass);
    console.log(`  ${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? '  -- ' + detail : ''}`);
  };

  const hasBanner = await evalJs(`!!document.querySelector('.animate-shake')`);
  check('error banner rendered with .animate-shake', hasBanner);
  if (!hasBanner) {
    console.log('\nCannot continue: no banner. Did the login actually fail?');
    ws.close();
    process.exit(1);
  }

  const a = JSON.parse(await evalJs(`(() => {
    const cs = getComputedStyle(document.querySelector('.animate-shake'));
    return JSON.stringify({
      name: cs.animationName,
      duration: cs.animationDuration,
      timing: cs.animationTimingFunction,
    });
  })()`));
  check('computed animation-name is "shake"', a.name === 'shake', `got "${a.name}"`);
  check('animation-duration is non-zero', a.duration !== '0s' && a.duration !== '', a.duration);

  const kf = await evalJs(`(() => {
    for (const sheet of document.styleSheets) {
      let rules;
      try { rules = sheet.cssRules; } catch (e) { continue; }
      for (const r of rules) {
        if (r.type === CSSRule.KEYFRAMES_RULE && r.name === 'shake') return r.cssText.length;
      }
    }
    return 0;
  })()`);
  check('@keyframes shake reachable in stylesheets', kf > 0, kf ? kf + ' chars' : 'NOT FOUND');

  // The real proof: restart the animation and sample actual geometry per frame.
  const xs = JSON.parse(await evalJs(`new Promise((resolve) => {
    const el = document.querySelector('.animate-shake');
    el.style.animation = 'none';
    void el.offsetWidth;
    el.style.animation = '';
    const xs = [];
    const t0 = performance.now();
    (function tick() {
      xs.push(el.getBoundingClientRect().x);
      if (performance.now() - t0 < 700) requestAnimationFrame(tick);
      else resolve(JSON.stringify(xs));
    })();
  })`, true));

  const min = Math.min(...xs), max = Math.max(...xs);
  const spread = +(max - min).toFixed(2);
  check('element x-position actually moves', spread > 1,
    `${xs.length} frames, spread ${spread}px (min ${min.toFixed(1)}, max ${max.toFixed(1)})`);

  ws.close();
  const failed = results.filter((p) => !p).length;
  console.log(failed ? `\n${failed} check(s) FAILED` : '\nAll checks passed.');
  process.exit(failed ? 1 : 0);
}

main().catch((e) => { console.error('ERROR:', e.message); process.exit(1); });

/* Medienseite: Anmeldung per E-Mail-Code mit Selbstregistrierung
   (adaptiert aus dem Heftarchiv-Plugin von schwand-baeregg.ch) */
(function () {
  'use strict';
  var roots = document.querySelectorAll('.ms-auth');
  if (!roots.length) return;

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') e.textContent = attrs[k];
      else if (k === 'class') e.className = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { e.appendChild(c); });
    return e;
  }

  function post(endpunkt, action, data) {
    return fetch(endpunkt + '/auth/' + action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(data)
    }).then(function (r) { return r.json().catch(function () { return { ok: false, step: 'email', message: 'Unerwartete Antwort des Servers.' }; }); })
      .catch(function () { return { ok: false, step: 'email', message: 'Keine Verbindung. Bitte versuchen Sie es erneut.' }; });
  }

  roots.forEach(function (root) {
    var context = 'seite';
    var endpunkt = root.getAttribute('data-endpunkt') || '/mediendaten';
    var datenschutz = root.getAttribute('data-datenschutz') || '';
    var seitenname = root.getAttribute('data-seitenname') || 'der Betreiber dieser Website';
    var state = { email: '', step: 'email', msg: '', ok: true, reg: {}, challenge: null };

    function field(id, label, type, extra) {
      var input = el('input', Object.assign({ id: 'ms-' + id, name: id, type: type, class: 'ms-auth__input' }, extra || {}));
      return { wrap: el('label', { class: 'ms-auth__field', 'for': 'ms-' + id }, [el('span', { text: label }), input]), input: input };
    }

    function render() {
      root.innerHTML = '';
      var box = el('div', { class: 'ms-auth__box' });
      box.appendChild(el('h2', { class: 'ms-auth__title', text: state.step === 'register' ? 'Neu hier? Kurz registrieren' : 'Anmelden für alle Bereiche' }));
      if (state.step === 'email') box.appendChild(el('p', { class: 'ms-auth__lead', text: 'Einige Bereiche stehen nur registrierten Nutzerinnen und Nutzern offen. Geben Sie Ihre E-Mail-Adresse ein: Wir senden Ihnen einen Code, ein Passwort ist nicht nötig.' }));
      var msg = el('p', { class: 'ms-auth__msg' + (state.ok ? '' : ' is-error'), role: 'status', 'aria-live': 'polite', text: state.msg });
      if (state.msg) box.appendChild(msg);

      var form = el('form', { class: 'ms-auth__form', novalidate: 'novalidate' });
      var hp = el('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true', class: 'ms-auth__hp' });
      form.appendChild(hp);

      var submit, focus;
      if (state.step === 'email' || state.step === 'password') {
        var f = field('email', 'E-Mail-Adresse', 'email', { autocomplete: 'email', inputmode: 'email', required: 'required' });
        f.input.value = state.email;
        form.appendChild(f.wrap);
        submit = 'Code senden';
        focus = f.input;
        form.addEventListener('submit', function (e) { e.preventDefault(); start({ email: f.input.value }); });
      } else if (state.step === 'register') {
        var fv = field('vorname', 'Vorname', 'text', { autocomplete: 'given-name', required: 'required' });
        var fn = field('nachname', 'Nachname', 'text', { autocomplete: 'family-name', required: 'required' });
        var fo = field('organisation', 'Organisation oder Medium', 'text', { autocomplete: 'organization' });
        var ft = field('telefon', 'Telefon (freiwillig)', 'tel', { autocomplete: 'tel' });
        fv.input.value = state.reg.vorname || ''; fn.input.value = state.reg.nachname || '';
        fo.input.value = state.reg.organisation || ''; ft.input.value = state.reg.telefon || '';
        var fq = field('answer', state.challenge ? 'Sicherheitsfrage: ' + state.challenge.question : 'Sicherheitsfrage', 'text', { inputmode: 'numeric', autocomplete: 'off', required: 'required' });
        fq.input.classList.add('ms-auth__answer');
        var row = el('div', { class: 'ms-auth__row' }, [fv.wrap, fn.wrap]);
        var cb = el('input', { type: 'checkbox', id: 'ms-consent', name: 'consent' });
        var link = el('a', { href: datenschutz || '#', target: '_blank', rel: 'noopener', text: 'Datenschutzerklärung' });
        var consent = el('label', { class: 'ms-auth__consent', 'for': 'ms-consent' }, [cb,
          el('span', {}, [document.createTextNode('Ich habe die '), link,
            document.createTextNode(' gelesen und bin damit einverstanden, dass ' + seitenname + ' meine Angaben für mein Konto und den Zugang zur Medienseite speichert.')])]);
        [row, fo.wrap, ft.wrap, fq.wrap, consent].forEach(function (n) { form.appendChild(n); });
        submit = 'Registrieren und Code senden';
        focus = fv.input;
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          state.reg = { vorname: fv.input.value, nachname: fn.input.value, organisation: fo.input.value, telefon: ft.input.value };
          start(Object.assign({ email: state.email, consent: cb.checked, ctoken: state.challenge ? state.challenge.token : '', answer: fq.input.value }, state.reg));
        });
      } else if (state.step === 'code') {
        var fc = field('code', 'Code aus der E-Mail', 'text', { inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '8', pattern: '[0-9]*', required: 'required' });
        fc.input.classList.add('ms-auth__code');
        form.appendChild(fc.wrap);
        submit = 'Anmelden';
        focus = fc.input;
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          busy(form, true);
          post(endpunkt, 'verify', { email: state.email, code: fc.input.value, context: context }).then(function (r) {
            if (r.ok && r.step === 'done') { state.msg = r.message; state.ok = true; if (r.redirect) location.href = r.redirect; else location.reload(); return; }
            apply(r);
          });
        });
      } else {                                                       // pending / done
        root.appendChild(box);
        return;
      }
      var btn = el('button', { type: 'submit', class: 'ms-auth__btn', text: submit });
      form.appendChild(btn);
      box.appendChild(form);

      var aux = el('div', { class: 'ms-auth__aux' });
      if (state.step === 'code') {
        var again = el('button', { type: 'button', class: 'ms-auth__link', text: 'Neuen Code senden' });
        again.addEventListener('click', function () { start({ email: state.email, resend: true }); });
        var other = el('button', { type: 'button', class: 'ms-auth__link', text: 'Andere E-Mail-Adresse' });
        other.addEventListener('click', function () { state.step = 'email'; state.msg = ''; state.ok = true; render(); });
        aux.appendChild(again); aux.appendChild(other);
      }
      if (state.step === 'register') {
        var back = el('button', { type: 'button', class: 'ms-auth__link', text: 'Zurück' });
        back.addEventListener('click', function () { state.step = 'email'; state.msg = ''; state.ok = true; render(); });
        aux.appendChild(back);
      }
      if (aux.children.length) box.appendChild(aux);
      root.appendChild(box);
      if (focus && !state.noFocus) setTimeout(function () { try { focus.focus(); } catch (e) {} }, 30);
    }

    function busy(form, on) {
      var b = form.querySelector('button[type=submit]');
      if (b) { b.disabled = on; if (on) b.dataset.t = b.textContent, b.textContent = 'Einen Moment …'; else if (b.dataset.t) b.textContent = b.dataset.t; }
    }

    function apply(r) {
      state.ok = !!r.ok; state.msg = r.message || ''; state.step = r.step || 'email'; if (r.challenge) state.challenge = r.challenge;
      render();
    }

    function start(data) {
      state.email = String(data.email || '').trim().toLowerCase();
      var form = root.querySelector('form'); if (form) busy(form, true);
      post(endpunkt, 'start', Object.assign({}, data, { email: state.email, website: (root.querySelector('.ms-auth__hp') || {}).value || '' })).then(apply);
    }

    state.noFocus = true;   /* beim Laden der Seite nicht zum Formular springen */
    render();
    state.noFocus = false;
  });
})();

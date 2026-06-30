/* ──────────────────────────────────────────────────────────────────────────
   FlyMasar — Unified Checkout Gateway (one component for every payment point).
   Renders the approved payment design (docs/payment-ui-reference): Apple/Google
   Pay → divider → cardholder name → split card fields. Card data stays inside
   Stripe Elements iframes (PCI). Apple/Google Pay use the Express Checkout
   Element (never hand-built). Styled to match css/checkout-gateway.css.

   Usage:
     const gw = await FMCheckout.mount({
       fieldsEl,                 // container the component renders into
       currency:   'GBP',        // ISO currency for Express Checkout
       amountMinor: 2548,        // total in minor units (for the wallet sheet)
       express: {                // optional — omit to disable Apple/Google Pay
         getClientSecret: async () => ({ clientSecret, paymentIntentId }),
         onSuccess: async (paymentIntent) => { ...redirect... },
         returnUrl: 'https://…',
       },
       onError: (msg) => {},
     });
     // then, from the page's own pay button:
     const { paymentIntent, error } = await gw.confirmCard(clientSecret);

   The page keeps its own layout and pay button (we only recolour those); this
   component owns the card capture + wallet buttons so every payment point is
   identical. Honours C-01/C-02/C-04 (server-side; this is the client surface).
   ────────────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  // Stripe card-field styling that matches the unified design (Tajawal, navy ink).
  var CARD_STYLE = {
    base: {
      fontFamily: "'Tajawal', system-ui, sans-serif",
      fontSize: '16px',
      fontWeight: '500',
      color: '#1A2A44',
      fontSmoothing: 'antialiased',
      iconColor: '#9AA7B6',
      '::placeholder': { color: '#A8B3C1', fontWeight: '400' }
    },
    invalid: { color: '#E2574C', iconColor: '#E2574C' }
  };

  function el(html) {
    var t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstChild;
  }

  function template() {
    return '' +
      '<div class="fmco-card fmco-embed">' +
        '<div class="fmco-body">' +
          '<div class="fmco-wallets" data-fmco="wallets"></div>' +
          '<div class="fmco-divider" data-fmco="divider">أو ادفع بالبطاقة</div>' +
          '<div class="fmco-field">' +
            '<label class="fmco-label" for="cardName">اسم حامل البطاقة</label>' +
            '<div class="fmco-box"><input id="cardName" type="text" autocomplete="cc-name" placeholder="الاسم كما هو على البطاقة"></div>' +
          '</div>' +
          '<div class="fmco-field">' +
            '<label class="fmco-label">رقم البطاقة</label>' +
            '<div class="fmco-box" data-fmco="box-number"><div class="fmco-el" data-fmco="number"></div></div>' +
          '</div>' +
          '<div class="fmco-row-2">' +
            '<div class="fmco-field" style="margin-bottom:0">' +
              '<label class="fmco-label">تاريخ الانتهاء</label>' +
              '<div class="fmco-box" data-fmco="box-expiry"><div class="fmco-el" data-fmco="expiry"></div></div>' +
            '</div>' +
            '<div class="fmco-field" style="margin-bottom:0">' +
              '<label class="fmco-label">CVC</label>' +
              '<div class="fmco-box" data-fmco="box-cvc"><div class="fmco-el" data-fmco="cvc"></div></div>' +
            '</div>' +
          '</div>' +
          '<div class="fmco-err" data-fmco="err"></div>' +
        '</div>' +
      '</div>';
  }

  function fetchStripeKey() {
    return fetch('/api/config/stripe-key')
      .then(function (r) { return r.json(); })
      .then(function (c) { return (c && (c.publishable_key || c.key)) || ''; })
      .catch(function () { return ''; });
  }

  // Hide a node and collapse the divider's top spacing when wallets are absent.
  function hideWallets(root) {
    var w = root.querySelector('[data-fmco="wallets"]');
    var d = root.querySelector('[data-fmco="divider"]');
    if (w) w.style.display = 'none';
    if (d) { d.style.display = 'none'; }
  }

  var FMCheckout = {
    mount: function (cfg) {
      cfg = cfg || {};
      var host = cfg.fieldsEl;
      if (!host) return Promise.reject(new Error('FMCheckout: fieldsEl is required'));

      var root = el(template());
      host.innerHTML = '';
      host.appendChild(root);

      var errEl = root.querySelector('[data-fmco="err"]');
      var setErr = function (m) {
        if (errEl) errEl.textContent = m ? ('⚠️ ' + m) : '';
        if (typeof cfg.onError === 'function' && m) cfg.onError(m);
      };

      var controller = {
        stripe: null,
        card: null,
        unavailable: false,
        get cardName() {
          var i = root.querySelector('#cardName');
          return i ? i.value.trim() : '';
        },
        setError: setErr,
        confirmCard: function () { return Promise.reject(new Error('not-ready')); },
        // Keep the Apple/Google Pay sheet amount in sync (e.g. after a coupon).
        updateAmount: function (minor) {
          if (controller.expressElements && minor > 0) {
            try { controller.expressElements.update({ amount: Math.round(minor) }); } catch (e) {}
          }
        }
      };

      return fetchStripeKey().then(function (pk) {
        if (!pk || typeof Stripe === 'undefined') {
          controller.unavailable = true;
          setErr('نظام الدفع غير مُفعّل بعد (مفتاح Stripe غير مُهيّأ).');
          if (typeof cfg.onUnavailable === 'function') cfg.onUnavailable();
          return controller;
        }

        var stripe = Stripe(pk);
        controller.stripe = stripe;

        // ── Card capture: a plain (non-deferred) elements group so the proven
        //    confirmCardPayment(clientSecret, {card}) flow is unchanged. ──────
        var cardElements = stripe.elements({ locale: 'ar' });
        var cardNumber = cardElements.create('cardNumber', { style: CARD_STYLE, showIcon: true, placeholder: '0000 0000 0000 0000' });
        var cardExpiry = cardElements.create('cardExpiry', { style: CARD_STYLE });
        var cardCvc    = cardElements.create('cardCvc',    { style: CARD_STYLE });
        controller.card = cardNumber;

        cardNumber.mount(root.querySelector('[data-fmco="number"]'));
        cardExpiry.mount(root.querySelector('[data-fmco="expiry"]'));
        cardCvc.mount(root.querySelector('[data-fmco="cvc"]'));

        // Focus ring + inline validation on the styled boxes (iframes don't bubble :focus).
        [[cardNumber, 'box-number'], [cardExpiry, 'box-expiry'], [cardCvc, 'box-cvc']].forEach(function (pair) {
          var element = pair[0];
          var box = root.querySelector('[data-fmco="' + pair[1] + '"]');
          element.on('focus', function () { box && box.classList.add('fmco-focus'); });
          element.on('blur',  function () { box && box.classList.remove('fmco-focus'); });
          element.on('change', function (e) {
            if (box) box.classList.toggle('fmco-invalid', !!(e && e.error));
            setErr(e && e.error ? e.error.message : '');
          });
        });

        controller.confirmCard = function (clientSecret, opts) {
          opts = opts || {};
          return stripe.confirmCardPayment(clientSecret, {
            payment_method: {
              card: cardNumber,
              billing_details: { name: opts.name || controller.cardName }
            }
          });
        };

        // ── Express Checkout Element (Apple Pay / Google Pay) — optional. ────
        if (cfg.express && cfg.amountMinor > 0 && cfg.currency) {
          try {
            var exElements = stripe.elements({
              mode: 'payment',
              amount: Math.round(cfg.amountMinor),
              currency: String(cfg.currency).toLowerCase(),
              locale: 'ar'
            });
            controller.expressElements = exElements;
            var expr = exElements.create('expressCheckout', { buttonHeight: 48 });

            expr.on('ready', function (e) {
              if (!e || !e.availablePaymentMethods) hideWallets(root);
            });
            expr.on('loaderror', function () { hideWallets(root); });

            expr.on('confirm', function (event) {
              Promise.resolve(cfg.express.getClientSecret())
                .then(function (res) {
                  var cs = res && (res.clientSecret || res.client_secret);
                  if (!cs) throw new Error('تعذّر إنشاء طلب الدفع.');
                  return stripe.confirmPayment({
                    elements: exElements,
                    clientSecret: cs,
                    confirmParams: { return_url: cfg.express.returnUrl || window.location.href },
                    redirect: 'if_required'
                  }).then(function (out) {
                    if (out.error) throw new Error(out.error.message || 'فشل الدفع عبر المحفظة.');
                    return cfg.express.onSuccess ? cfg.express.onSuccess(out.paymentIntent) : null;
                  });
                })
                .catch(function (err) {
                  setErr(err && err.message ? err.message : 'فشل الدفع عبر المحفظة.');
                });
            });

            expr.mount(root.querySelector('[data-fmco="wallets"]'));
          } catch (e) {
            hideWallets(root);
          }
        } else {
          hideWallets(root);
        }

        return controller;
      });
    }
  };

  window.FMCheckout = FMCheckout;
})();

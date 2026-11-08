(function () {
    'use strict';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    // Onglets du ruban
    var onglets = $$('[data-onglet]');
    onglets.forEach(function (tab) {
        tab.addEventListener('click', function () {
            onglets.forEach(function (t) {
                var actif = t === tab;
                t.setAttribute('aria-selected', actif ? 'true' : 'false');
                var p = document.getElementById('panneau-' + t.getAttribute('data-onglet'));
                if (p) { p.hidden = !actif; }
            });
        });
    });

    // Sélecteur de saison : envoi immédiat
    $$('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { el.form.submit(); });
    });

    // Confirmation avant action irréversible
    $$('form[data-confirmer]').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            if (!window.confirm(f.getAttribute('data-confirmer'))) { ev.preventDefault(); }
        });
    });

    // Fiche adhérent : copie nom/prénom vers le parent
    var copie = $('#copie-parent');
    if (copie) {
        copie.addEventListener('click', function () {
            $('#f_nom_parent').value = $('#f_nom').value;
            $('#f_prenom_parent').value = $('#f_prenom').value;
        });
    }

    // Fiche adhérent : fiches ressemblantes pendant la saisie
    var zone = $('#zone-doublons');
    var form = $('#form-adherent');
    if (zone && form) {
        var minuteur = null;
        var champs = ['nom', 'prenom', 'mail', 'telephone1', 'telephone2', 'date_naissance'];
        var interroger = function () {
            var q = new URLSearchParams();
            champs.forEach(function (c) { var el = $('#f_' + c); if (el && el.value.trim()) { q.set(c, el.value.trim()); } });
            if (zone.getAttribute('data-sauf') !== '0') { q.set('sauf', zone.getAttribute('data-sauf')); }
            if (!q.has('nom') && !q.has('prenom')) { zone.hidden = true; return; }
            fetch(zone.getAttribute('data-url') + '?' + q.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (liste) {
                    zone.textContent = '';
                    if (!liste.length) { zone.hidden = true; return; }
                    var titre = document.createElement('p');
                    titre.className = 'titre-doublons';
                    titre.textContent = liste.length > 1 ? 'Ces fiches existent déjà et ressemblent à votre saisie :' : 'Cette fiche existe déjà et ressemble à votre saisie :';
                    zone.appendChild(titre);
                    var ul = document.createElement('ul');
                    liste.forEach(function (f) {
                        var li = document.createElement('li');
                        var a = document.createElement('a');
                        a.href = f.url; a.textContent = f.nom || '(sans nom)';
                        li.appendChild(a);
                        var badge = document.createElement('span');
                        badge.className = 'pastille pastille-niv-' + f.niveau;
                        badge.textContent = f.libelle;
                        li.appendChild(document.createTextNode(' '));
                        li.appendChild(badge);
                        li.appendChild(document.createTextNode(' ' + (f.naissance ? 'né(e) le ' + f.naissance + ' — ' : '') + f.raisons.join(', ')));
                        ul.appendChild(li);
                    });
                    zone.appendChild(ul);
                    zone.hidden = false;
                })
                .catch(function () { /* la vérification serveur reste active à l'enregistrement */ });
        };
        champs.forEach(function (c) {
            var el = $('#f_' + c);
            if (el) { el.addEventListener('input', function () { clearTimeout(minuteur); minuteur = setTimeout(interroger, 400); }); }
        });
        if ($('#f_nom').value || $('#f_prenom').value) { interroger(); }
    }

    // Inscription : le montant se remplit avec le tarif de la section tant qu'il n'a pas été modifié à la main
    var sel = $('[data-prix-section]');
    if (sel) {
        var montant = $('#f_montant');
        var dernier = (sel.selectedOptions[0] && sel.selectedOptions[0].getAttribute('data-montant')) || '';
        sel.addEventListener('change', function () {
            var nouveau = (sel.selectedOptions[0] && sel.selectedOptions[0].getAttribute('data-montant')) || '';
            if (montant.value === '' || montant.value === dernier) { montant.value = nouveau; }
            dernier = nouveau;
        });
        if (montant.value === '' && dernier) { montant.value = dernier; }
    }

    // Règlement : dates de pose des chèques (1er ou 15 du mois)
    var mode = $('[data-mode-cheque]');
    var rapide = $('#dates-cheque');
    var date = $('#f_date_reglement');
    if (mode && rapide && date) {
        var maj = function () { rapide.hidden = mode.value !== mode.getAttribute('data-mode-cheque'); };
        var iso = function (d) {
            var m = d.getMonth() + 1, j = d.getDate();
            return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (j < 10 ? '0' : '') + j;
        };
        mode.addEventListener('change', maj);
        maj();
        $$('[data-pose-date]', rapide).forEach(function (b) {
            b.addEventListener('click', function () { date.value = b.getAttribute('data-pose-date'); });
        });
        $$('[data-pose]', rapide).forEach(function (b) {
            b.addEventListener('click', function () {
                var auj = new Date(), jour = b.getAttribute('data-pose');
                var d = jour === '1'
                    ? new Date(auj.getFullYear(), auj.getMonth() + 1, 1)
                    : new Date(auj.getFullYear(), auj.getMonth() + (auj.getDate() < 15 ? 0 : 1), 15);
                date.value = iso(d);
            });
        });
    }
}());

// Validation HelloAsso : afficher la nouvelle fiche seulement si « Nouvelle fiche » est choisie
(function () {
    'use strict';
    var zone = document.getElementById('nouvelle-fiche');
    if (!zone) { return; }
    var radios = document.querySelectorAll('[data-choix-adherent]');
    var maj = function () {
        var choisi = document.querySelector('[data-choix-adherent]:checked');
        zone.hidden = !(choisi && choisi.value === 'nouveau');
    };
    Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', maj); });
    maj();
}());

// QR codes (bibliothèque qrcode-generator, chargée seulement sur les pages qui en affichent)
(function () {
    'use strict';
    if (typeof window.qrcode !== 'function') { return; }
    Array.prototype.forEach.call(document.querySelectorAll('[data-qr]'), function (el) {
        var qr = window.qrcode(0, 'M');
        qr.addData(el.getAttribute('data-qr'));
        qr.make();
        el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 16, scalable: true }); // marge blanche de 4 modules, nécessaire à la lecture
    });
}());

// Bouton « Imprimer »
(function () {
    'use strict';
    Array.prototype.forEach.call(document.querySelectorAll('[data-imprimer]'), function (b) {
        b.addEventListener('click', function () { window.print(); });
    });
}());

// Formulaire public : le bloc « parent » ne s'affiche que pour un mineur (il reste visible sans JavaScript)
(function () {
    'use strict';
    var bloc = document.querySelector('[data-bloc-parent]');
    var dn = document.getElementById('f_date_naissance');
    if (!bloc || !dn) { return; }
    var maj = function () {
        if (!dn.value) { bloc.hidden = false; return; }
        var n = new Date(dn.value + 'T00:00:00'), a = new Date();
        var age = a.getFullYear() - n.getFullYear() - ((a.getMonth() < n.getMonth() || (a.getMonth() === n.getMonth() && a.getDate() < n.getDate())) ? 1 : 0);
        bloc.hidden = age >= 18;
    };
    dn.addEventListener('change', maj);
    dn.addEventListener('input', maj);
    maj();
    var resume = document.getElementById('resume-erreurs');
    if (resume) { resume.focus(); }
}());

// Remise de chèques : « tout cocher » et calcul en direct du nombre de remises (30 chèques au plus)
(function () {
    'use strict';
    var form = document.getElementById('form-remise');
    if (!form) { return; }
    var max = parseInt(form.getAttribute('data-max'), 10) || 30;
    var cases = form.querySelectorAll('input[name="cheques[]"]');
    var tout = form.querySelector('[data-tout]');
    var resume = document.getElementById('resume-remise');
    var euros = function (v) { return v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'; };
    var maj = function () {
        var n = 0, total = 0;
        Array.prototype.forEach.call(cases, function (c) { if (c.checked) { n++; total += parseFloat(c.getAttribute('data-montant')) || 0; } });
        var k = Math.ceil(n / max), tailles = [];
        for (var i = 0; i < k; i++) { tailles.push(Math.floor(n / k) + (i < n % k ? 1 : 0)); }
        resume.textContent = '';
        var b = document.createElement('strong');
        b.textContent = n + ' chèque' + (n > 1 ? 's' : '') + ', ' + euros(total);
        resume.appendChild(b);
        resume.appendChild(document.createTextNode(n === 0 ? '. Cochez au moins un chèque.'
            : k > 1 ? '. La banque limite une remise à ' + max + ' chèques : ils seront répartis sur ' + k + ' remises (' + tailles.join(' + ') + ' chèques), avec un bordereau chacune.'
            : '. Une seule remise suffit.'));
        if (tout) { tout.checked = n === cases.length; tout.indeterminate = n > 0 && n < cases.length; }
    };
    Array.prototype.forEach.call(cases, function (c) { c.addEventListener('change', maj); });
    if (tout) { tout.addEventListener('change', function () { Array.prototype.forEach.call(cases, function (c) { c.checked = tout.checked; }); maj(); }); }
}());

// Règlement : aides (coupons mairie, Pass Sport, Pass Région, Pass Senior) — montant proposé, toujours modifiable
(function () {
    'use strict';
    var mode = document.getElementById('f_mode_reglement_id');
    var montant = document.getElementById('f_montant');
    var ref = document.getElementById('f_identifiant');
    var date = document.getElementById('f_date_reglement');
    if (!mode || !montant || !ref) { return; }
    // Date d'encaissement prévue de l'aide : remplace la date tant qu'elle n'a pas été changée à la main
    var dateAuto = date ? date.value : '';
    var poserDate = function (d) {
        if (!date || !d) { return; }
        if (date.value === '' || date.value === dateAuto) { date.value = d; }
        dateAuto = d;
    };
    // On ne remplace que ce que l'application a elle-même proposé (jamais une saisie de l'utilisateur)
    var auto = { montant: null, reference: '' };
    var appliquer = function (m, r) {
        if (montant.value === '' || montant.value === auto.montant || auto.montant === null) { montant.value = m; }
        if (ref.value === '' || ref.value === auto.reference) { ref.value = r; }
        auto = { montant: m, reference: r };
    };
    Array.prototype.forEach.call(document.querySelectorAll('[data-aide]'), function (b) {
        b.addEventListener('click', function () {
            mode.value = b.getAttribute('data-mode');
            mode.dispatchEvent(new Event('change', { bubbles: true }));
            montant.value = b.getAttribute('data-montant');
            ref.value = b.getAttribute('data-reference');
            auto = { montant: montant.value, reference: ref.value };
            poserDate(b.getAttribute('data-date'));
            montant.focus();
            montant.select();
        });
    });
    var initial = montant.value;
    mode.addEventListener('change', function () {
        var o = mode.selectedOptions[0];
        if (!o || !o.hasAttribute('data-montant')) { return; }
        if (auto.montant === null) { auto.montant = initial; }
        appliquer(o.getAttribute('data-montant'), o.getAttribute('data-reference') || '');
        poserDate(o.getAttribute('data-date'));
    });
}());

// Application installable : enregistrement du service worker (HTTPS uniquement ; il ne garde aucune page ni donnée)
(function () {
    'use strict';
    if (!('serviceWorker' in navigator)) { return; }
    var script = document.querySelector('script[data-sw]');
    if (!script) { return; }
    window.addEventListener('load', function () {
        navigator.serviceWorker.register(script.getAttribute('data-sw'), { scope: script.getAttribute('data-sw').replace(/sw\.js.*$/, '') })
            .catch(function () { /* l'application fonctionne aussi sans */ });
    });
}());

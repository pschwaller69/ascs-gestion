/* ASCS – service worker : rend l'application installable.
 * Les pages et les données ne sont JAMAIS mises en cache (données personnelles, chiffres toujours à jour) :
 * seuls les fichiers d'interface (styles, scripts, icônes) le sont. Sans réseau, une page « Pas de connexion » s'affiche.
 * La version (paramètre ?v= de l'enregistrement) renouvelle le cache à chaque nouvelle version de l'application. */
'use strict';
var VERSION = new URL(self.location).searchParams.get('v') || '0';
var CACHE = 'ascs-interface-' + VERSION;
var HORS_LIGNE = 'hors-ligne.html';
var PRECHARGE = [HORS_LIGNE, 'assets/app.css?v=' + VERSION, 'assets/app.js?v=' + VERSION, 'assets/icones/icone-192.png'];

self.addEventListener('install', function (e) {
    e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(PRECHARGE); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
    e.waitUntil(caches.keys().then(function (cles) {
        return Promise.all(cles.filter(function (k) { return k.indexOf('ascs-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
    var req = e.request;
    if (req.method !== 'GET') { return; }
    var url = new URL(req.url);
    if (url.origin !== self.location.origin) { return; }
    // Pages : toujours le réseau ; page de secours seulement en cas de coupure.
    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).catch(function () { return caches.match(HORS_LIGNE); }));
        return;
    }
    // Fichiers d'interface : cache d'abord (ils changent de nom à chaque version).
    if (url.pathname.indexOf('/assets/') !== -1) {
        e.respondWith(caches.match(req).then(function (r) {
            return r || fetch(req).then(function (rep) {
                if (rep.ok) { var copie = rep.clone(); caches.open(CACHE).then(function (c) { c.put(req, copie); }); }
                return rep;
            });
        }));
    }
    // Tout le reste (recherches, données JSON…) : réseau seulement, rien n'est gardé.
});

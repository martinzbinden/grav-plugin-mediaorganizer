const TAG = window.__GRAV_PAGE_TAG || 'grav-mediaorganizer--mediaorganizer';

/**
 * Admin2-Seite des Media Organizers: Datei-Browser für die Mediathek (user/media).
 * Alle Dateitypen; Vorschau, Vollbild, EXIF und Optimieren für Bilder.
 * Entstanden aus dem Medienarchiv von riedackerhof-templates.
 *
 * Links Ordnerbaum (einklappbar), Mitte Galerie oder sortierbare Liste (wahlweise inkl.
 * Unterordner), rechts Detailbereich mit Vorschau, Eigenschaften, Mediathek-Texten und
 * EXIF-Editor (bei Mehrfachauswahl: Massenbearbeitung). Doppelklick/Enter: Vollbild mit Zoom.
 * Optimieren (verkleinern/neu komprimieren, Original nach _original/) und Wiederherstellen.
 *
 * Schnittstellen: classes/ApiController.php (/api/v1/mediaorganizer/…), mit dem Admin-Token.
 */

const PREFS_KEY = 'mediaorganizer';
const EXIF_FELDER = [
    ['artist', 'Autor'],
    ['copyright', 'Copyright'],
    ['description', 'Beschreibung (EXIF)'],
    ['datetime', 'Aufnahmedatum'],
];
const META_FELDER = [
    ['title', 'Titel'],
    ['alt', 'Alt-Text'],
    ['caption', 'Beschriftung'],
];
const SPALTEN = [
    ['file', 'Name'], ['ordner', 'Ordner'], ['aufnahme', 'Aufnahme'], ['mtime', 'Geändert'],
    ['pixel', 'Abmessungen'], ['size', 'Grösse'], ['kamera', 'Kamera'], ['artist', 'Autor'], ['title', 'Titel'],
];

class Mediaorganizer extends HTMLElement {
    #tree = null;
    #items = [];
    #sel = new Set();
    #anchor = null;
    #focus = null;
    #prefs = { view: 'galerie', rekursiv: true, sort: 'aufnahme', dir: 'desc', size: 170, treeZu: false, offen: [] };
    #filter = '';
    #thumbs = new Map();
    #queue = [];
    #laufend = 0;
    #observer = null;
    #viewer = null;
    #onPop = null;
    #onKey = null;

    connectedCallback() {
        try { Object.assign(this.#prefs, JSON.parse(localStorage.getItem(PREFS_KEY) || '{}')); } catch { /* egal */ }
        // Ablage: medien (Mediathek), seiten (Seitenmedien) oder verlauf
        const u = new URLSearchParams(location.search).get('ansicht');
        this._quelle = ['medien', 'seiten', 'verlauf'].includes(u) ? u : 'medien';
        this.attachShadow({ mode: 'open' });
        this._shell();
        this.#observer = new IntersectionObserver(entries => {
            for (const e of entries) {
                if (e.isIntersecting) {
                    this.#observer.unobserve(e.target);
                    this._thumbLaden(e.target);
                }
            }
        }, { root: this.$('.main'), rootMargin: '300px' });
        this.#onPop = () => {
            const u = new URLSearchParams(location.search).get('ansicht') || 'medien';
            if (u !== this._quelle) { this._quelle = u; this._ansichtZeigen(false); } else if (u !== 'verlauf') this._ordnerLaden(this._ordnerAusUrl(), false);
        };
        window.addEventListener('popstate', this.#onPop);
        this.#onKey = ev => this._taste(ev);
        this.shadowRoot.addEventListener('keydown', this.#onKey);
        this._ansichtZeigen(false);
    }

    disconnectedCallback() {
        window.removeEventListener('popstate', this.#onPop);
        this.#observer?.disconnect();
        this._viewerZu();
    }

    /* ---------- Hilfen ---------- */

    $(s) { return this.shadowRoot.querySelector(s); }
    $$(s) { return [...this.shadowRoot.querySelectorAll(s)]; }
    esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
    mb(b) { return b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`; }
    exifDatum(s) {
        const m = /^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2})/.exec(s || '');
        return m ? `${+m[3]}.${+m[2]}.${m[1]} ${m[4]}:${m[5]}` : '';
    }
    unixDatum(t) {
        const d = new Date(t * 1000);
        return `${d.getDate()}.${d.getMonth() + 1}.${d.getFullYear()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    }
    aufnahmeKey(i) {
        const m = /^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/.exec(i.exif?.datetime || '');
        return m ? Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]) / 1000 : i.mtime;
    }
    relOrdner(o) {
        const basis = this._ordner || '';
        if (!basis) return o;
        return o === basis ? '' : (o.startsWith(`${basis}/`) ? o.slice(basis.length + 1) : o);
    }
    _prefsSpeichern() { try { localStorage.setItem(PREFS_KEY, JSON.stringify(this.#prefs)); } catch { /* egal */ } }
    /** Meldung mit «Rückgängig» (eigener Toast, da mit Knopf) */
    _toastRueck(msg, id, fehler = false) {
        if (!id) return this._toast(msg, fehler);
        const el = this.$('.toast');
        el.innerHTML = '';
        el.append(document.createTextNode(msg + ' '));
        const b = document.createElement('button');
        b.className = 'toastknopf'; b.textContent = 'Rückgängig';
        b.addEventListener('click', () => { el.className = 'toast'; this._rueckgaengig(id); });
        el.append(b);
        el.className = `toast zeigen klickbar${fehler ? ' fehler' : ''}`;
        clearTimeout(this._tt);
        this._tt = setTimeout(() => { el.className = 'toast'; }, 9000);
    }

    _toast(msg, fehler = false) {
        const t = window.__GRAV_TOAST;
        if (t) { fehler ? t.error(msg) : t.success(msg); return; }
        const el = this.$('.toast');
        el.textContent = msg;
        el.className = `toast zeigen${fehler ? ' fehler' : ''}`;
        clearTimeout(this._tt);
        this._tt = setTimeout(() => { el.className = 'toast'; }, 3500);
    }

    get _ablage() { return this._quelle === 'seiten' ? 'seiten' : 'medien'; }

    async _api(method, path, { params = {}, body = null, blob = false, quelle = null } = {}) {
        const base = window.__GRAV_API_SERVER_URL || '';
        const prefix = window.__GRAV_API_PREFIX || '/api/v1';
        const ablage = quelle || this._ablage;
        if (method === 'GET') params = { quelle: ablage, ...params };
        else if (body) body = { quelle: ablage, ...body };
        const q = new URLSearchParams(params).toString();
        const headers = { Accept: blob ? '*/*' : 'application/json' };
        if (window.__GRAV_API_TOKEN) headers['X-API-Token'] = window.__GRAV_API_TOKEN;
        if (body) headers['Content-Type'] = 'application/json';
        const resp = await fetch(`${base}${prefix}/mediaorganizer${path}${q ? `?${q}` : ''}`,
            { method, headers, body: body ? JSON.stringify(body) : undefined });
        if (!resp.ok) {
            const j = await resp.json().catch(() => ({}));
            throw new Error(j.detail || j.title || `Fehler ${resp.status}`);
        }
        if (blob) return resp.blob();
        const j = await resp.json();
        return j.data !== undefined ? j.data : j;
    }

    _speichernAls(blob, name) {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 30000);
    }

    _ordnerAusUrl() { return new URLSearchParams(location.search).get('ordner') || ''; }

    /* ---------- Gerüst ---------- */

    _shell() {
        this.shadowRoot.innerHTML = `
<style>
:host { display:block; height: calc(100vh - 4rem); min-height: 520px; color: var(--foreground, inherit); font-size: .8125rem; --rand: var(--border, rgba(127,127,127,.28)); --leise: var(--muted-foreground, #6b7280); --akzent: var(--primary, #1a926e); --flaeche: var(--muted, rgba(127,127,127,.08)); }
* { box-sizing: border-box; }
button, input, select, textarea { font: inherit; color: inherit; }
.app { display: grid; grid-template-rows: auto 1fr; height: 100%; }
.bar { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; padding: .6rem .9rem; border-bottom: 1px solid var(--rand); }
.bar h1 { font-size: 1rem; font-weight: 700; margin: 0 .5rem 0 0; }
.bar .rechts { margin-left: auto; display: flex; gap: .5rem; align-items: center; }
.seg { display: inline-flex; border: 1px solid var(--rand); border-radius: .4rem; overflow: hidden; }
.seg button { border: 0; background: transparent; padding: .3rem .65rem; cursor: pointer; }
.seg button.an { background: var(--akzent); color: var(--primary-foreground, #fff); }
.bar input[type=search] { padding: .3rem .55rem; border: 1px solid var(--rand); border-radius: .4rem; background: transparent; width: 13rem; }
.bar select { padding: .28rem .4rem; border: 1px solid var(--rand); border-radius: .4rem; background: transparent; }
.bar label { display: inline-flex; gap: .3rem; align-items: center; cursor: pointer; }
.leise { color: var(--leise); }
.body { display: grid; grid-template-columns: var(--baum, 240px) 1fr 360px; min-height: 0; }
.body.baumzu { --baum: 34px; }
.baum { border-right: 1px solid var(--rand); overflow: auto; padding: .4rem 0; min-height: 0; }
.baumkopf { display: flex; justify-content: space-between; align-items: center; padding: 0 .5rem .3rem .7rem; }
.baumzu .baum .knoten, .baumzu .baum .titel { display: none; }
.knoten { display: flex; align-items: center; gap: .15rem; padding: .18rem .5rem .18rem 0; cursor: pointer; white-space: nowrap; border-radius: .3rem; margin: 0 .3rem; }
.knoten:hover { background: var(--flaeche); }
.knoten.an { background: color-mix(in srgb, var(--akzent) 16%, transparent); color: var(--akzent); font-weight: 600; }
.knoten .pf { width: 1.1rem; text-align: center; color: var(--leise); flex: none; font-size: .7rem; }
.knoten .n { margin-left: auto; padding-left: .5rem; color: var(--leise); font-size: .72rem; font-weight: 400; }
.knoten .name { overflow: hidden; text-overflow: ellipsis; }
.icon { border: 0; background: transparent; cursor: pointer; padding: .2rem .35rem; border-radius: .3rem; color: var(--leise); }
.icon:hover { background: var(--flaeche); color: inherit; }
.main { overflow: auto; min-height: 0; outline: none; position: relative; }
.pfad { padding: .55rem .9rem; display: flex; gap: .35rem; flex-wrap: wrap; align-items: center; position: sticky; top: 0; background: var(--background, #fff); z-index: 2; border-bottom: 1px solid var(--rand); }
.pfad a { color: var(--akzent); cursor: pointer; }
.raster { display: grid; grid-template-columns: repeat(auto-fill, minmax(var(--gr, 170px), 1fr)); gap: .6rem; padding: .8rem .9rem 2rem; }
.kachel { border-radius: .45rem; overflow: hidden; border: 2px solid transparent; cursor: pointer; background: var(--flaeche); user-select: none; position: relative; }
.kachel.sel { border-color: var(--akzent); }
.kachel.fokus { outline: 2px dashed var(--akzent); outline-offset: 1px; }
.kachel img { width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; background: rgba(127,127,127,.15); }
.kachel img.svg, td img.svg, .detail img.svg { object-fit: contain; background: #fff; padding: .4rem; }
.typkachel { width: 100%; aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.15rem; letter-spacing: .04em; color: var(--leise); background: rgba(127,127,127,.12); }
.typkachel[data-typ=pdf] { color: #b91c1c; background: color-mix(in srgb, #b91c1c 9%, transparent); }
.typkachel.klein { width: 52px; height: 39px; aspect-ratio: auto; font-size: .62rem; border-radius: .25rem; }
.kachel .cap { padding: .3rem .45rem .35rem; font-size: .72rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kachel .cap small { display: block; color: var(--leise); }
.kachel .haken { position: absolute; top: .35rem; left: .35rem; width: 1.15rem; height: 1.15rem; border-radius: 50%; background: var(--akzent); color: #fff; font-size: .7rem; display: none; align-items: center; justify-content: center; }
.kachel.sel .haken { display: flex; }
.kachel .bk { position: absolute; top: .35rem; right: .35rem; font-size: .62rem; padding: .05rem .3rem; border-radius: .25rem; background: rgba(0,0,0,.55); color: #fff; }
table { border-collapse: collapse; width: 100%; }
th, td { text-align: left; padding: .3rem .55rem; border-bottom: 1px solid var(--rand); white-space: nowrap; }
th { position: sticky; top: 2.45rem; background: var(--background, #fff); cursor: pointer; font-weight: 600; z-index: 1; }
th .pf { color: var(--akzent); }
td.num, th.num { text-align: right; }
tr.zeile { cursor: pointer; user-select: none; }
tr.zeile:hover { background: var(--flaeche); }
tr.zeile.sel { background: color-mix(in srgb, var(--akzent) 14%, transparent); }
tr.zeile.fokus td:first-child { box-shadow: inset 3px 0 0 var(--akzent); }
td img { width: 52px; height: 39px; object-fit: cover; border-radius: .25rem; display: block; background: rgba(127,127,127,.15); }
.leer { padding: 2rem; color: var(--leise); }
.detail { border-left: 1px solid var(--rand); overflow: auto; min-height: 0; padding: .8rem .9rem 2rem; }
.detail .gross { width: 100%; max-height: 260px; object-fit: contain; background: rgba(127,127,127,.12); border-radius: .4rem; display: block; cursor: zoom-in; }
.detail h2 { font-size: .95rem; margin: .7rem 0 .15rem; word-break: break-all; }
.detail h3 { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: var(--leise); margin: 1.1rem 0 .4rem; }
dl { display: grid; grid-template-columns: 7.2rem 1fr; gap: .2rem .6rem; margin: 0; }
dt { color: var(--leise); }
dd { margin: 0; word-break: break-word; }
.feld { display: grid; grid-template-columns: 1.1rem 7rem 1fr; gap: .3rem; align-items: center; margin-bottom: .35rem; }
.feld.einzeln { grid-template-columns: 7.3rem 1fr; }
.feld input[type=text], .feld textarea { width: 100%; padding: .3rem .45rem; border: 1px solid var(--rand); border-radius: .35rem; background: transparent; }
.feld textarea { resize: vertical; min-height: 2.2rem; }
.knoepfe { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .6rem; }
.btn { font-weight: 600; padding: .35rem .7rem; border-radius: .4rem; cursor: pointer; border: 1px solid var(--akzent); background: transparent; color: var(--akzent); }
.btn:hover:not(:disabled) { background: var(--akzent); color: var(--primary-foreground, #fff); }
.btn.voll { background: var(--akzent); color: var(--primary-foreground, #fff); }
.btn.warn { border-color: #b45309; color: #b45309; }
.btn.warn:hover:not(:disabled) { background: #b45309; color: #fff; }
.btn:disabled { opacity: .45; cursor: default; }
details summary { cursor: pointer; color: var(--akzent); margin: .8rem 0 .4rem; }
.exifliste { font-size: .74rem; }
.exifliste dt { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hinweis { font-size: .74rem; color: var(--leise); margin: .3rem 0 0; }
.dialog { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 50; }
.dialog .box { background: var(--background, #fff); color: var(--foreground, inherit); border-radius: .6rem; padding: 1.1rem 1.2rem; width: min(30rem, 92vw); box-shadow: 0 10px 40px rgba(0,0,0,.3); }
.dialog h3 { margin: 0 0 .7rem; font-size: 1rem; text-transform: none; letter-spacing: 0; color: inherit; }
.dialog label { display: grid; grid-template-columns: 9rem 1fr; gap: .5rem; align-items: center; margin-bottom: .5rem; }
.dialog select { padding: .3rem; border: 1px solid var(--rand); border-radius: .35rem; background: transparent; }
.viewer { position: fixed; inset: 0; background: #0b0b0b; z-index: 60; overflow: hidden; color: #eee; cursor: grab; user-select: none; }
.viewer.zieht { cursor: grabbing; }
.viewer img { position: absolute; left: 0; top: 0; transform-origin: 0 0; max-width: none; image-rendering: auto; }
.viewer .vk { position: absolute; left: 0; right: 0; top: 0; display: flex; gap: .5rem; align-items: center; padding: .55rem .8rem; background: linear-gradient(rgba(0,0,0,.7), transparent); font-size: .8rem; }
.viewer .vk .titel { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.viewer .vk .r { margin-left: auto; display: flex; gap: .3rem; }
.viewer button { background: rgba(255,255,255,.12); border: 0; color: #fff; border-radius: .35rem; padding: .3rem .6rem; cursor: pointer; }
.viewer button:hover { background: rgba(255,255,255,.25); }
.viewer .nav { position: absolute; top: 50%; transform: translateY(-50%); font-size: 1.6rem; padding: .6rem .8rem; }
.viewer .nav.l { left: .6rem; } .viewer .nav.r { right: .6rem; }
.viewer .status { position: absolute; bottom: .6rem; left: 50%; transform: translateX(-50%); font-size: .75rem; background: rgba(0,0,0,.55); padding: .2rem .6rem; border-radius: .3rem; }
.toast { position: fixed; bottom: 1rem; right: 1rem; background: #1f2937; color: #fff; padding: .55rem .9rem; border-radius: .45rem; opacity: 0; transition: opacity .2s; pointer-events: none; z-index: 70; }
.toast.zeigen { opacity: 1; } .toast.fehler { background: #b91c1c; }
.toast.klickbar { pointer-events: auto; }
.toastknopf { margin-left: .6rem; background: rgba(255,255,255,.2); border: 0; color: #fff; padding: .2rem .55rem; border-radius: .3rem; cursor: pointer; font-weight: 600; }
.seg.tabs button { font-weight: 600; }
.app.verlaufmodus .nurdateien, .app.verlaufmodus .baum, .app.verlaufmodus .detail,
.app.verlaufmodus .bar label:has(.rek), .app.verlaufmodus .suche, .app.verlaufmodus .sort, .app.verlaufmodus .groesse, .app.verlaufmodus .alle { display: none !important; }
.app.verlaufmodus .body { grid-template-columns: 1fr; }
.kachel .vw { position: absolute; bottom: 2.6rem; right: .35rem; font-size: .62rem; padding: .05rem .3rem; border-radius: .25rem; background: #b45309; color: #fff; }
.verweisliste { font-size: .74rem; margin: 0; padding-left: 1rem; }
.verweisliste li { margin: .15rem 0; word-break: break-word; }
.verweisliste code { font-size: .7rem; color: var(--leise); }
.vtab td { white-space: normal; vertical-align: top; }
.vtab .status-rueckgaengig { color: var(--leise); text-decoration: line-through; }
.vtab .dateiliste { font-size: .72rem; color: var(--leise); }
@media (max-width: 1100px) { .body { grid-template-columns: var(--baum, 220px) 1fr; } .detail { grid-column: 1 / -1; border-left: 0; border-top: 1px solid var(--rand); max-height: 45vh; } }
</style>
<div class="app">
  <div class="bar">
    <h1 class="seitentitel">Medienarchiv</h1>
    <span class="seg tabs"><button data-quelle="medien" title="Mediathek (user/media)">Mediathek</button><button data-quelle="seiten" title="Dateien in den Seitenordnern (user/pages)">Seitenmedien</button><button data-quelle="verlauf" title="Alle Änderungen mit Sicherung, rückgängig machen">Verlauf</button></span>
    <span class="seg nurdateien"><button data-view="galerie">Galerie</button><button data-view="liste">Liste</button></span>
    <label title="Dateien aus allen Unterordnern mit anzeigen"><input type="checkbox" class="rek"> inkl. Unterordner</label>
    <input type="search" class="suche" placeholder="Suchen (Name, Titel, Typ, Autor)">
    <select class="sort" title="Sortierung">
      <option value="aufnahme:desc">Aufnahme, neueste zuerst</option><option value="aufnahme:asc">Aufnahme, älteste zuerst</option>
      <option value="file:asc">Name A–Z</option><option value="size:desc">Grösse, grösste zuerst</option>
      <option value="pixel:desc">Pixel, meiste zuerst</option><option value="mtime:desc">Geändert, neueste zuerst</option><option value="typ:asc">Typ</option>
    </select>
    <input type="range" class="groesse" min="110" max="320" step="10" title="Vorschaugrösse">
    <span class="rechts"><span class="anzahl leise"></span><button class="btn alle">Alle auswählen</button></span>
  </div>
  <div class="body">
    <aside class="baum"><div class="baumkopf"><span class="titel leise">Ordner</span><button class="icon baumknopf" title="Ordnerbaum ein-/ausklappen">⇤</button></div><div class="baumliste"></div></aside>
    <section class="main" tabindex="0"><div class="pfad"></div><div class="inhalt"><p class="leer">Wird geladen…</p></div></section>
    <aside class="detail"></aside>
  </div>
</div>
<div class="toast"></div>`;
        this.$$('[data-view]').forEach(b => b.addEventListener('click', () => { this.#prefs.view = b.dataset.view; this._prefsSpeichern(); this._mitteZeichnen(); }));
        this.$$('[data-quelle]').forEach(b => b.addEventListener('click', () => { if (b.dataset.quelle !== this._quelle) { this._quelle = b.dataset.quelle; this._ansichtZeigen(true); } }));
        const rek = this.$('.rek');
        rek.checked = this.#prefs.rekursiv;
        rek.addEventListener('change', () => { this.#prefs.rekursiv = rek.checked; this._prefsSpeichern(); this._ordnerLaden(this._ordner, false); });
        this.$('.suche').addEventListener('input', ev => { this.#filter = ev.target.value.trim().toLowerCase(); this._mitteZeichnen(); });
        const sort = this.$('.sort');
        sort.value = `${this.#prefs.sort}:${this.#prefs.dir}`;
        if (!sort.value) sort.value = 'aufnahme:desc';
        sort.addEventListener('change', () => { [this.#prefs.sort, this.#prefs.dir] = sort.value.split(':'); this._prefsSpeichern(); this._mitteZeichnen(); });
        const gr = this.$('.groesse');
        gr.value = this.#prefs.size;
        this.style.setProperty('--gr', `${this.#prefs.size}px`);
        gr.addEventListener('input', () => { this.#prefs.size = +gr.value; this.style.setProperty('--gr', `${gr.value}px`); this._prefsSpeichern(); });
        this.$('.alle').addEventListener('click', () => this._alleWaehlen());
        this.$('.baumknopf').addEventListener('click', () => { this.#prefs.treeZu = !this.#prefs.treeZu; this._prefsSpeichern(); this._baumZeichnen(); });
    }

    /* ---------- Ablage / Verlauf ---------- */

    _ansichtZeigen(push) {
        this.$$('[data-quelle]').forEach(b => b.classList.toggle('an', b.dataset.quelle === this._quelle));
        this.$('.app').classList.toggle('verlaufmodus', this._quelle === 'verlauf');
        if (push) {
            const url = new URL(location.href);
            url.searchParams.delete('ordner');
            this._quelle === 'medien' ? url.searchParams.delete('ansicht') : url.searchParams.set('ansicht', this._quelle);
            history.pushState({}, '', url);
        }
        this.#sel.clear();
        this.#items = [];
        this.#thumbs.clear();
        if (this._quelle === 'verlauf') return this._verlaufLaden();
        this.#tree = null;
        this.#prefs.offen = [''];
        this._baumLaden();
        this._ordnerLaden(push ? '' : this._ordnerAusUrl(), false);
    }

    async _verlaufLaden() {
        this.$('.pfad').innerHTML = '<span class="leise">Verlauf: alle Änderungen, jeweils mit Sicherung der betroffenen Dateien</span>';
        this.$('.anzahl').textContent = '';
        const inhalt = this.$('.inhalt');
        inhalt.innerHTML = '<p class="leer">Wird geladen…</p>';
        let l;
        try { l = await this._api('GET', '/verlauf'); } catch (e) { inhalt.innerHTML = `<p class="leer">${this.esc(e.message)}</p>`; return; }
        this.$('.anzahl').textContent = `${l.length} Vorgänge`;
        if (!l.length) { inhalt.innerHTML = '<p class="leer">Noch keine Änderungen.</p>'; return; }
        const aktion = { konvertieren: 'Umgewandelt', automatisch: 'Automatisch umgewandelt', optimieren: 'Optimiert', umbenennen: 'Umbenannt', papierkorb: 'Papierkorb', kopieren: 'Kopiert', texte: 'Texte/EXIF', wiederherstellen: 'Original zurück', rueckgaengig: 'Rückgängig' };
        inhalt.innerHTML = `<table class="vtab"><thead><tr><th>Zeit</th><th>Vorgang</th><th>Ablage</th><th>Benutzer</th><th>Dateien</th><th></th></tr></thead><tbody>
            ${l.map(v => `<tr class="${v.status === 'rueckgaengig' ? 'status-rueckgaengig' : ''}">
              <td>${this.unixDatum(v.zeit)}</td>
              <td><b>${this.esc(aktion[v.aktion] || v.aktion)}</b><br>${this.esc(v.text)}</td>
              <td>${v.quelle === 'seiten' ? 'Seiten' : 'Mediathek'}</td>
              <td>${this.esc(v.benutzer)}</td>
              <td><details><summary>${v.anzahl} ${v.anzahl === 1 ? 'Datei' : 'Dateien'} · ${this.mb(v.groesse || 0)}</summary><div class="dateiliste">${v.dateien.map(d => `${this.esc(this._pfadAnzeige(d.datei))} <i>(${d.art})</i>`).join('<br>')}</div></details></td>
              <td>${v.status === 'aktiv' ? `<button class="btn rueck" data-id="${this.esc(v.id)}">Rückgängig</button>` : '<span class="leise">rückgängig gemacht</span>'}</td>
            </tr>`).join('')}</tbody></table>`;
        inhalt.querySelectorAll('.rueck').forEach(b => b.addEventListener('click', () => this._rueckgaengig(b.dataset.id)));
    }

    // «medien:a/b.jpg» → «Mediathek/a/b.jpg», «user:pages/…» → «Seiten/…»
    _pfadAnzeige(p) {
        return String(p).replace(/^medien:/, 'Mediathek/').replace(/^user:pages\//, 'Seiten/').replace(/^user:/, 'user/');
    }

    async _rueckgaengig(id, erzwingen = false) {
        try {
            const r = await this._api('POST', '/rueckgaengig', { body: { id, erzwingen } });
            if (!r.ok) {
                if (confirm(`Diese Dateien wurden seither erneut geändert:\n\n${r.konflikte.map(p => this._pfadAnzeige(p)).join('\n')}\n\nTrotzdem rückgängig machen? Die späteren Änderungen an diesen Dateien gehen dann verloren (sie werden aber ebenfalls gesichert).`)) return this._rueckgaengig(id, true);
                return;
            }
            this._toastRueck('Rückgängig gemacht.', r.id);
        } catch (e) { this._toast(e.message, true); }
        if (this._quelle === 'verlauf') this._verlaufLaden();
        else { this._baumLaden(); this._neuLaden([]); }
    }

    /* ---------- Ordnerbaum ---------- */

    async _baumLaden() {
        try {
            this.#tree = await this._api('GET', '/baum');
            this.$('.seitentitel').textContent = this.#tree.name;
            this._konv = this.#tree.konvertierung || null;
            this._wurzelName = this.#tree.name;
            if (!this.#prefs.offen.length) this.#prefs.offen = [''];
            this._baumZeichnen();
        } catch (e) { this.$('.baumliste').innerHTML = `<p class="leer">${this.esc(e.message)}</p>`; }
    }

    _baumZeichnen() {
        this.$('.body').classList.toggle('baumzu', !!this.#prefs.treeZu);
        this.$('.baumknopf').textContent = this.#prefs.treeZu ? '⇥' : '⇤';
        if (!this.#tree) return;
        const offen = new Set(this.#prefs.offen);
        const zeile = (k, tiefe) => {
            const hatKinder = k.kinder.length > 0;
            const auf = offen.has(k.path);
            let h = `<div class="knoten${k.path === this._ordner ? ' an' : ''}" data-pfad="${this.esc(k.path)}" style="padding-left:${tiefe * .9 + .2}rem">
                <span class="pf" data-auf="${this.esc(k.path)}">${hatKinder ? (auf ? '▾' : '▸') : ''}</span>
                <span class="name" title="${this.esc(k.ordner || k.name)}">${this.esc(k.name)}</span><span class="n">${k.total}</span></div>`;
            if (hatKinder && auf) h += k.kinder.map(c => zeile(c, tiefe + 1)).join('');
            return h;
        };
        const liste = this.$('.baumliste');
        liste.innerHTML = zeile(this.#tree, 0);
        liste.querySelectorAll('[data-auf]').forEach(el => el.addEventListener('click', ev => {
            ev.stopPropagation();
            const p = el.dataset.auf;
            offen.has(p) ? offen.delete(p) : offen.add(p);
            this.#prefs.offen = [...offen];
            this._prefsSpeichern();
            this._baumZeichnen();
        }));
        liste.querySelectorAll('.knoten').forEach(el => el.addEventListener('click', () => this._ordnerLaden(el.dataset.pfad, true)));
    }

    /* ---------- Bilder laden ---------- */

    async _ordnerLaden(ordner, push) {
        this._ordner = ordner;
        if (push) {
            const url = new URL(location.href);
            ordner ? url.searchParams.set('ordner', ordner) : url.searchParams.delete('ordner');
            history.pushState({}, '', url);
        }
        // Pfad im Baum aufklappen
        const offen = new Set(this.#prefs.offen);
        ordner.split('/').reduce((acc, teil) => { const p = acc ? `${acc}/${teil}` : teil; offen.add(p); return p; }, '');
        offen.add('');
        this.#prefs.offen = [...offen];
        this._baumZeichnen();
        this._pfadZeichnen();
        this.$('.inhalt').innerHTML = '<p class="leer">Wird geladen…</p>';
        try {
            this.#items = await this._api('GET', '/dateien', { params: { ordner, rekursiv: this.#prefs.rekursiv ? 1 : '', verweise: 1 } });
        } catch (e) {
            this.#items = [];
            this.$('.inhalt').innerHTML = `<p class="leer">${this.esc(e.message)}</p>`;
            return;
        }
        this.#sel.clear();
        this.#anchor = this.#focus = null;
        this._mitteZeichnen();
        this._detailZeichnen();
    }

    _pfadZeichnen() {
        const teile = this._ordner ? this._ordner.split('/') : [];
        let acc = '';
        const h = [`<a data-pfad="">${this.esc(this._wurzelName || 'Medien')}</a>`].concat(teile.map(t => { acc = acc ? `${acc}/${t}` : t; return `<a data-pfad="${this.esc(acc)}">${this.esc(t)}</a>`; }));
        this.$('.pfad').innerHTML = h.join('<span class="leise">/</span>');
        this.$$('.pfad a').forEach(a => a.addEventListener('click', () => this._ordnerLaden(a.dataset.pfad, true)));
    }

    /* ---------- Mitte: Galerie / Liste ---------- */

    get _sichtbar() {
        const f = this.#filter;
        let l = this.#items;
        if (f) {
            l = l.filter(i => [i.file, i.ordner, i.title, i.caption, i.alt, i.ext, i.exif?.artist, i.exif?.kamera, i.exif?.description]
                .some(v => v && String(v).toLowerCase().includes(f)));
        }
        const k = this.#prefs.sort, d = this.#prefs.dir === 'asc' ? 1 : -1;
        const wert = i => ({
            aufnahme: this.aufnahmeKey(i), mtime: i.mtime, size: i.size, pixel: i.width * i.height,
            file: i.file.toLowerCase(), ordner: i.ordner.toLowerCase(), kamera: (i.exif?.kamera || '').toLowerCase(),
            artist: (i.exif?.artist || '').toLowerCase(), title: (i.title || '').toLowerCase(), typ: `${i.ext}`.toLowerCase(),
        }[k]);
        return [...l].sort((a, b) => { const x = wert(a), y = wert(b); return (x > y ? 1 : x < y ? -1 : 0) * d || a.path.localeCompare(b.path); });
    }

    _mitteZeichnen() {
        this.$$('[data-view]').forEach(b => b.classList.toggle('an', b.dataset.view === this.#prefs.view));
        this.$('.sort').style.display = this.#prefs.view === 'galerie' ? '' : 'none';
        this.$('.groesse').style.display = this.#prefs.view === 'galerie' ? '' : 'none';
        const l = this._sichtbar;
        this._liste = l;
        const summe = l.reduce((s, i) => s + i.size, 0);
        this.$('.anzahl').textContent = `${l.length} ${l.length === 1 ? 'Datei' : 'Dateien'} · ${this.mb(summe)}`;
        const inhalt = this.$('.inhalt');
        this.#observer.disconnect();
        this.#queue = [];
        if (!l.length) {
            inhalt.innerHTML = `<p class="leer">${this.#items.length ? 'Keine Treffer.' : 'Keine Dateien in diesem Ordner.'}</p>`;
            return;
        }
        if (this.#prefs.view === 'galerie') {
            inhalt.innerHTML = `<div class="raster">${l.map((i, n) => `
                <div class="kachel${this.#sel.has(i.path) ? ' sel' : ''}" data-i="${n}" title="${this.esc(i.path)}">
                  ${this._vorschauHtml(i, 360, 1)}
                  <span class="haken">✓</span>${i.backup ? '<span class="bk">optimiert</span>' : ''}${i.verweise === 0 ? '<span class="vw" title="Kein direkter Verweis in Seiten oder Konfiguration gefunden">kein Verweis</span>' : ''}
                  <div class="cap">${this.esc(i.title || i.file)}<small>${this.esc(this.#prefs.rekursiv && i.ordner !== this._ordner ? this.relOrdner(i.ordner) : (this.exifDatum(i.exif?.datetime) || this.unixDatum(i.mtime)))}</small></div>
                </div>`).join('')}</div>`;
        } else {
            const pf = k => this.#prefs.sort === k ? `<span class="pf">${this.#prefs.dir === 'asc' ? '▲' : '▼'}</span>` : '';
            inhalt.innerHTML = `<table><thead><tr><th></th>${SPALTEN.map(([k, t]) => `<th data-k="${k}" class="${['size', 'pixel'].includes(k) ? 'num' : ''}">${t} ${pf(k)}</th>`).join('')}</tr></thead><tbody>
                ${l.map((i, n) => `<tr class="zeile${this.#sel.has(i.path) ? ' sel' : ''}" data-i="${n}">
                  <td>${this._vorschauHtml(i, 120, 1, true)}</td>
                  <td>${this.esc(i.file)}</td><td class="leise">${this.esc(this.relOrdner(i.ordner))}</td>
                  <td>${this.esc(this.exifDatum(i.exif?.datetime))}</td><td class="leise">${this.unixDatum(i.mtime)}</td>
                  <td class="num">${i.width ? `${i.width} × ${i.height}` : this.esc(i.ext)}</td><td class="num">${this.mb(i.size)}</td>
                  <td>${this.esc(i.exif?.kamera || '')}</td><td>${this.esc(i.exif?.artist || '')}</td><td>${this.esc(i.title || '')}</td>
                </tr>`).join('')}</tbody></table>`;
            inhalt.querySelectorAll('th[data-k]').forEach(th => th.addEventListener('click', () => {
                const k = th.dataset.k;
                if (this.#prefs.sort === k) this.#prefs.dir = this.#prefs.dir === 'asc' ? 'desc' : 'asc';
                else { this.#prefs.sort = k; this.#prefs.dir = ['aufnahme', 'mtime', 'size', 'pixel'].includes(k) ? 'desc' : 'asc'; }
                this.$('.sort').value = `${this.#prefs.sort}:${this.#prefs.dir}`;
                this._prefsSpeichern();
                this._mitteZeichnen();
            }));
        }
        inhalt.querySelectorAll('[data-i]').forEach(el => {
            el.addEventListener('click', ev => this._klick(+el.dataset.i, ev));
            el.addEventListener('dblclick', () => this._viewerAuf(+el.dataset.i));
        });
        inhalt.querySelectorAll('img[data-pfad], img[data-svg]').forEach(img => {
            const key = img.dataset.svg ? `svg|${img.dataset.svg}` : `${img.dataset.pfad}|${img.dataset.w}|${img.dataset.crop}`;
            if (this.#thumbs.has(key)) img.src = this.#thumbs.get(key);
            else this.#observer.observe(img);
        });
        this._auswahlMarkieren();
    }

    /** EXIF schreibbar (WebP, JPEG)? */
    _exifOk(i) { return /\.(webp|jpe?g)$/i.test(i.file); }
    /** Ins Zielformat umwandelbar? */
    _konvOk(i) {
        const k = this._konv;
        if (!k || !k.verfuegbar || i.typ !== 'bild') return false;
        const ext = i.file.split('.').pop().toLowerCase().replace('jpeg', 'jpg');
        return ext !== k.ziel && (k.quellen || []).map(q => String(q).toLowerCase().replace('jpeg', 'jpg')).includes(ext);
    }

    /** Vorschau: Rasterbild (verkleinert), SVG (Datei als Blob) oder Typ-Kachel */
    _vorschauHtml(i, w, crop, klein = false) {
        if (i.typ === 'bild') return `<img data-pfad="${this.esc(i.path)}" data-w="${w}" data-crop="${crop}" alt="">`;
        if (i.typ === 'svg') return `<img class="svg" data-svg="${this.esc(i.path)}" alt="">`;
        return `<span class="typkachel${klein ? ' klein' : ''}" data-typ="${this.esc(i.typ)}">${this.esc(i.ext || '?')}</span>`;
    }

    _thumbLaden(img) {
        this.#queue.push(img);
        this._queueWeiter();
    }

    _queueWeiter() {
        while (this.#laufend < 3 && this.#queue.length) {
            const img = this.#queue.shift();
            if (!img.isConnected) continue;
            const svg = img.dataset.svg;
            const key = svg ? `svg|${svg}` : `${img.dataset.pfad}|${img.dataset.w}|${img.dataset.crop}`;
            this.#laufend++;
            // SVG: Datei selbst (geschuetzt) als Blob laden
            const holen = svg
                ? this._api('GET', '/datei', { params: { pfad: svg }, blob: true }).then(b => ({ url: URL.createObjectURL(b) }))
                : this._api('GET', '/vorschau', { params: { pfad: img.dataset.pfad, w: img.dataset.w, crop: img.dataset.crop } });
            holen
                .then(r => { this.#thumbs.set(key, r.url); img.src = r.url; })
                .catch(() => { img.alt = '⚠'; })
                .finally(() => { this.#laufend--; this._queueWeiter(); });
        }
    }

    /* ---------- Auswahl ---------- */

    _klick(n, ev) {
        const i = this._liste[n];
        if (ev.shiftKey && this.#anchor !== null) {
            const [a, b] = [Math.min(this.#anchor, n), Math.max(this.#anchor, n)];
            if (!(ev.ctrlKey || ev.metaKey)) this.#sel.clear();
            for (let k = a; k <= b; k++) this.#sel.add(this._liste[k].path);
        } else if (ev.ctrlKey || ev.metaKey) {
            this.#sel.has(i.path) ? this.#sel.delete(i.path) : this.#sel.add(i.path);
            this.#anchor = n;
        } else {
            this.#sel.clear();
            this.#sel.add(i.path);
            this.#anchor = n;
        }
        this.#focus = n;
        this._auswahlMarkieren();
        this._detailZeichnen();
    }

    _alleWaehlen() {
        const alle = this._liste.every(i => this.#sel.has(i.path));
        this.#sel.clear();
        if (!alle) this._liste.forEach(i => this.#sel.add(i.path));
        this._auswahlMarkieren();
        this._detailZeichnen();
    }

    _auswahlMarkieren() {
        this.$$('.inhalt [data-i]').forEach(el => {
            const n = +el.dataset.i;
            el.classList.toggle('sel', this.#sel.has(this._liste[n]?.path));
            el.classList.toggle('fokus', n === this.#focus);
        });
        this.$('.alle').textContent = this._liste?.length && this._liste.every(i => this.#sel.has(i.path)) ? 'Auswahl aufheben' : 'Alle auswählen';
    }

    _taste(ev) {
        if (this.#viewer) return this._viewerTaste(ev);
        if (ev.target.closest('input, textarea, select')) return;
        const l = this._liste || [];
        if (!l.length) return;
        let n = this.#focus ?? -1;
        const spalten = this.#prefs.view === 'galerie'
            ? Math.max(1, Math.round(this.$('.raster')?.clientWidth / (this.$('.kachel')?.offsetWidth + 9.6) || 1)) : 1;
        const schritt = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: spalten, ArrowUp: -spalten }[ev.key];
        if (schritt) {
            ev.preventDefault();
            n = Math.max(0, Math.min(l.length - 1, n < 0 ? 0 : n + (this.#prefs.view === 'liste' && Math.abs(schritt) === 1 && ['ArrowLeft', 'ArrowRight'].includes(ev.key) ? 0 : schritt)));
            this._klick(n, { shiftKey: ev.shiftKey, ctrlKey: false, metaKey: false });
            this.$(`.inhalt [data-i="${n}"]`)?.scrollIntoView({ block: 'nearest' });
        } else if ((ev.key === 'Enter' || ev.key === ' ') && n >= 0) {
            ev.preventDefault();
            this._viewerAuf(n);
        } else if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'a') {
            ev.preventDefault();
            this.#sel = new Set(l.map(i => i.path));
            this._auswahlMarkieren();
            this._detailZeichnen();
        } else if (ev.key === 'Escape') {
            this.#sel.clear();
            this._auswahlMarkieren();
            this._detailZeichnen();
        }
    }

    get _gewaehlt() { return this.#items.filter(i => this.#sel.has(i.path)); }

    /* ---------- Detail ---------- */

    _detailZeichnen() {
        const d = this.$('.detail');
        const g = this._gewaehlt;
        if (!g.length) {
            const l = this._liste || [];
            d.innerHTML = `<h3>${this.esc(this._wurzelName || 'Medien')}</h3><p class="leise">Datei anklicken für Details. Strg/⌘-Klick oder Umschalt-Klick wählt mehrere Dateien für Massenbearbeitung, ZIP oder Optimierung. Doppelklick oder Enter öffnet ein Bild im Vollbild bzw. lädt eine andere Datei herunter.</p>
                ${l.length ? `<div class="knoepfe"><button class="btn zipordner">Ordner als ZIP${this.#prefs.rekursiv ? ' (inkl. Unterordner)' : ''}</button></div>` : ''}`;
            d.querySelector('.zipordner')?.addEventListener('click', ev => this._zip(this.#prefs.rekursiv ? null : l.map(i => i.path), ev.target));
            return;
        }
        if (g.length === 1) return g[0].typ === 'bild' ? this._detailEinzeln(g[0]) : this._detailDatei(g[0]);
        this._detailMehrere(g);
    }

    _detailEinzeln(i) {
        const d = this.$('.detail');
        const x = i.exif || {};
        const key = `${i.path}|900|`;
        d.innerHTML = `
            <img class="gross" alt="" title="Doppelklick: Vollbild">
            <h2>${this.esc(i.title || i.file)}</h2>
            <p class="leise" style="margin:0">${this.esc(i.path)}</p>
            <h3>Eigenschaften</h3>
            <dl>
              <dt>Abmessungen</dt><dd>${i.width} × ${i.height} px (${(i.width * i.height / 1e6).toFixed(1)} MP)</dd>
              <dt>Dateigrösse</dt><dd>${this.mb(i.size)}${i.backup ? ` <span class="leise">(Original: ${this.mb(i.backupSize)})</span>` : ''}</dd>
              <dt>Aufnahme</dt><dd>${this.esc(this.exifDatum(x.datetime) || '–')}</dd>
              <dt>Geändert</dt><dd>${this.unixDatum(i.mtime)}</dd>
              ${x.kamera ? `<dt>Kamera</dt><dd>${this.esc(x.kamera)}</dd>` : ''}
              ${x.objektiv ? `<dt>Objektiv</dt><dd>${this.esc(x.objektiv)}</dd>` : ''}
              ${x.belichtung || x.blende || x.iso ? `<dt>Belichtung</dt><dd>${this.esc([x.belichtung, x.blende, x.iso ? `ISO ${x.iso}` : '', x.brennweite].filter(Boolean).join(' · '))}</dd>` : ''}
              ${x.gps ? `<dt>Ort</dt><dd><a href="https://map.geo.admin.ch/?swisssearch=${x.gps[0]},${x.gps[1]}&zoom=10" target="_blank" rel="noopener">${x.gps[0]}, ${x.gps[1]}</a></dd>` : ''}
            </dl>
            <h3>Mediathek-Texte</h3>
            ${META_FELDER.map(([k, t]) => `<div class="feld einzeln"><label for="f-${k}">${t}</label>${k === 'caption' ? `<textarea id="f-${k}" data-f="${k}">${this.esc(i[k] || '')}</textarea>` : `<input type="text" id="f-${k}" data-f="${k}" value="${this.esc(i[k] || '')}">`}</div>`).join('')}
            <h3>EXIF</h3>
            ${this._exifOk(i) ? EXIF_FELDER.map(([k, t]) => `<div class="feld einzeln"><label for="f-${k}">${t}</label><input type="text" id="f-${k}" data-f="${k}" value="${this.esc(x[k] || '')}" ${k === 'datetime' ? 'placeholder="JJJJ:MM:TT hh:mm:ss"' : ''}></div>`).join('') : `<p class="hinweis">EXIF lässt sich bei ${this.esc(i.ext)} nicht bearbeiten (nur WebP und JPEG)${this._konvOk(i) ? ' – nach dem Konvertieren schon' : ''}.</p>`}
            <div class="knoepfe"><button class="btn voll speichern" disabled>Speichern</button></div>
            <details class="allexif"><summary>Alle EXIF-Daten</summary><div class="exifliste leise">Wird geladen…</div></details>
            <h3>Aktionen</h3>
            <div class="knoepfe">
              <button class="btn dl">Original herunterladen</button>
              <button class="btn opt">Optimieren…</button>
              ${this._konvOk(i) ? `<button class="btn konv">In ${this._konv.ziel.toUpperCase()} umwandeln</button>` : ''}
              ${i.backup ? '<button class="btn warn rest">Original wiederherstellen</button>' : ''}
              ${this._orgKnoepfe()}
            </div>
            ${this._verweisAbschnitt()}
            ${i.backup ? '<p class="hinweis">Das Original vor der Optimierung liegt gesichert im Unterordner _original.</p>' : ''}`;
        const img = d.querySelector('.gross');
        const setze = url => { img.src = url; };
        if (this.#thumbs.has(key)) setze(this.#thumbs.get(key));
        else this._api('GET', '/vorschau', { params: { pfad: i.path, w: 900 } }).then(r => { this.#thumbs.set(key, r.url); if (img.isConnected) setze(r.url); }).catch(() => {});
        img.addEventListener('dblclick', () => this._viewerAuf(this._liste.findIndex(v => v.path === i.path)));
        img.addEventListener('click', () => this._viewerAuf(this._liste.findIndex(v => v.path === i.path)));
        const knopf = d.querySelector('.speichern');
        const start = {};
        d.querySelectorAll('[data-f]').forEach(el => {
            start[el.dataset.f] = el.value;
            el.addEventListener('input', () => { knopf.disabled = [...d.querySelectorAll('[data-f]')].every(e => e.value === start[e.dataset.f]); });
        });
        knopf.addEventListener('click', async () => {
            const felder = {};
            d.querySelectorAll('[data-f]').forEach(el => { if (el.value !== start[el.dataset.f]) felder[el.dataset.f] = el.value.trim(); });
            await this._metaSpeichern([i.path], felder, knopf);
        });
        d.querySelector('.allexif').addEventListener('toggle', async ev => {
            if (!ev.target.open || ev.target.dataset.geladen) return;
            ev.target.dataset.geladen = '1';
            const box = d.querySelector('.exifliste');
            try {
                const r = await this._api('GET', '/exif', { params: { pfad: i.path } });
                box.innerHTML = r.tags.length
                    ? `<dl>${r.tags.map(t => `<dt title="${this.esc(t.gruppe)} ${t.tag}">${this.esc(t.name || t.tag)}</dt><dd>${this.esc(t.wert)}</dd>`).join('')}</dl>`
                    : 'Keine EXIF-Daten in dieser Datei.';
            } catch (e) { box.textContent = e.message; }
        });
        d.querySelector('.dl').addEventListener('click', ev => this._herunterladen(i, ev.target));
        d.querySelector('.opt').addEventListener('click', () => this._optimierenDialog([i]));
        d.querySelector('.konv')?.addEventListener('click', ev => this._konvertieren([i], ev.target));
        this._orgBinden(d, [i]);
        d.querySelector('.rest')?.addEventListener('click', () => this._wiederherstellen([i]));
    }

    /** Detail fuer Nicht-Rasterbilder (SVG, PDF, Office, …): Vorschau, Texte, Herunterladen */
    _detailDatei(i) {
        const d = this.$('.detail');
        d.innerHTML = `
            ${i.typ === 'svg' ? '<img class="gross svg" alt="">' : this._vorschauHtml(i, 0, 0)}
            <h2>${this.esc(i.title || i.file)}</h2>
            <p class="leise" style="margin:0">${this.esc(i.path)}</p>
            <h3>Eigenschaften</h3>
            <dl><dt>Typ</dt><dd>${this.esc(i.ext)}</dd><dt>Dateigrösse</dt><dd>${this.mb(i.size)}</dd><dt>Geändert</dt><dd>${this.unixDatum(i.mtime)}</dd></dl>
            <h3>Mediathek-Texte</h3>
            ${META_FELDER.map(([k, t]) => `<div class="feld einzeln"><label for="f-${k}">${t}</label>${k === 'caption' ? `<textarea id="f-${k}" data-f="${k}">${this.esc(i[k] || '')}</textarea>` : `<input type="text" id="f-${k}" data-f="${k}" value="${this.esc(i[k] || '')}">`}</div>`).join('')}
            <div class="knoepfe"><button class="btn voll speichern" disabled>Speichern</button></div>
            <h3>Aktionen</h3>
            <div class="knoepfe"><button class="btn dl">Herunterladen</button>${i.typ === 'pdf' || i.typ === 'svg' ? '<button class="btn oeffnen">Im Browser öffnen</button>' : ''}${this._orgKnoepfe()}</div>
            ${this._verweisAbschnitt()}`;
        if (i.typ === 'svg') {
            const img = d.querySelector('.gross');
            const key = `svg|${i.path}`;
            if (this.#thumbs.has(key)) img.src = this.#thumbs.get(key);
            else this._api('GET', '/datei', { params: { pfad: i.path }, blob: true }).then(b => { const u = URL.createObjectURL(b); this.#thumbs.set(key, u); if (img.isConnected) img.src = u; }).catch(() => {});
        }
        const knopf = d.querySelector('.speichern');
        const start = {};
        d.querySelectorAll('[data-f]').forEach(el => {
            start[el.dataset.f] = el.value;
            el.addEventListener('input', () => { knopf.disabled = [...d.querySelectorAll('[data-f]')].every(e => e.value === start[e.dataset.f]); });
        });
        knopf.addEventListener('click', async () => {
            const felder = {};
            d.querySelectorAll('[data-f]').forEach(el => { if (el.value !== start[el.dataset.f]) felder[el.dataset.f] = el.value.trim(); });
            await this._metaSpeichern([i.path], felder, knopf);
        });
        d.querySelector('.dl').addEventListener('click', ev => this._herunterladen(i, ev.target));
        this._orgBinden(d, [i]);
        d.querySelector('.oeffnen')?.addEventListener('click', async ev => {
            ev.target.disabled = true;
            try {
                const b = await this._api('GET', '/datei', { params: { pfad: i.path }, blob: true });
                const u = URL.createObjectURL(b);
                window.open(u, '_blank', 'noopener');
                setTimeout(() => URL.revokeObjectURL(u), 60000);
            } catch (e) { this._toast(e.message, true); }
            ev.target.disabled = false;
        });
    }

    _detailMehrere(g) {
        const d = this.$('.detail');
        const summe = g.reduce((s, i) => s + i.size, 0);
        const mitBackup = g.filter(i => i.backup);
        const bilder = g.filter(i => i.typ === 'bild');
        const gemeinsam = k => { const w = new Set(g.map(i => (META_FELDER.some(([m]) => m === k) ? i[k] : i.exif?.[k]) || '')); return w.size === 1 ? [...w][0] : null; };
        const feld = ([k, t]) => {
            const w = gemeinsam(k);
            return `<div class="feld"><input type="checkbox" data-an="${k}" title="Dieses Feld bei allen ausgewählten Bildern setzen">
                <label for="m-${k}">${t}</label><input type="text" id="m-${k}" data-m="${k}" value="${this.esc(w ?? '')}" placeholder="${w === null ? '(verschieden)' : ''}" ${k === 'datetime' ? 'title="JJJJ:MM:TT hh:mm:ss"' : ''}></div>`;
        };
        d.innerHTML = `
            <h2>${g.length} Dateien ausgewählt</h2>
            <p class="leise" style="margin:0">${this.mb(summe)}${mitBackup.length ? ` · ${mitBackup.length} optimiert` : ''}</p>
            <h3>Gemeinsam bearbeiten</h3>
            <p class="hinweis" style="margin-bottom:.5rem">Nur angehakte Felder werden geändert. Leeres Feld = Wert entfernen.</p>
            ${META_FELDER.map(feld).join('')}
            ${g.every(i => this._exifOk(i)) ? EXIF_FELDER.map(feld).join('') : '<p class="hinweis">EXIF-Felder nur, wenn ausschliesslich WebP- und JPEG-Bilder gewählt sind.</p>'}
            <div class="knoepfe"><button class="btn voll mspeichern" disabled>Bei ${g.length} Dateien speichern</button></div>
            <h3>Aktionen</h3>
            <div class="knoepfe">
              <button class="btn mzip">Als ZIP herunterladen</button>
              ${bilder.length ? `<button class="btn mopt">${bilder.length === g.length ? '' : `${bilder.length} Bilder `}Optimieren…</button>` : ''}
              ${g.some(i => this._konvOk(i)) ? `<button class="btn mkonv">${g.filter(i => this._konvOk(i)).length} in ${this._konv.ziel.toUpperCase()} umwandeln</button>` : ''}
              ${this._orgKnoepfe(true)}
              ${mitBackup.length ? `<button class="btn warn mrest">${mitBackup.length} Originale wiederherstellen</button>` : ''}
            </div>`;
        const knopf = d.querySelector('.mspeichern');
        const pruefe = () => { knopf.disabled = !d.querySelector('[data-an]:checked'); };
        d.querySelectorAll('[data-an]').forEach(c => c.addEventListener('change', pruefe));
        d.querySelectorAll('[data-m]').forEach(el => el.addEventListener('input', () => { d.querySelector(`[data-an="${el.dataset.m}"]`).checked = true; pruefe(); }));
        knopf.addEventListener('click', async () => {
            const felder = {};
            d.querySelectorAll('[data-an]:checked').forEach(c => { felder[c.dataset.an] = d.querySelector(`[data-m="${c.dataset.an}"]`).value.trim(); });
            const namen = Object.keys(felder).map(k => [...META_FELDER, ...EXIF_FELDER].find(([m]) => m === k)[1]).join(', ');
            if (!confirm(`${namen} bei ${g.length} Dateien setzen?`)) return;
            await this._metaSpeichern(g.map(i => i.path), felder, knopf);
        });
        d.querySelector('.mzip').addEventListener('click', ev => this._zip(g.map(i => i.path), ev.target));
        d.querySelector('.mopt')?.addEventListener('click', () => this._optimierenDialog(bilder));
        d.querySelector('.mkonv')?.addEventListener('click', ev => this._konvertieren(g.filter(i => this._konvOk(i)), ev.target));
        this._orgBinden(d, g);
        d.querySelector('.mrest')?.addEventListener('click', () => this._wiederherstellen(mitBackup));
    }

    /* ---------- Aktionen ---------- */

    async _neuLaden(pfade) {
        // Einträge neu holen (Eigenschaften/EXIF), Auswahl behalten
        const auswahl = new Set(this.#sel);
        try {
            this.#items = await this._api('GET', '/dateien', { params: { ordner: this._ordner, rekursiv: this.#prefs.rekursiv ? 1 : '', verweise: 1 } });
        } catch { /* behalten */ }
        for (const p of pfade || []) for (const k of [...this.#thumbs.keys()]) if (k.startsWith(`${p}|`)) this.#thumbs.delete(k);
        this.#sel = new Set([...auswahl].filter(p => this.#items.some(i => i.path === p)));
        this._mitteZeichnen();
        this._detailZeichnen();
    }

    async _metaSpeichern(pfade, felder, knopf) {
        knopf.disabled = true;
        const text = knopf.textContent;
        knopf.textContent = 'Speichert…';
        try {
            const r = await this._api('POST', '/meta', { body: { pfade, felder } });
            if (r.fehler?.length) this._toastRueck(`${r.geaendert} gespeichert, Fehler: ${r.fehler.join('; ')}`, r.vorgang, true);
            else this._toastRueck(`${r.geaendert} ${r.geaendert === 1 ? 'Datei' : 'Dateien'} gespeichert.`, r.vorgang);
            await this._neuLaden([]);
        } catch (e) {
            this._toast(e.message, true);
            knopf.disabled = false;
            knopf.textContent = text;
        }
    }

    async _herunterladen(i, knopf) {
        knopf.disabled = true;
        try { this._speichernAls(await this._api('GET', '/datei', { params: { pfad: i.path }, blob: true }), i.file); }
        catch (e) { this._toast(`Download fehlgeschlagen: ${e.message}`, true); }
        knopf.disabled = false;
    }

    async _zip(pfade, knopf) {
        const text = knopf.textContent;
        knopf.disabled = true;
        knopf.textContent = 'ZIP wird erstellt…';
        try {
            const praefix = (location.hostname || 'medien').replace(/^www\./, '').replace(/[^a-z0-9-]+/gi, '-');
            const name = pfade ? `${praefix}-auswahl-${pfade.length}.zip` : `${praefix}-${(this._ordner || 'alle').replace(/\//g, '_')}.zip`;
            const blob = pfade
                ? await this._api('POST', '/zip', { body: { pfade }, blob: true })
                : await this._api('GET', '/zip', { params: { ordner: this._ordner }, blob: true });
            this._speichernAls(blob, name);
        } catch (e) { this._toast(`ZIP fehlgeschlagen: ${e.message}`, true); }
        knopf.disabled = false;
        knopf.textContent = text;
    }

    _optimierenDialog(g) {
        const groesste = Math.max(...g.map(i => Math.max(i.width, i.height)));
        const dlg = document.createElement('div');
        dlg.className = 'dialog';
        dlg.innerHTML = `<div class="box">
            <h3>${g.length === 1 ? 'Bild' : `${g.length} Bilder`} optimieren</h3>
            <label>Längste Kante max.<select class="max">${[2000, 2400, 3000, 4000, 6000].map(v => `<option value="${v}" ${v === 3000 ? 'selected' : ''}>${v} px</option>`).join('')}</select></label>
            <label>Qualität<select class="q">${[75, 80, 85, 90].map(v => `<option value="${v}" ${v === 85 ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
            <p class="hinweis">Grösste Kante in der Auswahl: ${groesste} px. Kleinere Bilder werden nur neu komprimiert. Ohne Ersparnis bleibt ein Bild unverändert. Die bisherige Fassung wird gesichert (Verlauf)${this._quelle === 'medien' ? ', das erste Original zusätzlich in _original' : ''} und kann zurückgeholt werden. Auf der Website erscheinen die neuen Kopien automatisch.</p>
            <div class="knoepfe" style="justify-content:flex-end"><button class="btn abbr">Abbrechen</button><button class="btn voll los">Optimieren</button></div></div>`;
        this.shadowRoot.appendChild(dlg);
        dlg.querySelector('.abbr').addEventListener('click', () => dlg.remove());
        dlg.addEventListener('click', ev => { if (ev.target === dlg) dlg.remove(); });
        dlg.querySelector('.los').addEventListener('click', async ev => {
            const max = +dlg.querySelector('.max').value, qualitaet = +dlg.querySelector('.q').value;
            ev.target.disabled = true;
            const pfade = g.map(i => i.path);
            let vorher = 0, nachher = 0, opt = 0, gleich = 0, vorgang = null;
            const fehler = [];
            // in Paketen, damit der Server nicht zu lange rechnet
            for (let k = 0; k < pfade.length; k += 5) {
                ev.target.textContent = `Läuft… ${Math.min(k + 5, pfade.length)}/${pfade.length}`;
                try {
                    const r = await this._api('POST', '/optimieren', { body: { pfade: pfade.slice(k, k + 5), max, qualitaet } });
                    for (const e of r) {
                        if (e.vorgang) vorgang = e.vorgang;
                        if (e.status === 'optimiert') { opt++; vorher += e.vorher; nachher += e.nachher; }
                        else if (e.status === 'unveraendert') gleich++;
                        else fehler.push(`${e.path}: ${e.meldung}`);
                    }
                } catch (e2) { fehler.push(e2.message); }
            }
            dlg.remove();
            const meldung = fehler.length
                ? `${opt} optimiert, ${fehler.length} Fehler: ${fehler.slice(0, 3).join('; ')}`
                : `${opt} optimiert (${this.mb(vorher)} → ${this.mb(nachher)})${gleich ? `, ${gleich} ohne Ersparnis unverändert` : ''}`;
            // bei mehr als 5 Bildern mehrere Vorgaenge: Rueckgaengig dann im Verlauf
            if (pfade.length <= 5) this._toastRueck(meldung, opt ? vorgang : null, fehler.length > 0);
            else this._toast(`${meldung}. Rückgängig im Verlauf.`, fehler.length > 0);
            await this._neuLaden(pfade);
        });
    }

    /* ---------- Organisieren ---------- */

    _orgKnoepfe(mehrere = false) {
        return `${mehrere ? '' : '<button class="btn umben">Umbenennen…</button>'}
            ${this._quelle === 'seiten' ? '<button class="btn kopie">In Mediathek kopieren…</button>' : ''}
            <button class="btn warn papier">In den Papierkorb</button>`;
    }

    _verweisAbschnitt() {
        return '<h3>Verwendet in</h3><div class="verweise leise">Wird gesucht…</div>';
    }

    _orgBinden(d, g) {
        d.querySelector('.umben')?.addEventListener('click', () => this._umbenennen(g[0]));
        d.querySelector('.kopie')?.addEventListener('click', ev => this._kopieren(g, ev.target));
        d.querySelector('.papier')?.addEventListener('click', ev => this._papierkorb(g, ev.target));
        const box = d.querySelector('.verweise');
        if (box && g.length === 1) {
            this._api('GET', '/verweise', { params: { pfad: g[0].path } }).then(l => {
                if (!box.isConnected) return;
                box.innerHTML = l.length
                    ? `<ul class="verweisliste">${l.map(t => `<li>${this.esc(t.datei)}<code> Zeile ${t.zeile}</code><br><code>${this.esc(t.text)}</code></li>`).join('')}</ul>`
                    : 'Kein direkter Verweis in Seiten oder Konfiguration gefunden. Vorsicht: Galerien, Vorlagen oder Sammlungen (z.B. «alle Bilder einer Seite») werden nicht erkannt.';
            }).catch(e => { box.textContent = e.message; });
        }
    }

    async _umbenennen(i) {
        const name = prompt(`Neuer Dateiname für «${i.file}» (die Endung bleibt). Verweise in Seiten und Konfiguration werden angepasst.`, i.file);
        if (!name || name === i.file) return;
        try {
            const r = await this._api('POST', '/umbenennen', { body: { pfad: i.path, name } });
            this._toastRueck(`Umbenannt${r.verweise ? `, ${r.verweise} Verweis${r.verweise === 1 ? '' : 'e'} angepasst` : ''}.`, r.vorgang);
            this.#sel = new Set([r.neu]);
            await this._baumLaden();
            await this._neuLaden([i.path]);
        } catch (e) { this._toast(e.message, true); }
    }

    async _papierkorb(g, knopf) {
        const ohne = g.filter(i => i.verweise > 0).length;
        if (!confirm(`${g.length === 1 ? `«${g[0].file}»` : `${g.length} Dateien`} in den Papierkorb legen?${ohne ? `\n\nAchtung: ${ohne === 1 ? 'Diese Datei wird' : `${ohne} davon werden`} in Seiten oder der Konfiguration verwendet.` : ''}\n\nDie Dateien werden gesichert und lassen sich im Verlauf zurückholen.`)) return;
        knopf.disabled = true;
        try {
            const r = await this._api('POST', '/papierkorb', { body: { pfade: g.map(i => i.path) } });
            this._toastRueck(`${r.entfernt} in den Papierkorb gelegt${r.fehler.length ? `, Fehler: ${r.fehler.join('; ')}` : ''}.`, r.vorgang, r.fehler.length > 0);
            this.#sel.clear();
            await this._baumLaden();
            await this._neuLaden([]);
        } catch (e) { this._toast(e.message, true); knopf.disabled = false; }
    }

    async _kopieren(g, knopf) {
        const vorschlag = `seitenmedien/${(g[0].ordner || 'seiten').split('/').pop().replace(/^\d+\./, '')}`;
        const ziel = prompt(`Zielordner in der Mediathek für ${g.length === 1 ? `«${g[0].file}»` : `${g.length} Dateien`} (wird angelegt, Originale bleiben bei der Seite):`, vorschlag);
        if (!ziel) return;
        knopf.disabled = true;
        try {
            const r = await this._api('POST', '/kopieren', { body: { pfade: g.map(i => i.path), ziel } });
            this._toastRueck(`${r.kopiert} in die Mediathek kopiert (${r.ziel})${r.fehler.length ? `, Fehler: ${r.fehler.join('; ')}` : ''}.`, r.vorgang, r.fehler.length > 0);
        } catch (e) { this._toast(e.message, true); }
        knopf.disabled = false;
    }

    async _konvertieren(g, knopf) {
        const ziel = this._konv.ziel.toUpperCase();
        if (!confirm(`${g.length === 1 ? 'Dieses Bild' : `${g.length} Bilder`} in ${ziel} umwandeln? Die bisherige Datei wird ersetzt (Titel und Texte gehen mit). Seiten, die direkt auf den alten Dateinamen verweisen, müssen angepasst werden.`)) return;
        knopf.disabled = true;
        const pfade = g.map(i => i.path);
        let ok = 0, vorgang = null, vw = 0;
        const fehler = [], gleich = [];
        for (let k = 0; k < pfade.length; k += 5) {
            knopf.textContent = `Läuft… ${Math.min(k + 5, pfade.length)}/${pfade.length}`;
            try {
                const r = await this._api('POST', '/konvertieren', { body: { pfade: pfade.slice(k, k + 5) } });
                for (const e of r) {
                    vorgang = vorgang || e.vorgang; vw += e.verweise || 0;
                    if (e.status === 'umgewandelt') ok++;
                    else if (e.status === 'unveraendert') gleich.push(`${e.path.split('/').pop()}: ${e.meldung}`);
                    else fehler.push(`${e.path}: ${e.meldung}`);
                }
            } catch (e2) { fehler.push(e2.message); }
        }
        const teile = [`${ok} in ${ziel} umgewandelt${vw ? ` (${vw} Verweis${vw === 1 ? '' : 'e'} angepasst)` : ''}`];
        if (gleich.length) teile.push(`${gleich.length} unverändert (${gleich.slice(0, 2).join('; ')})`);
        if (fehler.length) teile.push(`Fehler: ${fehler.slice(0, 3).join('; ')}`);
        this._toastRueck(teile.join(', '), ok ? vorgang : null, fehler.length > 0 || (ok === 0 && gleich.length > 0));
        this.#sel.clear();
        await this._baumLaden();
        await this._neuLaden(pfade);
    }

    async _wiederherstellen(g) {
        if (!confirm(`${g.length === 1 ? 'Das Original' : `${g.length} Originale`} wiederherstellen? Die optimierte Fassung wird ersetzt.`)) return;
        try {
            const r = await this._api('POST', '/wiederherstellen', { body: { pfade: g.map(i => i.path) } });
            const f = r.filter(e => e.status !== 'wiederhergestellt');
            this._toast(f.length ? `Fehler: ${f.map(e => `${e.path}: ${e.meldung}`).join('; ')}` : `${r.length} wiederhergestellt`, f.length > 0);
        } catch (e) { this._toast(e.message, true); }
        await this._neuLaden(g.map(i => i.path));
    }

    /* ---------- Vollbild mit Zoom ---------- */

    _viewerAuf(n) {
        if (n < 0 || !this._liste?.[n]) return;
        const ziel = this._liste[n];
        if (ziel.typ !== 'bild') { this._herunterladen(ziel, this.$('.detail .dl') || document.createElement('button')); return; }
        if (!this.#viewer) {
            const v = document.createElement('div');
            v.className = 'viewer';
            v.tabIndex = 0;
            v.innerHTML = `<img alt=""><div class="vk"><span class="titel"></span><span class="r">
                <button data-a="aus" title="Verkleinern (−)">−</button><button data-a="fit" title="Einpassen (0)">Einpassen</button>
                <button data-a="100" title="Originalgrösse (1)">1:1</button><button data-a="ein" title="Vergrössern (+)">+</button>
                <button data-a="dl" title="Original herunterladen">⤓</button><button data-a="zu" title="Schliessen (Esc)">✕</button></span></div>
                <button class="nav l" data-a="prev" title="Vorheriges (←)">‹</button><button class="nav r" data-a="next" title="Nächstes (→)">›</button>
                <div class="status"></div>`;
            this.shadowRoot.appendChild(v);
            this.#viewer = { el: v, img: v.querySelector('img'), s: 1, x: 0, y: 0, fit: 1, url: null };
            v.querySelectorAll('[data-a]').forEach(b => b.addEventListener('click', ev => { ev.stopPropagation(); this._viewerAktion(b.dataset.a); }));
            v.addEventListener('wheel', ev => { ev.preventDefault(); this._zoom(ev.deltaY < 0 ? 1.15 : 1 / 1.15, ev.clientX, ev.clientY); }, { passive: false });
            v.addEventListener('dblclick', ev => { if (ev.target.closest('button')) return; this._viewerAktion(Math.abs(this.#viewer.s - this.#viewer.fit) < 1e-3 ? '100' : 'fit', ev.clientX, ev.clientY); });
            let zug = null;
            v.addEventListener('pointerdown', ev => { if (ev.target.closest('button')) return; zug = { x: ev.clientX, y: ev.clientY, ox: this.#viewer.x, oy: this.#viewer.y }; v.classList.add('zieht'); v.setPointerCapture(ev.pointerId); });
            v.addEventListener('pointermove', ev => { if (!zug) return; this.#viewer.x = zug.ox + ev.clientX - zug.x; this.#viewer.y = zug.oy + ev.clientY - zug.y; this._transform(); });
            v.addEventListener('pointerup', () => { zug = null; v.classList.remove('zieht'); });
            window.addEventListener('resize', this._onResize = () => this._viewerAktion('fit'));
        }
        this.#viewer.n = n;
        this.#focus = n;
        this._viewerBild();
        this.#viewer.el.focus();
    }

    async _viewerBild() {
        const v = this.#viewer, i = this._liste[v.n];
        v.el.querySelector('.titel').textContent = `${i.title || i.file} — ${i.width} × ${i.height} px · ${v.n + 1}/${this._liste.length}`;
        v.img.style.opacity = '.0';
        if (v.url) { URL.revokeObjectURL(v.url); v.url = null; }
        const n = v.n;
        const zeige = src => new Promise(res => { v.img.onload = () => res(); v.img.onerror = () => res(); v.img.src = src; });
        // zuerst schnelle Kopie, dann das Original in voller Auflösung
        try {
            const key = `${i.path}|2400|`;
            let url = this.#thumbs.get(key);
            if (!url) { url = (await this._api('GET', '/vorschau', { params: { pfad: i.path, w: 2400 } })).url; this.#thumbs.set(key, url); }
            if (v.n !== n) return;
            await zeige(url);
            v.nat = [i.width, i.height];
            this._viewerAktion('fit');
            v.img.style.opacity = '1';
            this._status('Original wird geladen…');
            const blob = await this._api('GET', '/datei', { params: { pfad: i.path }, blob: true });
            if (!this.#viewer || v.n !== n) return;
            const s = v.s, x = v.x, y = v.y;
            v.url = URL.createObjectURL(blob);
            await zeige(v.url);
            v.s = s; v.x = x; v.y = y;
            this._transform();
            this._status('');
        } catch (e) { this._status(e.message); }
    }

    _status(t) { const s = this.#viewer?.el.querySelector('.status'); if (s) { s.textContent = t; s.style.display = t ? '' : 'none'; } }

    _transform() {
        const v = this.#viewer;
        // Bild wird in Originalpixeln dargestellt (nat), skaliert über transform
        v.img.style.width = `${v.nat[0]}px`;
        v.img.style.height = `${v.nat[1]}px`;
        v.img.style.transform = `translate(${v.x}px, ${v.y}px) scale(${v.s})`;
        this._status(v.url ? `${Math.round(v.s * 100)} %` : this.#viewer.el.querySelector('.status').textContent);
    }

    _zoom(f, cx, cy) {
        const v = this.#viewer;
        const ns = Math.max(v.fit * 0.5, Math.min(8, v.s * f));
        const r = ns / v.s;
        v.x = cx - (cx - v.x) * r;
        v.y = cy - (cy - v.y) * r;
        v.s = ns;
        this._transform();
    }

    _viewerAktion(a, cx, cy) {
        const v = this.#viewer;
        if (!v) return;
        const W = innerWidth, H = innerHeight;
        if (a === 'zu') return this._viewerZu();
        if (a === 'prev' || a === 'next') {
            // nur zwischen Bildern blaettern
            let n = v.n;
            for (let k = 0; k < this._liste.length; k++) {
                n = (n + (a === 'next' ? 1 : -1) + this._liste.length) % this._liste.length;
                if (this._liste[n].typ === 'bild') break;
            }
            this.#sel = new Set([this._liste[n].path]);
            this.#anchor = this.#focus = n;
            this._auswahlMarkieren();
            this._detailZeichnen();
            v.n = n;
            return this._viewerBild();
        }
        if (a === 'dl') return this._herunterladen(this._liste[v.n], v.el.querySelector('[data-a="dl"]'));
        if (!v.nat) return;
        if (a === 'fit') {
            v.fit = Math.min(W / v.nat[0], (H - 20) / v.nat[1], 1);
            v.s = v.fit;
            v.x = (W - v.nat[0] * v.s) / 2;
            v.y = (H - v.nat[1] * v.s) / 2;
            return this._transform();
        }
        if (a === '100') return this._zoom(1 / v.s, cx ?? W / 2, cy ?? H / 2);
        if (a === 'ein') return this._zoom(1.3, W / 2, H / 2);
        if (a === 'aus') return this._zoom(1 / 1.3, W / 2, H / 2);
    }

    _viewerTaste(ev) {
        const map = { Escape: 'zu', ArrowLeft: 'prev', ArrowRight: 'next', '+': 'ein', '=': 'ein', '-': 'aus', '0': 'fit', '1': '100' };
        if (map[ev.key]) { ev.preventDefault(); this._viewerAktion(map[ev.key]); }
    }

    _viewerZu() {
        if (!this.#viewer) return;
        if (this.#viewer.url) URL.revokeObjectURL(this.#viewer.url);
        this.#viewer.el.remove();
        this.#viewer = null;
        window.removeEventListener('resize', this._onResize);
        this.$('.main')?.focus();
        this.$(`.inhalt [data-i="${this.#focus}"]`)?.scrollIntoView({ block: 'nearest' });
    }
}

if (!customElements.get(TAG)) customElements.define(TAG, Mediaorganizer);

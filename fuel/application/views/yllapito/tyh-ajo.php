<?php
$st        = $st ?? array();
$a_status  = $st['a_status'] ?? '';
$a_total   = (int)($st['a_total'] ?? 0);
$a_done    = (int)($st['a_done'] ?? 0);
$a_last    = (int)($st['a_last'] ?? 0);
$preview   = $preview ?? null;
$last_msg  = $st['last_msg'] ?? '';
$box = 'padding:15px; border-radius:5px; border:1px solid #dee2e6; margin-bottom:20px;';
?>
<h2>TYH-ajo</h2>

<p>Kertaluontoinen työkalu, joka laskee TYH-jaoksen (jaos <?= (int)$jaos; ?>) hevosten kilpailustatistiikat uudelleen hyväksytyistä tuloksista.
Laskenta tehdään <strong>alusta asti</strong> ja arvot kirjoitetaan <strong>absoluuttisina</strong>, joten ajon voi toistaa tuplaamatta tilastoja.</p>

<ol>
    <li><strong>Analyysi</strong> käy tulokset läpi erissä eikä muuta oikeita tilastoja.</li>
    <li><strong>Esikatselu</strong> näyttää mitä päivitys muuttaisi.</li>
    <li><strong>Päivitys</strong> tekee ensin varmuuskopion TYH-riveistä ja kirjoittaa sitten uudet arvot.</li>
    <li><strong>Palautus</strong> palauttaa varmuuskopion ja ajaa sen jälkeen hyväksytyt tulokset uudelleen, ettei mikään katoa.</li>
</ol>

<div style="<?= $box; ?> background:#f8f9fa;">
    <strong>TYH-tilastorivejä tietokannassa nyt:</strong>
    <div style="font-size:24px; font-weight:bold; color:#495057;"><?= (int)$before_count; ?> kpl</div>
</div>

<?php if (!empty($msg)): ?>
    <div style="padding:12px; background:#f8d7da; color:#721c24; margin-bottom:20px; border-radius:4px;">
        <strong><?= htmlspecialchars($msg); ?></strong>
    </div>
<?php endif; ?>

<?php if ($last_msg !== ''): ?>
    <div style="padding:12px; background:#d4edda; color:#155724; margin-bottom:20px; border-radius:4px;">
        <strong><?= htmlspecialchars($last_msg); ?></strong>
    </div>
<?php endif; ?>

<div id="tyh-error" style="display:none; padding:12px; background:#f8d7da; color:#721c24; margin-bottom:20px; border-radius:4px;"></div>

<!-- ===================== 1. ANALYYSI ===================== -->
<div style="<?= $box; ?>">
    <h3 style="margin-top:0;">1. Analyysi</h3>

    <?php if ($a_status === 'analysed' || $a_status === 'applied'): ?>
        <p style="color:#155724;">&#10004; Analyysi valmis (<?= $a_done; ?> / <?= $a_total; ?> tulosta, rajattu hetkeen <?= htmlspecialchars($st['a_cutoff'] ?? ''); ?>).</p>
    <?php endif; ?>

    <div id="tyh-progress-wrap" style="<?= $a_status === 'analysing' ? '' : 'display:none;'; ?> margin-bottom:10px;">
        <div style="background:#e9ecef; border-radius:4px; height:22px; overflow:hidden;">
            <div id="tyh-bar" style="background:#28a745; height:22px; width:0%; transition:width .2s;"></div>
        </div>
        <div id="tyh-progress-text" style="margin-top:6px; font-size:14px;"></div>
    </div>

    <?php if ($a_status === 'analysing'): ?>
        <button type="button" id="tyh-resume" style="padding:8px 16px; cursor:pointer;">Jatka analyysia (<?= $a_done; ?> / <?= $a_total; ?>)</button>
        <button type="button" id="tyh-restart" style="padding:8px 16px; cursor:pointer;">Aloita alusta</button>
    <?php else: ?>
        <button type="button" id="tyh-start" style="padding:8px 16px; cursor:pointer;">
            <?= ($a_status === 'analysed' || $a_status === 'applied') ? 'Aja analyysi uudelleen' : 'Aloita analyysi'; ?>
        </button>
    <?php endif; ?>
    <?php if ($a_status !== ''): ?>
        <button type="button" id="tyh-reset" style="padding:8px 16px; cursor:pointer;">Tyhjennä analyysi</button>
    <?php endif; ?>
</div>

<!-- ===================== 2. ESIKATSELU + PÄIVITYS ===================== -->
<?php if ($preview !== null): ?>
<div style="<?= $box; ?> background:#e8f4f8; border-color:#b8daff;">
    <h3 style="margin-top:0;">2. Esikatselu</h3>
    <table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; margin-bottom:15px;">
        <tr><td>Hevosia tuloksissa</td><td><strong><?= $preview['horses']; ?></strong></td></tr>
        <tr><td>Lisätään uusina riveinä</td><td><strong><?= $preview['new']; ?></strong></td></tr>
        <tr><td>Päivitettäviä rivejä (arvot muuttuvat)</td><td><strong><?= $preview['changed']; ?></strong></td></tr>
        <tr><td>Rivejä jotka ovat jo oikein</td><td><?= $preview['unchanged']; ?></td></tr>
        <tr><td>Nykyisiä TYH-rivejä joita tuloksissa ei esiinny (jätetään koskematta)</td><td><?= $preview['untouched']; ?></td></tr>
        <tr><td>VH-tunnuksia joita ei löydy hevosrekisteristä (ohitetaan)</td><td><?= $preview['unknown']; ?></td></tr>
    </table>

    <?php if (!empty($preview['sample'])): ?>
        <p><strong>10 suurinta muutosta</strong> (OS / SIJ / VOI, porrastetut OS erikseen):</p>
        <table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%; margin-bottom:15px;">
            <thead style="background:#f1f1f1;"><tr><th>reknro</th><th>Nyt</th><th>Päivityksen jälkeen</th><th>Porr. OS nyt &rarr; uusi</th></tr></thead>
            <tbody>
            <?php foreach ($preview['sample'] as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['reknro']); ?></td>
                    <td><?= (int)$r['old_os']; ?> / <?= (int)$r['old_sij']; ?> / <?= (int)$r['old_voi']; ?></td>
                    <td><?= (int)$r['new_os']; ?> / <?= (int)$r['new_sij']; ?> / <?= (int)$r['new_voi']; ?></td>
                    <td><?= (int)$r['old_porr_os']; ?> &rarr; <?= (int)$r['new_porr_os']; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($backup_exists): ?>
        <p style="color:#856404;">Varmuuskopio on jo olemassa, joten uutta päivitystä ei voi ajaa ennen kuin se palautetaan tai hylätään (alla).</p>
    <?php else: ?>
        <p>Päivitys tekee ensin varmuuskopion <?= (int)$before_count; ?> TYH-rivistä. Aja mieluiten hiljaiseen aikaan, kun TYH-tuloksia ei hyväksytä.</p>
        <button type="button" id="tyh-apply" style="padding:8px 16px; cursor:pointer;">3. Aja päivitys</button>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ===================== 4. VARMUUSKOPIO / PALAUTUS ===================== -->
<?php if (!empty($backup_exists)): ?>
<div style="<?= $box; ?> background:#fff3cd; border-color:#ffeeba;">
    <h3 style="margin-top:0;">Varmuuskopio ja palautus</h3>
    <p>Varmuuskopio otettu <strong><?= htmlspecialchars($st['snapshot_at'] ?? '?'); ?></strong> (<?= (int)($st['backup_rows'] ?? 0); ?> riviä).
    Palautus korvaa kaikki TYH-statistiikkarivit kopion sisällöllä ja ajaa sen jälkeen hyväksytyt tulokset uudelleen.</p>
    <button type="button" id="tyh-revert" style="padding:8px 16px; cursor:pointer;">Palauta varmuuskopiosta</button>
    <button type="button" id="tyh-discard" style="padding:8px 16px; cursor:pointer;">Hylkää varmuuskopio</button>
</div>
<?php endif; ?>

<!-- ===================== Otos ===================== -->
<?php if (!empty($recent_stats_sample)): ?>
    <h3>Otos tietokannan TYH-tilastoista (10 riviä)</h3>
    <table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">
        <thead style="background:#f1f1f1;">
            <tr><th>VH-numero (reknro)</th><th>Jaos</th><th>Max taso</th><th>Perinteiset (OS / SIJ / VOI)</th><th>Porrastetut (OS / SIJ / VOI)</th></tr>
        </thead>
        <tbody>
        <?php foreach ($recent_stats_sample as $row): ?>
            <tr>
                <td><strong><?= htmlspecialchars($row['reknro']); ?></strong></td>
                <td><?= (int)$row['jaos']; ?></td>
                <td><?= htmlspecialchars($row['taso_max'] ?? '-'); ?></td>
                <td><?= (int)($row['os'] ?? 0); ?> / <?= (int)($row['sij'] ?? 0); ?> / <?= (int)($row['voi'] ?? 0); ?></td>
                <td><?= (int)($row['porr_os'] ?? 0); ?> / <?= (int)($row['porr_sij'] ?? 0); ?> / <?= (int)($row['porr_voi'] ?? 0); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p><em>Tietokannasta ei löytynyt vielä yhtään TYH-tilastomerkintää.</em></p>
<?php endif; ?>

<script>
(function () {
    var base = <?= json_encode($ajax_base); ?>;
    var csrf = { name: <?= json_encode($csrf_name); ?>, hash: <?= json_encode($csrf_hash); ?> };
    var state = { total: <?= $a_total; ?>, done: <?= $a_done; ?>, last: <?= $a_last; ?> };
    var busy = false;

    function $(id) { return document.getElementById(id); }

    function showError(text) {
        var e = $('tyh-error');
        e.textContent = text;
        e.style.display = 'block';
        window.scrollTo(0, 0);
    }

    function lockButtons(lock) {
        var b = document.querySelectorAll('button');
        for (var i = 0; i < b.length; i++) { b[i].disabled = lock; }
    }

    function post(action, data) {
        var body = new URLSearchParams();
        body.append(csrf.name, csrf.hash);
        data = data || {};
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch(base + action, {
            method: 'POST', body: body, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.text(); }).then(function (t) {
            var j;
            try { j = JSON.parse(t); } catch (e) { throw new Error('Palvelin palautti virheen (ei JSON-vastausta). Tarkista lokit.'); }
            if (j.csrf_name) { csrf.name = j.csrf_name; csrf.hash = j.csrf_hash; }
            if (!j.ok) { throw new Error(j.msg || 'Tuntematon virhe'); }
            return j;
        });
    }

    var t0 = 0, doneAtStart = 0;
    function render() {
        var pct = state.total > 0 ? Math.min(100, Math.round(state.done / state.total * 100)) : 100;
        $('tyh-progress-wrap').style.display = 'block';
        $('tyh-bar').style.width = pct + '%';
        var txt = state.done + ' / ' + state.total + ' tulosta (' + pct + ' %)';
        var secs = (Date.now() - t0) / 1000, n = state.done - doneAtStart;
        if (n > 0 && secs > 2 && state.done < state.total) {
            var left = Math.round((state.total - state.done) / (n / secs));
            txt += ' - arvio jäljellä noin ' + (left >= 60 ? Math.round(left / 60) + ' min' : left + ' s');
        }
        $('tyh-progress-text').textContent = txt;
    }

    function loop() {
        return post('batch', { after: state.last }).then(function (j) {
            state.total = j.total; state.done = j.done; state.last = j.last;
            render();
            if (j.finished) { return; }
            return loop();
        });
    }

    function runAnalysis(start) {
        if (busy) { return; }
        busy = true; lockButtons(true);
        $('tyh-error').style.display = 'none';
        var first = start ? post('prepare', { force: 1 }).then(function (j) {
            state.total = j.total; state.done = 0; state.last = 0;
        }) : Promise.resolve();
        t0 = Date.now(); doneAtStart = start ? 0 : state.done;
        first.then(function () { render(); return loop(); })
            .then(function () { location.reload(); })
            .catch(function (e) { busy = false; lockButtons(false); showError(e.message + ' (Voit jatkaa ajoa painamalla "Jatka analyysia" sivun päivityksen jälkeen.)'); });
    }

    function simple(action, confirmText, workingText) {
        if (busy) { return; }
        if (confirmText && !confirm(confirmText)) { return; }
        busy = true; lockButtons(true);
        $('tyh-error').style.display = 'none';
        if (workingText) {
            $('tyh-progress-wrap').style.display = 'block';
            $('tyh-bar').style.width = '100%';
            $('tyh-progress-text').textContent = workingText;
        }
        post(action).then(function () { location.reload(); })
            .catch(function (e) { busy = false; lockButtons(false); showError(e.message); });
    }

    function on(id, fn) { var el = $(id); if (el) { el.addEventListener('click', fn); } }

    on('tyh-start',   function () { runAnalysis(true); });
    on('tyh-restart', function () { if (confirm('Aloitetaanko analyysi alusta?')) { runAnalysis(true); } });
    on('tyh-resume',  function () { runAnalysis(false); });
    on('tyh-reset',   function () { simple('reset', 'Tyhjennetäänkö analyysin työtiedot?'); });
    on('tyh-apply',   function () { simple('apply', 'Tehdäänkö varmuuskopio ja päivitetäänkö TYH-tilastot esikatselun mukaisesti?', 'Tehdään varmuuskopiota ja päivitetään... älä sulje sivua.'); });
    on('tyh-revert',  function () { simple('revert', 'Palautetaanko TYH-tilastot varmuuskopiosta?', 'Palautetaan... älä sulje sivua.'); });
    on('tyh-discard', function () { simple('discard_backup', 'Hylätäänkö varmuuskopio? Palautus ei ole sen jälkeen enää mahdollinen.'); });

    <?php if ($a_status === 'analysing'): ?>
    t0 = Date.now(); doneAtStart = state.done; render();
    <?php endif; ?>
})();
</script>

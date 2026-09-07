<h2>TYH-ajo</h2>

<p>Kertaluontoinen skripti jolla ajetaan TYH-tulokset tulostietokannasta hevosten kilpastatistiikoihin. Paina nappia.</p>

<!-- Current DB State Summary -->
<div style="margin-bottom:20px;">
    <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; border: 1px solid #dee2e6; flex: 1;">
        <strong>Tilastoja tietokannassa ennen ajoa:</strong>
        <div style="font-size: 24px; font-weight: bold; color: #495057;">
            <?= $before_count ?? 0; ?> kpl
        </div>
    </div>
    
    <?php if (isset($after_count)): ?>
    <div style="background: #e8f4f8; padding: 15px; border-radius: 5px; border: 1px solid #b8daff; flex: 1;">
        <strong>Tilastoja tietokannassa ajon jälkeen:</strong>
        <div style="font-size: 24px; font-weight: bold; color: #004085;">
            <?= $after_count; ?> kpl 
            <span style="font-size: 14px; font-weight: normal;">(+<?= $after_count - $before_count; ?> uutta)</span>
        </div>
    </div>
    <?php endif; ?>
</div>


<?php if (!empty($msg)): ?>
    <div style="padding: 12px; background-color: <?= ($msg_type ?? 'info') === 'success' ? '#d4edda' : '#fff3cd'; ?>; color: <?= ($msg_type ?? 'info') === 'success' ? '#155724' : '#856404'; ?>; margin-bottom: 20px; border-radius: 4px;">
        <strong><?= htmlspecialchars($msg); ?></strong>
    </div>
<?php endif; ?>

<form method="post" action="" style="margin-bottom: 30px;">
    <button type="submit" name="run_tyh" value="1" style="padding: 8px 16px; cursor: pointer;" onclick="return confirm('Haluatko varmasti ajaa TYH-tilastoinnin?');">
        Aja TYH-tulokset
    </button>
</form>

<?php if (!empty($processed_samples)): ?>
    <h3>Käsiteltyjen kilpailujen otos (Ensimmäiset 5):</h3>
    <table border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse; width: 100%; margin-bottom: 20px;">
        <thead style="background: #f1f1f1;">
            <tr>
                <th>Kisa ID</th>
                <th>Porrastettu</th>
                <th>Tila</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($processed_samples as $sample): ?>
                <tr>
                    <td>#<?= $sample['kisa_id']; ?></td>
                    <td><?= $sample['porrastettu'] ? 'Kyllä' : 'Ei'; ?></td>
                    <td><span style="color: green; font-weight: bold;">✔ <?= $sample['status']; ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if (!empty($recent_stats_sample)): ?>
    <h3>Otos tietokannan TYH-tilastoista (Max 10 riviä):</h3>
    <table border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse; width: 100%;">
        <thead style="background: #f1f1f1;">
            <tr>
                <th>VH-numero (reknro)</th>
                <th>Jaos</th>
                <th>Max Taso</th>
                <th>Perinteiset (OS / SIJ / VOI)</th>
                <th>Porrastetut (OS / SIJ / VOI)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recent_stats_sample as $row): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['reknro']); ?></strong></td>
                    <td><?= $row['jaos']; ?></td>
                    <td><?= htmlspecialchars($row['taso_max'] ?? '-'); ?></td>
                    <td>
                        <?= $row['os'] ?? 0; ?> / 
                        <?= $row['sij'] ?? 0; ?> / 
                        <?= $row['voi'] ?? 0; ?>
                    </td>
                    <td>
                        <?= $row['porr_os'] ?? 0; ?> / 
                        <?= $row['porr_sij'] ?? 0; ?> / 
                        <?= $row['porr_voi'] ?? 0; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p><em>Tietokannasta ei löytynyt vielä yhtään TYH-tilastomerkintää.</em></p>
<?php endif; ?>

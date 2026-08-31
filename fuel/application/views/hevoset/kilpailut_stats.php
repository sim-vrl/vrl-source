<p>Statistiikka on kerätty kilpailuista, joissa hevonen on kilpaillut VH-tunnuksen kanssa 5.5.2014 alkaen.</p>

<?php

echo "<h3>Porrastetut kilpailut</h3>";
tableHead();

foreach ($jaokset as $jaos_id => $jaos) {
    // Näytetään vain porrastetut kilpailujaokset
    if (empty($jaos['s_salli_porrastetut'])) {
        continue;
    }

    $info = $kisatiedot[$jaos_id] ?? array();
    $voi = $info['porr_voi'] ?? 0;
    $sij = $info['porr_sij'] ?? 0;
    $os  = $info['porr_os'] ?? 0;

    echo "<tr>";
    echo "<td><b>" . htmlspecialchars($jaos['lyhenne']) . "</b></td>";
    echo "<td>" . $voi . "</td>";
    echo "<td>" . $sij . "</td>";
    echo "<td>" . $os . "</td>";
    echo "<td>" . sijpros($voi, $sij, $os) . "%</td>";
    echo "</tr>";
}

tableEnd();


echo "<h3>Perinteiset kilpailut</h3>";
tableHead();

foreach ($jaokset as $jaos_id => $jaos) {
    // Ohitetaan näyttelyjaokset perinteisistä urheilukilpailuista
    if (!empty($jaos['nayttelyt'])) {
        continue;
    }

    $info = $kisatiedot[$jaos_id] ?? array();
    $voi = $info['voi'] ?? 0;
    $sij = $info['sij'] ?? 0;
    $os  = $info['os'] ?? 0;

    echo "<tr>";
    echo "<td><b>" . htmlspecialchars($jaos['lyhenne']) . "</b></td>";
    echo "<td>" . $voi . "</td>";
    echo "<td>" . $sij . "</td>";
    echo "<td>" . $os . "</td>";
    echo "<td>" . sijpros($voi, $sij, $os) . "%</td>";
    echo "</tr>";
}

tableEnd();


function sijpros($voi, $sij, $os) {
    $voi = intval(round($voi));
    $sij = intval(round($sij));
    $os  = intval(round($os));

    if ($os === 0) {
        return 0;
    } else {
        $sijpros = (($voi + $sij) / $os) * 100;
        return round($sijpros);
    }
}

function tableHead() {
    echo '<table class="table">
    <thead>
      <tr>
        <th scope="col">Jaos</th>
        <th scope="col">Voitot</th>
        <th scope="col">Muut sijoitukset</th>
        <th scope="col">Osallistumiset</th>        
        <th scope="col">Sijoitusprosentti</th>
      </tr>
    </thead>
    <tbody>';
}

function tableEnd() {
    echo "</tbody>
    </table>";
}
?>

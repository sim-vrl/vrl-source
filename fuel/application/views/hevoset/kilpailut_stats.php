<p>Statistiikka on kerätty kilpailuista, joissa hevonen on kilpaillut VH-tunnuksen kanssa 5.5.2014 alkaen.</p>

<?php

echo "<h3>Porrastetut kilpailut</h3>";
tableHead();
    
// Käydään läpi KAIKKI jaokset, ei vain hevosen kisoja
foreach ($jaokset as $id => $jaos_info) {
    // Hypätään yli jaokset, jotka eivät salli porrastettuja
    if ($jaos_info['s_salli_porrastetut'] == 0) {
        continue;
    }
    
    // Katsotaan löytyykö tälle jaokselle tietoja hevosen kisoista, muuten nollataan
    $stats = isset($kisatiedot[$id]) ? $kisatiedot[$id] : null;
    $voitot = isset($stats['porr_voi']) ? $stats['porr_voi'] : 0;
    $sijoitukset = isset($stats['porr_sij']) ? $stats['porr_sij'] : 0;
    $osallistumiset = isset($stats['porr_os']) ? $stats['porr_os'] : 0;

    echo "<tr>";
    echo "<td><b>" . $jaos_info['lyhenne'] . "</b></td>";
    echo "<td>" . $voitot . "</td>";
    echo "<td>" . $sijoitukset . "</td>";
    echo "<td>" . $osallistumiset . "</td>";
    echo "<td>" . sijpros($voitot, $sijoitukset, $osallistumiset) . "%</td>";
    echo "</tr>";
}

tableEnd();


echo "<h3>Perinteiset kilpailut</h3>";
tableHead();
    
foreach ($jaokset as $id => $jaos_info) {
    $stats = isset($kisatiedot[$id]) ? $kisatiedot[$id] : null;
    $voitot = isset($stats['voi']) ? $stats['voi'] : 0;
    $sijoitukset = isset($stats['sij']) ? $stats['sij'] : 0;
    $osallistumiset = isset($stats['os']) ? $stats['os'] : 0;

    echo "<tr>";
    echo "<td><b>" . $jaos_info['lyhenne'] . "</b></td>";
    echo "<td>" . $voitot . "</td>";
    echo "<td>" . $sijoitukset . "</td>";
    echo "<td>" . $osallistumiset . "</td>";
    echo "<td>" . sijpros($voitot, $sijoitukset, $osallistumiset) . "%</td>";
    echo "</tr>";
}

tableEnd();

// Alkuperäiset funktiot säilyvät ennallaan...
function sijPros($voi, $sij, $os){
    $voi = intval(round($voi));
    $sij = intval(round($sij));
    $os = intval(round($os));
    
    if ($os <= 0){
        return 0;
    }
    
    $sijpros = (($voi + $sij) / $os) * 100;
    return round($sijpros);
}

function tableHead(){
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

function tableEnd(){
    echo "</tbody></table>";
}
?>

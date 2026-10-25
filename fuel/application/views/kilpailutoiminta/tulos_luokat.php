<?php
    $luokkien_max = 40;

    // Pilkotaan teksti riveiksi ja pudotetaan tyhjät (pelkkää tyhjää sisältävät) rivit pois.
    // Aiemmin tämä tehtiin jokaiselle luokalle kolmella eri funktiokutsulla (preg_grep + array_filter + array_values).
    $rivita = function ($teksti) {
        $rivit = array();
        foreach (explode("\n", (string)$teksti) as $rivi) {
            if (trim($rivi) !== '') {
                $rivit[] = $rivi;
            }
        }
        return $rivit;
    };

    $luokat   = $rivita($tulos['luokat']);
    $luokkien_maara = min(sizeof($luokat), $luokkien_max);

    // Tulokset ja hylätyt: luokan loppu merkitään ~-merkillä
    $tulokset = explode("~", (string)$tulos['tulokset']);
    $hylsyt   = explode("~", (string)$tulos['hylatyt']);

    // Kerätään tuloste merkkijonoon ja tulostetaan kerralla
    $out = '<strong>Luokkien määrä: </strong> <span id="luokkien_maara">' . $luokkien_maara . ' kpl</span>';

    for ($i = 0; $i < $luokkien_maara; $i++) {

        $voittajat = $rivita($tulokset[$i] ?? '');
        $osallistujia = sizeof($voittajat);

        // Sijoittuneiden määrä (jaostieto muistetaan kirjastossa, ei kyselyä per luokka)
        $sijoittuu = $tulos['jarjestelma']->sijoittuu($osallistujia, $tulos['jaos']);

        $out .= '<h3>' . $luokat[$i] . ' (<span id="luokan_max_os" style="margin:0; padding:0;">' . $osallistujia . '</span> osallistujaa)</h3><p>';

        foreach ($voittajat as $j => $voittaja) {
            if ($sijoittuu > $j) {
                $out .= '<strong>' . $voittaja . '</strong> <br />';
            } else {
                $out .= $voittaja . '<br />';
            }
        }
        $out .= '</p>';

        $hylatyt = $rivita($hylsyt[$i] ?? '');
        if (!empty($hylatyt)) {
            $out .= '<p><strong>Hylätyt</strong><br />' . implode('<br />', $hylatyt) . '<br /></p>';
        }
    }

    echo $out;
?>

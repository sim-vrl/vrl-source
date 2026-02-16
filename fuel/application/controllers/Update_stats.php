<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Update_stats extends CI_Controller {
  
  public function __construct() {
        parent::__construct();
        // Ladataan Ion Auth kirjasto, jotta voidaan tarkistaa oikeudet
        $this->load->library('ion_auth');
    }

    public function update_stats() {
      if (!$this->ion_auth->logged_in() || !$this->ion_auth->is_admin()) {
        show_error('Sinulla ei ole oikeuksia suorittaa tätä toimintoa. Vain ylläpitäjät voivat päivittää tilastoja.', 403);
    }
        set_time_limit(0);
        
        $nro = 250440150; // rekisterinumero

        // Haetaan osat viivallista hakua varten
        $vuosi = substr($nro, 0, 2);
        $rotu = substr($nro, 2, 3);
        $yksilo = substr($nro, 5, 4);
        $vh_muoto_pitka = $vuosi . "-" . $rotu . "-" . $yksilo;

        echo "<h2>Käynnistetään sijoitusstatistiikan korjaus: $nro</h2>";
        
        $stats = array();

        // SQL-haku on turvallinen sadoille tuhansille riveille kisaosallis-suodattimen ansiosta
      $sql = "SELECT t.tulokset, k.jaos 
        FROM vrlv3_kisat_tulokset t 
        JOIN vrlv3_kisat_kisakalenteri k ON t.kisa_id = k.kisa_id 
        WHERE t.kisa_id IN (
            SELECT kisa_id 
            FROM vrlv3_kisat_kisaosallis 
            WHERE VH = '$nro'
        )";
    
        $q = $this->db->query($sql);

        foreach($q->result_array() as $row) {
            $j_id = $row['jaos'];
            if(!isset($stats[$j_id])) { 
                $stats[$j_id] = array('v'=>0, 's'=>0, 'o'=>0); 
            }

            // PERINTEISET KISAT: Luokat on erotettu ~ merkillä
            $luokat = explode("~", $row['tulokset']);
            
            foreach($luokat as $luokka_teksti) {
                if (empty(trim($luokka_teksti))) continue;

                $luokan_rivit = explode("\n", trim($luokka_teksti));
                $osallistujat_tassa_luokassa = 0;
                $hevosen_sija = 0;

                // 1. Lasketaan luokan osallistujat (rivit jotka alkaa numerolla)
                foreach($luokan_rivit as $rivi) {
                    $rivi = trim($rivi);
                    if(preg_match('/^(\d+)\./', $rivi, $match)) {
                        $osallistujat_tassa_luokassa++;
                        
                        // Tarkistetaan onko hevonen tällä rivillä
                        if(strpos($rivi, (string)$nro) !== false || strpos($rivi, $vh_muoto_pitka) !== false) {
                            $hevosen_sija = intval($match[1]);
                        }
                    }
                }

                // 2. Jos hevonen löytyi, sovelletaan VRL-sijoituskaavaa
                if($hevosen_sija > 0) {
                    $stats[$j_id]['o']++; // Osallistuminen lasketaan aina

                    $max_sij = 0;
                    $n = $osallistujat_tassa_luokassa;

                    if($n >= 100) $max_sij = 10;
                    elseif($n >= 81) $max_sij = 9;
                    elseif($n >= 64) $max_sij = 8;
                    elseif($n >= 49) $max_sij = 7;
                    elseif($n >= 36) $max_sij = 6;
                    elseif($n >= 25) $max_sij = 5;
                    elseif($n >= 16) $max_sij = 4;
                    elseif($n >= 9)  $max_sij = 3;
                    elseif($n >= 4)  $max_sij = 2;
                    elseif($n >= 1)  $max_sij = 1;

                    if($hevosen_sija == 1) {
                        $stats[$j_id]['v']++;
                        $stats[$j_id]['s']++; // Voitto on myös sijoitus
                    } elseif($hevosen_sija <= $max_sij) {
                        $stats[$j_id]['s']++;
                    }
                }
            }
        }

        // Tallennus tietokantaan
        if(!empty($stats)) {
            foreach($stats as $jaos_id => $s) {
                echo "Jaos $jaos_id: Os {$s['o']}, Sij {$s['s']}, Voi {$s['v']}<br>";
                $this->db->query("INSERT INTO vrlv3_hevosrekisteri_kisatiedot (reknro, jaos, voi, sij, os) 
                                 VALUES ($nro, $jaos_id, {$s['v']}, {$s['s']}, {$s['o']}) 
                                 ON DUPLICATE KEY UPDATE voi={$s['v']}, sij={$s['s']}, os={$s['o']}");
            }
            echo "<h3>Maliinan tilastot päivitetty onnistuneesti!</h3>";
        } else {
            echo "Hevoselle $nro ei löytynyt tuloksia.";
        }
    }
}

<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Update_stats extends CI_Controller {
  
    public function __construct() {
        parent::__construct();
        $this->load->library('ion_auth');
    }

    public function update_stats() {
$is_cli = (php_sapi_name() === 'cli' OR defined('STDIN'));
    
    if (!$is_cli) {
        if (!$this->ion_auth->logged_in() || !$this->ion_auth->is_admin()) {
            show_error('Pääsy kielletty.', 403);
        }
    }
        
        set_time_limit(0);
        
        $nro = 250440150; // Rekisterinumero ilman VH-etuliitettä

        // Luodaan eri hakumuodot
        $vuosi = substr($nro, 0, 2);
        $rotu = substr($nro, 2, 3);
        $yksilo = substr($nro, 5, 4);
        
        $vh_viivalla = $vuosi . "-" . $rotu . "-" . $yksilo; // 25-044-0150
        $vh_prefix = "VH" . $vh_viivalla;                  // VH25-044-0150

        echo "<h2>Käynnistetään sijoitusstatistiikan korjaus: $nro</h2>";
        echo "Etsitään muodoilla: $nro, $vh_viivalla, $vh_prefix... <br>";
        
        $stats = array();

        // PLAN B: Haetaan suoraan tekstistä LIKE-haulla, koska osallistujataulu on tyhjä
        $sql = "SELECT t.tulokset, k.jaos 
                FROM vrlv3_kisat_tulokset t 
                JOIN vrlv3_kisat_kisakalenteri k ON t.kisa_id = k.kisa_id 
                WHERE t.tulokset LIKE '%$nro%' 
                   OR t.tulokset LIKE '%$vh_viivalla%' 
                   OR t.tulokset LIKE '%$vh_prefix%'";
    
        $q = $this->db->query($sql);

        foreach($q->result_array() as $row) {
            $j_id = $row['jaos'];
            if(!isset($stats[$j_id])) { 
                $stats[$j_id] = array('v'=>0, 's'=>0, 'o'=>0); 
            }

            // Luokat on erotettu ~ merkillä
            $luokat = explode("~", $row['tulokset']);
            
            foreach($luokat as $luokka_teksti) {
                if (empty(trim($luokka_teksti))) continue;

                $luokan_rivit = explode("\n", trim($luokka_teksti));
                $osallistujat_tassa_luokassa = 0;
                $hevosen_sija = 0;

                // 1. Lasketaan luokan osallistujat ja etsitään hevonen
                foreach($luokan_rivit as $rivi) {
                    $rivi = trim($rivi);
                    if(preg_match('/^(\d+)\./', $rivi, $match)) {
                        $osallistujat_tassa_luokassa++;
                        
                        // Tarkistetaan löytyykö jokin numeromuodoista riviltä
                        if(strpos($rivi, (string)$nro) !== false || 
                           strpos($rivi, $vh_viivalla) !== false || 
                           strpos($rivi, $vh_prefix) !== false) {
                            $hevosen_sija = intval($match[1]);
                        }
                    }
                }

                // 2. Jos hevonen löytyi, sovelletaan VRL-sijoituskaavaa
                if($hevosen_sija > 0) {
                    $stats[$j_id]['o']++; 

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
                        $stats[$j_id]['s']++; 
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
            echo "Hevoselle $nro ei löytynyt tuloksia tekstihallakaan.";
        }
    }
}

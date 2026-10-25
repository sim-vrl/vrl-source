<?php
/**
 * Rajapinta (public JSON API) - optimized version
 *
 * What changed compared to the original (response format is unchanged unless noted):
 *
 *  1. N+1 queries removed. Parents of all foals are fetched with ONE "WHERE reknro IN (...)"
 *     query (chunked) instead of up to two queries per foal. This affects varsat,
 *     tallinkasvatit and nimenkasvatit.
 *  2. Caching with APCu (apcu_*) ONLY. If APCu is not installed/enabled on the server, the
 *     API runs completely uncached and nothing is written to application/cache.
 *       - divisions/traits tables ......... TTL_TRAITS (they almost never change)
 *       - horse data for porrastetut /
 *         ominaisuudet .................... TTL_DEAD_HORSE for dead horses,
 *                                           TTL_LIVE_HORSE for living ones
 *       - foal lists ...................... TTL_FOALS
 *       - result ids ...................... long when found, short when not found
 *     Age / level calculations are done on every request from the cached raw rows, so the
 *     age never goes stale.
 *  3. Per-division trait sums are computed once per request instead of once per division.
 *  4. Notice fixes that do not change the output: porrastetut() read $horse_info[0] before
 *     checking that a row exists, and ominaisuudet() read an undefined array key on error
 *     responses (error_message is still null there, as before).
 *
 * The JSON payload, HTTP headers, input handling and error responses are unchanged.
 * Rows are returned in the same order as before (no added ORDER BY).
 *
 * Cache invalidation: keys are  rj2_horse_<reknro>  for horse data. Call
 *   apcu_delete('rj2_horse_' . (int)$reknro);
 * from the code that awards points / changes a horse's levels if you want instant updates.
 * Bump CACHE_PREFIX (rj2_ -> rj3_) to invalidate everything after a deploy.
 */
class Rajapinta extends CI_Controller
{
    const CACHE_PREFIX     = 'rj2_';
    const TTL_LIVE_HORSE   = 300;     // 5 min
    const TTL_DEAD_HORSE   = 86400;   // 1 day
    const TTL_TRAITS       = 3600;    // 1 hour
    const TTL_FOALS        = 600;     // 10 min
    const TTL_RESULT_FOUND = 86400;   // 1 day (approved results do not change)
    const TTL_MISS         = 60;      // cache "not found" briefly, protects against probing
    const IN_CHUNK         = 1000;    // max ids per WHERE IN query

    const PARENT_COLS_FOALS = "h.reknro, h.nimi, r.rotunro, r.lyhenne as rotulyhenne, h.vari, v.lyhenne as varilyhenne, url";
    const PARENT_COLS_VARSAT = "h.reknro, h.nimi, r.rotunro, r.lyhenne as rotulyhenne, h.vari, v.lyhenne as varilyhenne, sukupuoli, syntymaaika, url";

    //muuttujat osoitteille
    public $hevonen_url;
    public $bis_url;
    public $result_url;

    private $use_apcu = false;

    function __construct()
    {
        parent::__construct();
        $this->hevonen_url = site_url() . "virtuaalihevoset/hevonen/";
        $this->bis_url = site_url() . "kilpailutoiminta/tulosarkisto/bis/";
        $this->result_url = site_url(). "kilpailutoiminta/tulosarkisto/tulos/";
        $this->load->library("vrl_helper");
        $this->load->library("porrastetut");

        // Caching is APCu only. When APCu is missing or disabled, no cache is used at all
        // (the CI file cache is intentionally NOT loaded, so nothing is written to
        // application/cache). To disable caching even when APCu exists, set this to false.
        $this->use_apcu = function_exists('apcu_fetch') && function_exists('apcu_enabled') && apcu_enabled();
    }

    function index ()
    {
        $vars = array();

        $vars['virhekoodit'] = $this->virhekoodit;
        $vars['esimerkit'] = array();
        $vars['esimerkit']['error'] = json_encode($this->_virhetaulu("Virhekoodi numerona", "Virheen sanallinen kuvaus"));
        $vars['esimerkit']['success'] = json_encode(array_merge($this->_base_array(), array("rajapinnan nimi"=>array("rajapinnan", "palauttama", "sisalto", "taulukkona"))));
        $vars['rajapinnat'] = array();
        $vars['rajapinnat']['varsat'] = array("parametrit"=>array("vh-tunnus"),
                                              "esimerkki"=>array("VH03-028-8756"),
                                              "esimerkkikoodi" => "https://github.com/marjaananen/vrlv3/blob/master/esimerkit/rajapinta/varsat.php",
                                              "kuvaus" => "Tällä komennolla voit hakea valitsemasi hevosen jälkeläiset.");
        $vars['rajapinnat']['tulos_id'] = array("parametrit"=>array("kisan id"),
                                              "esimerkki"=>array(153969),
                                              "kuvaus" => "Tällä komennolla saat valitsemasi kilpailun tulos-id:n ja tulosarkistosivun osoitteen");
        $vars['rajapinnat']['nayttelytulos_id'] = array("parametrit"=>array("näyttelyn id"),
                                              "esimerkki"=>array(3240),
                                              "kuvaus" => "Tällä komennolla saat valitsemasi kilpailun tulos-id:n ja tulosarkistosivun osoitteen");
        $vars['rajapinnat']['ominaisuudet'] = array("parametrit"=>array("vh-tunnus"),
                                              "esimerkki"=>array("VH03-028-8756"),
                                              "kuvaus" => "Tällä komennolla voit hakea valitsemasi hevosen porrastettujen ominaisuuspisteet ja tason.
                                              <b>HUOM!</b> tämä on vanhentunut, ja käsittää vain neljä alkuperäistä porrastettujen jaosta. Toimii samalla
                                              tavalla kuin vanha \"ominaisuudet\" rajapinta, mutta vanha rakenne on nyt uuden rajapintarakenteen mukaisesti
                                              \"ominaisuudet\" kentässä. Virhekoodit vastaavat vanhaa, eivätkä uutta rajapintaa. Älä ota tätä käyttöön mikäli
                                              teet sivuillesi uutta toteutusta! Käytä \"porrastetut\"-rajapintaa tämän sijaan. ");
        $vars['rajapinnat']['porrastetut'] = array("parametrit"=>array("vh-tunnus"),
                                              "esimerkki"=>array("VH03-028-8756"),
                                              "esimerkkikoodi" => "https://github.com/marjaananen/vrlv3/blob/master/esimerkit/rajapinta/porrastetut.php",
                                              "kuvaus" => "Tällä komennolla voit hakea valitsemasi hevosen porrastettujen ominaisuuspisteet ja tason kaikissa
                                              jaoksissa ja ominaisuuksissa. Koska jaoksia ja ominaisuuksia voi tulla lisää, rajapinta palauttaa myös tiedot jaoksista ja ominaisuuksista.
                                              Rajapinta palauttaa myös hevosen ikä- ja säkäkorkeustiedot sekä VRL:n sivuilla asetetun maksimitason. 
                                              ");
        $vars['rajapinnat']['tallinkasvatit'] = array("parametrit"=>array("tallitunnus", "rotunro (ei pakollinen)"),
                                              "esimerkki"=>array("KARK4835", "28"),
                                              "kuvaus" => "Haetaan tietyn tallin kasvatit, parametrina tallin tallitunnus, ja halutessaan valitun rodun id.
                                              Rodun id:n antaminen ei ole pakollista. Ilman ID:tä haetaan kaikenrotuiset kasvatit.");
        $vars['rajapinnat']['nimenkasvatit'] = array("parametrit"=>array("kasvattajanimi_id", "rotunro (ei pakollinen)"),
                                              "esimerkki"=>array("2047", "28"),
                                              "kuvaus" => "Haetaan tietyn kasvattajanimen kasvatit, parametrina kasvattajanimen id, ja halutessaan valitun rodun id.
                                              Rodun id:n antaminen ei ole pakollista. Ilman ID:tä haetaan kaikenrotuiset kasvatit.");

        $this->fuel->pages->render('rajapinta/index', $vars);
    }

    var $virhekoodit = array ("400" => "Antamasi parametri (esim. vh-tunnus tai kilpailun id) on virheellinen",
                              "404" => "Antamillasi parametreilla (esim. vh-tunnus tai kilpailun id) ei löydy tulosta",
                              "600" => "Porrastetut: Hevonen liian nuori tai siltä puuttuu ikä.",
                              "601" => "Porrastetut: Hevonen liian matala tai siltä puuttuu säkäkorkeus.",
                              "800" => "Tapahtui odottamaton virhe. Ota yhteys ylläpitoon.");

    // ------------------------------------------------------------------
    // Output helpers
    // ------------------------------------------------------------------

    private function _print($array = array()){
        if(sizeof($array) == 0){
            $array = $this->_virhetaulu(800, $this->virhekoodit[800] );
        }

        $this->load->view('rajapinta/json', array('json'=>json_encode($array)));
    }

    //valmiit perusrakenteet
    private function _virhetaulu($koodi, $teksti){
        return array ("error"=> 1, "error_code"=> $koodi, "error_description"=> $teksti);
    }

    private function _base_array(){
        return array ("error"=>0);
    }

    // ------------------------------------------------------------------
    // Cache / validation helpers
    // ------------------------------------------------------------------

    private function _key($type, $parts = array()){
        return self::CACHE_PREFIX . $type . '_' . md5(implode('|', (array)$parts));
    }

    /**
     * Cache-aside helper.
     * $ttl is either seconds (int) or a callable that receives the produced value and
     * returns seconds (return 0 to skip caching).
     */
    private function _cached($key, $ttl, $producer){
        $hit = $this->_cache_get($key);
        if($hit !== FALSE){
            return $hit;
        }

        $value = $producer();
        $seconds = is_callable($ttl) ? $ttl($value) : $ttl;
        if($seconds > 0){
            $this->_cache_set($key, $value, $seconds);
        }
        return $value;
    }

    // Returns FALSE on a miss (we never store a bare FALSE).
    // Without APCu this is always a miss, so the data is simply fetched from the database.
    private function _cache_get($key){
        if($this->use_apcu){
            $value = apcu_fetch($key, $found);
            return $found ? $value : FALSE;
        }
        return FALSE;
    }

    // Without APCu this does nothing (no files are written).
    private function _cache_set($key, $value, $seconds){
        if($this->use_apcu){
            apcu_store($key, $value, (int)$seconds);
        }
    }

    // for results that are a plain list of rows. Empty lists are not cached: ids are not
    // validated (as in the original), so caching misses would let junk ids fill the cache.
    private function _cached_rows($key, $producer){
        return $this->_cached($key, function($rows){
            return empty($rows) ? 0 : self::TTL_FOALS;
        }, $producer);
    }

    // ------------------------------------------------------------------
    // Foals: varsat, tallinkasvatit, nimenkasvatit
    // ------------------------------------------------------------------

    function varsat ($vh){
        $data = $this->_base_array();
        if(!$this->vrl_helper->check_vh_syntax($vh)){
            $data = $this->_virhetaulu(400, "Virheellinen vh-tunnus");
        }else {
            $nro = $this->vrl_helper->vh_to_number($vh);

            $data['varsat'] = $this->_cached_rows($this->_key('varsat', array($nro)), function() use ($nro){
                return $this->_get_horses_foals($nro);
            });
        }

        $this->_print($data);
    }

    function tallinkasvatit ($tnro, $rotunro = null){
        $data = $this->_base_array();
        $data['tallinkasvatit'] = $this->_get_foals_by('h.kasvattaja_talli', $tnro, $rotunro, false);
        $this->_print($data);
    }

    function nimenkasvatit ($id, $rotunro = null){
        $data = $this->_base_array();
        $data['nimenkasvatit'] = $this->_get_foals_by('h.kasvattajanimi_id', $id, $rotunro, true);
        $this->_print($data);
    }

    // Cached wrapper shared by tallinkasvatit and nimenkasvatit.
    private function _get_foals_by($column, $value, $rotunro, $include_stable){
        $key = $this->_key('foals', array($column, $value, $rotunro));

        return $this->_cached_rows($key, function() use ($column, $value, $rotunro, $include_stable){
            return $this->_query_foals($column, $value, $rotunro, $include_stable);
        });
    }

    /**
     * Fetches the foals with one query and ALL their parents with one more query
     * (instead of up to 2 queries per foal).
     * $include_stable adds the kasvattaja_talli column (used by nimenkasvatit, as before).
     */
    private function _query_foals($column, $value, $rotunro, $include_stable){
        $select = "h.reknro, h.nimi, r.rotunro, r.lyhenne as rotulyhenne, "
                . ($include_stable ? "h.kasvattaja_talli, " : "")
                . "h.kasvattajanimi, h.kasvattajanimi_id, h.vari, v.lyhenne as varilyhenne, sukupuoli, syntymaaika, url, i_nro, e_nro";

        $this->db->select($select);
        $this->db->from('vrlv3_hevosrekisteri as h');
        $this->db->join("vrlv3_lista_rodut as r", "h.rotu = r.rotunro", 'left outer');
        $this->db->join("vrlv3_lista_varit as v", "h.vari = v.vid", 'left outer');
        $this->db->join("vrlv3_hevosrekisteri_sukutaulut as s", "s.reknro = h.reknro", 'left outer');
        if($rotunro !== null){
            $this->db->where('h.rotu', $rotunro);
        }
        $this->db->where($column, $value);
        $query = $this->db->get();
        $rows = $query->result_array();
        $query->free_result();

        // one query for every distinct parent of every foal
        $parent_ids = array();
        foreach($rows as $rivi){
            $parent_ids[] = $rivi['i_nro'];
            $parent_ids[] = $rivi['e_nro'];
        }
        $parents = $this->_get_parent_horses($parent_ids, self::PARENT_COLS_FOALS);

        $out = array();
        foreach($rows as $rivi){
            $i_raw = $rivi['i_nro'];
            $e_raw = $rivi['e_nro'];

            $rivi['reknro'] = $this->vrl_helper->get_vh($rivi['reknro']);
            $rivi['i_nro'] = $this->vrl_helper->get_vh($i_raw);
            $rivi['e_nro'] = $this->vrl_helper->get_vh($e_raw);
            $rivi['syntymaaika'] = $this->vrl_helper->sql_date_to_normal($rivi['syntymaaika']);

            if(!empty($i_raw) && isset($parents[$i_raw])){
                $isa = $parents[$i_raw];
                $isa['reknro'] = $this->vrl_helper->get_vh($isa['reknro']);
                $rivi['i'] = $isa;
            }

            if(!empty($e_raw) && isset($parents[$e_raw])){
                $ema = $parents[$e_raw];
                $ema['reknro'] = $this->vrl_helper->get_vh($ema['reknro']);
                $rivi['e'] = $ema;
            }

            $out[] = $rivi;
        }

        return $out;
    }

    /**
     * Fetch many horses by id with WHERE IN (chunked). Returns raw rows keyed by raw reknro.
     * (The original parent queries also joined the pedigree table, but that join is not
     * needed: none of the selected columns come from it.)
     */
    private function _get_parent_horses($ids, $columns){
        $out = array();
        $ids = array_values(array_unique(array_filter($ids)));

        foreach(array_chunk($ids, self::IN_CHUNK) as $chunk){
            $this->db->select($columns);
            $this->db->from('vrlv3_hevosrekisteri as h');
            $this->db->join("vrlv3_lista_rodut as r", "h.rotu = r.rotunro", 'left outer');
            $this->db->join("vrlv3_lista_varit as v", "h.vari = v.vid", 'left outer');
            $this->db->where_in('h.reknro', $chunk);
            $query = $this->db->get();
            foreach($query->result_array() as $row){
                $out[$row['reknro']] = $row;
            }
            $query->free_result();
        }

        return $out;
    }

    private function _get_horses_foals($nro)
    {
        $this->db->select("h.reknro, h.nimi, r.rotunro, r.lyhenne as rotulyhenne, h.vari, v.lyhenne as varilyhenne, sukupuoli, syntymaaika, url, i_nro, e_nro");
        $this->db->from('vrlv3_hevosrekisteri as h');
        $this->db->join("vrlv3_lista_rodut as r", "h.rotu = r.rotunro", 'left outer');
        $this->db->join("vrlv3_lista_varit as v", "h.vari = v.vid", 'left outer');
        $this->db->join("vrlv3_hevosrekisteri_sukutaulut as s", "s.reknro = h.reknro", 'left outer');
        $this->db->group_start();//this will start grouping
        $this->db->where('i_nro', $nro);
        $this->db->or_where('e_nro', $nro);
        $this->db->group_end(); //this will end grouping
        $this->db->order_by('syntymaaika', 'desc');
        $query = $this->db->get();
        $hevoset = $query->result_array();
        $query->free_result();

        // 1) work out which "other parent" each foal needs
        $other = array();
        foreach($hevoset as $idx => $hevonen){
            $haettava = null;
            //jos hettava oli emä, katsotaan onko isää
            if(isset($hevonen['e_nro']) && $hevonen['e_nro'] == $nro && isset($hevonen['i_nro'])){
                $haettava = $hevonen['i_nro'];
            }else if(isset($hevonen['i_nro']) && $hevonen['i_nro'] == $nro  && isset($hevonen['e_nro'])){
                $haettava = $hevonen['e_nro'];
            }
            $other[$idx] = $haettava;
        }

        // 2) fetch all of them with one query
        $parents = $this->_get_parent_horses($other, self::PARENT_COLS_VARSAT);

        // 3) format
        $out = array();
        foreach($hevoset as $idx => $hevonen){
            $hevonen['reknro'] = $this->vrl_helper->get_vh($hevonen['reknro']);
            $hevonen['syntymaaika'] = $this->vrl_helper->sql_date_to_normal($hevonen['syntymaaika']);
            $hevonen['rek_url'] = $this->hevonen_url . $hevonen['reknro'];
            $hevonen['vanhempi'] = array();

            $haettava = $other[$idx];
            if($haettava !== null && isset($parents[$haettava])){
                $vanhempi = $parents[$haettava];
                $vanhempi['reknro'] = $this->vrl_helper->get_vh($vanhempi['reknro']);
                $vanhempi['syntymaaika'] = $this->vrl_helper->sql_date_to_normal($vanhempi['syntymaaika']);
                $vanhempi['rek_url'] = $this->hevonen_url . $vanhempi['reknro'];
                $hevonen['vanhempi'] = $vanhempi;
            }

            unset($hevonen['i_nro']);
            unset($hevonen['e_nro']);
            $out[] = $hevonen;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Result ids
    // ------------------------------------------------------------------

    function tulos_id($kisa_id){
        $this->_tulos_id_haku($kisa_id, "tulos_id", false);
    }

    function nayttelytulos_id($nayttely_id){
        $this->_tulos_id_haku($nayttely_id, "nayttelytulos_id", true);
    }

    private function _tulos_id_haku($id, $name, $nayttelyt){
        if (strpos($id,".") === false AND strpos($id,",") === false AND strlen($id) < 12 AND is_numeric ( $id )){
            $data = $this->_base_array();
            $url = $nayttelyt ? $this->bis_url : $this->result_url;

            $tulos_id = $this->_cached($this->_key($name, array($id)), function($v){
                return $v > 0 ? self::TTL_RESULT_FOUND : self::TTL_MISS;
            }, function() use ($id, $nayttelyt){
                return $nayttelyt ? $this->_get_show_result_id($id) : $this->_get_comp_result_id($id);
            });

            if($tulos_id > 0){
                $data[$name]['id'] = $tulos_id;
                $data[$name]['url'] = $url . $tulos_id;
                $this->_print($data);
            }else {
                $this->_print($this->_virhetaulu(404, "Antamallasi id:llä ei löydy tuloksia"));
            }
        }
        else {
            $this->_print($this->_virhetaulu(400, "Virheellinen kisa-id"));
        }
    }

    private function _get_show_result_id($kisa_id){
        $this->db->select("bis_id");
        $this->db->from("vrlv3_kisat_nayttelytulokset");
        $this->db->where("nayttely_id", $kisa_id);
        $this->db->where('hyvaksytty is NOT NULL', NULL, FALSE);
        $this->db->where("hyvaksytty != ", '0000-00-00 00:00:00');
        $this->db->limit(1);
        $query = $this->db->get();
        if ($query->num_rows() > 0)
        {
            return $query->result_array()[0]['bis_id'];
        }
        else {
            return 0;
        }
    }

    private function _get_comp_result_id($kisa_id){
        $this->db->select("tulos_id");
        $this->db->from("vrlv3_kisat_tulokset");
        $this->db->where("kisa_id", $kisa_id);
        $this->db->where('hyvaksytty is NOT NULL', NULL, FALSE);
        $this->db->where("hyvaksytty != ", '0000-00-00 00:00:00');
        $this->db->limit(1);
        $query = $this->db->get();
        if ($query->num_rows() > 0)
        {
            return $query->result_array()[0]['tulos_id'];
        }
        else {
            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Leveled system: porrastetut, ominaisuudet
    // ------------------------------------------------------------------

    function porrastetut($vh){
        $data = $this->_base_array();
        $return_data = array();

        if(!$this->vrl_helper->check_vh_syntax($vh)){
            $data = $this->_virhetaulu(400, "Virheellinen vh-tunnus");
        }else {
            $vh = $this->vrl_helper->vh_to_number($vh);
            $horse_data = $this->_get_horse_data($vh);
            $horse_info = $horse_data['rows'];

            if (sizeof($horse_info) == 0){
                $data = $this->_virhetaulu(404, "VH-tunnukselta ei löytynyt tietoja");
            }
            else {
                $horse = $horse_info[0];
                $traits = $this->_get_all_traits();
                $ominaisuudet_id_name = $traits['ominaisuudet_id_name'];
                $ominaisuus_per_jaos = $traits['ominaisuus_per_jaos'];
                $jaokset_id_name = $traits['jaokset_id_name'];
                $maxs = $horse_data['maxs'];
                $sums = $horse_data['sums']; // precomputed when the horse was cached

                $this->load->library("age_calc");
                $age = $this->porrastetut->calculate_age($horse);
                $levelByAge =  $this->porrastetut->level_by_age($age);
                $compare_day = date("Y-m-d");
                $ikaantyminen = $horse['ikaantyminen_d'] ?? 0;
                $calculated_age = 0;
                if($ikaantyminen > 0){
                     $calculated_age = $this->age_calc->calculateAge ($horse['syntymaaika'],  $ikaantyminen, $compare_day);
                }

                //kaikki ominaisuudet ja jaokset listaan
                $return_data['info'] = array();
                $return_data['info']['ominaisuudet'] = $ominaisuudet_id_name;
                $return_data['info']['jaokset'] = $jaokset_id_name;

                //hevosen tiedot
                $return_data['hevonen'] = array();
                $return_data['hevonen']['info'] = array();
                $return_data['hevonen']['ominaisuudet'] = array();
                $return_data['hevonen']['tasot'] = array();
                $return_data['hevonen']['error'] = 0;

                if( $levelByAge == -1 ) {
                    $return_data['hevonen']['error'] = 1;
                    $return_data['hevonen']['error_code'] = 600;
                    $return_data['hevonen']['error_message'] ='Hevonen on liian nuori kilpailemaan tai siltä puuttuu 3-vuotispäivämäärä profiilistaan.';
                }
                else if(!isset($horse['sakakorkeus']) || $horse['sakakorkeus'] == null || $horse['sakakorkeus'] == 0 || $horse['sakakorkeus'] < 50){
                    $return_data['hevonen']['error'] = 1;
                    $return_data['hevonen']['error_code'] = 601;
                    $return_data['hevonen']['error_message']  = 'Hevoselta puuttuu säkäkorkeus profiilista!';
                } else {
                    $return_data['hevonen']['info']['max_taso_per_ika'] = $levelByAge;
                    $return_data['hevonen']['info']['sakakorkeus'] = $horse['sakakorkeus'];
                    $return_data['hevonen']['info']['ika'] = array("syntymaaika"=>$horse['syntymaaika'],
                                                                   "3vuotta"=>$horse['3vuotta'],
                                                                   "4vuotta"=>$horse['4vuotta'],
                                                                   "5vuotta"=>$horse['5vuotta'],
                                                                   "6vuotta"=>$horse['6vuotta'],
                                                                   "7vuotta"=>$horse['7vuotta'],
                                                                   "8vuotta"=>$horse['8vuotta'],
                                                                   "ika"=> $age,
                                                                   "ikaantyminen_paivaa"=> $ikaantyminen,
                                                                   "ikaantyminen_ika"=>$calculated_age);
                     $return_data['hevonen']['info']['kuollut'] = $horse['kuollut'];

                     if($return_data['hevonen']['info']['kuollut']){
                        $return_data['hevonen']['info']['kuol_pvm'] = date('Y-m-d', strtotime($horse['kuol_pvm']));
                     }
                     else {
                        $return_data['hevonen']['info']['kuol_pvm'] = null;
                     }
                }

                //jaosten ominaisuudet jaokselle ja hevoselle
                foreach ($return_data['info']['jaokset'] as $id=>$jaos){
                    $return_data['info']['jaokset'][$id]['ominaisuudet'] = $ominaisuus_per_jaos[$id];
                    $points = $this->_sum_for($sums, $ominaisuus_per_jaos[$id]);

                    if( $return_data['hevonen']['error']  == 0){
                        $level_per_points = $this->porrastetut->get_level_by_points($points);
                        $level_per_age = $return_data['hevonen']['info']['max_taso_per_ika'];
                        $return_data['hevonen']['tasot'][$id] = array("jaos"=>$id,
                                                               "pisteet" => $points,
                                                               "taso" => min($level_per_age, $level_per_points),
                                                                "max_taso_per_pisteet" => $level_per_points,
                                                                "taso_rajoitus" => $maxs[$id] ?? 10);
                    }else {
                        $return_data['hevonen']['tasot'][$id] = array("jaos"=>$id,
                                                                "pisteet" => $points,
                                                                "taso" => 0,
                                                                "max_taso_per_pisteet" => 0,
                                                                "taso_rajoitus" => 0);
                    }
                }

                foreach ($return_data['info']['ominaisuudet'] as $id => $ominaisuus){
                    $return_data['hevonen']['ominaisuudet'][$id] = array("ominaisuus"=>$id,
                                                              "pisteet" => $this->_sum_for($sums, array($id)));
                }

                $data['porrastetut'] = $return_data;
            }
        }
        //Heitetään data luettavaksi
        $this->_print($data);
    }

    function ominaisuudet($vh){

        $return_data = array();
        $return_data['points'] = array();
        $return_data['error'] = 0;
        $return_data['error_code'] = 0;
        $return_data['error_message'] = "";
        if(!$this->vrl_helper->check_vh_syntax($vh)){
            $return_data = $this->_virhetaulu(400, "Virheellinen vh-tunnus");
        }else {
            $vh = $this->vrl_helper->vh_to_number($vh);
            $horse_data = $this->_get_horse_data($vh);
            $horse_info = $horse_data['rows'];

            if (sizeof($horse_info) == 0){
                $return_data = $this->_virhetaulu(404, "VH-tunnukselta ei löytynyt tietoja");
            }
            else {
                $sums = $horse_data['sums'];

                //hyppuykapasiteetti ja rohkeus 3, 4
                //tahti ja irtonaisuus 6
                //kuuliaisuus ja luonne 5
                //nopeus ja kestävyys = 1, 2
                //tarkkuus ja ketteryys = 7

                $return_data['points'] = array();
                $return_data['points']['hyppykapasiteetti_rohkeus'] = $this->_sum_for($sums, array(3,4));
                $return_data['points']['tahti_irtonaisuus'] = $this->_sum_for($sums, array(6));
                $return_data['points']['kuuliaisuus_luonne'] = $this->_sum_for($sums, array(5));
                $return_data['points']['nopeus_kestavyys'] = $this->_sum_for($sums, array(1,2));
                $return_data['points']['tarkkuus_ketteryys'] = $this->_sum_for($sums, array(7));

                $lajit = array(1 =>"erj", 3 => "kerj", 2 => "krj", 4 => "vvj");

                $return_data['erj']['points'] = $return_data['points']['hyppykapasiteetti_rohkeus'] + $return_data['points']['kuuliaisuus_luonne'];
                $return_data['krj']['points'] = $return_data['points']['tahti_irtonaisuus'] + $return_data['points']['kuuliaisuus_luonne'];
                $return_data['kerj']['points'] = $return_data['points']['hyppykapasiteetti_rohkeus'] + $return_data['points']['nopeus_kestavyys'];
                $return_data['vvj']['points'] = $return_data['points']['tarkkuus_ketteryys'] + $return_data['points']['kuuliaisuus_luonne'];

                foreach($lajit as $id=>$laji){
                    $return_data[$laji]['error'] = 0;
                    $return_data[$laji]['level_max'] = 10;
                    $return_data[$laji]['level'] = 0;
                }
                $horse = $horse_info[0];

                $age = $this->porrastetut->calculate_age($horse);
                $levelByAge =  $this->porrastetut->level_by_age($age);

                if( $levelByAge == -1 ) {
                    $return_data['error'] = 1;
                    $return_data['error_code'] = 600;
                    $return_data['error_message'] ='Hevonen on liian nuori kilpailemaan tai siltä puuttuu 3-vuotispäivämäärä profiilistaan.';
                }
                else if(!isset($horse['sakakorkeus']) || $horse['sakakorkeus'] == null || $horse['sakakorkeus'] == 0 || $horse['sakakorkeus'] < 50){
                    $return_data['error'] = 1;
                    $return_data['error_code'] = 601;
                    $return_data['error_message'] = 'Hevoselta puuttuu säkäkorkeus profiilista!';
                }
                else {
                    $maxs = $horse_data['maxs'];

                    foreach($lajit as $id=>$laji){

                        if(isset($maxs[$id])){
                            $return_data[$laji]['level_max'] = $maxs[$id];
                        }

                        $level = $this->porrastetut->get_level_by_points($return_data[$laji]['points']);

                        if ($levelByAge < $level){
                            $level = $levelByAge;
                            $return_data[$laji]['error'] = 403;
                            $return_data[$laji]['error_message'] = "Hevonen on liian nuori nousemaan tasolta vaikka ominaisuuspisteet sen sallisivat.";
                        }

                        $return_data[$laji]['level'] = $level;
                    }
                }
            }
        }

        //Muunnetaan array jsoniksi
        $data = $this->_base_array();
        $data['ominaisuudet'] = $return_data;
        $data['error'] = $return_data['error'];
        $data['error_code'] = $return_data['error_code'];
        // _virhetaulu() responses have no "error_message" key, the original output null there
        $data['error_message'] = $return_data['error_message'] ?? null;
        //Heitetään data luettavaksi
        $this->_print($data);
    }

    /**
     * Raw horse rows + max levels, cached per horse.
     * Dead horses can't change, so they are cached for a day; living horses only briefly.
     * Age and levels are calculated from these rows on every request, so they never go stale.
     */
    private function _get_horse_data($vh){
        return $this->_cached(self::CACHE_PREFIX . 'horse_' . (int)$vh, function($d){
            if(empty($d['rows'])){
                return self::TTL_MISS;
            }
            return !empty($d['rows'][0]['kuollut']) ? self::TTL_DEAD_HORSE : self::TTL_LIVE_HORSE;
        }, function() use ($vh){
            $rows = $this->_get_horses_leveled_info($vh);
            if(empty($rows)){
                return array('rows' => array(), 'sums' => array(), 'maxs' => array());
            }
            return array(
                // the query returns one row per trait, each repeating the same horse columns;
                // only the first row is needed, trait points are kept as precomputed sums
                'rows' => array($rows[0]),
                'sums' => $this->_skill_sums($rows),
                'maxs' => $this->_get_horse_max_levels($vh)
            );
        });
    }

    /**
     * Divisions / traits. Static configuration data, cached.
     * Returns array('ominaisuudet_id_name' => ..., 'ominaisuus_per_jaos' => ..., 'jaokset_id_name' => ...)
     */
    private function _get_all_traits(){
        return $this->_cached(self::CACHE_PREFIX . 'traits', self::TTL_TRAITS, function(){
            $ominaisuudet_id_name = array();
            $ominaisuus_per_jaos = array();
            $jaokset_id_name = array();

            $this->db->select('j.id as jaos_id, j.lyhenne as jaos_lyhenne, j.nimi as jaos_nimi, o.id as ominaisuus_id, o.ominaisuus as ominaisuus_nimi');
            $this->db->from('vrlv3_kisat_jaokset_ominaisuudet');
            $this->db->join('vrlv3_lista_ominaisuudet as o','o.id = vrlv3_kisat_jaokset_ominaisuudet.ominaisuus');
            $this->db->join('vrlv3_kisat_jaokset as j', 'j.id = vrlv3_kisat_jaokset_ominaisuudet.jaos');

            $query = $this->db->get();

            foreach($query->result_array() as $rivi){
                $ominaisuudet_id_name[$rivi['ominaisuus_id']] = $rivi['ominaisuus_nimi'];
                $jaokset_id_name[$rivi['jaos_id']] = array("jaos_nimi"=>$rivi['jaos_nimi'], "jaos_lyhenne"=>$rivi['jaos_lyhenne']);

                if(!isset($ominaisuus_per_jaos[$rivi['jaos_id']])){
                    $ominaisuus_per_jaos[$rivi['jaos_id']] = array();
                }
                if(!in_array($rivi['ominaisuus_id'], $ominaisuus_per_jaos[$rivi['jaos_id']])){
                    $ominaisuus_per_jaos[$rivi['jaos_id']][] = $rivi['ominaisuus_id'];
                }
            }
            $query->free_result();

            return array(
                'ominaisuudet_id_name' => $ominaisuudet_id_name,
                'ominaisuus_per_jaos' => $ominaisuus_per_jaos,
                'jaokset_id_name' => $jaokset_id_name
            );
        });
    }

    // Kept as SELECT * on purpose: Porrastetut::calculate_age() is a library we can't see here,
    // and it may read any column of this joined row. The row is small, so the cost is low.
    private function _get_horses_leveled_info($vh){
        $this->db->from('vrlv3_hevosrekisteri as h');
        $this->db->join('vrlv3_hevosrekisteri_ikaantyminen as i', 'h.reknro = i.reknro', 'LEFT');
        $this->db->join('vrlv3_hevosrekisteri_ominaisuudet as o', 'h.reknro = o.reknro', 'LEFT');

        $this->db->where('h.reknro', $vh);

        $query = $this->db->get();
        $rows = $query->result_array();
        $query->free_result();
        return $rows;
    }

    private function _get_horse_max_levels($vh){
        $max_levels = array();
        $info = $this->_get_horse_competition_info($vh);
        foreach($info as $rivi){
            $max_levels[$rivi['jaos']] = $rivi['taso_max'];
        }

        return $max_levels;
    }

    private function _get_horse_competition_info($vh){
        $this->db->select('jaos, taso_max');
        $this->db->from('vrlv3_hevosrekisteri_kisatiedot');
        $this->db->where('reknro', $vh);

        $query = $this->db->get();
        $rows = $query->result_array();
        $query->free_result();
        return $rows;
    }

    // Sum of points per trait id, computed in one pass over the horse's rows.
    private function _skill_sums($list){
        $sums = array();
        foreach ($list as $row){
            if(isset($row['ominaisuus'])){
                $sums[$row['ominaisuus']] = ($sums[$row['ominaisuus']] ?? 0) + $row['arvo'];
            }
        }
        return $sums;
    }

    private function _sum_for($sums, $needed_skills){
        $sum = 0;
        foreach ($needed_skills as $id){
            $sum = $sum + ($sums[$id] ?? 0);
        }
        return $sum;
    }
}
?>
